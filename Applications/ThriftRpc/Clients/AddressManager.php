<?php
namespace ThriftClient;
/**
 * 
 * address 分配器
 * 支持故障地址剔除接口（需要PHP开启sysvshm）
 * 支持一定几率（默认5/10000）使用故障地址，用来判断故障地址是否重新可用（需要PHP开启sysvshm）
 * 
 */
class AddressManager
{
    /**
     * 多少几率去访问故障节点，用来判断节点是否已经恢复
     * @var float
     */
    const DETECT_RATE = 0.0005;
    
    /**
     * 存储RPC服务端节点共享内存的key
     * @var int
     */
    const BAD_ASSRESS_LIST_SHM_KEY = 0x55656;
    
    /**
     * 保存所有故障节点的VAR
     * @var int
     */
    const SHM_BAD_ADDRESS_KEY = 1;
    
    /**
     * 保存配置的md5的VAR,用于判断文件配置是否已经更新
     * @var int
     */
    const SHM_CONFIG_MD5 = 2;
    
    /**
     * 配置
     * @var array
     */
    private static $config = null;
    
    /**
     * 故障节点共享内存fd
     * @var resource
     */
    private static $badAddressShmFd = null;
    
    /**
     * 故障的节点列表
     * @var array
     */
    private static $badAddressList = null;
    
    /**
     * 信号量的fd
     * @var resource
     */
    private static $semFd = null;
    
    /**
     * 是否支持共享内存
     * @var bool
     */
    protected static $shmEnable = true;
    
    /**
     * 设置/获取 配置
     *  array(  
     *      'HelloWorld' => array(
     *              '127.0.0.1:9090',
     *              '127.0.0.1:9191',
     *              '127.0.0.2:9090',
     *      ),
     *      'UserInfo' => array(
     *              '127.0.0.1:9393'
     *      ),
     *  )
     * @param array $config
     * @return array
     */
    public static function config(array $config = array())
    {
        if(!empty($config))
        {
            // 初始化配置
            self::$config = $config;
            if(self::$shmEnable && extension_loaded('sysvshm') && extension_loaded('sysvsem'))
            {
                // 检查现在配置md5与共享内存中md5是否匹配，用来判断配置是否有更新
                self::checkConfigMd5();
            }
            else if(self::$shmEnable)
            {
                self::$shmEnable = false;
            }
                
            // 从共享内存中获得故障节点列表
            self::getBadAddressList();
        }
        return self::$config;
    }
    
    /**
     * 根据key获取一个节点，如果有故障节点，则有一定几率(默认5/10000)获得一个故障节点
     * @param string $key
     * @throws \Exception
     * @return string
     */
    public static function getOneAddress($key)
    {
        // 配置中没有配置这个服务
        if(!isset(self::$config[$key]))
        {
            throw new \Exception("Address[$key] is not exist!");
        }
    
        // 总的节点列表
        $address_list = self::$config[$key];
    
        // 获取故障节点列表
        $bad_address_list = self::getBadAddressList();
        if($bad_address_list)
        {
            // 获得可用节点
            $address_list = array_diff($address_list, $bad_address_list);
            // 有一定几率使用故障节点,或者全部故障了，则随机选择一个故障节点
            $base_num = 1000000;
            if(rand(0, $base_num)/$base_num <= self::DETECT_RATE || (empty($address_list)))
            {
                // 故障的节点
                $address_list_bad = array_intersect($bad_address_list, self::$config[$key]);
                if($address_list_bad)
                {
                    // 命中几率，获取到一个故障地址
                    $address = $address_list_bad[array_rand($address_list_bad)];
                    // 先从故障节点列表中删除，如果仍然是故障节点，则应用会再一次将这个节点踢掉放入故障节点列表
                    self::recoverAddress($address);
                    // 返回故障节点
                    return $address;
                }
            }
        }
        
        // 如果没有可用的节点
        if (empty($address_list))
        {
            throw new \Exception("No avaliable server node! service_name:$key allAddress:[".implode(',', self::$config[$key]).'] badAddress:[' . implode(',', $bad_address_list).']');
        }
    
        // 随机选择一个节点
        return $address_list[array_rand($address_list)];
    }
    
    /**
     * 获取故障节点共享内存的Fd
     * @return resource
     */
    public static function getShmFd()
    {
        if(!self::$badAddressShmFd && self::$shmEnable)
        {
            self::$badAddressShmFd = shm_attach(self::BAD_ASSRESS_LIST_SHM_KEY);
        }
        return self::$badAddressShmFd;
    }
    
    /**
     * 获取信号量fd
     * @return resource
     */
    public static function getSemFd()
    {
        if(!self::$semFd && self::$shmEnable)
        {
            self::$semFd = sem_get(self::BAD_ASSRESS_LIST_SHM_KEY);
        }
        return self::$semFd;
    }
    
    /**
     * 检查配置文件的md5值是否正确,
     * 用来判断配置是否有更改
     * 有更改清空badAddressList
     * @return bool
     */
    public static function checkConfigMd5()
    {
        // 没有加载扩展
        if(!self::$shmEnable )
        {
            return false;
        }
        
        // 获取shm_fd
        if(!self::getShmFd())
        {
            return false;
        }
        
        // 尝试读取md5，可能其它进程已经写入了
        $config_md5 = @shm_get_var(self::getShmFd(), self::SHM_CONFIG_MD5);
        $config_md5_now = md5(serialize(self::$config));
        
        // 有md5值，则判断是否与当前md5值相等
        if($config_md5 === $config_md5_now)
        {
            return true;
        }
        
        self::$badAddressList = array();
        
        // 清空badAddressList
        self::getMutex();
        $ret = shm_put_var(self::getShmFd(), self::SHM_BAD_ADDRESS_KEY, array());
        self::releaseMutex();
        if($ret)
        {
            // 写入md5值
            self::getMutex();
            $ret = shm_put_var(self::getShmFd(), self::SHM_CONFIG_MD5, $config_md5_now);
            self::releaseMutex();
            return $ret;
        }
        return false;
    }
    
    /**
     * 获取故障节点列表
     * @return array
     */
    public static function getBadAddressList($use_cache = true)
    {
        // 还没有初始化故障节点
        if(null === self::$badAddressList || !$use_cache)
        {
            $bad_address_list = array();
            if(self::$shmEnable && shm_has_var(self::getShmFd(), self::SHM_BAD_ADDRESS_KEY))
            {
                // 获取故障节点
                $bad_address_list = shm_get_var(self::getShmFd(), self::SHM_BAD_ADDRESS_KEY);
                if(!is_array($bad_address_list))
                {
                    // 可能是共享内寻写怀了，重新清空
                    self::getMutex();
                    shm_remove(self::getShmFd());
                    self::releaseMutex();
                    self::$badAddressShmFd = null;
                    self::$badAddressList = array();
                }
                else
                {
                    self::$badAddressList = $bad_address_list;
                }
            }
            else
            {
                self::$badAddressList = $bad_address_list;
            }
        }
        return self::$badAddressList;
    }
    
    /**
     * 踢掉故障节点
     * @param string $address
     * @bool
     */
    public static function kickAddress($address)
    {
        if(!self::$shmEnable)
        {
            return false;
        }
        $bad_address_list = self::getBadAddressList(false);
        $bad_address_list[] = $address;
        $bad_address_list = array_unique($bad_address_list);
        self::$badAddressList = $bad_address_list;
        self::getMutex();
        $ret = shm_put_var(self::getShmFd(), self::SHM_BAD_ADDRESS_KEY, $bad_address_list);
        self::releaseMutex();
        return $ret;
    }
    
    /**
     * 恢复故障节点（实际上就是从故障节点列表中删除该节点）
     * @param string $address
     * @return bool
     */
    public static function recoverAddress($address)
    {
        if(!self::$shmEnable)
        {
            return false;
        }
        $bad_address_list = self::getBadAddressList(false);
        $bad_address_list_flip = is_array($bad_address_list) ? array_flip($bad_address_list) : array();
        
        if(!isset($bad_address_list_flip[$address]))
        {
            return true;
        }
        unset($bad_address_list_flip[$address]);
        $bad_address_list = array_keys($bad_address_list_flip);
        self::$badAddressList = $bad_address_list;
        self::getMutex();
        $ret = shm_put_var(self::getShmFd(), self::SHM_BAD_ADDRESS_KEY, $bad_address_list);
        self::releaseMutex();
        return $ret;
    }
    
    /**
     * 获取锁(睡眠锁)
     * @return bool
     */
    public static function getMutex()
    {
        ($fd = self::getSemFd()) && sem_acquire($fd);
        return true;
    }
    
    /**
     * 释放锁
     * @return bool
     */
    public static function releaseMutex()
    {
        ($fd = self::getSemFd()) && sem_release($fd);
        return true;
    }
    
}

// ================ 以下是测试代码 ======================
if(PHP_SAPI == 'cli' && isset($argv[0]) && $argv[0] == basename(__FILE__))
{
    AddressManager::config(array(  
                               'HelloWorld' => array(
                                       '127.0.0.1:9090',
                                       '127.0.0.2:9090',
                                       '127.0.0.3:9090',
                               ),
                               'HelloWorldService' => array(
                                       '127.0.0.4:9090'
                               ),
                         ));
    echo "\n剔除address 127.0.0.1:9090 127.0.0.2:9090，放入故障address列表\n";
    AddressManager::kickAddress('127.0.0.1:9090');
    AddressManager::kickAddress('127.0.0.2:9090');
    echo "\n打印故障address列表\n";
    var_export(AddressManager::getBadAddressList());
    echo "\n获取HelloWorld服务的一个可用address\n";
    var_export(AddressManager::getOneAddress('HelloWorld'));
    echo "\n恢复address 127.0.0.2:9090\n";
    var_export(AddressManager::recoverAddress('127.0.0.2:9090'));
    echo "\n打印故障address列表\n";
    var_export(AddressManager::getBadAddressList());
    echo "\n配置有更改，md5会改变，则故障address列表自动清空\n";
    AddressManager::config(array(
                        'HelloWorld' => array(
                               '127.0.0.2:9090',
                               '127.0.0.3:9090',
                        ),
                    ));
    echo "\n打印故障address列表\n";
    var_export(AddressManager::getBadAddressList());
}
