# Push — NativePHP Mobile plugin

Push notifications for NativePHP Mobile: **direct APNs** (`.p8` key, no Firebase)
on iOS and **Firebase Cloud Messaging** on Android.

The core `nativephp/mobile` package ships the PHP facade
(`PushNotifications::enroll/checkPermission/getToken`), the JS bridge and the
`TokenGenerated` event — but no native handlers, and no way to read what a push
actually contains. This plugin fills both gaps on both platforms.

| | iOS | Android |
|---|---|---|
| Transport | APNs direct (`.p8`) | Firebase Cloud Messaging |
| Token | `UNUserNotificationCenter` + `registerForRemoteNotifications()` | `FirebaseMessaging` |
| Foreground notification | shown (`willPresent`) | shown (`FirebaseMessagingService`) |
| Payload delivered to PHP | ✅ `MessageReceived` | ✅ `MessageReceived` |
| Tap delivered to PHP | ✅ `NotificationTapped` | ✅ `NotificationTapped` |
| Cold-start tap | ✅ | ✅ (carried on the launch intent) |
| Unenroll (logout) | ✅ | ✅ (`deleteToken`) |
| Badge | ✅ | ❌ no platform API |
| Provisional permission | ✅ | ❌ no equivalent |

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

Publish the config to change the notification channel or the status bar icon:

```bash
php artisan vendor:publish --tag=push-config
```

## Usage

Enrolment, permission checks and token reads use the core's API — this plugin
only makes them work natively:

```php
use Native\Mobile\Facades\PushNotifications;

PushNotifications::enroll();          // asks permission and enrolls the device
PushNotifications::checkPermission(); // granted|denied|not_determined|provisional
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

### Reading the push itself

```php
use Guppylab\Push\Events\MessageReceived;
use Guppylab\Push\Events\NotificationTapped;
use Native\Mobile\Attributes\OnNative;

#[OnNative(MessageReceived::class)]
public function onPush(MessageReceived $event): void
{
    $orderId = $event->get('order_id');
}

#[OnNative(NotificationTapped::class)]
public function onTap(NotificationTapped $event): void
{
    if ($event->link) {
        $this->redirect($event->link);
    }
}
```

`MessageReceived` fires for visible notifications received while the app is
running and for data-only (silent) pushes. `NotificationTapped` fires when the
user taps one, including the tap that cold-started the app.

### Logout, badge, support

```php
use Guppylab\Push\Facades\Push;

Push::unenroll();   // delete the device token and stop receiving pushes
Push::setBadge(3);  // iOS only; reports success: false on Android
Push::clearBadge();
Push::isSupported(); // false on an iOS simulator or without google-services.json
```

Remove the token from your backend **before** calling `unenroll()` — the device
cannot forget it on the server's behalf, and afterwards you no longer know it.

## Usage (JavaScript / Inertia — Vue or React)

```js
import { requestPermission, checkPermission, onToken, onMessage, onTapped } from 'guppylab-plugin-push';

const stopToken = onToken((token, id) => { /* register with your backend */ });
const stopTap = onTapped(({ data, link }) => { if (link) router.visit(link); });

if (await checkPermission() !== 'granted') {
    await requestPermission();
}
```

The JS library ships TypeScript definitions and works across Livewire v3/v4 and
Inertia (Vue/React).

## iOS setup

The plugin declares the `aps-environment` entitlement in its manifest, so it is
re-injected on every `native:run --rebuild`. The provisioning profile must
include the Push Notifications capability.

No Firebase SDK is used on iOS: the backend talks to APNs directly with a `.p8`
key.

> **Sandbox builds.** The entitlement is `production`, so tokens are production
> APNs tokens and the backend must talk to `api.push.apple.com`. If you need the
> sandbox (`api.sandbox.push.apple.com`) for debug builds, change
> `ios.entitlements.aps-environment` to `development` in the plugin manifest
> before building. A per-build-type entitlement is not expressible in the plugin
> manifest today.

## Android setup

FCM requires `google-services.json`. It is **not** a plugin asset: the
`com.google.gms.google-services` Gradle plugin expects it in the module root, and
NativePHP copies it from `nativephp/resources/google-services.json` in the app.
Drop the file there.

The plugin pulls in `firebase-messaging`, declares `POST_NOTIFICATIONS` (asked at
runtime on API 33+) and registers `PushMessagingService` for
`com.google.firebase.MESSAGING_EVENT`.

Foreground messages are rendered by the plugin on the configured channel.
Background notification messages are rendered by FCM itself from the payload —
those do not reach `MessageReceived`, by design of the platform, but a tap on
them still produces `NotificationTapped`.

### Notification channel and icon

```php
// config/push.php
'channel' => [
    'id' => 'default',
    'name' => __('Notifications'), // shown in YOUR app's system settings
    'importance' => 'high',        // heads-up banner
],
'android' => [
    'small_icon' => 'ic_notification', // white, transparent background
    'color' => '#2563EB',
],
```

Android freezes a channel's importance once it has been created, on purpose, so
an app cannot escalate itself after the fact. Ship a new channel `id` when you
need to change it.

A full-colour launcher icon is rendered as a white blob in the status bar, which
is why `small_icon` exists. Leave it empty and the launcher icon is used.

## Permission states

`checkPermission()` returns the PHP vocabulary
`granted|denied|not_determined`, plus `provisional|ephemeral` on iOS.

On Android 13+ "never asked" is told apart from "denied" via
`shouldShowRequestPermissionRationale`, so a soft-ask can show an "Enable" button
instead of sending the user to Settings.

## License

MIT
