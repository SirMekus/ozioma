<?php

namespace Emmy\Ozioma\Provider;

use Emmy\Ozioma\OutgoingRequestLogger;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

class OziomaServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ozioma.php', 'ozioma');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/ozioma.php' => config_path('ozioma.php'),
        ], 'ozioma-config');

        $this->registerDefaultChannel();

        if (config('ozioma.register_globally', true)) {
            // Runs for every new Factory instance, not just the first one, so the
            // middleware survives the Factory being forgotten and rebuilt.
            $this->callAfterResolving(Factory::class, function (Factory $factory): void {
                $factory->globalMiddleware(OutgoingRequestLogger::make());
            });
        }
    }

    /**
     * Adds the configured log channel to "logging.channels" unless the app
     * already defines one with that name.
     */
    protected function registerDefaultChannel(): void
    {
        $channel = config('ozioma.channel', 'outgoing_requests');

        if (config()->has("logging.channels.{$channel}")) {
            return;
        }

        config(["logging.channels.{$channel}" => config('ozioma.default_channel')]);
    }
}
