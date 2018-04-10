<?php

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
	public static $version = '0.1.0';

	/**
	 * worker process objects
	 * @var array [Process]
	 */
	public $workers = [];

	/**
	 * tasks 
	 * @var array
	 */
	private $tasks = [];

	/**
	 * the pool for the worker that will be handle by the signal
	 * signal: string reload / stop
	 * pool: array [Process]
	 * @var array
	 */
	private $waitSignalProcessPool = [
		'signal'=>'',
		'pool'=>[]
	];

	/**
	 * master pid save path
	 */
	private $pidFile = '';

	/**
	 * env config
	 * @var array
	 */
	private $env = [];

	/**
	 * support linux signal (The signal received by the main process.)
	 * @var array
	 */
	private $signalSupport = [
		'reload' => SIGUSR1,
		'status' => SIGUSR2,
		'terminate' => SIGTERM,
		'int' => SIGINT
	];

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
		$this->tasks = $newTasks;
        $this->pidFile = dirname(__DIR__) . "/zba.pid";

		$this->setProcessName('Master');
		parent::__construct();
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
		if (false === @file_put_contents($this->pidFile, $masterPid)) {
            ProcessException::error('can not save pid to '.$masterPid);
		}
		$this->pipeCreate();
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
		foreach($this->tasks as $task) {
			foreach(range(1, $task->count) as $n) {
				$this->fork($task);
			}			
		}
	}

	/**
	 * fork a worker process
	 * @return void
	 */
	private function fork($task) 
	{
		/**
		 *
		 * $pid = pcntl_fork();// pcntl_fork 的返回值是一个int值
		 *		             // 如果$pid=-1 fork进程失败
		 *		             // 如果$pid=0 当前的上下文环境为worker
		 *		             // 如果$pid>0 当前的上下文环境为master，这个pid就是fork的worker的pid
		 * 
		 *	             	 // in master context
		 *					 pcntl_wait($status); // pcntl_wait会阻塞，例如直到一个子进程exit
		 *					 // 或者 pcntl_waitpid($pid, $status, WNOHANG); // WNOHANG:即使没有子进程exit，也会立即返回
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
					$worker->pipeCreate();
					$worker->hangup($task->closure);
				} catch(Exception $ex) {
					ProcessException::error("msg:{$ex->getMessage()}, file:{$ex->getFile()}, line:{$ex->getLine()}");
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
					$this->workers[$task->taskId][$pid] = $worker;
				} catch(Exception $ex) {
					ProcessException::error("msg:{$ex->getMessage()}, file:{$ex->getFile()}, line:{$ex->getLine()}");
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
		foreach($this->signalSupport as $sig) {
			pcntl_signal($sig, ['Zba\ProcessPool', 'defineSigHandler']);
		}
	}

	/**
	 * define signal handler
	 * @param integer $signal
	 * @return void
	 */
	public function defineSigHandler($signal = 0)
	{
		switch($signal)
		{
			// reload signal
			case $this->signalSupport['reload']:
				$allWorkers = [];
				// push reload signal to the worker processes from the master process
				foreach($this->workers as $taskId => $workers) {
					foreach($workers as $pid => $worker) {	
						$worker->pipeWrite('stop');
						$allWorkers[$pid] = $worker;
					}
				}
				$this->waitSignalProcessPool = [
					'signal'=>'reload',
					'pool'=>$allWorkers
				];
				break;
			case $this->signalSupport['int']:
			case $this->signalSupport['terminate']://master process exit
				foreach($this->workers as $taskId => $workers)
				{
					foreach($workers as $pid => $worker)
					{
						// clear pipe
						$worker->clearPipe();
						// kill -9 worker process
						posix_kill($worker->pid, SIGKILL);
					}
				}
				// clear pipe
				$this->clearPipe();

				// clear master pid file
				if(is_file($this->pidFile)) @unlink($this->pidFile);
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
		while(true)
		{
            // Calls signal handlers for pending signals again.
            pcntl_signal_dispatch();

			// Prophylactic subprocesses become zombie processes.
			// pcntl_wait($status)
			foreach($this->workers as $taskId => $workers)
			{
				foreach($workers as $pid => $worker) 
				{
					$res = pcntl_waitpid($worker->pid, $status, WNOHANG);
					if($res > 0)
					{
						$worker->clearPipe();
						unset($this->workers[$taskId][$res]);

						if($this->waitSignalProcessPool['signal'] === 'reload')
						{
							if(array_key_exists($res, $this->waitSignalProcessPool['pool'])) {
								unset($this->waitSignalProcessPool['pool'][$res]);
								// fork a new worker
								$this->fork($this->tasks[$taskId]);
							}
						}

					}
				}

				if(empty($this->waitSignalProcessPool['signal'])) {
					$task = $this->tasks[$taskId];
					while(count($this->workers[$task->taskId]) < $task->count) {
						$this->fork($this->tasks[$taskId]);
					}
				}
			}
			if($this->waitSignalProcessPool['signal'] === 'stop')
			{
				// all worker stop then stop the master process
				if(empty($this->waitSignalProcessPool['pool'])) {
					$this->stop();
				}
			}

			// Prevent CPU utilization from reaching 100%.
			usleep(self::$hangupLoopMicrotime);
		}
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