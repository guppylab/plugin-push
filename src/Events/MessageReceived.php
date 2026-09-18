<?php

namespace Guppylab\Push\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A push arrived while the app was running.
 *
 * Covers both a notification the user can see and a data-only (silent) push.
 * This is the piece the core leaves out: it delivers the device token and
 * nothing else, so an app could be told it had been pushed to but never what
 * the push said.
 */
class MessageReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        /** JSON of the payload, minus transport keys such as "aps" and "gcm.message_id". */
        public readonly string $payload = '{}',
        public readonly ?string $title = null,
        public readonly ?string $body = null,
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
