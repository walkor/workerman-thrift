<?php

define('THRIFT_CLIENT', realpath(__DIR__ ) );

require_once THRIFT_CLIENT . '/../Lib/Thrift/ClassLoader/ThriftClassLoader.php';
require_once THRIFT_CLIENT . '/AddressManager.php';

$loader = new Thrift\ClassLoader\ThriftClassLoader();
$loader->registerNamespace('Thrift', THRIFT_CLIENT. '/../Lib');
$loader->register();

/**
 * 
 * 通用客户端,支持故障ip自动踢出及探测节点是否已经存活
 * 
 * <b>使用示例:</b>
 * <pre>
 * <code>
 * ThriftClient::config(array(  
 *                         'HelloWorld' => array(
 *                             'addresses' => array(
 *                                   '127.0.0.1:9090',
 *                                   '127.0.0.2:9090',
 *                               ),
 *                               'thrift_protocol' => 'TBinaryProtocol',
 *                               'thrift_transport' => 'TBufferedTransport',
 *                           ),
 *                           'UserInfo' => array(
 *                               'addresses' => array(
 *                                   '127.0.0.1:9090'
 *                               ),
 *                           ),
 *                     )
 * );
 * 
 * // 同步调用
 * $hello_world_client = ThriftClient::instance('HelloWorld');
 * $ret = $hello_world_client->sayHello("TOM"));
 * 
 * // ===以下是异步调用===
 * // 异步调用之发送请求给服务器。提示：在方法前面加上asend_前缀即为异步发送请求
 * $hello_world_client->asend_sayHello("TOM");
 * 
 * .................这里是你的其它业务逻辑...............
 * 
 * // 异步调用之获取服务器返回。提示：在方法前面加上arecv_前缀即为异步接收服务器返回
 * $ret_async = arecv_sayHello("TOM");
 * 
 * <code>
 * </pre>
 * 
 *
 */
class ThriftClient 
{
    /**
     * 客户端实例
     * @var array
     */
    private static $instance = array();
    
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
     * 设置/获取 配置
     *  array(  
     *      'HelloWorld' => array(
     *          'addresses' => array(
     *              '127.0.0.1:9090',
     *              '127.0.0.2:9090',
     *              '127.0.0.3:9090',
     *          ),
     *      ),
     *      'UserInfo' => array(
     *          'addresses' => array(
     *              '127.0.0.1:9090'
     *          ),
     *      ),
     *  )
     * @param array $config
     * @return array
     */
    public static function config(array $config = array())
    {
        if(!empty($config))
        {
            // 赋值
            self::$config = $config;
            
            // 注册address到AddressManager
            $address_map = array();
            foreach(self::$config as $key => $item)
            {
                $address_map[$key] = $item['addresses'];
            }
            ThriftClient\AddressManager::config($address_map);
        }
        return self::$config;
    }
    
    /**
     * 获取实例
     * @param string $serviceName 服务名称
     * @param bool $newOne 是否强制获取一个新的实例
     * @return object/Exception
     */
    public static function instance($serviceName, $newOne = false)
    {
        if (empty($serviceName))
        {
            throw new \Exception('ServiceName can not be empty');
        }
        
        if($newOne)
        {
            unset(self::$instance[$serviceName]);
        }
        
        if(!isset(self::$instance[$serviceName]))
        {
            self::$instance[$serviceName] = new ThriftInstance($serviceName);
        }
        
        return self::$instance[$serviceName];
    }
    
    /**
     * getProtocol
     * @param string $key
     * @return string
     */
    public static function getProtocol($service_name)
    {
        $config = self::config();
        $protocol = 'TBinaryProtocol';
        if(!empty($config[$service_name]['thrift_protocol']))
        {
            $protocol = $config[$service_name]['thrift_protocol'];
        }
        return "Thrift\\Protocol\\".$protocol;
    }
    
    /**
     * getTransport
     * @param string $key
     * @return string
     */
    public static function getTransport($service_name)
    {
        $config = self::config();
        $transport= 'TBufferedTransport';
        if(!empty($config[$service_name]['thrift_transport']))
        {
            $transport = $config[$service_name]['thrift_transport'];
        }
        return "Thrift\\Transport\\".$transport;
    }
}

/**
 * 
 * thrift异步客户端实例
 * @author liangl
 *
 */
class ThriftInstance
{
    /**
     * 异步发送前缀
     * @var string
     */
    const ASYNC_SEND_PREFIX = 'asend_';
    
    /**
     * 异步接收后缀
     * @var string
     */
    const ASYNC_RECV_PREFIX = 'arecv_';
    
    /**
     * 服务名
     * @var string
     */
    public $serviceName = '';
    
    /**
     * thrift实例
     * @var array
     */
    protected $thriftInstance = null;
    
    /**
     * thrift异步实例['asend_method1'=>thriftInstance1, 'asend_method2'=>thriftInstance2, ..]
     * @var array
     */
    protected $thriftAsyncInstances = array();
    
    /**
     * 初始化工作
     * @return void
     */
    public function __construct($serviceName)
    {
        if(empty($serviceName))
        {
            throw new \Exception('serviceName can not be empty', 500);
        }
        $this->serviceName = $serviceName;
    }
    
    /**
     * 方法调用
     * @param string $name
     * @param array $arguments
     * @return mix
     */
    public function __call($method_name, $arguments)
    {
        // 异步发送
        if(0 === strpos($method_name ,self::ASYNC_SEND_PREFIX))
        {
            $real_method_name = substr($method_name, strlen(self::ASYNC_SEND_PREFIX));
            $arguments_key = serialize($arguments);
            $method_name_key = $method_name . $arguments_key;
            // 判断是否已经有这个方法的异步发送请求
            if(isset($this->thriftAsyncInstances[$method_name_key]))
            {
                $this->mnlog($this->serviceName, $method_name, 1, microtime(true)-$time_start, 0, 1500);
                throw new \Exception($this->serviceName."->$method_name(".implode(',',$arguments).") already has been called, you can't call again before you call ".self::ASYNC_RECV_PREFIX.$real_method_name, 500);
            }
           
            // 创建实例发送请求
            $this->thriftAsyncInstances[$method_name_key] = $this->__instance();
            $callback = array($this->thriftAsyncInstances[$method_name_key], 'send_'.$real_method_name);
            if(!is_callable($callback))
            {
                throw new \Exception($this->serviceName.'->'.$method_name. ' not callable', 1400);
            }
            $ret = call_user_func_array($callback, $arguments);
            
            return $ret;
        }
        // 异步接收
        if(0 === strpos($method_name, self::ASYNC_RECV_PREFIX))
        {
            $real_method_name = substr($method_name, strlen(self::ASYNC_RECV_PREFIX));
            $send_method_name = self::ASYNC_SEND_PREFIX.$real_method_name;
            $arguments_key = serialize($arguments);
            $method_name_key = $send_method_name . $arguments_key;
            // 判断是否有发送过这个方法的异步请求
            if(!isset($this->thriftAsyncInstances[$method_name_key]))
            {
                throw new \Exception($this->serviceName."->$send_method_name(".implode(',',$arguments).") have not previously been called", 1500);
            }
            
            $callback = array($this->thriftAsyncInstances[$method_name_key], 'recv_'.$real_method_name);
            if(!is_callable($callback))
            {
                throw new \Exception($this->serviceName.'->'.$method_name. ' not callable', 1400);
            }
            // 接收请求
            $ret = call_user_func_array($callback, array());
                
            // 删除实例
            $this->thriftAsyncInstances[$method_name_key] = null;
            unset($this->thriftAsyncInstances[$method_name_key]);
            return $ret;
        }
        
        $success = true;
        // 同步发送接收
        if(empty($this->thriftInstance))
        {
            $this->thriftInstance = $this->__instance();
        }
        $callback = array($this->thriftInstance, $method_name);
        if(!is_callable($callback))
        {
            throw new \Exception($this->serviceName.'->'.$method_name. ' not callable', 1400);
        }
        $ret = call_user_func_array($callback, $arguments);
        
        return $ret;
    }
    
    
    /**
     * 获取一个实例
     * @return instance
     */
    protected function __instance()
    {
        $address = ThriftClient\AddressManager::getOneAddress($this->serviceName);
        list($ip, $port) = explode(':', $address);
        $socket = new Thrift\Transport\TSocket($ip, $port);
        $transport_name = ThriftClient::getTransport($this->serviceName);
        $transport = new $transport_name($socket);
        $protocol_name = ThriftClient::getProtocol($this->serviceName);
        $protocol = new $protocol_name($transport);
        
        $classname = "Services\\" . $this->serviceName . "\\" . $this->serviceName . "Client";
        try 
        {
            $transport->open();
        }
        catch(\Exception $e)
        {
            ThriftClient::kickAddress($address);
            throw $e;
        }
        
        return new $classname($protocol);
    }
}


if(true)
{
    ThriftClient::config(array(
                         'HelloWorld' => array(
                             'addresses' => array(
                                   '127.0.0.1:9090',
                                   '127.0.0.2:9090',
                               ),
                               'thrift_protocol' => 'TBinaryProtocol',
                               'thrift_transport' => 'TBufferedTransport',
                           ),
                           'UserInfo' => array(
                               'addresses' => array(
                                   '127.0.0.1:9090'
                               ),
                           ),
                     )
             );
    $client = ThriftClient::instance('HelloWorld');
    
    var_export($client->sayHello("TOM"));
}
