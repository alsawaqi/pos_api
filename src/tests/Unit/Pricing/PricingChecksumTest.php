<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;

final class PricingChecksumTest extends TestCase
{
    public function test_v020_golden_manifest_matches_the_vendored_corpus(): void
    {
        $directory = dirname(__DIR__, 2).'/Fixtures/pricing_goldens/v0.2.0';
        $files = glob($directory.'/*.json');
        self::assertIsArray($files);
        usort($files, static fn (string $a, string $b): int => strcmp(basename($a), basename($b)));

        $lines = [];
        foreach ($files as $file) {
            $bytes = file_get_contents($file);
            self::assertNotFalse($bytes);
            $lines[] = hash('sha256', str_replace("\r\n", "\n", $bytes)).'  '.basename($file);
        }

        $perFileManifest = implode('', array_map(static fn (string $line): string => $line."\n", $lines));
        $total = hash('sha256', $perFileManifest);
        $lines[] = 'TOTAL '.$total;
        $computed = implode("\n", $lines)."\n";
        $stored = file_get_contents($directory.'/goldens.sha256');

        self::assertCount(33, $files);
        self::assertSame('edb376603b876f69a1ca00770c2f47409f4a558ba2bc41a7c662ed6aa2dea320', $total);
        self::assertNotFalse($stored);
        self::assertSame(str_replace("\r\n", "\n", $stored), $computed);
    }
}
