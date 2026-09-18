<?php

namespace Guppylab\Push;

use Illuminate\Support\ServiceProvider;

/**
 * Cross-platform native push: APNs direct on iOS (.p8, no Firebase) and FCM on
 * Android.
 *
 * The core nativephp/mobile package ships the PHP facade
 * (Native\Mobile\Facades\PushNotifications) and the TokenGenerated event but not
 * the native handlers; this plugin provides them — iOS in resources/ios/Sources
 * (Swift/APNs), Android in resources/android/src (Kotlin/FCM) — plus the parts
 * the core has no API for at all: the payload of a push, notification taps,
 * unenrolment and the badge.
 */
class PushNotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/push.php', 'push');

        $this->app->singleton(Push::class, fn () => new Push);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/push.php' => config_path('push.php'),
            ], 'push-config');
        }

        // Native code cannot read the config file, so hand it over once per
        // process. Cheap (an in-process bridge call) and it keeps the channel,
        // the icon and the event classes in step with the app on every build.
        if (config('push.auto_configure', true) && function_exists('nativephp_call')) {
            $this->app->make(Push::class)->configure();
        }
    }
}
