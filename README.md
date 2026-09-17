# Push — NativePHP Mobile plugin

Push notifications for NativePHP Mobile: **APNs direct** (`.p8` key, no Firebase)
on iOS and **Firebase Cloud Messaging** on Android.

The core `nativephp/mobile` package ships the PHP facade
(`PushNotifications::enroll/checkPermission/getToken`), the JS bridge and the
`TokenGenerated` event — but not the native handlers. This plugin fills that gap
on both platforms.

- iOS: `UNUserNotificationCenter` + `registerForRemoteNotifications()`, APNs
  device token returned raw (hex)
- Android: `FirebaseMessaging` + a `FirebaseMessagingService` for foreground
  messages

## Installation

```bash
composer require guppylab/plugin-push
php artisan native:plugin:register guppylab/plugin-push
php artisan native:run   # rebuild the native project
```

Register the service provider in the app's `NativeServiceProvider::plugins()`:

```php
use Guppylab\Push\PushNotificationServiceProvider;

public function plugins(): array
{
    return [
        PushNotificationServiceProvider::class,
    ];
}
```

## Usage

The API is the core's — this plugin only makes it work natively:

```php
use Native\Mobile\Facades\PushNotifications;

PushNotifications::enroll();          // asks permission and enrolls the device
PushNotifications::checkPermission(); // granted|denied|not_determined
PushNotifications::getToken();        // last token received, or null
```

```php
use Native\Mobile\Attributes\OnNative;
use Native\Mobile\Events\PushNotification\TokenGenerated;

#[OnNative(TokenGenerated::class)]
public function onToken(string $token, ?string $id = null): void
{
    // iOS: raw APNs device token in hex — what a backend posting to
    // api.push.apple.com with an ES256 (.p8) JWT needs.
    // Android: FCM registration token.
}
```

## iOS setup

The plugin declares the `aps-environment` entitlement in its manifest, so it is
re-injected on every `native:run --rebuild`. The provisioning profile must
include the Push Notifications capability.

No Firebase SDK is used on iOS: the backend talks to APNs directly with a `.p8`
key.

## Android setup

FCM requires `google-services.json`. It is **not** a plugin asset: the
`com.google.gms.google-services` Gradle plugin expects it in the module root,
and NativePHP copies it from `nativephp/resources/google-services.json` in the
app. Drop the file there.

The plugin pulls in `firebase-messaging` and `firebase-analytics`, declares
`POST_NOTIFICATIONS` (asked at runtime on API 33+) and registers
`PushMessagingService` for `com.google.firebase.MESSAGING_EVENT`.

Foreground messages are rendered by the plugin on the `default` channel, titled
with the payload's title or, when absent, the host app's own name. Background
messages are rendered by FCM itself from the notification payload.

## Permission states

`CheckPermission` returns the PHP vocabulary `granted|denied|not_determined`.
On Android 13+ "never asked" is distinguished from "denied" via
`shouldShowRequestPermissionRationale`, so a soft-ask can show an "Enable"
button instead of sending the user to Settings.

## License

MIT
