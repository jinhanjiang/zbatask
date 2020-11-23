<?php
/**
 * This file is part of zba.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    jinhanjiang<jinhanjiang@foxmail.com>
 * @copyright jinhanjiang<jinhanjiang@foxmail.com>
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Zba;

use Zba\Process;
use Zba\Worker;
use Zba\Task;
use Zba\ProcessException;
use Exception;
use Closure;

/**
 * process pool class
 */
class ProcessPool extends Process
{
    /**
     * version
     * @var string
     */
    public static $version = '0.1.4';

    /**
     * worker process objects
     * @var array [Process]
     */
    public static $workers = [];

    /**
     * tasks 
     * @var array
     */
    private static $tasks = [];

    /**
     * env config
     * @var array
     */
    private $env = [];

    /**
     * support linux signal (The signal received by the main process.)
     * @var array
     */
    private static $signalSupport = [
        'reload' => SIGUSR1,
        'status' => SIGUSR2,
        'terminate' => SIGTERM,
        'int' => SIGINT
    ];

    /**
     * master process status: starting
     * @var int
     */
    const STARTING = 1;

    /**
     * master process status: running
     * @var int
     */
    const RUNNING = 2;

    /**
     * master process status: shutdown
     * @var int
     */
    const SHUTDOWN = 4;

    /**
     * master process status 1 starting, 2 running 4 shutdown
     * @var int
     */
    private static $status = self::STARTING;

    /**
     * Current master process
     */
    private static $master = null;
    /**
     * construct function
     */
    public function __construct($tasks)
    {
        $newTasks = array();
        if(is_array($tasks)) foreach($tasks as $task) {
            if($task instanceof Task && is_callable($task->closure)) {
                $newTasks[$task->taskId] = $task;
            }
        }
        (count($newTasks) == 0) && exit("\033[31mThere is no task to run.\033[0m\n");
        self::$tasks = $newTasks;

        self::$master = $this;
        self::$master->setProcessName('Master');
        parent::__construct();
    }

    public static function getConfigFile($config='pid') 
    {
        $file = '';
        switch($config) {
            // master pid save path
            default: $file = dirname(__DIR__) . "/zba.pid"; break;
        }
        return $file;
    }

    public function start()
    {
        // load env
        $this->loadEnv();

        // welcome
        $this->welcome();

        // daemonize
        $this->daemonize();

        // save master pid
        $this->saveMasterPid();

        // execute fork
        $this->execFork();

        // register signal handler
        $this->registerSigHandler();

        // hangup master
        $this->hangup();
    }

    /**
     * load env
     */
    private function loadEnv() 
    {
        if(! extension_loaded('pcntl')) die("\033[31mPlease install pcntl extension.\033[0m\n");
        if(! extension_loaded('posix')) die("\033[31mPlease install posix extension.\033[0m\n");

        self::$status = self::STARTING;
        /*
          file .env contents 

            ; This is a sample configuration file
            ; Comments start with ';', as in php.ini
            
            [config]
            display_errors = On
            error_reporting = E_ALL & ~E_NOTICE
            timezone = Asia/Shanghai
            pipe_dir = /tmp/
            max_execute_times = 500

         */

        $evnFile = dirname(__DIR__).'/.env';
        $this->env = file_exists($evnFile) ? parse_ini_file($evnFile, true) : $this->env;

        // set display errors
        $display_errors = isset($this->env['config']['display_errors']) ? $this->env['config']['display_errors'] : 'On';
        ini_set('display_errors', $display_errors);

        // set error reporting
        $error_reporting = isset($this->env['config']['error_reporting']) ? $this->env['config']['error_reporting'] : 'E_ALL & ~E_NOTICE';
        ini_set('error_reporting', $error_reporting);

        // set pipe dir
        $this->pipeDir = isset($this->env['config']['pipe_dir']) ? $this->env['config']['pipe_dir'] : '';

        // set timezone
        $timezone = isset($this->env['config']['timezone']) ? $this->env['config']['timezone'] : 'Asia/Shanghai';
        date_default_timezone_set($timezone);
    }

    /**
     * save master pid
     */
    private function saveMasterPid() 
    {
        // init master instance
        $masterPid = posix_getpid();
        if (false === @file_put_contents(self::getConfigFile('pid'), $masterPid.'|'.self::$master->getPipeFile())) {
            ProcessException::error('can not save pid to '.$masterPid);
        }
        self::$master->pid = $masterPid;
        self::$master->pipeCreate();
    }

    /**
     * welcome slogan
     * @return void
     */
    public function welcome() {
        $version = self::$version;
        echo "\033[36mMulti-process task framework for PHP. \nZba Version: {$version}\033[0m\n";
    }

    /**
     * execute fork worker operation
     * @param int $num the number that the worker will be start
     * @return void
     */
    private function execFork()
    {
        foreach(self::$tasks as $key=>$task) {
            if(isset($this->env[$task->name])) {
                $task->envConfig = $this->env[$task->name];
                self::$tasks[$key] = $task;
            }
            foreach(range(1, $task->count) as $id) {
                $this->fork($task, $id);
            }           
        }
    }

    /**
     * fork a worker process
     * @return void
     */
    private function fork($task, $id=1) 
    {
        /*
         * $pid = pcntl_fork();
         * pcntl_fork returns an in value
         *
         * If $pid = -1 the fork process fails
         * If $pid = 0 the current context is worker
         * If $pid > 0 the current context is master, this pid is the pid of the fork worker
         * 
         * in master context
         * pcntl_wait($status); 
         * pcntl_wait blocks until a child process exits
         *
         * pcntl_waitpid($pid, $status, WNOHANG); 
         * WNOHANG: Return immediately even if there is no child process exit
         */

        $pid = pcntl_fork();
        switch($pid)
        {
            case -1: exit; break;// exception
            case 0:
                // child context
                try{
                    // init worker instance
                    $worker = new Worker([
                        'pipe_dir'=>isset($this->env['config']['pipe_dir']) ? $this->env['config']['pipe_dir'] : '',
                        'max_execute_times'=>isset($this->env['config']['max_execute_times']) 
                            ? $this->env['config']['max_execute_times'] : 0,
                        'name'=>$task->name.'[Worker]'
                    ]);
                    $worker->id = $id;
                    $worker->taskId = $task->taskId;
                    $worker->pipeCreate();
                    $worker->onWorkerStart = $task->onWorkerStart;
                    $worker->onWorkerStop = $task->onWorkerStop;
                    $worker->masterProcessPipeFile = self::$master->getPipeFile();
                    $worker->hangup($task->closure);
                } catch(Exception $ex) {
                    ProcessException::error("task:{$task->name}, msg:{$ex->getMessage()}, file:{$ex->getFile()}, line:{$ex->getLine()}");
                }
                exit;// worker exit, Avoid the problem that the worker continues to execute the following code.
                break;
            default:
                // master context
                try{
                    $worker = new Worker([
                        'pipe_dir'=>isset($this->env['config']['pipe_dir']) ? $this->env['config']['pipe_dir'] : '',
                        'name'=>$task->name.'[Master]',
                        'pid'=>$pid, // child process pid
                    ]);
                    $worker->id = $id;
                    $worker->taskId = $task->taskId;
                    self::$workers[$task->taskId][$pid] = $worker;
                } catch(Exception $ex) {
                    ProcessException::error("task:{$task->name}, msg:{$ex->getMessage()}, file:{$ex->getFile()}, line:{$ex->getLine()}");
                }
                break;
        }
    }

    /**
     * register signal handler
     * @return void
     */
    private function registerSigHandler()
    {
        foreach(self::$signalSupport as $sig) {
            pcntl_signal($sig, ['\Zba\ProcessPool', 'defineSigHandler'], false);
        }
    }

    /**
     * define signal handler
     * @param integer $signal
     * @return void
     */
    public static function defineSigHandler($signal = 0)
    {
        // The main process receives the information for processing
        if(self::$master->pid != posix_getpid()) return false;
        switch($signal)
        {
            // reload signal
            case self::$signalSupport['reload']:
                // push reload signal to the worker processes from the master process
                foreach(self::$workers as $taskId => $workers) 
                {
                    $isReload = isset(self::$tasks[$taskId]->reload) ? self::$tasks[$taskId]->reload : true;
                    if(! $isReload) continue;
                    foreach($workers as $pid => $worker) {  
                        $worker->pipeWrite('stop');
                    }
                }
                break;
            case self::$signalSupport['int']:
            case self::$signalSupport['terminate']://master process exit
                self::$status = self::SHUTDOWN;
                foreach(self::$workers as $taskId => $workers)
                {
                    foreach($workers as $pid => $worker) {
                      $worker->pipeWrite('stop');
                    }
                }
                // clear pipe
                self::$master->clearPipe();

                // clear master pid file
                if(is_file(self::getConfigFile('pid'))) @unlink(self::getConfigFile('pid'));
                // kill -9 master process
                exit;
                break;
            default: break; 
        }
    }

    /**
     * hangup the master process
     * @return viod
     */
    public function hangup(Closure $closure = null)
    {
        self::$status = self::RUNNING;  

        // Register shutdown function for checking errors.
        register_shutdown_function(array($this, 'checkErrors'));

        // @version 0.1.4
        // deal with signal for the worker process
        // {"action":"setProcessCount", "taskName":"DefaultTask", "count":3}
        // {"action":"setProcessCount", "taskId":"8ceff40749e427439b566fcda12f8ff7", "count":3}
        $dealWithSignal = function($signals=array()) 
        {
            if($signals && is_array($signals))
                foreach($signals as $signal) {
                if(self::isJson($signal)) 
                {
                    $json = json_decode($signal, true);
                    // Get taskId by task name
                    if(isset($json['taskName'])) {
                        foreach(self::$tasks as $task) {
                            if($task->name == $json['taskName']) {
                                $json['taskId'] = $task->taskId; break;
                            }
                        }
                    }
                    switch ($json['action']) {
                        case 'setProcessCount':
                            if(isset($json['taskId']) && isset(self::$tasks[$json['taskId']])) {
                                // Check the process count
                                $count = (int)$json['count'];
                                $count = $count <= 0 ? 1 : ($count > 1000 ? 1000 : $count);
                                self::$tasks[$json['taskId']]->count = $count;
                                // Adjust process count
                                if(isset(self::$workers[$json['taskId']])) 
                                    foreach(self::$workers[$json['taskId']] as $pid => $worker) {
                                    if($worker->id > $count) {
                                        $worker->pipeWrite('stop');
                                    }
                                }
                            }
                            break;
                    }
                }
            }
        };
        while(true)
        {
            // Calls signal handlers for pending signals again.
            pcntl_signal_dispatch();

            // handle pipe msg
            $this->pipeRead($dealWithSignal);

            // Prophylactic subprocesses become zombie processes.
            // pcntl_wait($status)
            foreach(self::$workers as $taskId => $workers)
            {
                foreach($workers as $pid => $worker) 
                {
                    $res = pcntl_waitpid($worker->pid, $status, WNOHANG);
                    if($res > 0)
                    {
                        $worker->clearPipe();
                        unset(self::$workers[$taskId][$res]);
                    }
                }

                $task = self::$tasks[$taskId];

                // Alive process id
                $aliveWorkerIds = array();
                foreach(self::$workers[$task->taskId] as $worker) {
                  $aliveWorkerIds[] = $worker->id;
                }
                // Find the dead process id and regengerate a new process
                $forkWorkerIds = array_diff(range(1, $task->count), $aliveWorkerIds);
                foreach ($forkWorkerIds as $forkWorkerId) {
                  $this->fork($task, $forkWorkerId);
                }
            }
            
            // Prevent CPU utilization from reaching 100%.
            usleep(self::$hangupLoopMicrotime);
        }
    }

    /**
     * Check errors when current process exited.
     *
     * @return void
     */
    public function checkErrors()
    {
        if (self::SHUTDOWN != self::$status) {
            $errors    = error_get_last();
            if ($errors && ($errors['type'] === E_ERROR ||
                    $errors['type'] === E_WARNING ||
                    $errors['type'] === E_PARSE ||
                    $errors['type'] === E_CORE_ERROR ||
                    $errors['type'] === E_COMPILE_ERROR ||
                    $errors['type'] === E_USER_ERROR ||
                    $errors['type'] === E_USER_WARNING ||
                    $errors['type'] === E_RECOVERABLE_ERROR)
            ) {
                ProcessException::error('Process ['. posix_getpid() .'] process terminated with : ' . self::getErrorType($errors['type']) . " \"{$errors['message']} in {$errors['file']} on line {$errors['line']}\"");
            }
        }
    }
    /**
     * Get error message by error code.
     *
     * @param integer $type
     * @return string
     */
    protected static function getErrorType($type)
    {
        switch ($type) {
            case E_ERROR: // 1 //
                return 'E_ERROR';
            case E_WARNING: // 2 //
                return 'E_WARNING';
            case E_PARSE: // 4 //
                return 'E_PARSE';
            case E_NOTICE: // 8 //
                return 'E_NOTICE';
            case E_CORE_ERROR: // 16 //
                return 'E_CORE_ERROR';
            case E_CORE_WARNING: // 32 //
                return 'E_CORE_WARNING';
            case E_COMPILE_ERROR: // 64 //
                return 'E_COMPILE_ERROR';
            case E_COMPILE_WARNING: // 128 //
                return 'E_COMPILE_WARNING';
            case E_USER_ERROR: // 256 //
                return 'E_USER_ERROR';
            case E_USER_WARNING: // 512 //
                return 'E_USER_WARNING';
            case E_USER_NOTICE: // 1024 //
                return 'E_USER_NOTICE';
            case E_STRICT: // 2048 //
                return 'E_STRICT';
            case E_RECOVERABLE_ERROR: // 4096 //
                return 'E_RECOVERABLE_ERROR';
            case E_DEPRECATED: // 8192 //
                return 'E_DEPRECATED';
            case E_USER_DEPRECATED: // 16384 //
                return 'E_USER_DEPRECATED';
        }
        return "";
    }

    /**
     * check string is json
     *
     * @param $text string
     */
    public static function isJson($text) {
        if(is_string($text)) {
            @json_decode($text); return (json_last_error() === JSON_ERROR_NONE);
        }
        return false;
    }

    /**
     * Run as deamon mode.
     *
     * @throws Exception
     */
    protected function daemonize()
    {
        umask(0);
        $pid = pcntl_fork();
        if (-1 === $pid) {
            ProcessException::error("process pool fork process fail");
        } elseif ($pid > 0) {
            exit(0);
        }
        if (-1 === posix_setsid()) {
            ProcessException::error("process pool setsid fail");
        }
        // Fork again avoid SVR4 system regain the control of terminal.
        $pid = pcntl_fork();
        if (-1 === $pid) {
            ProcessException::error("process pool fork process fail");
        } elseif (0 !== $pid) {
            exit(0);
        }
    }

}