#!/bin/env php
<?php
/**
 * 设置错误报告模式
 */
error_reporting(E_ALL);


/**
 * 检查exec 函数是否启用
 */
if (!function_exists('exec')) {
    exit('exec function is disabled' . PHP_EOL);
}
/**
 * 检查命令 lsof 命令是否存在
 */
exec("whereis lsof", $out);
if ($out[0] == 'lsof:') {
    exit('lsof is not found' . PHP_EOL);
}

define('DS', DIRECTORY_SEPARATOR);

/**
 * @var string swoole-task源码根目录
 */
define('SW_SRC_ROOT', realpath(__DIR__ . DS . '..'));

/**
 * @var string swoole-task 业务应用根目录:即 vendor_path的父目录
 */
define("SW_APP_ROOT", realpath(SW_SRC_ROOT . DS . '..' . DS . '..'  . DS . '..'));

define('SW_APP_CONF', 'Conf');//swoole-task业务配置目录,不允许修改
define('SW_APP_RUNTIME', 'Runtime');//swoole-task业务数据访问目录，不允许修改

//加载http-server
include SW_SRC_ROOT . DS . 'src' . DS . 'HttpServer.php';
//加载进程管理函数
include __DIR__ . DS . 'function.php';

/**
 * @var array swoole-http_server支持的进程管理命令
 */
$cmds = [
    'start',
    'stop',
    'restart',
    'status',
    'list',
];
/**
 * @var array 命令行参数，FIXME: getopt 函数的长参数 格式 requried:, optionnal::,novalue 三种格式，可选参数这个有问题
 */
$longopt = [
    'help',//显示帮助文档
    'nodaemon',//以守护进程模式运行,不指定读取配置文件
    'app:',//指定swoole-task业务实际的目录名称(即ctrl,dao,helper的父目录),如果不指定,默认为swoole-task
    'host:',//监听主机ip, 0.0.0.0 表示所有ip
    'port:',//监听端口
];
$opts = getopt('', $longopt);

if (isset($opts['help']) || $argc < 2) {
    echo <<<HELP
用法：php swoole-task.php 选项[help|app|daemon|host|port]  命令[start|stop|restart|status|list]
管理swoole-task服务,确保系统 lsof 命令有效
如果不指定监听host或者port，使用配置参数

参数说明
    --help      显示本帮助说明
    --app       swoole-task业务实际处理目录名称，即ctrl,dao,helper的父目录名称，默认为swoole-task，和vendor_path在同一目录下
    --nodaemon  指定此参数，以非守护进程模式运行,不指定则读取配置文件值
    --host      指定监听ip,例如 php swoole.php -h 127.0.0.1
    --port      指定监听端口port， 例如 php swoole.php --host 127.0.0.1 --port 9520
    
启动swoole-task 如果不指定 host和port，读取http-server中的配置文件
关闭swoole-task 必须指定port,没有指定host，关闭的监听端口是  *:port, 指定了host，关闭 host:port端口
重启swoole-task 必须指定端口
获取swoole-task 状态，必须指定port(不指定host默认127.0.0.1), tasking_num是正在处理的任务数量(0表示没有待处理任务)

HELP;
    exit;
}


/**
 * 参数检查
 */
foreach ($opts as $k => $v) {
    if ($k == "app") {
        if (empty($v)) {
            exit("参数 --app 必须指定值,例如swoole-task,此参数用于指定初始化时swoole-task处理业务的根目录\n");
        }
    }
    if ($k == 'host') {
        if (empty($v)) {
            exit("参数 --host 必须指定值\n");
        }
    }
    if ($k == 'port') {
        if (empty($v)) {
            exit("参数--port 必须指定值\n");
        }
    }
}

//命令检查
$cmd = $argv[$argc - 1];
if (!in_array($cmd, $cmds)) {
    exit("输入命令有误 : {$cmd}, 请查看帮助文档\n");
}

$app = 'sw-app';
if (!empty($opts['app'])) {
    $app = $opts['app'];
}

$appDir = SW_APP_ROOT . DS . $app;
if (!file_exists($appDir)) {
    swTaskInit($appDir);
}
//读取swoole-httpSerer配置
$httpConf = include $appDir . DS . SW_APP_CONF . DS . 'http_server.php';

/**
 * swoole_task业务实际目录
 */
$httpConf['app_dir'] = $appDir;
/**
 * @var string 监听主机ip, 0.0.0.0 表示监听所有本机ip, 如果命令行提供 ip 则覆盖配置项
 */
if (!empty($opts['host'])) {
    if (!filter_var($host, FILTER_VALIDATE_IP)) {
        exit("输入host有误:{$host}");
    }
    $httpConf['host'] = $opts['host'];
}
/**
 * @var int 监听端口
 */
if (!empty($opts['port'])) {
    $port = (int)$opts['port'];
    if ($port <= 0) {
        exit("输入port有误:{$port}");
    }
    $httpConf['port'] = $port;
}
//确定port之后则进程文件确定，可在conf中加入
$httpConf['pid_file'] = $httpConf['app_dir'] . DS . SW_APP_RUNTIME . DS . 'sw-' . $httpConf['port'] . '.pid';
/**
 * @var  bool swoole-httpServer运行模式，参数nodaemon 以非守护进程模式运行，否则以配置文件设置值为默认值
 */
if (isset($opts['nodaemon'])) {
    $httpConf['daemonize'] = 0;
}

//启动
if ($cmd == 'start') {
    swTaskStart($httpConf);
}
//停止
if ($cmd == 'stop') {
    swTaskStop($httpConf);
}
//重启
if ($cmd == 'restart') {
    echo "重启swoole-task服务" . PHP_EOL;
    swTaskStop($httpConf, true);
    swTaskStart($httpConf);
}
//状态
if ($cmd == 'status') {
    swTaskStatus($httpConf);
}
//列表 WARN macOS 下因为进程名称修改问题，此方法使用有问题
if ($cmd == 'list') {
    swTaskList($httpConf);
}



