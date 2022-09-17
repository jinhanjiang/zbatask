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

use Zba\Timer;
use Zba\Process;
use Zba\ProcessException;
use Closure;

/**
 * worker process class
 */
class Worker extends Process
{
	/**
	 * @version 0.1.4
	 * The task id
	 */
	public $taskId = '';

	/**
	 * @version 0.1.1
	 * The method that is exectued before the process start executing the task.
	 * currently only executed once in the current process
	 */
	public $onWorkerStart = null;

	/** 
	 * @version 0.1.1
	 * The method that is exectued after the process end executing the task.
	 * currently only executed once in the current process
	 */ 
	public $onWorkerStop = null;


	/**
	 * @version 0.1.4
	 * master process pipe file path
	 * @var string
	 */
	public $masterProcessPipeFile = '';

	/**
	 * construct function
	 * @param array $config ['pid'=>1222, 'type'=>'worker', 'pipe_dir'=>'/tmp/']
	 */
	public function __construct($config = []) {
		$this->setProcessName(isset($config['name']) ? $config['name'] : 'worker');
		$this->pid = isset($config['pid']) ? $config['pid'] : $this->pid;
		$this->pipeDir = isset($config['pipe_dir']) && is_dir($config['pipe_dir']) ? $config['pipe_dir'] : $this->pipeDir;

		self::$maxExecuteTimes = isset($config['max_execute_times']) && $config['max_execute_times'] > 0 
			? $config['max_execute_times'] : self::$maxExecuteTimes;
		parent::__construct();
	}

	/**
	 * @version 0.1.5
     * register signal handler
     * @return void
     */
    private function registerSigHandler()
    {
        foreach(ProcessPool::$signalSupport as $sig) {
            pcntl_signal($sig, ['\Zba\Worker', 'defineSigHandler'], false);
        }
    }
	
	/**
	 * @version 0.1.5
     * define signal handler
     * @param integer $signal
     * @return void
     */
	public static function defineSigHandler($signal = 0) {
		// do not handle signals
    }

	/**
	 * the worker hangup function
	 * @param Closure $closure
	 * @return void
	 */
	public function hangup(Closure $closure) 
	{
		// register quit sigle handler
		$this->registerSigHandler();
		// register timer sigle handler
		Timer::start();
		if($this->onWorkerStart && is_callable($this->onWorkerStart)) {
			try {
                call_user_func($this->onWorkerStart, $this);
            } catch (\Exception $ex) {
            	ProcessException::info("worker: {$this->name}, onWorkerStart, msg:{$ex->getMessage()}, file:{$ex->getFile()}, line:{$ex->getLine()}");
            }
		} 

		// deal with signal for the worker process
		$dealWithSignal = function($signals=array()) {
			if($this->workerExitFlag) return false;
			// parse signal
			if($signals && is_array($signals))
				foreach($signals as $signal) {
				if('stop' == $signal) {
					$this->workerExitFlag = true; break;
				}
			}
		};
		while(true)
		{
            // Calls signal handlers for pending signals again.
            pcntl_signal_dispatch();

			// business logic
			$closure($this);

			// check exit flag
			if($this->workerExitFlag) $this->workerExit();

			// check max execute time
			if(self::$currentExecuteTimes >= self::$maxExecuteTimes) $this->workerExit();

			// handle pipe msg
			$this->pipeRead($dealWithSignal);
			
			// increment 1
			++ self::$currentExecuteTimes;

			// prevent CPU utilization from reaching 100%.
			usleep(self::$hangupLoopMicrotime);
		}
	}

	/**
	 * exit worker
	 * @return void
	 */
	private function workerExit() {
		$this->clearPipe(); 
		if($this->onWorkerStop && is_callable($this->onWorkerStop)) {
			try {
                call_user_func($this->onWorkerStop, $this);
            } catch (\Exception $ex) {
            	ProcessException::info("worker: {$this->name}, onWorkerStop, msg:{$ex->getMessage()}, file:{$ex->getFile()}, line:{$ex->getLine()}");
            }
		}
		exit;
	}

	/**
	 * adjust process count
	 * @return void
	 */
	public function adjustProcessCount($count=1) {
		if(! $this->masterProcessPipeFile) return false;
		$this->pipeWrite(json_encode(
			array(
				'action'=>'setProcessCount',
				'taskId'=>$this->taskId,
				'count'=>$count,
			)
		), $this->masterProcessPipeFile);
		return true;
	}

}