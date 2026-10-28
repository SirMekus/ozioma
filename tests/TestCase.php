<?php

namespace Emmy\Ozioma\Tests;

use Emmy\Ozioma\Provider\OziomaServiceProvider;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [OziomaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('logging.channels.outgoing_requests', [
            'driver' => 'monolog',
            'handler' => TestHandler::class,
        ]);
    }

    protected function logHandler(string $channel = 'outgoing_requests'): TestHandler
    {
        return Log::channel($channel)->getLogger()->getHandlers()[0];
    }

    /** @return array<int, LogRecord> */
    protected function logged(string $channel = 'outgoing_requests'): array
    {
        return $this->logHandler($channel)->getRecords();
    }
}
