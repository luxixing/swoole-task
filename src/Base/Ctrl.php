<?php
namespace Ping\SwooleTask\Base;

/**
 * Class BaseCtrl
 */
class Ctrl
{
    const ERRNO_SUCCESS = 0;//成功
    const ERRNO_PARAM = 1;//参数错误

    const ERROR_SUCCESS = 'OK';
    const ERROR_PARAM = 'Params invalid';


    /**
     * dao 单例
     *
     * @var array
     */
    private $_dao = [

    ];

    /**
     * 返回请求秃顶模式
     *
     * @var array
     */
    protected $ret = [
        //请求协议
        'op' => '',
        //错误代码
        'errno' => self::ERRNO_SUCCESS,
        //错误信息
        'error' => self::ERROR_SUCCESS,
        //任务结束设置的回调函数
        'finish' => '',
        //请求参数
        'params' => [],
        //返回结果 可提供给回调函数使用
        'data' => [],
    ];
    /**
     * 请求唯一标识符 fromId#taskId
     *
     * @var string
     */
    public $id = '';
    /**
     * 当前请求
     *
     * @var string
     */
    public $op = '';
    /**
     * 数据抓取日期
     *
     * @var date
     */
    public $date = '';

    /**
     * 请求参数
     *
     * @var array
     */
    public $params = [];

    public function __construct($params, $op, $id)
    {
        $this->id = $id;
        $this->op = $this->ret['op'] = $op;
        $this->params = $params;
        $this->date = isset($params['dt']) ? date('Y-m-d', strtotime("-1 day {$params['dt']}")) : date('Y-m-d',
            strtotime('-1 day'));
        $this->ret['params'] = $this->params;
    }

    public function __destruct()
    {
        //TODO 资源释放
    }

    /**
     * 在ctrl 里面快速访问config
     *
     * @param $key
     *
     * @return mixed
     */
    public function getConfig($key)
    {
        return App::getConfig($key);
    }

    /**
     * 获取dao
     *
     * @param $dao
     *
     * @return bool
     */
    public function getDao($dao)
    {
        if (isset($this->_dao[$dao])) {
            return $this->_dao[$dao];
        }
        $class = App::$conf['app']['ns_pre'] . '\\' . App::$conf['app']['ns_dao'] . '\\' . $dao;
        if (!class_exists($class)) {
            return false;
        }
        $obj = new $class($this->isDebug());
        $this->_dao[$class] = $obj;

        return $obj;
    }

    public function getApp()
    {
        return App::getApp();
    }

    /**
     * @return bool true|false  true 表示当前请求为debug模式
     */
    public function isDebug()
    {
        return $this->getApp()->getDebug($this->id);
    }
}
