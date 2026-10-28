<?php

namespace Emmy\Ozioma\Tests;

use Illuminate\Support\Facades\Http;
use Monolog\Handler\TestHandler;

class DefaultChannelTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('ozioma.channel', 'third_party_calls');
        $app['config']->set('ozioma.default_channel', ['driver' => 'monolog', 'handler' => TestHandler::class]);
        $app['config']->set('ozioma.allowed', ['*']);
    }

    public function test_it_registers_the_default_channel_when_the_app_does_not_define_it(): void
    {
        $this->assertSame(TestHandler::class, config('logging.channels.third_party_calls.handler'));
    }

    public function test_it_writes_to_the_configured_channel(): void
    {
        Http::fake();

        Http::get('https://api.example.com/users');

        $this->assertCount(1, $this->logged('third_party_calls'));
        $this->assertCount(0, $this->logged());
    }
}
