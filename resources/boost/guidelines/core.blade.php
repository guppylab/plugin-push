## guppylab/plugin-push

Native push for NativePHP Mobile: APNs direct (`.p8`, no Firebase) on iOS,
Firebase Cloud Messaging on Android.

Enrolment, permission checks and token reads use the core's API
(`Native\Mobile\Facades\PushNotifications`) — this plugin supplies the native
handlers behind it. What the core has no API for at all lives on this plugin's
own facade and events: the payload of a push, notification taps, unenrolment and
the badge.

### PHP Usage (Livewire/Blade)

@verbatim
<code-snippet name="Enrolling the device" lang="php">
use Native\Mobile\Facades\PushNotifications;

PushNotifications::enroll();          // asks permission, then enrolls
PushNotifications::checkPermission(); // granted|denied|not_determined (+ provisional on iOS)
PushNotifications::getToken();        // last token received, or null
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Receiving the device token" lang="php">
use Native\Mobile\Attributes\OnNative;
use Native\Mobile\Events\PushNotification\TokenGenerated;

#[OnNative(TokenGenerated::class)]
public function onToken(string $token, ?string $id = null): void
{
    // iOS: raw APNs device token, hex. Android: FCM registration token.
    // Send it to the backend every time — FCM rotates tokens on its own.
}
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Reading the push and the tap" lang="php">
use Guppylab\Push\Events\MessageReceived;
use Guppylab\Push\Events\NotificationTapped;
use Native\Mobile\Attributes\OnNative;

// Visible pushes received while the app runs, and data-only (silent) pushes.
#[OnNative(MessageReceived::class)]
public function onPush(MessageReceived $event): void
{
    $orderId = $event->get('order_id');
}

// Includes the tap that cold-started the app.
#[OnNative(NotificationTapped::class)]
public function onTap(NotificationTapped $event): void
{
    if ($event->link) {
        $this->redirect($event->link);
    }
}
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Logout, badge and support" lang="php">
use Guppylab\Push\Facades\Push;

Push::unenroll();    // delete the token; call AFTER removing it from the backend
Push::setBadge(3);   // iOS only — success: false on Android
Push::clearBadge();
Push::isSupported(); // false on an iOS simulator or without google-services.json
</code-snippet>
@endverbatim

### JavaScript Usage (Inertia — Vue or React)

@verbatim
<code-snippet name="Enrolling and listening in JS" lang="js">
import { requestPermission, checkPermission, onToken, onMessage, onTapped } from 'guppylab-plugin-push';

const stopToken = onToken((token, id) => { /* register with your backend */ });
const stopTap = onTapped(({ data, link }) => { if (link) router.visit(link); });

if (await checkPermission() !== 'granted') {
    await requestPermission();
}
</code-snippet>
@endverbatim

### Rules

- Never hard-code the notification channel name or the status bar icon — they
  come from `config/push.php`, because they are user-visible in the host app.
  Publish it with `php artisan vendor:publish --tag=push-config`.
- Ship a white, transparent-background drawable for `push.android.small_icon`. A
  full-colour launcher icon renders as a white blob in the status bar.
- Android freezes a channel's importance once it exists. Changing
  `push.channel.importance` afterwards does nothing; ship a new channel `id`.
- Re-send the token to the backend on every `TokenGenerated`, not only the first.
  FCM rotates tokens, and the plugin dispatches the rotated one on the next
  resume.
- Delete the token server-side *before* `Push::unenroll()`; afterwards the device
  no longer knows it.
- Background notification messages on Android are rendered by FCM itself and do
  not reach `MessageReceived`. A tap on them still produces
  `NotificationTapped`.
- `provisional` and `ephemeral` are iOS-only permission states. Treat them as
  granted unless the app specifically distinguishes them.
- The `aps-environment` entitlement is `production`, so the backend must post to
  `api.push.apple.com`. Change it in the manifest to use the APNs sandbox.
