<?php
namespace Ping\SwooleTask\Base;

/**
 * Class Dao
 *
 * @package base
 */
class Dao
{
    /**
     * 当前所有连接实例
     *
     * @var \PDO[]
     */
    private static $_pdo = [];
    /**
     * 执行日志记录
     *
     * @var array
     */
    private static $_log = [];

    /**
     * 是否调试模式
     *
     * @var bool
     */
    protected $debug;
    /**
     * 当前连接
     *
     * @var \PDO | null
     */
    protected $pdo;
    /**
     * 当前连接名称
     *
     * @var string
     */
    protected $pdoName;

    public function __construct($debug = false)
    {
        //设置debug模式
        $this->debug = $debug;
    }

    /**
     * 非dao类调用进行数据库操作，比如helper类
     *
     * @param $name
     *
     * @return null|\PDO
     * @throws \Exception
     */
    public static function getPdoX($name)
    {
        $pdo = null;
        if (!empty(self::$_pdo[$name])) {
            self::ping(self::$_pdo[$name], $name);
            $pdo = self::$_pdo[$name];
        } else {
            $pdo = self::$_pdo[$name] = self::getPdoInstance($name);
        }

        return $pdo;
    }

    /**
     * 如果mysql go away,连接重启
     *
     * @param \PDO $pdo
     * @param $name
     *
     * @return bool
     * @throws \Exception
     */
    private static function ping($pdo, $name)
    {
        //是否重新连接 0 => ping连接正常, 没有重连 1=>ping 连接超时, 重新连接
        $isReconnect = 0;
        if (!is_object($pdo)) {
            $isReconnect = 1;
            self::$_pdo[$name] = self::getPdoInstance($name);
            App::getApp()->sql("mysql ping:pdo instance [{$name}] is null, reconnect");
        } else {
            try {
                //warn 此处如果mysql gone away会有一个警告,此处屏蔽继续重连
                @$pdo->query('SELECT 1');
            } catch (\PDOException $e) {
                //WARN 非超时连接错误
                if ($e->getCode() != 'HY000' || !stristr($e->getMessage(), 'server has gone away')) {
                    throw $e;
                }
                //手动重连
                self::$_pdo[$name] = self::getPdoInstance($name);
                $isReconnect = 1;
            } finally {
                if ($isReconnect) {
                    APP::getApp()->sql("mysql ping: reconnect {$name}");
                }
            }
        }

        return $isReconnect;
    }

    /**
     * 创建数据库连接实例
     *
     * @param $name
     *
     * @return \PDO
     * @throws \Exception
     */
    private static function getPdoInstance($name)
    {
        $default = [
            //连接成功默认执行的命令
            'commands' => [
                'set time_zone="+8:00"',
            ],
            'host' => '127.0.0.1',
            //unix_socket
            'socket' => '',
            'username' => 'root',
            'password' => '',
            //默认字符编码
            'charset' => 'utf8',
            'port' => '3306',
            'dbname' => 'mysql',
            'options' => [
                \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'UTF8'",
                \PDO::ATTR_CASE => \PDO::CASE_LOWER,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,    // 默认以数组方式提取数据
                \PDO::ATTR_ORACLE_NULLS => \PDO::NULL_TO_STRING, // 所有 null 转换为 string
            ],
        ];
        try {
            $conf = App::getApp()->getConfig("conf.db.{$name}");
            if (empty($conf)) {
                throw  new \Exception("pdo config: conf.db.{$name} not found");
            }
            foreach ($default as $k => $v) {
                $default[$k] = isset($conf[$k]) ? $conf[$k] : $v;
            }
            //PDO错误处理模式强制设定为exception, 连接强制设定为持久连接
            $default['options'][\PDO::ATTR_ERRMODE] = \PDO::ERRMODE_EXCEPTION;
            $default['options'][\PDO::ATTR_PERSISTENT] = false;
            $default['options'][\PDO::ATTR_TIMEOUT] = 200;

            $dsn = "mysql:dbname={$default['dbname']}";
            if ($default['host']) {
                $dsn .= ";host={$default['host']};port={$default['port']}";
            } else {
                $dsn .= ";unix_socket={$default['socket']}";
            }
            //create pdo connection
            $pdo = new \PDO(
                $dsn,
                $default['username'],
                $default['password'],
                $default['options']
            );
            if (!empty($default['commands'])) {
                $commands = is_array($default['commands']) ? $default['commands'] : [$default['commands']];
                foreach ($commands as $v) {
                    $pdo->exec($v);
                }
            }

            return $pdo;
        } catch (\Exception $e) {
            App::getApp()->error($e->getMessage());
            throw $e;
        }
    }

    /**
     * 获取当前请求执行的所有sql
     *
     * @return array
     */
    public static function logs()
    {
        return self::$_log;
    }

    /**
     * 设定数据库连接，可执行链式操作
     *
     * @param $name
     *
     * @return $this
     * @throws \Exception
     */
    public function getPdo($name)
    {
        $this->pdoName = $name;
        if (!empty(self::$_pdo[$name])) {
            $this->ping(self::$_pdo[$name], $name);
            $this->pdo = self::$_pdo[$name];
        } else {
            $this->pdo = self::$_pdo[$name] = $this->getPdoInstance($name);
        }

        return $this;
    }

    /**
     * 执行一条sql
     *
     * @param $sql
     *
     * @return int|string
     */
    final public function query($sql)
    {
        $lastId = 0;
        try {
            $this->debug($sql);
            $sth = $this->pdo->prepare($sql);
            if ($sth) {
                $sth->execute();
                $lastId = $this->pdo->lastInsertId();
            }

        } catch (\PDOException $e) {
            $this->getApp()->error($e->getMessage() . PHP_EOL . $this->lastQuery());
        }

        return $lastId;
    }

    private function debug($sql, $data = [])
    {
        //WARN sql记录最多保持100条,防止内存占用过多
        $max = 100;
        $query = $this->buildQuery($sql, $data);
        if (count(self::$_log) > $max) {
            array_shift(self::$_log);
        }
        self::$_log[] = $query;
        if ($this->debug) {
            $this->getApp()->sql($query);
        }
    }

    /**
     * prepare语句真实执行的sql
     *
     * @param $sql
     * @param array $data
     *
     * @return mixed
     */
    private function buildQuery($sql, $data = [])
    {
        $placeholder = [];
        $params = [];
        if (empty($data) || !is_array($data)) {
            return $sql;
        }
        foreach ($data as $k => $v) {
            $placeholder[] = is_numeric($k) ? '/[?]/' : ($k[0] === ':' ? "/{$k}/" : "/:{$k}/");
            $params[] = is_numeric($v) ? (int)$v : "'{$v}'";
        }

        return preg_replace($placeholder, $params, $sql, 1, $count);
    }

    /**
     * @return App|null
     */
    public function getApp()
    {
        return App::getApp();
    }

    /**
     * 最近一次sql查询
     *
     * @return mixed
     */
    public function lastQuery()
    {
        return end(self::$_log);
    }

    /**
     * 执行一个sql，获取一条结果
     *
     * @param string $sql
     * @param array $data
     *
     * @return array
     */
    final public function getOne($sql, $data = [])
    {
        return $this->baseGet($sql, $data, 'fetch');
    }

    /**
     * @param string $sql    要执行的sql
     * @param array $data    绑定参数
     * @param string $method [fetch|fetchAll|fetchColumn|fetchObject]
     * @param mixed $pdo     \PDO|null
     *
     * @return array
     * @throws \Exception
     */
    private function baseGet($sql, $data, $method, $pdo = null)
    {
        $ret = [];
        try {
            $this->debug($sql, $data);
            $pdo = $pdo instanceof \PDO ? $pdo : $this->pdo;
            $sth = $pdo->prepare($sql);
            if ($sth) {
                $sth->execute($data);
                if ($rows = $sth->$method(\PDO::FETCH_ASSOC)) {
                    $ret = $rows;
                }
            }

        } catch (\PDOException $e) {
            $this->getApp()->error($e->getMessage() . PHP_EOL . $this->lastQuery());
        }

        return $ret;
    }

    /**
     * 执行一个sql,获取所有结果
     *
     * @param string $sql
     * @param array $data
     *
     * @return array
     */
    final public function getAll($sql, $data = [])
    {
        return $this->baseGet($sql, $data, 'fetchAll');
    }

    /**
     * 使用生成器进行数据的批量查询,低内存占用
     *
     * @param string $pdoName 数据库连接配置名称
     * @param string $sql     欲执行sql
     * @param array $data     绑定参数
     * @param int $limit      限制查询数量
     *
     * @return \Generator
     * @throws \Exception
     */
    final public function getIterator($pdoName, $sql, $data = [], $limit = 500)
    {
        //pdo设置
        $pdo = null;
        if (isset(self::$_pdo[$pdoName])) {
            $this->ping(self::$_pdo[$pdoName], $pdoName);
            $pdo = self::$_pdo[$pdoName];
        } else {
            $pdo = $this->getPdoInstance($pdoName);
        }
        //迭代查询
        $offset = 0;
        do {
            //ping 防止超时
            if ($this->ping($pdo, $pdoName)) {
                $pdo = self::$_pdo[$pdoName];
            }
            $s = "{$sql} limit {$offset}, {$limit}";
            $ret = $this->baseGet($s, $data, 'fetchAll', $pdo);
            if (empty($ret)) {
                break;
            }
            foreach ($ret as $v) {
                yield $v;
            }
            $offset += $limit;
        } while (true);
    }

    /**
     * 预初始化一个pdo的prepare语句,返回准备好的PDOStatement环境，用于批量执行相同查询
     *
     * @param string $pdoName   数据库连接名称(db.php db数组的key名称)
     * @param string $sql       欲查询的sql
     * @param string $fetchMode 数据获取模式 [fetch|fetchAll|fetchColumn|fetchObject] 对应statement的方法
     *
     * @return \Closure
     * @throws \Exception
     */
    final public function getBatchSth($pdoName, $sql, $fetchMode = 'fetch')
    {
        $pdo = null;
        $sth = null;
        try {
            if (isset(self::$_pdo[$pdoName])) {
                //WARN 注意，顺序非常重要
                $this->ping(self::$_pdo[$pdoName], $pdoName);
                $pdo = self::$_pdo[$pdoName];
            } else {
                $pdo = $this->getPdoInstance($pdoName);
            }
            $sth = $pdo->prepare($sql);
        } catch (\PDOException $e) {
            $this->getApp()->error($e->getMessage());
        }

        $batch = function ($d) use ($sth, $fetchMode, $sql, $pdo, $pdoName) {
            // WARN 重连之后因为pdo对象变化，需要重新预处理sql
            if ($this->ping($pdo, $pdoName)) {
                $pdo = self::$_pdo[$pdoName];
                $sth = $pdo->prepare($sql);
            }
            try {
                $this->debug($sql, $d);
                $sth->execute($d);
                $ret = $sth->$fetchMode();

                return $ret;
            } catch (\PDOException $e) {
                $this->getApp()->error($e->getMessage() . PHP_EOL . $this->lastQuery());
            }
            return null;
        };

        return $batch;
    }

    /**
     * 向表里插入一条数据
     *
     * @param string $table
     * @param array $data
     *
     * @return int|string
     */
    final public function add($table, $data, $ignore = false)
    {
        $lastId = 0;
        if (empty($data)) {
            return $lastId;
        }
        $keys = array_keys($data);
        $cols = implode(',', $keys);
        $params = ':' . implode(',:', $keys);
        $sql = '';
        if ($ignore) {
            $sql = <<<SQL
insert IGNORE into {$table} ({$cols}) values ($params)
SQL;
        } else {
            $sql = <<<SQL
insert into {$table} ({$cols}) values ($params)
SQL;
        }

        try {
            $this->debug($sql, $data);

            $sth = $this->pdo->prepare($sql);
            $sth->execute($data);
            //WARN 当引擎为innodb 则不会自动提交，需要手动提交
            //$this->pdo->query('commit');
            $lastId = $this->pdo->lastInsertId();
        } catch (\PDOException $e) {
            $this->getApp()->error($e->getMessage() . PHP_EOL . $this->lastQuery());
        }

        return $lastId;
    }

    /**
     * 生成一个prepare的Statement语句，用于批量插入
     *
     * @param string $pdoName pdo连接名称
     * @param string $table   表名称
     * @param array $bindCols 插入数据字段数组
     *
     * @return \Closure
     * @throws \Exception
     */
    final public function addBatchSth($pdoName, $table, $bindCols)
    {
        $cols = implode(',', $bindCols);
        $params = ':' . implode(',:', $bindCols);
        $sql = <<<SQL
insert into {$table} ({$cols}) values ($params)
SQL;
        $pdo = null;
        $sth = null;
        try {
            if (isset(self::$_pdo[$pdoName])) {
                $this->ping(self::$_pdo[$pdoName], $pdoName);
                $pdo = self::$_pdo[$pdoName];
            } else {
                $pdo = $this->getPdoInstance($pdoName);
            }
            $sth = $pdo->prepare($sql);
        } catch (\PDOException $e) {
            $this->getApp()->error($e->getMessage());
        }
        //匿名函数用于sql prepare之后的批量操作
        $batch = function ($d) use ($sth, $bindCols, $pdo, $sql, $pdoName) {
            if ($this->ping($pdo, $pdoName)) {
                $pdo = self::$_pdo[$pdoName];
                $sth = $pdo->prepare($sql);
            }
            try {
                $bindParam = [];
                foreach ($bindCols as $v) {
                    $bindParam[":{$v}"] = $d[$v];
                }
                $this->debug($sql, $bindParam);
                $sth->execute($bindParam);

                return $pdo->lastInsertId();
            } catch (\PDOException $e) {
                $this->getApp()->error($e->getMessage() . PHP_EOL . $this->lastQuery());
            }
        };

        return $batch;
    }

    final public function addUpdate($table, $data)
    {
        //TODO
    }

    /**
     * 更新指定表数据
     *
     * @param string $table
     * @param array $data 绑定参数
     * @param string $where
     *
     * @return int
     */
    final public function update($table, $data, $where)
    {
        $rowsCount = 0;
        $keys = array_keys($data);
        $cols = [];
        foreach ($keys as $v) {
            $cols[] = "{$v}=:$v";
        }
        $cols = implode(', ', $cols);
        $sql = <<<SQL
update {$table} set {$cols} where {$where}
SQL;
        try {
            $this->debug($sql, $data);
            $sth = $this->pdo->prepare($sql);
            $sth->execute($data);
            $rowsCount = $sth->rowCount();
        } catch (\PDOException $e) {
            $this->getApp()->error($e->getMessage() . PHP_EOL . $this->lastQuery());
        }

        return $rowsCount;
    }

    /**
     * 删除指定表数据，返回影响行数
     *
     * @param string $table 表名称
     * @param string $where where 条件
     *
     * @return int
     */
    final public function del($table, $where)
    {
        $rowsCount = 0;
        $sql = <<<SQL
delete from {$table} where $where
SQL;
        try {
            //优先写入要执行的sql
            $this->debug($sql);
            //sth 返回值取决于pdo连接设置的errorMode,如果设置为exception,则抛出异常
            $sth = $this->pdo->prepare($sql);
            $sth->execute();
            $rowsCount = $sth->rowCount();
        } catch (\PDOException $e) {
            $this->getApp()->error($e->getMessage() . PHP_EOL . $this->lastQuery());
        }

        return $rowsCount;
    }
}
