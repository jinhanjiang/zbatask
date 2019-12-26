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

use \Exception;

class ProcessException extends Exception
{
	/**
	 * log method support
	 * @var array
	 */
	private static $methodSupport = ['info', 'error', 'debug'];

	/**
	 * the log path
	 * @var string
	 */
	private static $logFile = '';

	/**
	 * the magic __callStatics function
	 * @param string $method
	 * @param array $data
	 * @return void
	 */
	public static function __callStatic($method='', $data=[])
	{
		$data = $data[0];
		if(! in_array($method, self::$methodSupport)) {
			throw new Exception('log method not support', 500);
		}
		self::$logFile = dirname(__DIR__).'/zba.log';
		$msg = self::decorate($method, $data);
		error_log($msg, 3, self::$logFile);
		if('error' === $method) exit;
	}

	/**
	 * decorate log msg
	 * @param string $rank
	 * @param array $msg
	 * @return void
	 */
	private static function decorate($rank = 'info', $msg = "")
	{
		$time = date('Y-m-d H:i:s');
		$pid = posix_getpid();
		$memoryUsage = round(memory_get_usage() / 1024, 2) . ' kb';
		$default = [
			$time, $rank, $pid, $memoryUsage, $msg
		];
		$tmp = '';
		foreach(array_values($default) as $k => $v) {
			$tmp .= ($k == 0 ? '' : ' | ').$v;
		}
		$tmp .= PHP_EOL;
		// echo $tmp; 
		return $tmp;
	}
}