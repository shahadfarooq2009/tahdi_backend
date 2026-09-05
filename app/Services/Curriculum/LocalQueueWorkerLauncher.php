<?php



namespace App\Services\Curriculum;



use Illuminate\Support\Facades\App;

use Illuminate\Support\Facades\Cache;

use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Log;

use Symfony\Component\Process\PhpExecutableFinder;

use Symfony\Component\Process\Process;



class LocalQueueWorkerLauncher

{

    private static bool $kickScheduled = false;



    public function kickIfLocalWebRequest(): void

    {

        if (! App::environment('local') || App::runningInConsole()) {

            return;

        }



        if (self::$kickScheduled) {

            return;

        }



        self::$kickScheduled = true;



        app()->terminating(function (): void {

            $this->startBackgroundWorker(force: false);

        });

    }



    /**

     * Force-start a local queue worker (e.g. recover stuck queued jobs).

     */

    public function ensureWorkerRunning(): void

    {

        if (! App::environment('local')) {

            return;

        }

        if ($this->isDevWorkerAlreadyRunning()) {

            return;

        }

        $this->startBackgroundWorker(force: true);

    }



    private function startBackgroundWorker(bool $force = false): void

    {

        if (config('queue.default') !== 'database') {

            return;

        }

        if ($this->isDevWorkerAlreadyRunning()) {

            return;

        }



        $pendingJobs = (int) DB::table('jobs')->whereNull('reserved_at')->count();



        if ($pendingJobs === 0) {

            return;

        }



        $kickKey = 'local_queue_worker_kick';



        if (! $force && Cache::has($kickKey)) {

            Log::debug('Local queue worker kick skipped — recent kick still active', [

                'pending_jobs' => $pendingJobs,

            ]);



            return;

        }



        Cache::put($kickKey, true, now()->addSeconds(45));



        $php = $this->resolvePhpBinary();

        $artisan = base_path('artisan');

        $logFile = storage_path('logs/queue-worker-kick.log');



        Log::info('Local queue worker kick: starting background worker', [

            'php' => $php,

            'pending_jobs' => $pendingJobs,

            'log_file' => $logFile,

        ]);



        $queueList = 'textbook-extraction,textbook-analysis,question-generation,default';

        if (PHP_OS_FAMILY === 'Windows') {

            $this->startWindowsBackgroundWorker($php, $artisan, $logFile, $queueList);



            return;

        }



        $process = new Process(

            [

                $php,

                '-d', 'memory_limit=512M',

                '-d', 'max_execution_time=3600',

                '-d', 'max_input_time=3600',

                $artisan,

                'queue:work',

                'database',

                '--queue='.$queueList,

                '--stop-when-empty',

                '--tries=1',

                '--timeout=3600',

                '--max-time=3600',

            ],

            base_path(),

        );



        try {

            $process->start(function (string $type, string $buffer) use ($logFile): void {

                $line = trim($buffer);



                if ($line === '') {

                    return;

                }



                @file_put_contents($logFile, '['.now()->toDateTimeString()."] {$line}\n", FILE_APPEND);

                Log::debug('Local queue worker output', [

                    'stream' => $type,

                    'line' => $line,

                ]);

            });



            Log::info('Local queue worker started', [

                'pid' => $process->getPid(),

            ]);

        } catch (\Throwable $exception) {

            Log::error('Local queue worker failed to start', [

                'php' => $php,

                'pending_jobs' => $pendingJobs,

                'message' => $exception->getMessage(),

            ]);

        }

    }



    private function startWindowsBackgroundWorker(string $php, string $artisan, string $logFile, string $queueList): void

    {

        $command = sprintf(

            'start /B "" %s -d memory_limit=512M -d max_execution_time=3600 -d max_input_time=3600 %s queue:work database --queue=%s --stop-when-empty --tries=1 --timeout=3600 --max-time=3600 >> %s 2>&1',

            escapeshellarg($php),

            escapeshellarg($artisan),

            escapeshellarg($queueList),

            escapeshellarg($logFile),

        );



        try {

            pclose(popen($command, 'r'));

            Log::info('Local queue worker started via Windows background shell', [

                'log_file' => $logFile,

            ]);

        } catch (\Throwable $exception) {

            Log::error('Local queue worker failed to start on Windows', [

                'message' => $exception->getMessage(),

            ]);

        }

    }



    private function resolvePhpBinary(): string

    {

        $candidates = [

            env('PHP_BINARY'),

            'C:\\xampp\\php\\php.exe',

            (new PhpExecutableFinder)->find(false),

            PHP_BINARY,

        ];



        foreach ($candidates as $candidate) {

            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {

                return $candidate;

            }

        }



        return PHP_BINARY;

    }

    private function isDevWorkerAlreadyRunning(): bool

    {

        $pidFile = storage_path('app/queue-worker-dev.pid');

        if (! is_file($pidFile)) {

            return false;

        }

        $pid = (int) trim((string) file_get_contents($pidFile));

        if ($pid <= 0) {

            return false;

        }

        if (PHP_OS_FAMILY === 'Windows') {

            $result = shell_exec('tasklist /FI "PID eq '.$pid.'" /NH 2>NUL');

            return is_string($result) && str_contains($result, (string) $pid);

        }

        return function_exists('posix_kill') && @posix_kill($pid, 0);

    }

}


