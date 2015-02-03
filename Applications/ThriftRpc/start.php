<?php
require_once __DIR__ . '/ThriftWorker.php';


$worker = new ThriftWorker('tcp://0.0.0.0:9090');
$worker->count = 16;
$worker->class = 'HelloWorld';



