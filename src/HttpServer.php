<?php
/**
 *  基于swoole扩展的http-server封装的一个接受异步任务请求的http服务器
 */
namespace Ping\SwooleTask;

use Ping\SwooleTask\Base\App as BaseApp;

class HttpServer
{
    /**
     * swoole http-server 实例
     *
     * @var null | swoole_http_server
     */
    private $server = null;
    /**
     * 应用实例
     *
     * @var null | BaseApp
     */
    private $app = null;
    /**
     * swoole 配置
     *
     * @var array
     */
    private $setting = [];

    /**
     * 加载框架[Base/*.php]
     */
    private function loadFramework()
    {
        //TODO 未来可扩展内容继续完善
        //框架核心文件
        $coreFiles = [
            'Base' . DS . 'App.php',
            'Base' . DS . 'Ctrl.php',
            'Base' . DS . 'Dao.php',
            'Base' . DS . 'Helper.php',
        ];
        foreach ($coreFiles as $v) {
            include SW_SRC_ROOT . DS . 'src' . DS . $v;
        }
    }

    /**
     * 修改swooleTask进程名称，如果是macOS 系统，则忽略(macOS不支持修改进程名称)
     *
     * @param $name 进程名称
     *
     * @return bool
     * @throws \Exception
     */
    private function setProcessName($name)
    {
        if (PHP_OS == 'Darwin') {
            return false;
        }
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title($name);
        } else {
            if (function_exists('swoole_set_process_name')) {
                swoole_set_process_name($name);
            } else {
                throw new \Exception(__METHOD__ . "failed,require cli_set_process_title|swoole_set_process_name");
            }
        }
    }

    public function __construct($conf)
    {
        //TODO conf配置检查
        $this->setting = $conf;
    }

    public function getSetting()
    {
        return $this->setting;
    }

    public function run()
    {
        $this->server = new \swoole_http_server($this->setting['host'], $this->setting['port']);

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
        $this->app = BaseApp::getApp($this->server);
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
        $this->setProcessName($server->setting['ps_name'] . '-master');
        //记录进程id,脚本实现自动重启
        $pid = "{$this->server->master_pid}\n{$this->server->manager_pid}";
        file_put_contents($this->setting['pid_file'], $pid);
    }

    /**
     * manager worker start
     *
     * @param $server
     */
    public function onManagerStart($server)
    {
        echo 'Date:' . date('Y-m-d H:i:s') . "\t swoole_http_server manager worker start\n";
        $this->setProcessName($server->setting['ps_name'] . '-manager');
    }

    /**
     * swoole-server master shutdown
     */
    public function onShutdown()
    {
        unlink($this->setting['pid_file']);
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
            $this->setProcessName($server->setting['ps_name'] . '-task');
        } else {
            $this->setProcessName($server->setting['ps_name'] . '-work');
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
        echo 'Date:' . date('Y-m-d H:i:s') . "\t swoole_http_server[{$server->setting['ps_name']}] worker:{$workerId} shutdown\n";
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
            $res = $this->server->stats();
            $res['start_time'] = date('Y-m-d H:i:s', $res['start_time']);
            $response->end(json_encode($res));

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
        if (!empty($ret['errno'])) {
            //任务成功运行不再提示
            //echo "\tTask[taskId:{$taskId}] success" . PHP_EOL;
            $error = PHP_EOL . var_export($ret, true);
            echo "\tTask[taskId:$fromId#{$taskId}] failed, Error[$error]" . PHP_EOL;
        }
    }
}
