<?php

namespace Zba;

use Zba\Process;
use Zba\ProcessException;
use Closure;

/**
 * worker process class
 */
class Worker extends Process
{
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
	 * the worker hangup function
	 * @param Closure $closure
	 * @return void
	 */
	public function hangup(Closure $closure) 
	{
		while(true)
		{
			// business logic
			$closure($this);

			// check exit flag
			if($this->workerExitFlag) $this->workerExit();

			// check max execute time
			if(self::$currentExecuteTimes >= self::$maxExecuteTimes) $this->workerExit();

			// handle pipe nsg
			if($this->signal = $this->pipeRead()) $this->dispatchSig();

			// increment 1
			++ self::$currentExecuteTimes;

			// prevent CPU utilization from reaching 100%.
			usleep(self::$hangupLoopMicrotime);
		}
	}

	/**
	 * dispatch signal for the worker process
	 * @return void
	 */
	private function dispatchSig()
	{
		switch($this->signal) {
			case 'reload': $this->workerExitFlag = true; break;
			case 'stop': $this->workerExitFlag = true; break;
			default: break;
		}
	}

	/**
	 * exit worker
	 * @return void
	 */
	private function workerExit() {
		$this->clearPipe(); exit;
	}

}