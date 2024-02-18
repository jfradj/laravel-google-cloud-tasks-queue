<?php

declare(strict_types=1);

namespace Tests;

use Google\Cloud\Tasks\V2\Task;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksApi;
use Stackkit\LaravelGoogleCloudTasksQueue\LogFake;
use Tests\Support\EncryptedJob;
use Tests\Support\FailingJob;
use Tests\Support\FailingJobWithMaxTries;
use Tests\Support\FailingJobWithMaxTriesAndRetryUntil;
use Tests\Support\FailingJobWithRetryUntil;
use Tests\Support\SimpleJob;

class TaskHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CloudTasksApi::fake();
    }

    /**
     * @test
     */
    public function it_can_run_a_task()
    {
        // Arrange
        Log::swap(new LogFake());
        Event::fake([JobProcessing::class, JobProcessed::class]);

        // Act
        $this->dispatch(new SimpleJob())->runWithoutExceptionHandler();

        // Assert
        Log::assertLogged('SimpleJob:success');
    }

    /**
     * @test
     */
    public function it_can_run_a_task_using_the_task_connection()
    {
        // Arrange
        Log::swap(new LogFake());
        Event::fake([JobProcessing::class, JobProcessed::class]);
        $this->app['config']->set('queue.default', 'non-existing-connection');

        // Act
        $job = new SimpleJob();
        $job->connection = 'my-cloudtasks-connection';
        $this->dispatch($job)->runWithoutExceptionHandler();

        // Assert
        Log::assertLogged('SimpleJob:success');
    }

    /**
     * @test
     */
    public function after_max_attempts_it_will_log_to_failed_table()
    {
        // Arrange
        $job = $this->dispatch(new FailingJobWithMaxTries());

        // Act & Assert
        $this->assertDatabaseCount('failed_jobs', 0);

        $releasedJob = $job->runAndGetReleasedJob();
        $this->assertDatabaseCount('failed_jobs', 0);

        $releasedJob = $releasedJob->runAndGetReleasedJob();
        $this->assertDatabaseCount('failed_jobs', 0);

        $releasedJob->run();
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    /**
     * @test
     */
    public function after_max_attempts_it_will_delete_the_task()
    {
        // Arrange

        $job = $this->dispatch(new FailingJob());

        // Act & Assert
        $releasedJob = $job->runAndGetReleasedJob();
        CloudTasksApi::assertDeletedTaskCount(1);
        CloudTasksApi::assertTaskDeleted($job->task->getName());
        $this->assertDatabaseCount('failed_jobs', 0);

        $releasedJob = $releasedJob->runAndGetReleasedJob();
        CloudTasksApi::assertDeletedTaskCount(2);
        CloudTasksApi::assertTaskDeleted($job->task->getName());
        $this->assertDatabaseCount('failed_jobs', 0);

        $releasedJob->run();
        CloudTasksApi::assertDeletedTaskCount(3);
        CloudTasksApi::assertTaskDeleted($job->task->getName());
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    /**
     * @test
     *
     * @testWith [{"now": "2020-01-01 00:00:00", "try_at": "2020-01-01 00:00:00", "should_fail": false}]
     *           [{"now": "2020-01-01 00:00:00", "try_at": "2020-01-01 00:04:59", "should_fail": false}]
     *           [{"now": "2020-01-01 00:00:00", "try_at": "2020-01-01 00:05:00", "should_fail": true}]
     */
    public function after_max_retry_until_it_will_log_to_failed_table_and_delete_the_task(array $args)
    {
        // Arrange
        $this->travelTo($args['now']);

        $job = $this->dispatch(new FailingJobWithRetryUntil());

        // Act
        $releasedJob = $job->runAndGetReleasedJob();

        // Assert
        CloudTasksApi::assertDeletedTaskCount(1);
        CloudTasksApi::assertTaskDeleted($job->task->getName());
        $this->assertDatabaseCount('failed_jobs', 0);

        // Act
        $this->travelTo($args['try_at']);
        $releasedJob->run();

        // Assert
        $this->assertDatabaseCount('failed_jobs', $args['should_fail'] ? 1 : 0);
    }

    /**
     * @test
     */
    public function test_unlimited_max_attempts()
    {
        // Arrange

        // Act
        $job = $this->dispatch(new FailingJob());
        foreach (range(1, 50) as $attempt) {
            $job->run();
            CloudTasksApi::assertDeletedTaskCount($attempt);
            CloudTasksApi::assertTaskDeleted($job->task->getName());
            $this->assertDatabaseCount('failed_jobs', 0);
        }
    }

    /**
     * @test
     */
    public function test_max_attempts_in_combination_with_retry_until()
    {
        // Arrange

        $this->travelTo('2020-01-01 00:00:00');

        $job = $this->dispatch(new FailingJobWithMaxTriesAndRetryUntil());

        // When retryUntil is specified, the maxAttempts is ignored.

        // Act & Assert

        // The max attempts is 3, but the retryUntil is set to 5 minutes from now.
        // So when we attempt the job 10 times, it should still not fail.
        foreach (range(1, 10) as $attempt) {
            $job = $job->runAndGetReleasedJob();
            CloudTasksApi::assertDeletedTaskCount($attempt);
            CloudTasksApi::assertTaskDeleted($job->task->getName());
            $this->assertDatabaseCount('failed_jobs', 0);
        }

        // Now we travel to 5 minutes from now, and the job should fail.
        $this->travelTo('2020-01-01 00:05:00');
        $job->run();
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    /**
     * @test
     */
    public function it_can_handle_encrypted_jobs()
    {
        // Arrange
        Log::swap(new LogFake());

        // Act
        $job = $this->dispatch(new EncryptedJob());
        $job->run();

        // Assert
        $this->assertStringContainsString(
            'O:26:"Tests\Support\EncryptedJob"',
            decrypt($job->payloadAsArray('data.command')),
        );

        Log::assertLogged('EncryptedJob:success');
    }

    /**
     * @test
     */
    public function failing_jobs_are_released()
    {
        // Arrange
        Event::fake(JobReleasedAfterException::class);

        // Act
        $job = $this->dispatch(new FailingJob());

        CloudTasksApi::assertDeletedTaskCount(0);
        CloudTasksApi::assertCreatedTaskCount(1);
        CloudTasksApi::assertTaskNotDeleted($job->task->getName());

        $job->run();

        CloudTasksApi::assertDeletedTaskCount(1);
        CloudTasksApi::assertCreatedTaskCount(2);
        CloudTasksApi::assertTaskDeleted($job->task->getName());
        Event::assertDispatched(JobReleasedAfterException::class, function ($event) {
            return $event->job->attempts() === 1;
        });
    }

    /**
     * @test
     */
    public function attempts_are_tracked_internally()
    {
        // Arrange
        Event::fake(JobReleasedAfterException::class);

        // Act & Assert
        $job = $this->dispatch(new FailingJob());
        $job->run();
        $releasedJob = null;

        Event::assertDispatched(JobReleasedAfterException::class, function ($event) use (&$releasedJob) {
            $releasedJob = $event->job->getRawBody();

            return $event->job->attempts() === 1;
        });

        $this->runFromPayload($releasedJob);

        Event::assertDispatched(JobReleasedAfterException::class, function ($event) {
            return $event->job->attempts() === 2;
        });
    }

    /**
     * @test
     */
    public function retried_jobs_get_a_new_name()
    {
        // Arrange
        Event::fake(JobReleasedAfterException::class);
        CloudTasksApi::fake();

        // Act & Assert
        Carbon::setTestNow(Carbon::createFromTimestamp(1685035628));
        $job = $this->dispatch(new FailingJob());
        Carbon::setTestNow(Carbon::createFromTimestamp(1685035629));

        $job->run();

        // Assert
        CloudTasksApi::assertCreatedTaskCount(2);
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            [$timestamp] = array_reverse(explode('-', $task->getName()));

            return $timestamp === '1685035628000';
        });
        CloudTasksApi::assertTaskCreated(function (Task $task): bool {
            [$timestamp] = array_reverse(explode('-', $task->getName()));

            return $timestamp === '1685035629000';
        });
    }
}
