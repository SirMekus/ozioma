<?php

namespace Emmy\Ozioma\Tests;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\TestHandler;

class OziomaServiceProviderTest extends TestCase
{
    public function test_it_merges_the_package_config(): void
    {
        $this->assertTrue(config('ozioma.enabled'));
        $this->assertSame([], config('ozioma.allowed'));
        $this->assertSame('outgoing_requests', config('ozioma.channel'));
        $this->assertContains('authorization', config('ozioma.sensitive_headers'));
    }

    public function test_it_does_not_overwrite_a_channel_the_app_already_defines(): void
    {
        $this->assertSame(TestHandler::class, config('logging.channels.outgoing_requests.handler'));
    }

    public function test_the_middleware_is_restored_when_the_http_factory_is_rebuilt(): void
    {
        config(['ozioma.allowed' => ['*']]);

        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstance();

        Http::fake();
        Http::get('https://api.example.com/users');

        $this->assertCount(1, $this->logged());
    }
}
