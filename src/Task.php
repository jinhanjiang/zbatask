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
	public $name = __CLASS__;

	/**
	 * callback function
	 * @var callback
	 */
	public $closure = null;

	public function __construct() {
		$this->taskId = spl_object_hash($this);
	}
}