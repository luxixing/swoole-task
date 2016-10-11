<?php
/**
 * 首次启动swoole-task 服务时初始化应用
 *
 * @param string swooleTaskAppName swoole-task 业务目录名称
 * @param array  conf 覆盖默认配置可传递相关参数
 *
 * @throws Exception
 */
function swTaskInit($appDir)
{
    /**
     * @var array 默认配置
     */
    $conf = [
        'app' => [
            'ns_pre' => 'SwTask',//swoole-task 应用默认 namespace 前缀
            'ns_ctrl' => 'Ctrl',//Ctrl 代码目录,命名空间 SwTask\Ctrl\FooCtrl
            'ns_dao' => 'Dao',//Dao 代码目录,命名空间 SwTask\Dao\FooDao
            'ns_helper' => 'Helper',//Helper 代码目录,命名空间 SwTask\Helper\FooHelper
        ],
        'http_server' => [
            'tz' => 'Asia/Shanghai',//时区设定，数据统计时区非常重要
            'host' => '127.0.0.1',  //默认监听ip
            'port' => '9523',   //默认监听端口
            'app_env' => 'dev', //运行环境 dev|test|prod, 基于运行环境加载不同配置
            'ps_name' => 'swTask',  //默认swoole 进程名称
            'daemonize' => 0,   //是否守护进程 1=>守护进程| 0 => 非守护进程
            'worker_num' => 2,    //worker进程 cpu核数 1-4倍,一般选择和cpu核数一致
            'task_worker_num' => 2,    //task进程,根据实际情况配置
            'task_max_request' => 10000,    //当task进程处理请求超过此值则关闭task进程,保障进程无内存泄露
            'open_tcp_nodelay' => 1,    //关闭Nagle算法,提高HTTP服务器响应速度
        ],
    ];

    /**
     * @var string ctrl 默认模板
     */
    $ctrlTpl = <<<PHP
<?php
/**
 * swoole-task ctrl文件模板,可参考此文件扩充自己的实际功能
 */
namespace {$conf['app']['ns_pre']}\\{$conf['app']['ns_ctrl']};

use Ping\SwooleTask\Base\Ctrl as BaseCtrl;

class TplCtrl extends BaseCtrl
{
    /**
     * @var Dao \$testDao  连接数据库操作的dao实例,调用ctrl里面的getDao 方法获取单例实体
     */
    //private \$testDao;
    public function init()
    {
        //\$this->testDao  = \$this->getDao('TestDao');
    }
    public function helloAction()
    {
        echo 'hello world' . PHP_EOL;
        var_dump(\$this->params);
    }
}
PHP;
    /**
     * @var string dao 默认模板
     */
    $daoTpl = <<<PHP
<?php
/**
 * swoole-task dao文件模板,可参考此文件扩充自己的实际功能
 */
namespace {$conf['app']['ns_pre']}\\{$conf['app']['ns_dao']};

use Ping\SwooleTask\Base\Dao as BaseDao;

class TplDao extends BaseDao
{
    /**
     * @see Ping\SwooleTask\Base\Dao
     */
    //使用Ping\SwooleTask\Base\Dao 提供的数据库操作接口进行数据查询处理操作
    
}
PHP;

    //创建swoole-task 实际业务所需的目录
    try {
        if (!mkdir($appDir)) {
            throw  new Exception("创建swoole-task 应用目录失败,请检查是否权限不足:" . $appDir);
        }
        //ctrl目录创建
        mkdir($appDir . DS . $conf['app']['ns_ctrl']);
        //dao 目录创建
        mkdir($appDir . DS . $conf['app']['ns_dao']);
        //helper 目录创建
        mkdir($appDir . DS . $conf['app']['ns_helper']);
        //conf 目录创建
        mkdir($appDir . DS . SW_APP_CONF);
        //根据http_server的app_env配置项的值，根据环境加载配置内容，支持dev,test,prod三个值,如有自定义值，自己创建目录
        foreach (['dev', 'test', 'prod'] as $v) {
            mkdir($appDir . DS . SW_APP_CONF . DS . $v);
        }
        //runtime 目录创建
        $runtimePath = $appDir . DS . SW_APP_RUNTIME;
        mkdir($runtimePath, 0777);
        //runtime-log 目录创建
        mkdir($runtimePath . DS . 'log', 0777);
        //模板文件写入目录
        $ctrlFile = $appDir . DS . $conf['app']['ns_ctrl'] . DS . 'TplCtrl.php';
        if (!file_put_contents($ctrlFile, $ctrlTpl)) {
            throw  new Exception("创建swoole-task Ctrl 模板文件失败,请检查是否权限不足:" . $ctrlFile);
        }
        $daoFile = $appDir . DS . $conf['app']['ns_dao'] . DS . 'TplDao.php';
        if (!file_put_contents($daoFile, $daoTpl)) {
            throw  new Exception("创建swoole-task Dao 模板文件失败,请检查是否权限不足:" . $daoFile);
        }
        //配置文件初始化写入
        $httpServerConf = $appDir . DS . SW_APP_CONF . DS . 'http_server.php';
        if (!file_put_contents($httpServerConf,
            "<?php\nreturn \$http_server = " . var_export($conf['http_server'], 1) . ';')
        ) {
            throw  new Exception("创建swoole-task 配置文件 http_server 失败,请检查是否权限不足:" . $httpServerConf);
        }
        $appConf = $appDir . DS . SW_APP_CONF . DS . 'app.php';
        if (!file_put_contents($appConf, "<?php\nreturn \$app = " . var_export($conf['app'], 1) . ';')) {
            throw  new Exception("创建swoole-task 委派文件 app 失败,请检查是否权限不足:" . $appConf);
        }
    } catch (Exception $e) {
        throw $e;
    }
}

function swTaskPort($port)
{
    $ret = [];
    $cmd = "lsof -i :{$port}|awk '$1 != \"COMMAND\"  {print $1, $2, $9}'";
    exec($cmd, $out);
    if ($out) {
        foreach ($out as $v) {
            $a = explode(' ', $v);
            list($ip, $p) = explode(':', $a[2]);
            $ret[$a[1]] = [
                'cmd' => $a[0],
                'ip' => $ip,
                'port' => $p,
            ];
        }
    }

    return $ret;
}

function swTaskStart($conf)
{
    echo "正在启动 swoole-task 服务" . PHP_EOL;
    if (!is_writable(dirname($conf['pid_file']))) {
        exit("swoole-task-pid文件需要目录的写入权限:" . dirname($conf['pid_file']) . PHP_EOL);
    }

    if (file_exists($conf['pid_file'])) {
        $pid = explode("\n", file_get_contents($conf['pid_file']));
        $cmd = "ps ax | awk '{ print $1 }' | grep -e \"^{$pid[0]}$\"";
        exec($cmd, $out);
        if (!empty($out)) {
            exit("swoole-task pid文件 " . $conf['pid_file'] . " 存在，swoole-task 服务器已经启动，进程pid为:{$pid[0]}" . PHP_EOL);
        } else {
            echo "警告:swoole-task pid文件 " . $conf['pid_file'] . " 存在，可能swoole-task服务上次异常退出(非守护模式ctrl+c终止造成是最大可能)" . PHP_EOL;
            unlink($conf['pid_file']);
        }
    }
    $bind = swTaskPort($conf['port']);
    if ($bind) {
        foreach ($bind as $k => $v) {
            if ($v['ip'] == '*' || $v['ip'] == $conf['host']) {
                exit("端口已经被占用 {$conf['host']}:{$conf['port']}, 占用端口进程ID {$k}" . PHP_EOL);
            }
        }
    }
    date_default_timezone_set($conf['tz']);
    $server = new Ping\SwooleTask\HttpServer($conf);
    $server->run();
    //确保服务器启动后swoole-task-pid文件必须生成
    /*if (!empty(portBind($port)) && !file_exists(SWOOLE_TASK_PID_PATH)) {
        exit("swoole-task pid文件生成失败( " . SWOOLE_TASK_PID_PATH . ") ,请手动关闭当前启动的swoole-task服务检查原因" . PHP_EOL);
    }*/
    exit("启动 swoole-task 服务成功" . PHP_EOL);
}

function swTaskStop($conf, $isRestart = false)
{
    echo "正在停止 swoole-task 服务" . PHP_EOL;
    if (!file_exists($conf['pid_file'])) {
        exit('swoole-task-pid文件:' . $conf['pid_file'] . '不存在' . PHP_EOL);
    }
    $pid = explode("\n", file_get_contents($conf['pid_file']));
    $bind = swTaskPort($conf['port']);
    if (empty($bind) || !isset($bind[$pid[0]])) {
        exit("指定端口占用进程不存在 port:{$conf['port']}, pid:{$pid[0]}" . PHP_EOL);
    }
    $cmd = "kill {$pid[0]}";
    exec($cmd);
    do {
        $out = [];
        $c = "ps ax | awk '{ print $1 }' | grep -e \"^{$pid[0]}$\"";
        exec($c, $out);
        if (empty($out)) {
            break;
        }
    } while (true);
    //确保停止服务后swoole-task-pid文件被删除
    if (file_exists($conf['pid_file'])) {
        unlink($conf['pid_file']);
    }
    $msg = "执行命令 {$cmd} 成功，端口 {$conf['host']}:{$conf['port']} 进程结束" . PHP_EOL;
    if ($isRestart) {
        echo $msg;
    } else {
        exit($msg);
    }
}

function swTaskStatus($conf)
{
    echo "swoole-task {$conf['host']}:{$conf['port']} 运行状态" . PHP_EOL;
    $cmd = "curl -s '{$conf['host']}:{$conf['port']}?cmd=status'";
    exec($cmd, $out);
    if (empty($out)) {
        exit("{$conf['host']}:{$conf['port']} swoole-task服务不存在或者已经停止" . PHP_EOL);
    }
    foreach ($out as $v) {
        $a = json_decode($v);
        foreach ($a as $k1 => $v1) {
            echo "$k1:\t$v1" . PHP_EOL;
        }
    }
    exit();
}

//WARN macOS 下因为不支持进程修改名称，此方法使用有问题
function swTaskList($conf)
{
    echo "本机运行的swoole-task服务进程" . PHP_EOL;
    $cmd = "ps aux|grep " . $conf['ps_name'] . "|grep -v grep|awk '{print $1, $2, $6, $8, $9, $11}'";
    exec($cmd, $out);
    if (empty($out)) {
        exit("没有发现正在运行的swoole-task服务" . PHP_EOL);
    }
    echo "USER PID RSS(kb) STAT START COMMAND" . PHP_EOL;
    foreach ($out as $v) {
        echo $v . PHP_EOL;
    }
    exit();
}
