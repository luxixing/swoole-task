<?php
/**
 *  基于swoole扩展的http-server封装的一个接受异步任务请求的http服务器
 */
namespace ping\swooleTask;

class HttpServer
{
    /**
     * swoole http-server 实例
     *
     * @var null|swoole_http_server
     */
    private $server = null;
    /**
     * 应用实例
     *
     * @var null|base\App
     */
    private $app = null;
    /**
     * swoole 配置
     *
     * @var array
     */
    private $setting = [];


    /**
     * 加载框架[base/*.php]
     */
    private function loadFramework()
    {
        //框架核心文件
        $coreFiles = [
            'base' . DS . 'App.php',
            'base' . DS . 'Ctrl.php',
            'base' . DS . 'Dao.php',
            'base' . DS . 'Helper.php',
        ];
        foreach ($coreFiles as $v) {
            include SWOOLE_PATH . DS . $v;
        }
    }

    /**
     * 设置swoole进程名称
     *
     * @param string $name swoole进程名称
     */
    private function setProcessName($name)
    {
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title($name);
        } else {
            if (function_exists('swoole_set_process_name')) {
                swoole_set_process_name($name);
            } else {
                trigger_error(__METHOD__ . " failed. require cli_set_process_title or swoole_set_process_name.");
            }
        }
    }

    /**
     * 捏造一个请求 swoole_http_request请求
     *
     * @param $data
     *
     * @return object
     */
    private function genFinishReq($data)
    {
        if (empty($data['op'])) {
            $data['op'] = 'test.finish';
        }
        $data['isFinish'] = true;
        $req = new swoole_http_request();
        $req->header = [
            'host' => '',
            'accept' => '',
        ];
        $req->server = [

        ];
        $req->fd = 0;
        $req->get = [];
        $req->post = $data;

        return $req;
    }


    public function __construct($host = '0.0.0.0', $port = 9510)
    {
        defined('SWOOLE_PATH') || define('SWOOLE_PATH', __DIR__);
        defined('DS') || define('DS', DIRECTORY_SEPARATOR);

        $configPath = SWOOLE_PATH . DS . 'config';
        $configFile = $configPath . DS . 'swoole.ini';
        $tmpPath = SWOOLE_PATH . DS . 'tmp';
        //首次启动初始化默认配置
        if (!file_exists($configPath)) {
            mkdir($configPath);
            $setting = [
                'host' => $host,    //监听ip
                'port' => $port,    //监听端口
                'env' => 'dev',    //环境 dev|test|prod
                'process_name' => SWOOLE_TASK_NAME_PRE, //swoole 进程名称
                'open_tcp_nodelay' => 1,    //关闭Nagle算法,提高HTTP服务器响应速度
                'daemonize' => 0,    //是否守护进程 1=>守护进程| 0 => 非守护进程
                'worker_num' => 4,    //worker进程 cpu 1-4倍
                'task_worker_num' => 4,    //task进程
                'task_max_request' => 10000,    //当task进程处理请求超过此值则关闭task进程
                'root' => 'SWOOLE_PATH"/app"',  //open_basedir 安全措施
                'tmp_dir' => 'SWOOLE_PATH"/tmp"',
                'log_dir' => 'SWOOLE_PATH"/tmp/log"',
                'task_tmpdir' => 'SWOOLE_PATH"/tmp/task"', //task进程临时数据目录
                'log_file' => 'SWOOLE_PATH"/tmp/log/http.log"', //日志文件目录
            ];
            $iniSetting = '[http]' . PHP_EOL;
            foreach ($setting as $k => $v) {
                $iniSetting .= "{$k} = {$v}" . PHP_EOL;
            }
            file_put_contents($configFile, $iniSetting);
        }
        //首次启动创建临时目录
        if (!file_exists($tmpPath)) {
            mkdir($tmpPath, 0777);
            mkdir($tmpPath . DS . 'log', 0777);
            mkdir($tmpPath . DS . 'task', 0777);
        }
        //加载配置文件内容
        if (!file_exists($configFile)) {
            throw new ErrorException("swoole config file:{$configFile} not found");
        }
        //TODO 是否需要检查配置文件内容合法性
        $ini = parse_ini_file($configFile, true);
        $this->setting = $ini['http'];
    }

    public function getSetting()
    {
        return $this->setting;
    }

    public function run($host = '', $port = '', $daemon = -1, $processName = '', $baseDir = '')
    {
        if ($host) {
            $this->setting['host'] = $host;
        }
        if ($port) {
            $this->setting['port'] = $port;
        }
        if ($daemon >= 0) {
            $this->setting['daemonize'] = $daemon;
        }
        if ($processName) {
            $this->setting['process_name'] = SWOOLE_TASK_NAME_PRE . '-' . $processName;
        }
        //设置 basedir
        if ($baseDir && file_exists(SWOOLE_PATH . DS . $baseDir)) {
            $this->setting['root'] = SWOOLE_PATH . DS . $baseDir;
        }

        $this->server = new swoole_http_server($this->setting['host'], $this->setting['port']);

        $this->loadFramework();
        $this->server->set($this->setting);

        //回调函数
        $call = [
            'start',
            'workerStart',
            'managerStart',
            'request',
            'task',
            'finish',
            'workerStop',
            'shutdown',
        ];
        //事件回调函数绑定
        foreach ($call as $v) {
            $m = 'on' . ucfirst($v);
            if (method_exists($this, $m)) {
                $this->server->on($v, [$this, $m]);
            }
        }
        //注入框架 常驻内存
        $this->app = \base\App::getApp($this->server);
        $this->server->start();
    }


    /**
     * swoole-server master start
     *
     * @param $server
     */
    public function onStart($server)
    {
        echo 'Date:' . date('Y-m-d H:i:s') . "\t swoole_http_server master worker start\n";
        $this->setProcessName($server->setting['process_name'] . '-master');
        //记录进程id,脚本实现自动重启
        $pid = "{$this->server->master_pid}\n{$this->server->manager_pid}";
        file_put_contents(SWOOLE_TASK_PID_PATH, $pid);
    }

    /**
     * manager worker start
     *
     * @param $server
     */
    public function onManagerStart($server)
    {
        echo 'Date:' . date('Y-m-d H:i:s') . "\t swoole_http_server manager worker start\n";
        $this->setProcessName($server->setting['process_name'] . '-manager');
    }

    /**
     * swoole-server master shutdown
     */
    public function onShutdown()
    {
        unlink(SWOOLE_TASK_PID_PATH);
        echo 'Date:' . date('Y-m-d H:i:s') . "\t swoole_http_server shutdown\n";
    }

    /**
     * worker start 加载业务脚本常驻内存
     *
     * @param $server
     * @param $workerId
     */
    public function onWorkerStart($server, $workerId)
    {
        if ($workerId >= $this->setting['worker_num']) {
            $this->setProcessName($server->setting['process_name'] . '-task');
        } else {
            $this->setProcessName($server->setting['process_name'] . '-event');
        }
    }

    /**
     * worker 进程停止
     *
     * @param $server
     * @param $workerId
     */
    public function onWorkerStop($server, $workerId)
    {
        echo 'Date:' . date('Y-m-d H:i:s') . "\t swoole_http_server[{$server->setting['process_name']}  worker:{$workerId} shutdown\n";
    }


    /**
     * http请求处理
     *
     * @param $request
     * @param $response
     *
     * @return mixed
     */
    public function onRequest($request, $response)
    {
        //获取swoole服务的当前状态
        if (isset($request->get['cmd']) && $request->get['cmd'] == 'status') {
            $response->end(json_encode($this->server->stats()));

            return true;
        }
        //TODO 非task请求处理
        $this->server->task($request);
        $out = '[' . date('Y-m-d H:i:s') . '] ' . json_encode($request) . PHP_EOL;
        //INFO 立即返回 非阻塞
        $response->end($out);

        return true;
    }

    /**
     * 任务处理
     *
     * @param $server
     * @param $taskId
     * @param $fromId
     * @param $request
     *
     * @return mixed
     */
    public function onTask($server, $taskId, $fromId, $request)
    {
        //任务执行 worker_pid实际上是就是处理任务进程的task进程id
        $ret = $this->app->run($request, $taskId, $fromId);
        if (!isset($ret['workerPid'])) {
            //处理此任务的task-worker-id
            $ret['workerPid'] = $server->worker_pid;
        }

        //INFO swoole-1.7.18之后return 就会自动调用finish
        return $ret;
    }

    /**
     * 任务结束回调函数
     *
     * @param $server
     * @param $taskId
     * @param $ret
     */
    public function onFinish($server, $taskId, $ret)
    {
        $fromId = $server->worker_id;
        //任务结束，如果设置了任务完成回调函数,执行回调任务
        if (!empty($ret['finish']) && !isset($ret['params']['isFinish'])) {
            $data = $ret['data'];
            //请求回调任务的op
            $data['pre_op'] = $ret['op'];
            $data['op'] = $ret['finish'];
            //携带上一次请求执行的完整信息提供给回调函数
            $req = $this->genFinishReq($data);
            $this->server->task($req);
        }
        if (empty($ret['errno'])) {
            //任务成功运行不再提示
            //echo "\tTask[taskId:{$taskId}] success" . PHP_EOL;
        } else {
            $error = PHP_EOL . var_export($ret, true);
            echo "\tTask[taskId:$fromId#{$taskId}] failed, Error[$error]" . PHP_EOL;
        }
    }
}
