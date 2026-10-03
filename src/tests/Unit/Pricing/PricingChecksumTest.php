<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use PHPUnit\Framework\TestCase;

final class PricingChecksumTest extends TestCase
{
    /**
     * LAUNCH-P4 — the pinned engine is mithqal_pricing v0.3.0 (tag d63ba6c):
     * the v0.2.0 corpus plus six VAT-inclusive vectors.
     */
    public function test_v030_golden_manifest_matches_the_vendored_corpus(): void
    {
        $directory = dirname(__DIR__, 2).'/Fixtures/pricing_goldens/v0.3.0';
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

        self::assertCount(39, $files);
        self::assertSame('37d2741c0e7a801e6ffb9aef0ae6bbaeb9b13ed4f18201898e13c98d3f772d4f', $total);
        self::assertNotFalse($stored);
        self::assertSame(str_replace("\r\n", "\n", $stored), $computed);
    }

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
