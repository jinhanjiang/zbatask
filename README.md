# zbatask
Multi-process task framework for PHP.

Php version requires 5.4+, support php7
The program can run under linux and mac system, you need to install php extension pcntl, posix

You first need to create the task you want to perform, and you can quickly create an instance through the command.

```
./zba --create-default-task-file
```

This will generate the file in the current directory.
task/DefaultTask.php

```
<?php
namespace Task;

use Zba\Process;
use Zba\Task;
use Zba\Timer;

class DefaultTask extends Task
{
	public function __construct() {
		// Number of processes started
		$this->count = 2;

		// If set to false, execute the reload command, which is invalid for the task process
		$this->reload = true;

		// The name of the task
		$this->name = 'DefaultTask'; 

		// The callback task
		$this->closure = $this->run();

		// Time to sleep next time
		$this->nextSleepTime = 0;

		parent::__construct();
	}

	public function onWorkerStart() 
	{
		return function(Process $worker) 
		{
			// if you start multiple processes, please execute the scheduled task in the first process
			if(1 == $worker->id) {
				Timer::add(1, function(){
					file_put_contents(__DIR__."/w.log", date('Y-m-d H:i:s').PHP_EOL, 8);
				});
			}
			file_put_contents(__DIR__."/w-{$worker->pid}.log", $worker->id.'-Worker Start'.PHP_EOL, 8);
		};
	}

	public function onWorkerStop() 
	{
		return function(Process $worker) 
		{
			file_put_contents(__DIR__."/w-{$worker->pid}.log", $worker->id.'-Worker Stop'.PHP_EOL, 8);
		};
	}

	public function run()
	{
		return function(Process $worker) 
		{
			// Execute every 5 minutes, Note: no sleep in the code
			$nowTime = time(); $delayTime = strtotime('+5 sec');
			if($this->nextSleepTime == 0) $this->nextSleepTime = $delayTime;
			if($this->nextSleepTime < $nowTime)
			{
				$this->nextSleepTime = $delayTime;
				file_put_contents(__DIR__."/w-{$worker->pid}.log", date('Y-m-d H:i:s').PHP_EOL, 8);
			}
		};
	}
}
```
You can write your logic in the run method.

You can set some parameters to create a file .env the current directory. (This is not necessary)
```
; current file name is: .env
; This is a sample configuration file
; Comments start with ';', as in php.ini

[config]
display_errors = On
error_reporting = E_ALL & ~E_NOTICE
timezone = Asia/Shanghai
pipe_dir = ./tmp/
max_execute_times = 10000
```
pipe_dir: The process channel file holds the directory, and when the process is created, files are generated in this directory, and the process files are deleted when the process is destroyed. the default directory is: /tmp/.

max_execute_times：After executing N tasks, the process regenerates a new process to prevent the process from taking up too much memory, and the default value of N is: 432000.



Start task:
```
./zba start
```

Restart task:
```
./zba restart
```

Stop task:
```
./zba stop
```

Reload task:（Recreate the process）
```
./zba reload
```

If you perform a queue task, you can check the queue length, reach a certain peak, and dynamically adjust the number of processes based on the start of a task.

You can set the number of tasks dynamically, as follows.
```
./zba reload DefaultTask 3
```


Dynamically adjust the number of processes by task， For example: create a file task/AdjustProcessCountTask.php
```
<?php
namespace Task;

use Zba\ProcessPool;
use Zba\Process;
use Zba\Task;

class AdjustProcessCountTask extends Task
{
	public function __construct() {
		// Set to start only one process
		$this->count = 1;

		// This task is not valid when performing reload
		$this->reload = false;

		// The name of the task
		$this->name = 'AdjustProcessCountTask'; 

		// The callback task
		$this->closure = $this->run();

		// Time to sleep next time
		$this->nextSleepTime = 0;

		parent::__construct();
	}

	public function run()
	{
		return function(Process $worker) 
		{
			// Execute every 5 minutes, Note: no sleep in the code
			$nowTime = time(); $delayTime = strtotime('+5 minute');
			if($this->nextSleepTime == 0) $this->nextSleepTime = $delayTime;
			if($this->nextSleepTime < $nowTime)
			{
				$this->nextSleepTime = $delayTime;

				// In other ways, such as checking the length of the task queue, you get the number of processes to start， we set the process to 5

				$nowCount = 5;

				$processInfos = [];
				if(! $this->hasSetProceess) {
					$processInfos[] = ['name'=>'DefaultTask', 'count'=>$nowCount]; $this->hasSetProceess = true;
				}
                if(count($processInfos) > 0) 
                {
                    // Gets the main process pid
                    $masterPid = is_file(ProcessPool::getConfigFile('pid')) ? file_get_contents(ProcessPool::getConfigFile('pid')) : 0 ;

                    file_put_contents(ProcessPool::getConfigFile('communication'), 
                        json_encode(
                            [
                                'command'=>'setProcessCount', 
                                'processInfos'=>$processInfos
                            ]
                        )
                    );
                    $masterPid && posix_kill($masterPid, SIGUSR1);
                }
			}
		};
	}
}
```
