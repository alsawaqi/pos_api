<?php

declare(strict_types=1);

namespace Tests\Unit;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

class Phase1InertnessTest extends TestCase
{
    public function test_no_explicit_phase_two_value_producer_is_present(): void
    {
        $allowed = [
            'qr_web' => ['app/Models/Order.php'],
            'awaiting_payment' => ['app/Models/Order.php'],
            'payment_station' => ['app/Models/Device.php'],
        ];
        $violations = [];

        foreach ([app_path(), base_path('routes')] as $root) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            foreach ($files as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = str_replace('\\', '/', $file->getPathname());
                $relative = str_replace(str_replace('\\', '/', base_path()).'/', '', $path);
                $contents = file_get_contents($file->getPathname());
                $this->assertIsString($contents);

                foreach ($allowed as $value => $allowedFiles) {
                    if (str_contains($contents, $value) && ! in_array($relative, $allowedFiles, true)) {
                        $violations[] = "{$value} in {$relative}";
                    }
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    public function test_server_owned_qr_session_and_inactive_status_guards_remain_intact(): void
    {
        $createOrder = file_get_contents(app_path('Actions/Device/Sync/Handlers/CreateOrderHandler.php'));
        $deviceOrders = file_get_contents(app_path('Http/Controllers/Api/V1/Device/DeviceOrdersController.php'));

        $this->assertIsString($createOrder);
        $this->assertIsString($deviceOrders);
        $this->assertStringNotContainsString("'qr_session_id'", $createOrder);
        $this->assertStringNotContainsString('STATUS_AWAITING_PAYMENT', $deviceOrders);
    }
}
