## keepcloud/plugin-push

Native push handlers for NativePHP Mobile: APNs direct (`.p8`, no Firebase) on
iOS, Firebase Cloud Messaging on Android. The facade, the JS bridge and the
`TokenGenerated` event come from the core `nativephp/mobile`; this plugin only
supplies the native side, so the app code is the core's API.

### PHP Usage (Livewire/Blade)

@verbatim
<code-snippet name="Enrolling the device" lang="php">
use Native\Mobile\Facades\PushNotifications;

PushNotifications::enroll();          // asks permission, then enrolls
PushNotifications::checkPermission(); // granted|denied|not_determined
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
}
</code-snippet>
@endverbatim

### Rules

- Treat `not_determined` and `denied` differently in a soft-ask: only `denied`
  should send the user to the system settings; `not_determined` still gets the
  in-app "Enable" button, because the OS prompt has never been shown.
- The token arrives asynchronously through `TokenGenerated` — never assume
  `getToken()` returns a value right after `enroll()`.
- Android needs `google-services.json` at `nativephp/resources/` in the app, not
  inside the plugin: the Gradle plugin expects it in the module root and
  NativePHP copies it from there.
- The `aps-environment` entitlement is declared in the plugin manifest, so it
  survives `native:run --rebuild`. Do not patch the Xcode project by hand.
