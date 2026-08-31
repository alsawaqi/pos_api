<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Sentry;

use Sentry\Event;
use Tests\TestCase;

final class ScrubSensitiveRequestHeadersTest extends TestCase
{
    public function test_configured_before_send_scrubs_all_application_sensitive_headers_case_insensitively(): void
    {
        config(['sentry.send_default_pii' => true]);
        foreach (['before_send', 'before_send_transaction'] as $configKey) {
            $callback = config('sentry.'.$configKey);
            $this->assertIsCallable($callback);

            $event = Event::createEvent();
            $event->setRequest([
                'method' => 'POST',
                'headers' => [
                    'X-Pos-Web-Client-Auth' => ['global-secret'],
                    'x-pos-web-client-ip' => ['203.0.113.60'],
                    'X-QR-Client-Secret' => ['session-secret'],
                    'x-QR-session' => ['session-uuid'],
                    'X-Benign-Header' => ['keep-me'],
                    'X-POS-WEB-CLIENT-AUTH' => ['duplicate-one', 'duplicate-two'],
                ],
                'data' => [
                    'client_secret' => 'bind-body-secret',
                    'clientSecret' => 'camel-body-secret',
                    'table_token' => 'keep-table-token',
                    'nested' => [[
                        'client_secret' => 'nested-body-secret',
                        'product_id' => 101,
                    ]],
                ],
            ]);

            $result = $callback($event, null);

            $this->assertSame($event, $result);
            $headers = $event->getRequest()['headers'];
            $this->assertSame(['[Filtered]'], $headers['X-Pos-Web-Client-Auth']);
            $this->assertSame(['[Filtered]'], $headers['x-pos-web-client-ip']);
            $this->assertSame(['[Filtered]'], $headers['X-QR-Client-Secret']);
            $this->assertSame(['[Filtered]'], $headers['x-QR-session']);
            $this->assertSame(['keep-me'], $headers['X-Benign-Header']);
            $this->assertSame(['[Filtered]', '[Filtered]'], $headers['X-POS-WEB-CLIENT-AUTH']);
            $data = $event->getRequest()['data'];
            $this->assertSame('[Filtered]', $data['client_secret']);
            $this->assertSame('[Filtered]', $data['clientSecret']);
            $this->assertSame('keep-table-token', $data['table_token']);
            $this->assertSame('[Filtered]', $data['nested'][0]['client_secret']);
            $this->assertSame(101, $data['nested'][0]['product_id']);
        }
    }

    public function test_event_without_request_headers_is_unchanged(): void
    {
        $callback = config('sentry.before_send');
        $event = Event::createEvent();
        $event->setRequest(['method' => 'GET']);

        $this->assertSame($event, $callback($event, null));
        $this->assertSame(['method' => 'GET'], $event->getRequest());
    }
}
