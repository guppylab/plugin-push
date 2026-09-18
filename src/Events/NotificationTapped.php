<?php

namespace Guppylab\Push\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The user tapped a push notification and the app came to the foreground.
 *
 * Fires on both platforms, including a cold start: on Android the payload of
 * the tapped notification is carried on the launch intent, on iOS it arrives
 * through the notification delegate.
 */
class NotificationTapped
{
    use Dispatchable, SerializesModels;

    public function __construct(
        /** JSON of the payload, minus transport keys. */
        public readonly string $payload = '{}',
        /** The URL found under one of config('push.deep_link_keys'), if any. */
        public readonly ?string $link = null,
    ) {}

    /**
     * Decoded payload.
     *
     * @return array<string,mixed>
     */
    public function data(): array
    {
        $decoded = json_decode($this->payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data(), $key, $default);
    }
}
