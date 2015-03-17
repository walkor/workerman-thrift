<?php
use Workerman\Worker;
require_once __DIR__ . '/../../Workerman/Autoloader.php';
require_once __DIR__ . '/ThriftWorker.php';


$worker = new ThriftWorker('tcp://0.0.0.0:9090');
$worker->count = 16;
$worker->class = 'HelloWorld';


// 如果不是在根目录启动，则运行runAll方法
if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}
