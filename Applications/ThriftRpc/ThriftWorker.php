<?php
use Workerman\Worker;
use \Thrift\Transport\TBufferedTransport;
use \Thrift\Transport\TFramedTransport;
use \Thrift\Protocol\TBinaryProtocol;
use \Thrift\Protocol\TCompactProtocol;
use \Thrift\Protocol\TJSONProtocol;


define('THRIFT_ROOT', __DIR__);
require_once THRIFT_ROOT . '/Lib/Thrift/ClassLoader/ThriftClassLoader.php';
require_once THRIFT_ROOT . '/Lib/Statistics/StatisticClient.php';

use Thrift\ClassLoader\ThriftClassLoader;

$loader = new ThriftClassLoader();
$loader->registerNamespace('Thrift', THRIFT_ROOT.'/Lib');
$loader->registerNamespace('Service', THRIFT_ROOT);
$loader->register();

/**
 * 
 *  ThriftWorker
 * 
 * @author walkor <worker-man@qq.com>
 */
class ThriftWorker extends Worker 
{
    /**
     * Thrift processor
     * @var object 
     */
    protected $processor = null;
    
    /**
     * 使用的协议,默认TBinaryProtocol,可更改
     * @var string
     */
    public $thriftProtocol = 'TBinaryProtocol';
    
    /**
     * 使用的传输类,默认是TBufferedTransport，可更改
     * @var string
     */
    public $thriftTransport = 'TBufferedTransport';

    /**
     * 设置类名称
     * @var string
     */ 
    public $class = '';
    
    /**
     * 统计数据上报的地址
     * @var string
     */
    public $statisticAddress = 'udp://127.0.0.1:55656';
    
    /**
     * construct
     */
    public function __construct($socket_name)
    {
        parent::__construct($socket_name);
        $this->onWorkerStart = array($this, 'onStart');
        $this->onConnect = array($this, 'onConnect');
    }
    
    /**
     * 进程启动时做的一些初始化工作
     * @see Man\Core.SocketWorker::onStart()
     * @return void
     */
    public function onStart()
    {
        // 检查类是否设置
        if(!$this->class)
        {
            throw new \Exception('ThriftWorker->class not set');
        }

        // 设置name
        if($this->name == 'none')
        {
            $this->name = $this->class;
        }

        // 载入该服务下的所有文件
        foreach(glob(THRIFT_ROOT . '/Services/'.$this->class.'/*.php') as $php_file)
        {
            require_once $php_file;
        }
        
        // 检查类是否存在
        $processor_class_name = "\\Services\\".$this->class."\\".$this->class.'Processor';
        if(!class_exists($processor_class_name))
        {
            slef::log("Class $processor_class_name not found" );
            return;
        }
        
        // 检查类是否存在
        $handler_class_name ="\\Services\\".$this->class."\\".$this->class.'Handler';
        if(!class_exists($handler_class_name))
        {
            self::log("Class $handler_class_name not found" );
            return;
        }
       
        $handler = new $handler_class_name();
        $this->processor = new $processor_class_name($handler);
    }
    
    /**
     * 处理受到的数据
     * @param TcpConnection $connection
     * @return void
     */
    public function onConnect($connection)
    {
        $socket = $connection->getSocket();
        $t_socket = new Thrift\Transport\TSocket();
        $t_socket->setHandle($socket);
        $transport_name = '\\Thrift\\Transport\\'.$this->thriftTransport;
        $transport = new $transport_name($t_socket);
        $protocol_name = '\\Thrift\\Protocol\\' . $this->thriftProtocol;
        $protocol = new $protocol_name($transport);
        
        // 执行处理
        try{
            // 先初始化一个
            $protocol->fname == 'none';
            // 统计开始时间
            \Thrift\Statistics\StatisticClient::tick();
            // 业务处理
            $this->processor->process($protocol, $protocol);
            \Thrift\Statistics\StatisticClient::report($this->name, $protocol->fname, 1, 0, '', $this->statisticAddress);
        }
        catch(\Exception $e)
        {
            \Thrift\Statistics\StatisticClient::report($this->name, $protocol->fname, 0, $e->getCode(), $e, $this->statisticAddress);
            self::log('CODE:' . $e->getCode() . ' MESSAGE:' . $e->getMessage()."\n".$e->getTraceAsString()."\nCLIENT_IP:".$connection->getRemoteIp()."\n");
            $connection->send($e->getMessage());
        }
        
    }
    
}
