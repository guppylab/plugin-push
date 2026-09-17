<?php

namespace Keepcloud\Push;

use Illuminate\Support\ServiceProvider;

/**
 * Plugin nativo de push cross-platform: APNs direto no iOS (.p8, sem Firebase)
 * e FCM no Android.
 *
 * A facade PHP (Native\Mobile\Facades\PushNotifications) e o evento
 * TokenGenerated vêm do core nativephp/mobile; este plugin fornece os handlers
 * nativos que o core deixa em aberto — iOS em resources/ios/Sources (Swift/APNs),
 * Android em resources/android/src (Kotlin/FCM). Sem serviços PHP a registrar.
 */
class PushNotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
