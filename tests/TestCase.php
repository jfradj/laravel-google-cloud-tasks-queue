<?php

declare(strict_types=1);

namespace Tests;

use Google\ApiCore\ApiException;
use Google\Cloud\Tasks\V2\Client\CloudTasksClient;
use Google\Cloud\Tasks\V2\Task;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Stackkit\LaravelGoogleCloudTasksQueue\Events\TaskCreated;
use Stackkit\LaravelGoogleCloudTasksQueue\TaskHandler;

class TestCase extends \Orchestra\Testbench\TestCase
{
    use DatabaseTransactions;

    /**
     * @var CloudTasksClient
     */
    public $client;

    public string $releasedJobPayload;

    public array $createdTasks = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withFactories(__DIR__.'/../factories');

        Event::listen(TaskCreated::class, function (TaskCreated $event) {
            $this->createdTasks[] = $event->task;
        });

        Event::listen(
            JobReleasedAfterException::class,
            function ($event) {
                $this->releasedJobPayload = $event->job->getRawBody();
            }
        );
    }

    /**
     * Get package providers.  At a minimum this is the package being tested, but also
     * would include packages upon which our package depends, e.g. Cartalyst/Sentry
     * In a normal app environment these would be added to the 'providers' array in
     * the config/app.php file.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [
            \Stackkit\LaravelGoogleCloudTasksQueue\CloudTasksServiceProvider::class,
        ];
    }

    /**
     * Define database migrations.
     *
     * @return void
     */
    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__.'/../migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/orchestra/testbench-core/laravel/migrations');
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        foreach (glob(storage_path('framework/cache/data/*/*/*')) as $file) {
            unlink($file);
        }

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
        $app['config']->set('queue.failed.driver', 'database-uuids');
        $app['config']->set('queue.failed.database', 'testbench');

        $disableDashboardPrefix = 'when_dashboard_is_disabled';

        $testName = method_exists($this, 'name') ? $this->name() : $this->getName();
        if (substr($testName, 0, strlen($disableDashboardPrefix)) === $disableDashboardPrefix) {
            $app['config']->set('cloud-tasks.dashboard.enabled', false);
        } else {
            $app['config']->set('cloud-tasks.dashboard.enabled', true);
        }
    }

    protected function setConfigValue($key, $value)
    {
        $this->app['config']->set('queue.connections.my-cloudtasks-connection.'.$key, $value);
    }

    public function dispatch($job)
    {
        $payload = null;
        $task = null;

        Event::listen(TaskCreated::class, function (TaskCreated $event) use (&$payload, &$task) {
            $request = $event->task->getHttpRequest() ?? $event->task->getAppEngineHttpRequest();
            $payload = $request->getBody();
            $task = $event->task;
        });

        dispatch($job);

        return new class($payload, $task, $this)
        {
            public string $payload;

            public Task $task;

            public TestCase $testCase;

            public function __construct(string $payload, Task $task, TestCase $testCase)
            {
                $this->payload = $payload;
                $this->task = $task;
                $this->testCase = $testCase;
            }

            public function run(): void
            {
                rescue(function (): void {
                    app(TaskHandler::class)->handle($this->payload);
                });
            }

            public function runWithoutExceptionHandler(): void
            {
                app(TaskHandler::class)->handle($this->payload);
            }

            public function runAndGetReleasedJob(): self
            {
                rescue(function (): void {
                    app(TaskHandler::class)->handle($this->payload);
                });

                $releasedTask = end($this->testCase->createdTasks);

                if (! $releasedTask) {
                    $this->testCase->fail('No task was released.');
                }

                $payload = $releasedTask->getAppEngineHttpRequest()?->getBody()
                    ?: $releasedTask->getHttpRequest()->getBody();

                return new self(
                    $payload,
                    $releasedTask,
                    $this->testCase
                );
            }

            public function payloadAsArray(string $key = '')
            {
                $decoded = json_decode($this->payload, true);

                return data_get($decoded, $key ?: null);
            }
        };
    }

    public function runFromPayload(string $payload): void
    {
        rescue(function () use ($payload) {
            app(TaskHandler::class)->handle($payload);
        });
    }

    public function assertTaskDeleted(string $taskId): void
    {
        try {
            $this->client->getTask($taskId);

            $this->fail('Getting the task should throw an exception but it did not.');
        } catch (ApiException $e) {
            $this->assertStringContainsString('The task no longer exists', $e->getMessage());
        }
    }

    public function assertTaskExists(string $taskId): void
    {
        try {
            $task = $this->client->getTask($taskId);

            $this->assertInstanceOf(Task::class, $task);
        } catch (ApiException $e) {
            $this->fail('Task ['.$taskId.'] should exist but it does not (or something else went wrong).');
        }
    }

    protected function assertDatabaseCount($table, int $count, $connection = null)
    {
        $this->assertEquals($count, DB::connection($connection)->table($table)->count());
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

    public static function getCommandProperties(string $command): array
    {
        if (Str::startsWith($command, 'O:')) {
            return (array) unserialize($command, ['allowed_classes' => false]);
        }

        if (app()->bound(Encrypter::class)) {
            return (array) unserialize(
                app(Encrypter::class)->decrypt($command),
                ['allowed_classes' => ['Illuminate\Support\Carbon']]
            );
        }

        return [];
    }
}
