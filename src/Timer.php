<?php

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

    public static function defineSigHandler() {
        pcntl_alarm(1); self::tick();
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

    public static function tick() {
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

    public static function del() {
        self::$tasks = array(); pcntl_alarm(0);
    }
}