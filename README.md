workerman
=========

workerman 是一个高性能的PHP socket服务框架，开发者可以在这个框架下开发各种网络应用,例如Rpc服务、聊天室、游戏等。
workerman 具有以下特性
 * 多进程
 * 支持TCP/UDP
 * 支持各种应用层协议
 * 使用libevent事件轮询库，支持高并发
 * 支持文件更新检测及自动加载
 * 支持服务平滑重启
 * 支持长连接
 * 支持以指定用户运行worker进程

[更多请访问www.workerman.net](http://www.workerman.net/workerman-thrift)

所需环境
========

workerman需要PHP版本不低于5.3，只需要安装PHP的Cli即可，无需安装PHP-FPM、nginx、apache
workerman不能运行在Window平台

安装
=========
1、下载 或者 git clone ```https://github.com/walkor/workerman-thrift```

2、运行 ```composer install```


启动停止
=========

以ubuntu为例

启动  
`php start.php start -d`

重启启动  
`php start.php restart`

平滑重启/重新加载配置  
`php start.php reload`

查看服务状态  
`php start.php status`

停止  
`php start.php stop`

在WorkerMan上使用Thrift
============

安装Thrift
----------

###以ubuntu安装Thrift为例

 * 首先安装Thrift依赖的扩展包  
`sudo apt-get install libboost-dev automake libtool flex bison pkg-config g++`  
 * 下载Thrift  
`http://thrift.apache.org/download/`  
 * 解压缩后安装  
`sudo ./configure && sudo make && sudo make install`  

使用Thrift
----------

###定义一个Thrift的IDL文件 HelloWorld.thrift  

    namespace php Services.HelloWorld
    service HelloWorld
    {
        string sayHello(1:string name);
    }  
    
注意:命名空间统一使用 `Services.服务名`

###编译接口文件
`thrift -gen php:server HelloWorld.thrift`   

###拷贝编译好的文件到workerman下的目录
`cp ./gen-php/Services/HelloWorld /yourdir/workerman/applications/ThriftRpc/Services/ -r`

###编写handler文件
在Applications/ThriftRpc/Services/HelloWorld/目录下创建HelloWorldHandler.php如下

```php
<?php
// 统一使用Services\服务名 做为命名空间
namespace Services\HelloWorld;

class HelloWorldHandler implements HelloWorldIf {
  public function sayHello($name)
  {
      return "Hello $name";
  }
}
```

#### 初始化
在Applications/ThriftRpc/start.php 中初始化服务，包括进端口和程数
```php
require_once __DIR__ . '/ThriftWorker.php';

// helloworld
$hello_worker = new ThriftWorker('tcp://0.0.0.0:9090');
$hello_worker->count = 16;
$hello_worker->class = 'HelloWorld';

// another worker
//$another_worker = new ThriftWorker('tcp://0.0.0.0:9091');
//$another_worker->count = 16;
//$another_worker->class = 'AnotherClass';

```

#### 运行
进入workerman根目录运行
```shell
php start.php start -d
```



workerman-Thrift客户端的使用
==========

workerman-Thrift客户端支持的特性
---------------------

###支持故障节点自动踢出及故障节点恢复检测
 * 当某个节点无法访问的时候，客户端会自动将该节点踢掉
 * 有一定的几率(默认5/10000，保证在有故障节点时业务成功率在99.95%以上)重新访问故障节点，用来探测故障节点是否已经存活
 * 故障节点自动踢出及节点恢复检测功能需要客户端PHP支持sysvshm

###支持异步调用
 * workerman-Thrift客户端支持请求发送与接收返回分离
 * 业务可以通过一个客户端实例发送多个请求出去，但是不必立刻接收服务端回应
 * 待业务需要对应的服务端回应时再接收数据，使用服务器回应的数据
 * 异步调用实现了并行计算,可以大大的减少客户端业务的等待时间，极大的提高用户体验
  
workerman-Thrift客户端使用示例
----------------------------

```php
<?php
    
    // 引入客户端文件
    require_once 'yourdir/workerman/applications/ThriftRpc/Clients/ThriftClient.php';
    use ThriftClient\ThriftClient;
    
    // 传入配置，一般在某统一入口文件中调用一次该配置接口即可
    ThriftClient::config(array(
                         'HelloWorld' => array(
                           'addresses' => array(
                               '127.0.0.1:9090',
                               '127.0.0.2:9191',
                             ),
                             'thrift_protocol' => 'TBinaryProtocol',//不配置默认是TBinaryProtocol，对应服务端HelloWorld.conf配置中的thrift_protocol
                             'thrift_transport' => 'TBufferedTransport',//不配置默认是TBufferedTransport，对应服务端HelloWorld.conf配置中的thrift_transport
                           ),
                           'UserInfo' => array(
                             'addresses' => array(
                               '127.0.0.1:9393'
                             ),
                           ),
                         )
                       );
    // =========  以上在WEB入口文件中调用一次即可  ===========
    
    
    // =========  以下是开发过程中的调用示例  ==========
    
    // 初始化一个HelloWorld的实例
    $client = ThriftClient::instance('HelloWorld');
    
    // --------同步调用实例----------
    var_export($client->sayHello("TOM"));
    
    // --------异步调用示例-----------
    // 异步调用 之 发送请求给服务端（注意：异步发送请求格式统一为 asend_XXX($arg),既在原有方法名前面增加'asend_'前缀）
    $client->asend_sayHello("JERRY");
    $client->asend_sayHello("KID");
    
    // 这里是其它业务逻辑
    sleep(1);
    
    // 异步调用 之 接收服务端的回应（注意：异步接收请求格式统一为 arecv_XXX($arg),既在原有方法名前面增加'arecv_'前缀）
    var_export($client->arecv_sayHello("KID"));
    var_export($client->arecv_sayHello("JERRY"));

```

workerman-Thrift及客户端目录结构
=============================

###客户端放在了workerman/applications/ThriftRpc/Clients目录下
###在workerman里面客户端和服务端共用了Lib 和 Services 目录，单独使用客户端时，如果客户端没有这两个目录，需要从workerman上将这两个目录拷贝过去

    workerman/applications/ThriftRpc + 
                                  |- Clients +
                                  |          |- ThriftClient.php    // workerman-thrift客户端主文件
                                  |          |- AddressManager.php  // 带故障节点踢出功能的地址管理器
                                  |- Lib +
                                  |      |- Thrift +                // Thrift自带的文件，这里实际上和服务端共用了一套
                                  |                |- ........      
                                  |- Services +                     // 服务相关的文件，这里实际上和服务端共用了一套
                                              |- HelloWorld +
                                              |             |- HelloWorld.php         // thrift生成的文件
                                              |             |- Types.php              // thrift生成的文件
                                              |             |- HelloWorldHandler.php  // 服务端需要写的文件,客户端不需要
                                              |- 其他服务 +
                                              |           |- ...............
                                              .           .
                                              .           .
                                              .           .
                                              
性能测试
===============

###环境
```
系统：Debian GNU/Linux 6.0
cpu ：Intel(R) Xeon(R) CPU E5-2420 0 @ 1.90GHz * 24
内存：64G

WorkerMan：开启24个Worker进程处理业务请求
压测软件：loadrunner
```

###业务逻辑
`HelloWorld sayHello`

###结果
```
吞吐量：平均8200/S
内存占用：24*12M=288M
cpu平均使用率：55%
load：16
流量：15M/S

处理曲线平稳，无内存泄漏，无流量抖动
```
