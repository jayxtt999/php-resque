<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Resque;

use Exception;

/**
 * Timer.
 *
 * example:
 * \Resque\Timer::add($time_interval, callback, array($arg1, $arg2..));
 */
class Timer
{
    /**
     * Tasks that based on ALARM signal.
     * [
     *   run_time => [[$func, $args, $persistent, time_interval, $uniqID],[$func, $args, $persistent, time_interval, $uniqID],..]],
     *   run_time => [[$func, $args, $persistent, time_interval, $uniqID],[$func, $args, $persistent, time_interval, $uniqID],..]],
     *   ..
     * ]
     *
     * @var array
     */
    protected static $_tasks = array();

    /**
     * event
     *
     * @var \Workerman\Events\EventInterface
     */
    protected static $_event = null;

    /**
     * Init.
     *
     * @param \Workerman\Events\EventInterface $event
     * @return void
     */
    public static function init($event = null)
    {
        if ($event) {
            self::$_event = $event;
        } else {
            if (function_exists('pcntl_signal')) {
                pcntl_signal(SIGALRM, array('\Resque\Timer', 'signalHandle'), false);
            }
        }
    }

    /**
     * ALARM signal handler.
     *
     * @return void
     */
    public static function signalHandle()
    {
        if (!self::$_event) {
            pcntl_alarm(1);
            self::tick();
        }
    }

    /**
     * Add a timer.
     *
     * @param float    $time_interval  回调间隔
     * @param callable $func  回调函数
     * @param mixed    $args  回调参数
     * @param bool     $persistent 是否周期性任务
     * @param string   $uniqID uniqID
     * @return int/false
     */
    public static function add($time_interval, $func, $args = array(), $persistent = true, $uniqID = null)
    {
        if ($time_interval <= 0) {
            WorkerManager::log(new Exception("bad time_interval"));
            return false;
        }

        if (self::$_event) {
            return self::$_event->add($time_interval,
                $persistent ? \Workerman\Events\EventInterface::EV_TIMER : \Workerman\Events\EventInterface::EV_TIMER_ONCE, $func, $args);
        }

        if (!is_callable($func)) {
            WorkerManager::log(new Exception("not callable"));
            return false;
        }

        if (empty(self::$_tasks)) {
            pcntl_alarm(1);
        }

        $time_now = time();
        $run_time = $time_now + $time_interval;
        $uniqID = $uniqID ?: (\uniqid("timer_") . \rand(11111, 99999));
        if (!isset(self::$_tasks[$run_time])) {
            self::$_tasks[$run_time] = array();
        }
        self::$_tasks[$run_time][] = array($func, (array)$args, $persistent, $time_interval, $uniqID);
        return $uniqID;
    }


    /**
     * Tick.
     *
     * @return void
     */
    public static function tick()
    {
        if (empty(self::$_tasks)) {
            pcntl_alarm(0);
            return;
        }

        $time_now = time();
        foreach (self::$_tasks as $run_time => $task_data) {
            if ($time_now >= $run_time) {
                foreach ($task_data as $index => $one_task) {
                    $task_func     = $one_task[0];
                    $task_args     = $one_task[1];
                    $persistent    = $one_task[2];
                    $time_interval = $one_task[3];
                    $uniqID        = $one_task[4];
                    try {
                        call_user_func_array($task_func, $task_args);
                    } catch (\Exception $e) {
                        WorkerManager::log($e);
                    }
                    if ($persistent) {
                        self::add($time_interval, $task_func, $task_args, $persistent, $uniqID);
                    }
                }
                unset(self::$_tasks[$run_time]);
            }
        }
    }

    /**
     * Remove a timer.
     *
     * @param mixed $timer_id
     * @return bool
     */
    public static function del($timer_id)
    {
        if (self::$_event) {
            return self::$_event->del($timer_id, \Workerman\Events\EventInterface::EV_TIMER);
        }

        foreach (self::$_tasks as $run_time => &$task_data) {
            foreach ($task_data as $index => $one_task) {
                if ($one_task[4] === $timer_id) {
                    unset($task_data[$index]);
                }
            }
        }

        return true;
    }

    /**
     * Remove all timers.
     *
     * @return void
     */
    public static function delAll()
    {
        self::$_tasks = array();
        pcntl_alarm(0);
        if (self::$_event) {
            self::$_event->clearAllTimer();
        }
    }

    /**
     * 定时器计数
     * @return int
     */
    public static function count()
    {
        return count(self::$_tasks);
    }
}
