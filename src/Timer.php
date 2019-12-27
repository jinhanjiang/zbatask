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

class Timer
{
    /**
     * Set of tasks to be performed
     * @var string
     * array(
     *   'runtime'=>array(array(func, $params, $persistent, interval),array(func, $params, $persistent, interval), ...),
     *   'runtime'=>array(array(func, $params, $persistent, interval),array(func, $params, $persistent, interval), ...)
     * )
     */
    private static $tasks = array();
    

    public static function start() {
        pcntl_signal(SIGALRM, array('\Zba\Timer', 'defineSigHandler'), false);
    }

    public static function defineSigHandler($signal = 0) {
        if(SIGALRM == $signal) {
            pcntl_alarm(1); self::loop();
        }
    }

    public static function add($interval, $func, $args = array(), $persistent = true)
    {
        if($interval <= 0 || ! is_callable($func)) return false;
        if(! self::$tasks) pcntl_alarm(1);

        $now = time();
        $runtime = $now + $interval;
        if (! isset(self::$tasks[$runtime])) {
            self::$tasks[$runtime] = array();
        }
        self::$tasks[$runtime][] = array($func, (array)$args, $persistent, $interval);
        return true;
    }

    public static function del() {
        self::$tasks = array(); pcntl_alarm(0);
    }

    public static function loop() {
        if(! self::$tasks) {
            pcntl_alarm(0); return false;
        }
        $now = time();
        foreach (self::$tasks as $runtime => $data) {
            if ($now >= $time) {
                foreach ($data as $index => $task) {
                    list($func, $args, $persistent, $interval) = $task;
                    try {
                        call_user_func_array($func, $args);
                    } catch (\Exception $ex) {
                        ProcessException::error("task:{$func}, msg:{$ex->getMessage()}, file:{$ex->getFile()}, line:{$ex->getLine()}");
                    }
                    if ($persistent) {
                        self::add($interval, $func, $args);
                    }
                }
                unset(self::$tasks[$runtime]);
            }
        }
    }
}