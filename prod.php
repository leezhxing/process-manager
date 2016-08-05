<?php
define('_BASEDIR_', dirname(__FILE__));
define('_LOGDIR_', _BASEDIR_.'/log/');
define('_CONFIGDIR_', _BASEDIR_.'/config/');
define('_RUNDIR_', _BASEDIR_.'/run/');
define('_CONSUMERDIR_', _BASEDIR_.'/consumer/');

class ProcessManager
{
    /**
     * 保存子进程pid的数组
     * @var
     */
    private static $_childs;

    /**
     * 配置文件数组
     * @var array
     */
    private static $_config;

    /**
     * run.pid文件描述符
     * @var
     */
    private static $_runfd;

    /**
     * 当前执行的action[start, stop, reload, monitor]
     * @var
     */
    private static $_cur_action;



    /**
     * 当前子进程是否在执行
     * @var
     */
    private static $_is_execute = false;

    private static $_keep_execute = true;

    /**
     * 当前可以执行的命令[start, stop, reload, monitor]
     * @var
     */
    private static $_enable_action = [];

    const P_CONFIG_FILE = 'prod.ini';
    const P_RUN_PID_FILE = 'run.pid';
    const P_PROD_LOG = 'prod.log';

    /* 能够使用的控制命令 */
    const P_ACTION_START = 'start';
    const P_ACTION_STOP = 'stop';
    const P_ACTION_RELOAD = 'reload';
    const P_ACTION_MONITOR = 'monitor';

    public function __construct()
    {
        //解析配置文件,获取全部待执行的任务脚本和对应子进程数量
        self::$_config = $this->parseConfig();

        //获取当前执行的action
        self::$_cur_action = $_SERVER['argv'][1];
    }

    public function start()
    {
        //获取runpid文件的独占写锁,通过锁获取结果判断当前服务是否在运行,设置对应的action是否可执行
        self::$_enable_action = $this->setEnableActionByFlock();

        //如果当前的指令不在可执行指令内,返回异常
        if (!in_array(self::$_cur_action, self::$_enable_action)) {
            throw new Exception("[error]\tcur action can't access, check the prod was started or stoped.");
        }

        switch (self::$_cur_action) {
            case 'start':
                $this->printStdout('prod started');

                //把主进程的pid写入文件
                $runPid = $this->setRunPid(posix_getpid());
                if ($runPid === false) {
                    throw new Exception("[error]\tparent pid write run.pid err.");
                }

                //启动多个子进程来跑任务
                $this->workerStart();

                break;
            case 'stop':
                $runPid = $this->getRunPid(); //读取主进程pid
                posix_kill($runPid, SIGTERM);  //向主进程发送SIGTERM信号,强制停止

                $kill_errno = posix_get_last_error();
                $this->manageKillError($kill_errno);
                break;
            case 'reload':
                $runPid = $this->getRunPid();
                posix_kill($runPid, SIGHUP);

                $kill_errno = posix_get_last_error();
                $this->manageKillError($kill_errno);
                break;
            case 'monitor':
                $runPid = $this->getRunPid();
                posix_kill($runPid, SIGUSR1);

                $kill_errno = posix_get_last_error();
                $this->manageKillError($kill_errno);
                break;
            default:
                throw new Exception("Useage: ./prod stop/start/reload/monitor");
        }

        //主进程等待子进程退出再退出
        while (1) {

            //调用每个等待信号通过pcntl_signal() 安装的处理器。
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            //等待任意子进程退出,不挂起主进程
            $child_pid = pcntl_waitpid(-1, $status, WNOHANG);
            if ($child_pid > 0) {

                //父子进程共享打开的连接,子进程退出会关闭,父进程需要重新获取锁
                $relock_res = $this->requestRunPidLock();
                $this->writelog('access.p', "caught child exit,relock_result:$relock_res,pid:$child_pid,ppid:".posix_getpid());

                foreach (self::$_childs as $k => $v) {
                    $key = array_search($child_pid, $v);
                    if ($key !== false) {
                        unset(self::$_childs[$k][$key]); //从子进程数组中删除退出的子进程
                        $this->writelog('access.p', "pid:{$child_pid} was unset from childs");
                    }
                }
            }
            usleep(100000);
        }
    }

    /**
     * parse config file
     *
     * @return array
     */
    public function parseConfig()
    {
        $configPath = _CONFIGDIR_.self::P_CONFIG_FILE;

        return parse_ini_file($configPath);
    }

    /**
     * again parse config file
     *
     * @return array
     */
    public function reParseConfig()
    {
        return self::$_config = $this->parseConfig();
    }

    /**
     * 获取run.pid文件的锁
     *
     * @return bool
     * @throws Exception
     */
    public function requestRunPidLock()
    {
        //进程run.pid文件,进程启动后把pid写入当前文件
        $runPath = $this->getRunPidPath();

        if (!file_exists($runPath)) {
            @touch($runPath);
        }

        self::$_runfd = @fopen($runPath, "r+"); //读写方式打开,指向文件头
        if (!self::$_runfd) {
            throw new Exception("[error]\topen the runpid file err.");
        }

        // LOCK_EX 取得独占锁定（写入的程序。 如果不希望 flock() 在锁定时堵塞，则是 LOCK_NB
        // 在 PHP 5.3.2版本之前，锁也会被 fclose() 释放
        $flock = flock(self::$_runfd, LOCK_EX | LOCK_NB);

        // 测试PHP5.5版本发现fclose也会释放锁,导致这里不能关闭文件描述符,并且
        // 之后不能使用file_*_contents,这类函数最后相当于调用fclose.
        //fclose($runFD);

        return $flock;
    }

    /**
     * 把pid写入run.pid
     *
     * @param $pid
     * @return int
     */
    public function setRunPid($pid)
    {
        if (!self::$_runfd) {
            throw new Exception('run.pid fd not exists when set run pid.');
        }

        ftruncate(self::$_runfd, 0);      // truncate file
        return fwrite(self::$_runfd, $pid);
    }

    /**
     * 获取run.pid
     *
     * @return int
     */
    public function getRunPid()
    {
        if (!self::$_runfd) {
            throw new Exception('run.pid fd not exists when get run pid.');
        }

        return intval(fread(self::$_runfd, 6));
    }

    /**
     * 守护进程只能启动一个,通过是否能获取文件锁判断设置哪些action指令可以执行
     *
     * @param $runPidFD
     */
    public function setEnableActionByFlock()
    {
        $flock = $this->requestRunPidLock();

        if ($flock) {
            //获得锁说明程序没有在运行
            return [self::P_ACTION_START];
        } else {
            //没获取到锁说明程序在运行,此时可以执行stop,reload,monitor指令
            return [self::P_ACTION_STOP, self::P_ACTION_RELOAD, SELF::P_ACTION_MONITOR];
        }
    }

    /**
     * return run.pid file path
     *
     * @return string
     */
    public function getRunPidPath()
    {
        return _RUNDIR_.self::P_RUN_PID_FILE;
    }

    function sigHandler($signo)
    {
        switch ($signo) {
            case SIGUSR1:
                $this->monitorProd();
                break;
            case SIGHUP:
                $this->reloadProd();
                break;
            case SIGTERM:
                $this->shutdownProd();
                break;
            case SIGINT:
                $this->shutdownChild(); //子进程的信号处理函数
                break;
            default:
        }
    }

    public function writelog($type, $msg)
    {
        if (!$type) return false;
        $logMsg = sprintf("[%s] [action:%s] %s\n", $type, self::$_cur_action, $msg);
        $fp     = fopen(_LOGDIR_.self::P_PROD_LOG, 'a+');
        fwrite($fp, $logMsg);
        fclose($fp);
    }

    public function manageKillError($kill_errno)
    {
        if ($kill_errno == 0) {
            die("prod ".self::$_cur_action." succ\n");
        } elseif ($kill_errno == 3) {
            die("prod not running\n");
        } else {
            die("prod monitor failed, errno={$kill_errno}\n");
        }
    }

    public function workerStart($config = null)
    {
        $config = $config ?: self::$_config;

        if (is_array($config)) {
            $this->writelog('access.p', "worker start,ppid:".posix_getpid());

            //注册信号处理函数
            $this->registerSigHandler();

            foreach ($config as $name => $num) {
                while ($num-- > 0) {
                    $pid = pcntl_fork();
                    if ($pid < 0) { //error
                        $this->writelog('error.p', "fork failed,conf_name={$name}");
                        exit;
                    } elseif ($pid > 0) {
                        //parent
                        $this->writelog('access.p', "a child created,pid:{$pid},ppid:".posix_getpid());

                        //把生成的子进程pid加入childs对应的配置文件name的数组
                        if (!isset(self::$_childs[$name])) {
                            self::$_childs[$name] = [];
                        }
                        self::$_childs[$name][] = $pid;

                        $this->writelog('access.p', "add child pid {$pid} to childs,pid:{$pid},ppid:".posix_getpid());
                    } else {
                        //child
                        while (self::$_keep_execute) {
                            //调用每个等待信号通过pcntl_signal() 安装的处理器。
                            if (function_exists('pcntl_signal_dispatch')) {
                                pcntl_signal_dispatch();
                            }

                            self::$_is_execute = true; //设置子进程状态为执行中
                            sleep(20); //信号会中断sleep返回剩余的秒数
                            $this->consumer($name);
                            self::$_is_execute = false; //设置子进程执行状态
                            usleep(100000);
                        }

                        exit;
                    }
                }
            }
        } else {
            throw new Exception("prod.ini parse not arr,pid:".posix_getpid());
        }
    }

    public function consumer($name)
    {
        $consumer_file = _CONSUMERDIR_.$name.".php";
        if (!file_exists($consumer_file)) {
            $this->writelog('error', "consumer_file is not exist!".$consumer_file);
        }
        include($consumer_file);
    }

    /**
     * register signo handler
     */
    public function registerSigHandler()
    {
        if (!function_exists('pcntl_signal_dispatch')) {
            declare(ticks = 1);
        }

        $sigs = [SIGTERM, SIGHUP, SIGUSR1, SIGINT];

        foreach (array_unique($sigs) as $sig) {
            pcntl_signal($sig, [$this, "sigHandler"]);
        }
    }

    function reloadProd()
    {
        //SIGHUP一般都是重新加载配置文件,进程本身并不会关闭在重启,进程PID也不会变
        //但是这里子进程运行并不用到配置文件,我的配置文件是生成子进程用的,
        //这里直接关闭在重启来的快些.
        $this->writelog('access.p', "recv SIGHUP,ppid:".posix_getpid());
        $pid = pcntl_fork();
        if ($pid > 0) {
            //父进程重新load配置文件并启动新的子进程
            $this->reParseConfig();
            $this->workerStart();
        } elseif ($pid == 0) {
            //新开的子进程负责去结束旧的子进程
            $this->shutdownProd();
            exit;
        } else {
            $this->writelog('error.p', "recv SIGHUP then fork process to reload failed,ppid:".posix_getpid());
        }
    }

    /**
     * 父进程收到SIGTERM信号的处理函数
     */
    function shutdownProd()
    {
        $this->writelog('access.p', "recv SIGTERM,ppid:".posix_getpid());
        $allPidsArr = array();

        //把全部子进程的pid都放到一个数组中
        foreach (self::$_childs as $k => $v) {
            $allPidsArr = array_merge($allPidsArr, $v);
        }

        //优雅的kill全部子进程
        $this->shutdown($allPidsArr);
        $this->writelog('access.p', "sended SIGTERM to all child,ppid:".posix_getpid());

        //释放锁定，关闭文件描述符，删除run.pid
        if (self::$_runfd) {
            flock(self::$_runfd, LOCK_UN);    // 释放锁定
            fclose(self::$_runfd);
        }
        $runPath = $this->getRunPidPath();
        if (file_exists($runPath)) {
            @unlink($runPath);
        }

        exit;
    }

    /**
     * 子进程收到SIGTERM信号的处理函数
     */
    function shutdownChild()
    {
        $this->writelog('access.c', "recv SIGTERM from parent,pid:".posix_getpid().",ppid:", posix_getppid());

        //如果子进程没有在执行任务就直接退出
        if (!self::$_is_execute) {
            exit;
        }

        //设置子进程完成任务后退出
        self::$_keep_execute = false;
    }

    function monitorProd()
    {
        $this->writelog('access.p', "recv SIGUSER1,ppid:".posix_getpid());
        $shutdown_pids = array();
        $start_conf    = array();
        if (!isset(self::$_childs) || !is_array(self::$_childs)) {
            $this->writelog('error.p', "monitor process but the childpids is err,ppid".posix_getpid());

            return false;
        }

        //循环配置文件,跟childs对比,不存在的就fork子进程,子进程不够的就fork补充,子进程多的就shutdown
        foreach (self::$_config as $name => $num) {
            if (!isset(self::$_childs[$name]) || !is_array(self::$_childs[$name])) {
                $start_conf[$name] = $num;
                $this->writelog('access.p', "monitor find conf_{$name} child process not exists, and ready start,ppid".posix_getpid());
            } else {
                $alive_child_num = count(self::$_childs[$name]); //活跃子进程的数量
                $diff_num        = $num - $alive_child_num;
                if ($diff_num > 0) { //子进程数量不够
                    $start_conf[$name] = $diff_num;
                    $this->writelog('access.p', "monitor find conf_{$name} child process num is shortage, and ready add,ppid".posix_getpid());
                } elseif ($diff_num < 0) { //子进程数量多于配置文件
                    $shutdown_pids = array_slice(self::$_childs[$name], $diff_num); //取出最后的两个子进程pid准备kill掉
                }
            }
        }

        //循环childs数组,shutdown掉不在配置文件中的conf_name对应的子进程
        foreach (self::$_childs as $name => $v) {
            if (!isset(self::$_config[$name])) {
                $shutdown_pids = array_merge($shutdown_pids, $v);
            }
        }

        //根据配置fork子进程
        if (count($start_conf)) {
            $this->workerStart($start_conf);
        }

        //根据配置关闭子进程
        if (count($shutdown_pids)) {
            $this->shutdown($shutdown_pids);
        }
        unset($start_conf, $shutdown_pids);
    }

    public function shutdown($pidArr)
    {
        while (count($pidArr) > 0) {
            $pid = array_shift($pidArr); //弹出一个子进程,发送SIGTERM信号
            if ($pid) {
                $file_run = _RUNDIR_.$pid.".lock";

                //w写入方式打开，将文件指针指向文件头并将文件大小截为零。如果文件不存在则尝试创建之。
                $fp = fopen($file_run, "w");

                //TODO 杀死一个进程时为什么加锁呢,避免启动多个stop程序么？
                if (flock($fp, LOCK_EX | LOCK_NB)) {
                    posix_kill($pid, SIGINT);
                    $kill_errno = posix_get_last_error();
                    if ($kill_errno == 0) {
                        $this->writelog('access.p', "send SIGTERM to child succ,pid:$pid,ppid:".posix_getpid());
                    } elseif ($kill_errno == 3) {
                        $this->writelog('warning.p', "send SIGTERM to child but no such process,pid:$pid,ppid:".posix_getpid());
                    } else {
                        array_push($pidArr, $pid); //如果信号发送失败,加入数组尾部再次发送信号
                        $this->writelog('error.p', "send SIGTERM to child failed,
                                        kill_errno:{$kill_errno},kill_msg:".posix_strerror($kill_errno)
                            .",pid:$pid,ppid:".posix_getpid());
                    }
                    flock($fp, LOCK_UN);
                }
                fclose($fp);
                unlink($file_run);
            }
        }
    }

    public function printStdout($msg)
    {
        echo $msg, PHP_EOL;
    }
}

try {
    $o = new ProcessManager();
    $o->start();
} catch (Exception $e) {
    echo $e->getMessage().PHP_EOL;
    exit;
}
?>
