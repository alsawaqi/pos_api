<?php

namespace App\Support;

use Carbon\Carbon;

final class SyncReceiptFingerprint
{
    public static function fingerprint(object $event): string
    {
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($canonical, $value);
        };

        return hash('sha256', json_encode([
            (string) $event->client_event_id, (int) $event->device_id, $event->event_type,
            Carbon::parse($event->server_received_at)->utc()->format('Y-m-d H:i:s.u'),
            $canonical(json_decode($event->payload_json, true, 512, JSON_THROW_ON_ERROR)),
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
