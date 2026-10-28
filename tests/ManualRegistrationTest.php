<?php

namespace Emmy\Ozioma\Tests;

use Emmy\Ozioma\OutgoingRequestLogger;
use Illuminate\Support\Facades\Http;

class ManualRegistrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('ozioma.register_globally', false);
        $app['config']->set('ozioma.allowed', ['*']);
    }

    public function test_it_is_not_registered_globally_when_turned_off(): void
    {
        Http::fake();

        Http::get('https://api.example.com/users');

        $this->assertCount(0, $this->logged());
    }

    public function test_it_can_be_attached_to_a_single_request(): void
    {
        Http::fake();

        Http::withMiddleware(OutgoingRequestLogger::make())->get('https://api.example.com/users');

        $this->assertCount(1, $this->logged());
    }
}
