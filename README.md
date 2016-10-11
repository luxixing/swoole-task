swoole-task
-----------
swoole-task是基于PHP swoole扩展开发的一个异步多进程任务处理框架，服务端和客户端通过http协议进行交互。

它适用于任务需要花费较长时间处理，而客户端不必关注任务执行结果的场景.比如数据清洗统计类的工作，报表生成类任务。
    
### 环境要求

- PHP 5.4 以上版本,强烈推荐适用5.6，性能更好
- Swoole 1.8.7 以上版本
- PDO PDO_Mysql 

### 安装方法

鉴于目前composer已经成为PHP包管理事实的标准，swoole-task直接支持通过composer 命令安装使用。

关于composer的使用请参考 composer 官网学习。

假设当前我们项目的名称为play,需要在项目中集成swoole-task，服务，执行如下命令。

    
```sh
composer require ping/swoole-task
```
    
> 如果项目根目录中不存在composer.json文件，需要执行composer init 命令。

### 使用方法

安装完成之后，在项目的vendor/bin 目录下会有swoole-tssk.php这个脚本，此脚本就是swoole-task服务的管理脚本。

以默认配置启动swoole-task服务    

```sh
php swoole-task.php start 
```
> 第一次启动会执行初始化动作。

关于swoole-task.php脚本的详细说明

- 参数

```
--app  应用目录名称，默认 sw-app,可根据自己需求设置
--nodaemon 以非守护进程模式启动，默认读取配置文件http_server.php 中的 daemonize的值
--help 显示帮忙
--host 指定绑定的ip 默认读取配置文件 http_server.php中的host取值
--port 指定绑定的端口，默认读取配置文件中的 http_server.php中的port的取值
```

- 命令

```
start   //启动服务
stop    //停止服务
restart //重启，配置文件常驻内存，修改后需要重启
status  //查看状态
list    //swoole-task服务列表
```

用例说明:

```
//启动swoole-task服务，以项目根目录下的sw目录为业务目录
php swoole-task.php --app sw start 
//停止swoole-task 服务
php swoole-task.php -app sw stop
//启动swoole-task 服务，使用默认app目录(sw-app),使用host和port 覆盖默认配置
php swoole-task.php --host 127.0.0.1 --port 9520 start
//显示服务状态
php swoole-task.php --app sw status
```

### 配置说明
第一次使用 php swoole-task.php start 启动服务的时候，默认的业务逻辑编写目录是sw-app,和vendor在同级目录。

自动生成如下目录结构

```
- sw-app  和vendor是同级目录
    - Conf 配置文件目录，不可更改
        - http_server.php 主要是针对HttpServer的配置
        - app.php 主要是对实际业务的配置
        - dev  开发环境配置文件目录(列如 db.php redis.php等等，文件名为key值)
        - test 测试环境配置文件目录
        - prod 生产环境配置文件目录
    - Runtime 运行时输出目录
    - Ctrl  controller目录，可修改app.php配置文件，使用别的名称来替代Ctrl
    - Dao 数据访问业务Dao,可修改app.php配置文件，使用别的名称替代Dao
    - Helper 帮助类，修改配置文件可以使用别的名称
```

默认配置文件说明

app.php(没强迫症建议直接使用默认配置不必修改)

```
ns_pre  命名空间前缀，默认值 SwTask
ns_ctrl  ctrl类的命名空间，注意命名空间和ctrl类是一致的，遵循psr4规则，
ns_dao dao类的命名空间
ns_helper heper类的命名空间
```

http_server.php(修改配置好之后需要重启加载配置文件)

```
tz  时区设置，默认是上海时区，数据处理的时候非常重要
host 监听的主机ip地址，默认配置0.0.0.0
port 监听的主机端口，默认值 9523
app_env swoole-task 业务的环境，支持dev,test,prod三个值
ps_name 进程名称前缀 默认 swTask
daemonize 是否守护进程模式 0 非守护进程 1 守护进程，默认0
worker_num worker 进程数量，推荐数量和cpu核数保持一致
task_woker_num 任务进程数量，根据机器配合和实际需求设置
task_max_request 每个任务进程最多可处理请求数，超过重启，保证内存不泄露的机制
```

### swoole-task 服务启动流程说明

初次运行swoole-task,执行php vendor/bin/swoole-task.php 命令，不添加任何参数，会执行如下初始化工作

```
1 根据默认配置，创建Ctrl, Dao，Helper,
 Conf, Conf/test, Conf/dev, Conf/prod, Runtime, Runtime/log 目录,
2 创建TplCtrl,TplDao 文件，写入到Ctrl/Dao 目录下，作为模板文件
3 初始化配置文件app.php , http_server.php，写入到Conf目录下
```
运行 php swoole-task.php 命令，不添加任何参数，会在项目根目录下检查是否存在sw-app 目录。

如果不存在，执行初始化工作;

如果存在，加载sw-app/Conf目录下的相关配置，启动服务。

### 路由说明

客户端和服务端http协议交互，形式如下

curl "127.0.0.1:9523/ctrlName/actionName"

curl "127.0.0.1:9523?op=ctrlName.actionName"

初始化后，默认生成的TplCtrl.php 文件,其中包含了一个 helloAction的方法

访问这个action的命令为

curl "127.0.0.1:9523/tpl/hello"

或者 

curl "127.0.0.1:9523?op=tpl.hello"


