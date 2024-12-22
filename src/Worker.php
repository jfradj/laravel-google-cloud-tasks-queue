<?php

declare(strict_types=1);

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Illuminate\Queue\Worker as LaravelWorker;
use Illuminate\Queue\WorkerOptions;
use Throwable;

/**
 * Custom worker class to handle specific requirements for Google Cloud Tasks.
 *
 * This class modifies the behavior of the Laravel queue worker to better
 * integrate with Google Cloud Tasks, particularly focusing on job timeout
 * handling and graceful shutdowns to avoid interrupting the HTTP lifecycle.
 *
 * Firstly, the 'supportsAsyncSignals', 'listenForSignals', and 'registerTimeoutHandler' methods
 * are protected and called within the queue while(true) loop. We want (and need!) to have that
 * too in order to support job timeouts. So, to make it work, we create a public method that
 * can call the private signal methods.
 *
 * Secondly, we need to override the 'kill' method because it tends to kill the server process (artisan serve, octane),
 * as well as abort the HTTP request from Cloud Tasks. This is not the desired behavior.
 * Instead, it should just fire the WorkerStopped event and return a normal status code.
 */
class Worker extends LaravelWorker
{
    public function daemon($connectionName, $queue, WorkerOptions $options): int
    {
        if ($supportsAsyncSignals = $this->supportsAsyncSignals()) {
            $this->listenForSignals();
        }

        if (isset($this->resetScope)) {
            ($this->resetScope)();
        }

        // First, we will attempt to get the next job off of the queue. We will also
        // register the timeout handler and reset the alarm for this job so it is
        // not stuck in a frozen state forever. Then, we can fire off this job.
        $job = $this->getNextJob(
            $this->manager->connection($connectionName), $queue
        );

        if ($supportsAsyncSignals) {
            $this->registerTimeoutHandler($job, $options);
        }

        // If the daemon should run (not in maintenance mode, etc.), then we can run
        // fire off this job for processing. Otherwise, we will need to sleep the
        // worker so no more jobs are processed until they should be processed.
        if ($job) {
            $this->runJob($job, $connectionName, $options);

            if ($options->rest > 0) {
                $this->sleep($options->rest);
            }
        }

        if ($supportsAsyncSignals) {
            $this->resetTimeoutHandler();
        }

        return static::EXIT_SUCCESS;
    }

    protected function daemonShouldRun(WorkerOptions $options, $connectionName, $queue): bool
    {
        return ! (($this->isDownForMaintenance)() && ! $options->force);
    }

    protected function pauseWorker(WorkerOptions $options, $lastRestart)
    {
        // Nothing to do
    }

    /**
     * @throws Throwable
     */
    protected function runJob($job, $connectionName, WorkerOptions $options): void
    {
        $this->process($connectionName, $job, $options);
    }

    public function process($connectionName, $job, WorkerOptions $options): void
    {
        if ($this->supportsAsyncSignals()) {
            $this->listenForSignals();

            $this->registerTimeoutHandler($job, $options);
        }

        parent::process($connectionName, $job, $options);
    }
}
