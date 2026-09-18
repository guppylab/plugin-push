<?php

namespace Guppylab\Push;

use Guppylab\Push\Events\MessageReceived;
use Guppylab\Push\Events\NotificationTapped;

/**
 * The bits of push that the core facade does not cover.
 *
 * Enrolment, permission checks and token reads stay on
 * Native\Mobile\Facades\PushNotifications — this plugin implements the native
 * handlers behind them. What lives here is everything the core has no API for:
 * handing the app's notification settings to the native side, tearing an
 * enrolment down on logout, and the badge.
 */
class Push
{
    /**
     * Hand the app's push settings to the native side.
     *
     * Native code cannot read config/push.php, so the channel, the icon and the
     * event classes to dispatch are pushed across the bridge. Android persists
     * them, because the FCM service that renders a notification runs without an
     * Activity — and, on a cold start, without a PHP runtime to ask.
     */
    public function configure(): bool
    {
        if (! function_exists('nativephp_call')) {
            return false;
        }

        nativephp_call('PushNotification.Configure', json_encode([
            'channel' => [
                'id' => (string) config('push.channel.id', 'default'),
                'name' => (string) config('push.channel.name', 'Notifications'),
                'description' => (string) config('push.channel.description', ''),
                'importance' => (string) config('push.channel.importance', 'default'),
            ],
            'android' => [
                'small_icon' => config('push.android.small_icon'),
                'color' => config('push.android.color'),
            ],
            'ios' => [
                'foreground_presentation' => (array) config('push.ios.foreground_presentation', ['banner', 'sound', 'badge']),
                'provisional' => (bool) config('push.ios.provisional', false),
            ],
            'deep_link_keys' => (array) config('push.deep_link_keys', ['link', 'url']),
            'events' => [
                'message_received' => MessageReceived::class,
                'notification_tapped' => NotificationTapped::class,
            ],
        ]));

        return true;
    }

    /**
     * Whether push is usable on this device.
     *
     * False on a simulator without a paired Apple account, and on an Android
     * build whose google-services.json is missing, so an app can explain itself
     * instead of waiting for a token that will never arrive.
     *
     * @return array{supported:bool,platform:string,reason:?string}
     */
    public function support(): array
    {
        $unsupported = ['supported' => false, 'platform' => 'unknown', 'reason' => 'no_native_runtime'];

        if (! function_exists('nativephp_call')) {
            return $unsupported;
        }

        $result = nativephp_call('PushNotification.IsSupported', '{}');

        if (! $result) {
            return $unsupported;
        }

        $decoded = json_decode($result, true);

        return [
            'supported' => (bool) ($decoded['supported'] ?? false),
            'platform' => (string) ($decoded['platform'] ?? 'unknown'),
            'reason' => $decoded['reason'] ?? null,
        ];
    }

    public function isSupported(): bool
    {
        return $this->support()['supported'];
    }

    /**
     * Tear the enrolment down: delete the device token and stop receiving
     * pushes. This is the logout call — without it a signed-out device keeps
     * receiving the previous user's notifications until the token rotates.
     *
     * The backend still has to forget the token; the device cannot do that for
     * you. Delete it server-side before calling this, while you still know it.
     */
    public function unenroll(): bool
    {
        if (! function_exists('nativephp_call')) {
            return false;
        }

        $result = nativephp_call('PushNotification.Unenroll', '{}');

        if (! $result) {
            return false;
        }

        return (bool) (json_decode($result, true)['success'] ?? false);
    }

    /**
     * Set the badge number on the app icon.
     *
     * iOS only. Android has no platform badge API — launchers implement their
     * own, and most read the count off the notification itself, so the call is
     * a no-op there and reports platform: "android".
     */
    public function setBadge(int $count): bool
    {
        if (! function_exists('nativephp_call')) {
            return false;
        }

        $result = nativephp_call('PushNotification.SetBadge', json_encode([
            'count' => max(0, $count),
        ]));

        if (! $result) {
            return false;
        }

        return (bool) (json_decode($result, true)['success'] ?? false);
    }

    public function clearBadge(): bool
    {
        return $this->setBadge(0);
    }
}
