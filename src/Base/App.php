<?php
/**
 * 应用入口App,框架的唯一入口
 */

namespace Ping\SwooleTask\Base;


class App
{
    /**
     * 当前请求协议 key="fromId#taskId" value="ctrl.action"
     *
     * @var array
     */
    private $_op = [];

    /**
     * 当前请求ctrl，key="fromId#taskId" value=CtrlClass(包括namespace)
     *
     * @var array
     */

    private $_ctrl = [];

    /**
     * 当前请求action, key="fromId#taskId" value=actionName
     *
     * @var array
     */
    private $_action = [];

    /**
     *  当前请求 get+post数据 key="fromId#taskId"
     *
     * @var array
     */
    private $_request = [];
    /**
     * 当前请求是否debug模式
     *
     * @var array
     */
    private $_debug = [];


    /**
     * 返回App的单例实例,swoole-httpServer启动加载在内存不再改变
     *
     * @var null
     */
    private static $_app = null;


    /**
     * 读取Conf/http_server中的env配置的值
     * 当前环境 dev,test,product
     *
     * @var string
     */
    public static $env;

    /**
     * swoole-http-server
     *
     * @var  object
     */
    public static $server;

    /**
     * 常驻内存
     * app 默认配置&加载config目录配置
     *
     * @var array
     */
    public static $conf = [
        //config 目录配置加载(Conf/env/*.php)
        'conf' => [],
        //公共默认配置 Conf/app.php
        'app' => [],
    ];

    //单例需要
    private function __construct()
    {
    }

    //单例需要
    private function __clone()
    {
    }

    /**
     * TODO 继续完善优化规则
     * 简单路由规则实现
     *
     * @param $request
     * @param $id
     *
     * @return mixed 错误返回代码 成功返回op
     */
    private function route($request, $id)
    {
        $this->_request[$id] = [];
        if (!empty($request->get)) {
            $this->_request[$id] = array_merge($this->_request[$id], $request->get);
        }
        if (!empty($request->post)) {
            $this->_request[$id] = array_merge($this->_request[$id], $request->post);
        }
        $route = ['index', 'index'];
        if (!empty($request->server['path_info'])) {
            $route = explode('/', trim($request->server['path_info'], '/'));
        }
        if (!empty($this->_request[$id]['op'])) {
            //请求显式的指定路由:op=ctrl.action
            $route = explode('.', $this->_request[$id]['op']);
        }
        if (count($route) < 2) {
            return 1;
        }
        $this->_op[$id] = implode('.', $route);
        $ctrl = '\\' . self::$conf['app']['ns_pre'] . '\\' . self::$conf['app']['ns_ctrl'] . '\\' . ucfirst($route[0]) . 'Ctrl';
        $action = lcfirst($route[1]) . 'Action';
        if (!class_exists($ctrl) || !method_exists($ctrl, $action)) {
            return 2;
        }
        $this->_ctrl[$id] = $ctrl;
        $this->_action[$id] = $action;

        //设置请求调试模式
        $debug = false;
        if (!empty($this->_request[$id]['debug'])) {
            //请求中携带 debug 标识，优先级最高
            $debug = $this->_request[$id]['debug'];
        }
        $debug = ($debug === true || $debug === 'yes' || $debug === 'true' || $debug === 1 || $debug === '1') ? true : false;
        $this->_debug[$id] = $debug;

        return $this->_op[$id];
    }

    /**
     * app conf 获取 首先加载内置配置
     *
     * @param string $key
     * $param mixed  $default
     *
     * @return array
     */
    public static function getConfig($key = '', $default = '')
    {
        if ($key === '') {
            return self::$conf;
        }
        $value = [];
        $keyList = explode('.', $key);
        $firstKey = array_shift($keyList);
        if (isset(self::$conf[$firstKey])) {
            $value = self::$conf[$firstKey];
        } else {
            if (!isset(self::$conf['conf'][$firstKey])) {
                return $value;
            }
            $value = self::$conf['conf'][$firstKey];
        }
        //递归深度最大5层
        $i = 0;
        do {
            if ($i > 5) {
                break;
            }
            $k = array_shift($keyList);
            if (!isset($value[$k])) {
                $value = empty($default) ? [] : $default;

                return $value;
            }
            $value = $value[$k];
            $i++;
        } while ($keyList);

        return $value;
    }

    /**
     * 获取一个app的实例
     *
     * @param $server
     *
     * @return App|null
     */
    public static function getApp($server = null)
    {
        if (self::$_app) {
            return self::$_app;
        }
        //swoole-httpServer
        self::$server = $server;
        //swoole-app运行环境 dev|test|prod
        self::$env = self::$server->setting['app_env'];

        /**
         * 配置加载
         */
        //默认app配置加载
        $configDir = self::$server->setting['app_dir'] . DS . SW_APP_CONF;
        self::$conf['app'] = include $configDir . DS . 'app.php';
        self::$conf['app']['conf'] = SW_APP_CONF;
        self::$conf['app']['runtime'] = SW_APP_RUNTIME;
        //根据环境加载不同的配置
        $confFiles = [];
        if (file_exists($configDir . DS . self::$env)) {
            $confFiles = Helper::getFiles($configDir);
        }
        foreach ($confFiles as $inc) {
            $file = pathinfo($inc);
            self::$conf['conf'][$file['filename']] = include $inc;
        }

        //vendor目录加载，支持composer
        $vendorFile = self::$server->setting['app_dir'] . DS . 'vendor' . DS . 'autoload.php';
        if (file_exists($vendorFile)) {
            include $vendorFile;
        }

        //自动加载机制实现，遵循psr4
        spl_autoload_register(function ($className) {
            $path = array_filter(explode('\\', $className));
            $className = array_pop($path);
            $realPath = str_replace(self::$conf['app']['ns_pre'], '', implode(DS, $path));
            include self::$server->setting['app_dir'] . $realPath . DS . $className . '.php';

            return true;
        });

        self::$_app = new self();

        return self::$_app;
    }

    /**
     * 获取当前请求调试模式的值
     *
     * @param $id
     *
     * @return bool
     */
    public function getDebug($id)
    {
        return $this->_debug[$id];
    }


    public function logger($msg, $type = null)
    {
        if (empty($msg)) {
            return false;
        }
        //参数处理
        $type = $type ? $type : 'debug';
        if (!is_string($msg)) {
            $msg = var_export($msg, true);
        }
        $msg = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;

        $maxSize = 2097152;//2M
        list($y, $m, $d) = explode('-', date('Y-m-d'));
        $dir = self::$server->setting['app_dir'] . DS . self::$conf['app']['runtime'] . DS . 'log' . DS . $y . $m;
        $file = "{$dir}/{$type}-{$d}.log";
        if (!file_exists($dir)) {
            mkdir($dir, 0777);
        }
        if (file_exists($file) && filesize($file) >= $maxSize) {
            $a = pathinfo($file);
            $bak = $a['dirname'] . DIRECTORY_SEPARATOR . $a['filename'] . '-bak.' . $a['extension'];
            if (!rename($file, $bak)) {
                echo "rename file:{$file} to {$bak} failed";
            }
        }
        error_log($msg, 3, $file);
    }

    public function debug($msg)
    {
        $this->logger($msg, 'debug');
    }

    public function error($msg)
    {
        $this->logger($msg, 'error');
    }

    public function warn($msg)
    {
        $this->logger($msg, 'warn');
    }

    public function info($msg)
    {
        $this->logger($msg, 'info');
    }

    public function sql($msg)
    {
        $this->logger($msg, 'sql');
    }

    public function op($msg)
    {
        $this->logger($msg, 'op');
    }

    /**
     * 执行一个请求
     *
     * @param $request
     * @param $taskId
     * @param $fromId
     *
     * @return mixed
     */
    public function run($request, $taskId, $fromId)
    {
        //id由 workid#taskId组成，能唯一标识一个请求的来源
        $id = "{$fromId}#{$taskId}";
        //请求运行开始时间
        $runStart = time();
        //请求运行开始内存
        $mem = memory_get_usage();

        //TODO  before route
        $op = $this->route($request, $id);
        if (is_int($op)) {
            if ($op == 1) {
                $error = '缺少路由';
                $this->error($error);

                return false;
            }
            if ($op == 2) {
                $error = "路由解析失败:{$this->_op[$id]}";
                $this->error($error);

                return false;
            }
        }

        //TODO after route
        try {
            $ctrl = new $this->_ctrl[$id]($this->_request[$id], $this->_op[$id], $id);

            //before action:比如一些ctrl的默认初始化动作,加载dao等
            if (method_exists($ctrl, 'init')) {
                //执行action之前进行init
                $ctrl->init();
            }
            $res = $ctrl->{$this->_action[$id]}();
            //FIXME $res 返回如果不是数组会报错
            //after action
            if (method_exists($ctrl, 'done')) {
                //执行完action之后要做的事情
                $ctrl->done();
            }

            //请求运行时间和内存记录
            $runSpend = time() - $runStart;//请求花费时间
            $info = "op:{$op}, spend: {$runSpend}s, memory:" . Helper::convertSize(memory_get_usage() - $mem) . ", peak memory:" . Helper::convertSize(memory_get_peak_usage());
            $info .= ",date:{$ctrl->date}";
            $this->op($info);


            return $res;
        } catch (\Exception $e) {
            $this->error($e->getMessage() . PHP_EOL . $e->getTraceAsString());
        } finally {
            //WARN 请求结束后必须释放相关的数据,避免内存使用的无限增长
            unset($this->_op[$id], $this->_ctrl[$id], $this->_action[$id], $this->_request[$id], $this->_debug[$id]);
        }
    }
}
