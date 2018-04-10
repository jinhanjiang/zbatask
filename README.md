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

class DefaultTask extends Task
{
	public function __construct() {
		$this->count = 2;
		$this->name = 'DefaultTask';
		$this->closure = $this->run();
		parent::__construct();
	}

	public function run()
	{
		return function(Process $worker) 
		{
			file_put_contents(__DIR__."/w-{$worker->pid}.log", date('Y-m-d H:i:s').PHP_EOL, 8);
			usleep(1000000);
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
