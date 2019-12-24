<?php

namespace Zba;

class Task
{
	/**
	 * current task unique id
	 * @var string
	 */
	public $taskId = '';
	/** 
	 * task process count
	 * @var int
	 */
	public $count = 1;

	/** 
	 * task process name
	 * @var string
	 */
	public $name = 'none';

	/**
	 * The current file address, the subclass file and the parent class file address are different.
	 * @var string
	 */
	public $currentFile = '';

	/**
	 * The method that is exectued before the process start executing the task.
	 * currently only executed once in the current process
	 */
	public $onWorkerStart = null;

	/** 
	 * The method that is exectued after the process end executing the task.
	 * currently only executed once in the current process
	 */ 
	public $onWorkerStop = null;

	/**
	 * callback function
	 * @var callback
	 */
	public $closure = null;

	public function __construct() {
        $backtrace = debug_backtrace();
        $this->currentFile = $backtrace[0]['file'];
        if('none' === $this->name) {
        	$this->name = basename($this->currentFile, '.php');
        }
		$this->taskId = md5($this->currentFile);
		$this->onWorkerStart = $this->onWorkerStart();
		$this->onWorkerStop = $this->onWorkerStop();
	}

	public function onWorkerStart() {
		return function(Process $worker) {};
	}
	
	public function onWorkerStop() {
		return function(Process $worker) {};
	}
}