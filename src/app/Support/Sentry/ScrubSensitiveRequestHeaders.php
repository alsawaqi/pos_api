<?php

declare(strict_types=1);

namespace App\Support\Sentry;

use Sentry\Event;
use Sentry\EventHint;

/** Removes application credentials and customer IPs from Sentry requests. */
final class ScrubSensitiveRequestHeaders
{
    /** @var list<string> */
    private const HEADERS = [
        'x-pos-web-client-auth',
        'x-pos-web-client-ip',
        'x-qr-client-secret',
        // Extra hardening: the session UUID is the other half of the credential.
        'x-qr-session',
    ];

    public static function handle(Event $event, ?EventHint $hint = null): Event
    {
        $request = $event->getRequest();
        $headers = $request['headers'] ?? null;
        if (is_array($headers)) {
            foreach ($headers as $name => $values) {
                if (! is_string($name) || ! in_array(strtolower($name), self::HEADERS, true)) {
                    continue;
                }

                $headers[$name] = is_array($values)
                    ? array_fill(0, count($values), '[Filtered]')
                    : '[Filtered]';
            }
            $request['headers'] = $headers;
        }

        if (array_key_exists('data', $request)) {
            $request['data'] = self::scrubRequestData($request['data']);
        }
        $event->setRequest($request);

        return $event;
    }

    private static function scrubRequestData(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $nested) {
            if (is_string($key) && in_array(strtolower($key), ['client_secret', 'clientsecret'], true)) {
                $value[$key] = '[Filtered]';

                continue;
            }

            $value[$key] = self::scrubRequestData($nested);
        }

        return $value;
    }
}
