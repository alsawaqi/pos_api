<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Orders\OneLineNote;
use PHPUnit\Framework\TestCase;

/** Fix order A-1 (H1) — a note never starts a new line on a kitchen ticket. */
final class OneLineNoteTest extends TestCase
{
    public function test_it_turns_line_breaks_and_tabs_into_spaces_drops_other_controls_and_collapses_spaces(): void
    {
        $this->assertSame('Well done no onion', OneLineNote::clean("  Well\r\ndone\t\tno\u{0007}\u{0085}\u{2028}\u{2029} onion \x7F "));
        $this->assertSame('بدون بصل', OneLineNote::clean("بدون\nبصل"));
        $this->assertSame('', OneLineNote::clean(" \n\t "));
        $this->assertNull(OneLineNote::clean(null));
        $this->assertSame(12, OneLineNote::clean(12));
    }

    public function test_it_cuts_a_cleaned_note_at_140_code_points(): void
    {
        $this->assertSame(140, mb_strlen((string) OneLineNote::cut(str_repeat('é', 200))));
        $this->assertSame(str_repeat('a', 139), OneLineNote::cut(str_repeat('a', 139).' b'));
        $this->assertSame(str_repeat('a b ', 34).'a b', OneLineNote::cut(str_repeat("a\nb\n", 40)));
    }

    public function test_it_cleans_every_line_and_combo_pick_note_and_cuts_only_when_asked(): void
    {
        $lines = [['product_id' => 1, 'notes' => "No\nice", 'combo' => [['slot_id' => 1, 'notes' => "x\ty"], ['slot_id' => 2]]], 'junk'];

        $this->assertSame([['product_id' => 1, 'notes' => 'No ice', 'combo' => [['slot_id' => 1, 'notes' => 'x y'], ['slot_id' => 2]]], 'junk'],
            OneLineNote::inLines($lines));
        $this->assertSame(141, mb_strlen(OneLineNote::inLines([['notes' => str_repeat('z', 141)]])[0]['notes']));
        $this->assertSame(140, mb_strlen(OneLineNote::inLines([['notes' => str_repeat('z', 141)]], true)[0]['notes']));
        $this->assertSame('not lines', OneLineNote::inLines('not lines'));
    }
}
