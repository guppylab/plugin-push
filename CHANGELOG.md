# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.0.0]

### Added

- `MessageReceived` and `NotificationTapped` events on both platforms. Until now
  the plugin delivered the device token and nothing else: an app could be told it
  had been pushed to, but never what the push said or that the user had tapped
  it. Cold-start taps are included — Android carries the payload on the launch
  intent, iOS through the notification delegate.
- iOS now presents notifications while the app is in the foreground
  (`willPresent`). Previously a foreground push on iOS was silent while Android
  rendered one, which read as a bug in the app.
- `Push::unenroll()` — the logout call. Deletes the device token so a signed-out
  device stops receiving the previous user's notifications.
- `Push::setBadge()` / `Push::clearBadge()` (iOS; reports `success: false` on
  Android, which has no platform badge API).
- `Push::isSupported()` / `Push::support()`, false on an iOS simulator and on an
  Android build without `google-services.json`, so an app can explain itself
  instead of waiting for a token that will never arrive.
- Publishable `config/push.php` (`--tag=push-config`): notification channel id,
  name, description and importance, the Android status bar icon and accent
  colour, iOS foreground presentation and provisional authorisation, and the
  payload keys searched for a deep link.
- `PushNotification.Configure` bridge function, called automatically on boot, so
  the native side works from the app's configuration rather than from constants.
- `onMessage()`, `onTapped()`, `unenroll()`, `setBadge()`, `clearBadge()`,
  `support()` and `isSupported()` in the JS library.

### Fixed

- **A rotated FCM token never reached the backend.** `onNewToken` cached the new
  token in memory and stopped there, because dispatching an event needs a
  FragmentActivity the service does not have. Pushes then stopped arriving with
  nothing reporting why. Events raised without an Activity are now persisted and
  flushed on the next resume.
- **Android dropped events silently** whenever the bridge function was handed a
  non-Activity Context (`context as? FragmentActivity ?: return`).
- **The notification channel was created with a hard-coded Portuguese name**
  ("Notificações"), visible in the system settings of every app that installed
  the plugin. The channel now comes from the app's own config.
- **The status bar icon was the launcher icon**, which Android renders as a white
  blob. Apps can now name a monochrome drawable.
- **The JS library read the wrong part of the response.** `/_native/api/call`
  answers `{ status, data }`; the library read the top level, so
  `checkPermission()` always returned `not_determined` and `getToken()` always
  returned `null`. It now unwraps the envelope, sends `X-CSRF-TOKEN` and throws
  on a native error.
- **`CheckPermission` blocked the bridge thread on iOS** with an unbounded
  semaphore wait. It is now bounded and answers `unknown` on timeout.
- The iOS delegate now chains to whatever delegate was installed before it, so it
  no longer matters whether this plugin or the core's local-notification plugin
  initialises first.

### Changed

- **Breaking.** The plugin dispatches its own events, which must be listened for
  by their new class names (`Guppylab\Push\Events\*`).
- Dropped the `firebase-analytics` dependency. It was never used by the plugin
  and added weight and a data-collection surface to every app installing it.
- `GetToken`, `Configure`, `Unenroll`, `SetBadge` and `IsSupported` register with
  a `Context` rather than an Activity, so they also work on the cold-boot
  WorkManager path.
- Source comments and documentation translated to English.

## [2.1.0]

- Added the JS library required by the marketplace.

## [2.0.0]

- Vendor renamed to `guppylab`.

## [1.0.0]

- First release: native APNs handlers on iOS, FCM on Android.
