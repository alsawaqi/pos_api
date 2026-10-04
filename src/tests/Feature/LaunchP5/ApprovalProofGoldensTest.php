<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP5;

use App\Support\Staff\ApproverVerifier;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * LAUNCH-P5 — the approval proof primitives against the SHARED golden vectors
 * (tests/Fixtures/approval_proof_goldens.json, a byte copy of
 * D:\launch-work\p5\shared\approval_proof_goldens.json; the till and the
 * handheld test the same file in Dart).
 */
class ApprovalProofGoldensTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function vectors(): array
    {
        $file = json_decode((string) file_get_contents(base_path('tests/Fixtures/approval_proof_goldens.json')), true, flags: JSON_THROW_ON_ERROR);

        return $file['vectors'];
    }

    public function test_k_check_canonical_and_proof_match_every_golden_vector(): void
    {
        $this->assertCount(3, $this->vectors());
        foreach ($this->vectors() as $v) {
            $k = ApproverVerifier::deriveKey($v['pin'], $v['salt_hex'], (int) $v['iterations']);
            $canonical = ApproverVerifier::canonical($v['action'], $v['device_uuid'], (int) $v['approver_staff_id'],
                $v['approved_at'], $v['subject_uuid'] === '' ? null : $v['subject_uuid'], $v['amount_baisas'], $v['ref']);

            $this->assertSame($v['k_hex'], bin2hex($k), $v['action']);
            $this->assertSame($v['check_hex'], ApproverVerifier::check($k), $v['action']);
            $this->assertSame($v['canonical'], $canonical, $v['action']);
            $this->assertSame($v['proof_hex'], ApproverVerifier::proof($k, $canonical), $v['action']);

            $wrong = ApproverVerifier::deriveKey($v['wrong_pin'], $v['salt_hex'], (int) $v['iterations']);
            $this->assertSame($v['wrong_pin_check_hex'], ApproverVerifier::check($wrong), $v['action']);
            $this->assertNotSame($v['check_hex'], ApproverVerifier::check($wrong));
        }
    }

    public function test_the_server_check_accepts_the_golden_proof_and_refuses_a_tampered_one(): void
    {
        foreach ($this->vectors() as $v) {
            $k = (string) hex2bin($v['k_hex']);
            $this->assertTrue(ApproverVerifier::verifyProof($k, [$v['canonical']], $v['proof_hex']));
            $this->assertTrue(ApproverVerifier::verifyProof($k, ['other', $v['canonical']], strtoupper($v['proof_hex'])));
            $this->assertFalse(ApproverVerifier::verifyProof($k, [$v['canonical'].'x'], $v['proof_hex']));
            $this->assertFalse(ApproverVerifier::verifyProof($k, [$v['canonical']], str_repeat('0', 64)));
            $this->assertFalse(ApproverVerifier::verifyProof($k, [$v['canonical']], 'not-hex'));
            // A proof made with the wrong PIN's K never verifies.
            $wrong = ApproverVerifier::deriveKey($v['wrong_pin'], $v['salt_hex'], (int) $v['iterations']);
            $this->assertFalse(ApproverVerifier::verifyProof($k, [$v['canonical']], ApproverVerifier::proof($wrong, $v['canonical'])));
        }
    }

    public function test_the_stored_key_reads_hex_raw_and_base64_and_the_time_is_utc_milliseconds(): void
    {
        $k = (string) hex2bin($this->vectors()[0]['k_hex']);
        $this->assertSame($k, ApproverVerifier::storedKey($this->vectors()[0]['k_hex']));
        $this->assertSame($k, ApproverVerifier::storedKey(strtoupper($this->vectors()[0]['k_hex'])));
        $this->assertSame($k, ApproverVerifier::storedKey($k));
        $this->assertSame($k, ApproverVerifier::storedKey(base64_encode($k)));
        $this->assertNull(ApproverVerifier::storedKey(null));
        $this->assertNull(ApproverVerifier::storedKey('short'));

        $this->assertSame('2026-10-04T09:15:30.123Z', ApproverVerifier::isoMillis(Carbon::parse('2026-10-04T13:15:30.123+04:00')));
    }

    public function test_the_kdf_cost_is_configurable_and_defaults_to_100000(): void
    {
        $this->assertSame(100000, ApproverVerifier::iterations());
        config(['pos.approver_kdf_iterations' => 2500]);
        $this->assertSame(2500, ApproverVerifier::iterations());
    }
}
