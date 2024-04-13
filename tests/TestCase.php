<?php

declare(strict_types=1);

namespace Tests;

use Google\Cloud\Tasks\V2\Client\CloudTasksClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksServiceProvider;
use Stackkit\LaravelGoogleCloudTasksQueue\Events\TaskCreated;
use Tests\Support\DispatchedJob;

class TestCase extends \Orchestra\Testbench\TestCase
{
    use DatabaseTransactions;

    public CloudTasksClient $client;

    public array $createdTasks = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(TaskCreated::class, function (TaskCreated $event) {
            $this->createdTasks[] = $event->task;
        });
    }

    protected function getPackageProviders($app)
    {
        return [
            CloudTasksServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations()
    {
        // Necessary to test the [failed_jobs] table.

        $this->loadMigrationsFrom(__DIR__.'/../vendor/orchestra/testbench-core/laravel/migrations');
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'testbench');
        $port = env('DB_DRIVER') === 'mysql' ? 3307 : 5432;
        $app['config']->set('database.connections.testbench', [
            'driver' => env('DB_DRIVER', 'mysql'),
            'host' => '127.0.0.1',
            'port' => $port,
            'database' => 'cloudtasks',
            'username' => 'cloudtasks',
            'password' => 'cloudtasks',
            'prefix' => '',
        ]);

        $app['config']->set('cache.default', 'file');
        $app['config']->set('queue.default', 'my-cloudtasks-connection');
        $app['config']->set('queue.connections.my-cloudtasks-connection', [
            'driver' => 'cloudtasks',
            'queue' => 'barbequeue',
            'project' => 'my-test-project',
            'location' => 'europe-west6',
            'handler' => env('CLOUD_TASKS_HANDLER', 'https://docker.for.mac.localhost:8080'),
            'service_account_email' => 'info@stackkit.io',
        ]);

        $app['config']->set('queue.connections.my-other-cloudtasks-connection', [
            ...config('queue.connections.my-cloudtasks-connection'),
            'queue' => 'other-barbequeue',
            'project' => 'other-my-test-project',
        ]);

        $app['config']->set('queue.failed.driver', 'database-uuids');
        $app['config']->set('queue.failed.database', 'testbench');
    }

    protected function setConfigValue($key, $value)
    {
        $this->app['config']->set('queue.connections.my-cloudtasks-connection.'.$key, $value);
    }

    public function dispatch($job): DispatchedJob
    {
        $payload = null;
        $task = null;

        Event::listen(TaskCreated::class, function (TaskCreated $event) use (&$payload, &$task) {
            $request = $event->task->getHttpRequest() ?? $event->task->getAppEngineHttpRequest();
            $payload = $request->getBody();
            $task = $event->task;
        });

        dispatch($job);

        return new DispatchedJob($payload, $task, $this);
    }

    public function withTaskType(string $taskType): void
    {
        switch ($taskType) {
            case 'appengine':
                $this->setConfigValue('handler', null);
                $this->setConfigValue('service_account_email', null);

                $this->setConfigValue('app_engine', true);
                $this->setConfigValue('app_engine_service', 'api');
                break;
            case 'http':
                $this->setConfigValue('app_engine', false);
                $this->setConfigValue('app_engine_service', null);

                $this->setConfigValue('handler', 'https://docker.for.mac.localhost:8080');
                $this->setConfigValue('service_account_email', 'info@stackkit.io');
                break;
        }
    }
}
