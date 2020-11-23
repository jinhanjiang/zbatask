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

use Zba\ProcessException;
use Closure;

/**
 * process abstract class
 */
abstract class Process
{
	/**
	 * current process name, such as master or worker
	 * @var string
	 */
	public $name = 'none';

	/**
	 * @version 0.1.1
	 * tag id
	 *
	 * @var int
	 */
	public $id = 1;

	/**
	 * process id
	 * @var int
	 */
	public $pid = '';

	/**
	 * pipe name
	 * @var string
	 */
	protected $pipeName = '';

	/**
	 * pipe mode
	 * @var integer
	 */
	protected $pipeMode = 0777;

	/**
	 * pipe name prefix
	 * @var string
	 */
	protected $pipeNamePrefix = 'zba.pipe';

	/**
	 * the folder for pipe file store
	 * @var string
	 */
	protected $pipeDir = '/tmp/';

	/**
	 * pipe file path
	 * @var string
	 */
	protected $pipeFile = '';

	/**
	 * @version 0.1.4
	 * pipe temp cache
	 *
	 * @var string
	 */
	protected $pipeTemp = '';

	/**
	 * the byte size read from pipe
	 * @var integer
	 */
	protected $readPipeSize = 1024;

	/**
	 * worker process exit flag
	 * @var boolean
	 */
	protected $workerExitFlag = false;

	/**
	 * hangup sleep time unit: microsecond /μs
	 * default 200000μs
	 * @var int
	 */
	protected static $hangupLoopMicrotime = 200000;

	/**
	 * max execute times
	 * default 5 * 60 * 60 * 24 = 432000
	 * @var int
	 */
	protected static $maxExecuteTimes = 432000;

	/**
	 * current execute times
	 * default 0
	 * @var int
	 */
	protected static $currentExecuteTimes = 0;


	/**
	 * construct function
	 * @param array $config
	 */
	public function __construct() {
		if(empty($this->pid)) {
			$this->pid = posix_getpid();
		}
		$this->pipeName = $this->pipeNamePrefix . '.'. $this->pid;
		$this->pipeFile = $this->pipeDir . $this->pipeName;
	}

	/**
	 * hangup abstract function
	 * @param Closure $closure
	 */
	abstract protected function hangup(Closure $closure);

	/**
	 * create pipe
	 * @return void
	 */
	public function pipeCreate() {
		if(! file_exists($this->pipeFile)) {
			if(! posix_mkfifo($this->pipeFile, $this->pipeMode)) {
				ProcessException::error("{$this->name} pipe create {$this->pipeFile}");
				exit;
			}
			chmod($this->pipeFile, $this->pipeMode);
		}
	}

	/**
	 * write msg to the pipe
	 * @return void
	 */
	public function pipeWrite($signal = '', $pipeFile = '') {
		if(! $pipeFile) $pipeFile = $this->pipeFile;
		if(! file_exists($pipeFile)) return false;
		$pipe = fopen($pipeFile, 'w');
		if(! $pipe) {
			ProcessException::error("{$this->name} pipe open {$this->pipeFile}");
			return false;
		}
		$res = fwrite($pipe, $signal."\n");
		if(! $res) {
			ProcessException::error("{$this->name} pipe write {$this->pipeFile} signal:{$signal}, res:{$res}");
			return false;
		}
		if(! fclose($pipe)) {
			ProcessException::error("{$this->name} pipe close {$this->pipeFile}");
		}
	}

	/**
	 * read msg from the pipe
	 * @param void
	 */
	public function pipeRead(Closure $closure = null) {
		// check pipe
		if(! file_exists($this->pipeFile)) return false;
		// open pipe
		do {
			// fopen() will block if the file to be opened is a fifo, 
			// This is the whether it's opened in "r" or "w" mode
			// (See man 7 fifo: this is the correct , default behaviour; although Linux supports non-blocking fopen() of a fifo, php does't ).
			// The "r+" allows fopen to reutrn immediately regardiess of external writer channel
			$workerPipe = fopen($this->pipeFile, "r+");
			usleep(self::$hangupLoopMicrotime);
		} while(! $workerPipe);

		// set pipe switch a non blocking stream
		// prevent fread / fwrite blocking
		stream_set_blocking($workerPipe, false);
		// read data from pipe
		while(! feof($workerPipe)) {
			$pipeContent .= fread($workerPipe, $this->readPipeSize);
			$streamMetaData = stream_get_meta_data($workerPipe);
	        if($streamMetaData['unread_bytes'] <= 0) break;
		}
		// close pipe
		fclose($workerPipe);
		// Parse the content read from the pipe
		$signals = $this->parsePipeContent($pipeContent);
		// Processing parsed content
		if($signals && $closure && is_callable($closure)) {
			$closure($signals);
		}
		return true;
	}

	/**
	 * @version 0.1.4
	 * Parse the content read from the pipe
	 *
	 * @param $content 
	 * @return array
	 */
	public function parsePipeContent($content)
	{
		$content = $this->pipeTemp.$content;
		$endstr = ''; $datas = array(); 
		$nowLoop = 0; $maxLoop = 1000;
		while(true) {
			if(++$nowLoop > $maxLoop) break; // Prevent endless loop
			$pos = strpos($content, "\n");
			if(false === $pos) {
				$this->pipeTemp = $content; break;
			}
			$datas[] = substr($content, 0, $pos);
			$content = substr($content, $pos + 1);
		}
		return $datas;
	}

	/**
	 * clear pipe file
	 * @return viod
	 */
	public function clearPipe() {
		$this->workerExitFlag = true;
		if(file_exists($this->pipeFile) && ! unlink($this->pipeFile)) {
			ProcessException::error("{$this->name} pipe clear {$this->pipeFile}");
			return false;
		}
		shell_exec("rm -rf {$this->pipeFile}");
		return true;
	}

	/**
	 * stop the process
	 * @return void
	 */
	public function stop() {
		$this->clearPipe();
		if(! posix_kill($this->pid, SIGKILL)) {
			ProcessException::error("{$this->name} stop {$this->pipeFile}");
			return false;
		}
		return true;
	}

	/**
	 * @version 0.1.4
	 * get pipe file
	 */
	protected function getPipeFile() {
		return $this->pipeFile;
	}

	/**
	 * set this process name
	 * @return void
	 */
	protected function setProcessName($name = '') {
		if(PHP_OS == 'Darwin') return false;
		// >=php 5.5
		$this->name = $name?:$this->name;
        if (function_exists('cli_set_process_title')) {
            @cli_set_process_title($this->name);
        } // Need proctitle when php<=5.5 .
        elseif (extension_loaded('proctitle') && function_exists('setproctitle')) {
            @setproctitle($this->name);
        }
	}

}