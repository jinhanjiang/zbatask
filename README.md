# zbatask
Multi-process task framework for PHP.

Php version requires 5.4+, support php7
The program can run under linux and mac system, you need to install php extension pcntl, posix


### Checkout project

Check out the address as follows
```
git clone https://github.com/jinhanjiang/zbatask
```


### Quickly generate task template files

First you need to create the task you want to perform, and you can quickly create an instance through the command.

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
				Timer::add(1, function() use($worker) {

					// We can adjust process count, such as checking the length of the task queue, and set the number of processes according to the the length
					// $pcount = rand(1, 5);
					// $worker->adjustProcessCount($pcount);

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

### Operation parameter setting

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


You can set the specified task to run or not to run, Set the following constant values in config.php
```
<?php
define('ALLOWED_TASKS', json_encode(array('DefaultTask')));
define('DISABLED_TASKS', json_encode(array('Default1Task', 'Default2Task')));
```


### Task start/stop/restart/reload

Start task:
```
./zba start

# Terminal doest not exit
./zba startt 
```

Stop task:
```
./zba stop
```

Restart task:
```
./zba restart
```

Reload task:（Recreate the process）
```
./zba reload
```


### Dynamically adjust the number of processes

If you perform a queue task, you can check the queue length, reach a certain peak, and dynamically adjust the number of processes based on the start of a task.

You can set the number of tasks dynamically, as follows.
```
./zba reload DefaultTask 3
```

Dynamically adjust the number of processes by task，
```
<?php

	.....

	public function onWorkerStart() 
	{
		return function(Process $worker) 
		{
			// if you start multiple processes, please execute the scheduled task in the first process
			if(1 == $worker->id) {
				Timer::add(10, function() use($worker){
					
					// We can adjust process count, such as checking the length of the task queue, and set the number of processes according to the the length
					
					$pcount = rand(1, 5);
					$worker->adjustProcessCount($pcount);

				});
			}
		};
	}

	.....
}
```


Example2
```
<?php
namespace Task;

use \RedisClient;
use \RedisMQClient;
use Zba\Process;
use Zba\Task;
use Zba\Timer;

/**
 * This is example
 */
class ExampleTask extends Task
{
	public function __construct() {
		$this->count = 2; // Minimum number of processes to start
		$this->maxCount = 5; // Maximum number of processes started
		$this->queryLimitCount = 0; // The number of continuous queries to limit data
		$this->isMaxProcess = false; // Whether to adjust to the maximum process
		// Other configurate
		$this->nextSleepTime = 0;
		$this->name = 'ExampleTask';
		$this->queueName = 'QUEUE_TASK_EXAMPLE';
		$this->closure = $this->run();
		parent::__construct();
	}

	public function onWorkerStart() 
	{
		return function(Process $worker) 
		{
			// Start the timer in the first process
			if(1 == $worker->id) 
			{
				Timer::add(5, function() use($worker) {

					// If the task queue length is 0, there is no data in the queue, and needs to be supplemented
					$qlen = RedisMQClient::me()->size($this->queueName);
					if($qlen == 0) {
						// Get lock
						$lockVal = md5($worker->pid.'|'.getMacAddr()); $lockName = $this->queueName.'_LOCK';
						$flag = RedisClient::me()->setnx($lockName, $lockVal, 15); // The lock is automatically released after 15 seconds
						if($flag) // Successed lock
						{
							$limit = 500;
							// Here you need to instantiate the database object
							$tasks = $db->select("SELECT * FROM `XXX` WHERE `xxx`='xxx' LIMIT {$limit}");

							if(is_array($tasks) && ($ct = count($tasks)) > 0)
							{
								$qdata = array(
									'id'=>'xxx'
								)
								// Put the queried data into the queue 
								RedisMQClient::me()->put($this->queueName, $qdata)
							}

							// Set whether to increase the number of processes to the maximum
							if($limit == $ct) { // Continue to query limit data, then start the maximum number of processes
								if($this->fullProcessCount < 5) $this->fullProcessCount ++;
							}
							else {
								if($this->fullProcessCount > 0) $this->fullProcessCount --;
							}

							unset($tasks);
						}
					}
					
				});

				// Timing adjustment of the number of processes
				Timer::add(15, function() use($worker) {
					if($this->fullProcessCount > 0 && ! $this->isFullProcess) {
						// The maximum number of processes, and the current state is not the maximum process
						$worker->adjustProcessCount($this->maxCount);
					}
					else if($this->fullProcessCount == 0 && $this->isFullProcess) {
						// The minimum number of processes, and the current state is not the minimum process
						$worker->adjustProcessCount($this->count);
					}
				});
			}
		};
	}

	public function onWorkerStop() 
	{
		return function(Process $worker) 
		{
			// file_put_contents(__DIR__."/w-{$worker->pid}.log", $worker->id.'-Worker Stop'.PHP_EOL, 8);
		};
	}

	public function run()
	{
		return function(Process $worker) 
		{
			$nowTime = time(); $delayTime = strtotime('+1 sec'); // Porcesses seelp 1s
			if($this->nextSleepTime == 0) $this->nextSleepTime = $delayTime;
			if($this->nextSleepTime < $nowTime)
			{
				$qdata = RedisMQClient::me()->get($this->queueName);
				if($qdata && $qdata['id'])
				{
					// Process processing business logic

				}
				
				// file_put_contents(__DIR__."/w-{$worker->pid}.log", date('Y-m-d H:i:s').PHP_EOL, 8);
				$this->nextSleepTime = strtotime('+1 sec');
			}
		};
	}
}
```

### Build Docker image

We can package the image based on the current zbatask version, for example:

```
docker build -t zbatask:0.1.6 .
```

Dockerfile
```
FROM php:7.4-cli-alpine
MAINTAINER JinHanJiang <jinhanjiang@foxmail.com>
WORKDIR /opt
RUN sed -i 's/dl-cdn.alpinelinux.org/mirrors.ustc.edu.cn/g' /etc/apk/repositories && \
    apk update && \
    apk add --no-cache --virtual mypacks \
        autoconf \  
        build-base \
        linux-headers \
        file \
        g++ \
        gcc \
        make \
        pkgconf \
        re2c \
        coreutils && \
    apk add --no-cache \ 
        curl-dev \
        libevent-dev \
        libressl-dev \
        openldap-dev
RUN set -x && \
    docker-php-ext-install sockets pcntl pdo_mysql curl && \
    pecl install event && \
    docker-php-ext-enable --ini-name event.ini event && \
    pecl install redis && \
    docker-php-ext-enable --ini-name redis.ini redis && \
    mkdir -p /opt/zbatask
RUN apk del mypacks    

COPY src/ /opt/zbatask/src
COPY task/ /opt/zbatask/task
COPY zba /opt/zbatask/zba

VOLUME ["/opt/zbatask"]
CMD ["php", "-f", "/opt/zbatask/zba", "startt"]
```

If we use it in combination with the doba framework, we can package a new image

```
docker build -t demo:0.1 .
```

Dockerfile
```
FROM zbatask:0.1.6

RUN set -x && \
    apk add --no-cache \ 
        git && \
    rm -f /opt/zbatask/task/*.php && \
    cd /opt && \
    git clone https://github.com/jinhanjiang/doba

ADD systask/config.php /opt/zbatask/config.php
ADD systask/.env /opt/zbatask/.env
COPY systask/task/ /opt/zbatask/task
COPY common /opt/common
```



