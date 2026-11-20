<?php

namespace Tests\Unit;

use App\Support\SameVenueKey;
use PHPUnit\Framework\TestCase;

/**
 * 同じ日・同じ会場・同じお客様の案件を1件に数える合言葉（2026-11-20 小沼さん決定）。
 * ・3つがそろって同じときだけ同じ合言葉。
 * ・お客様名か会場が空なら null（まとめない）。
 * ・空白と末尾の「様」は無視する。
 */
class SameVenueKeyTest extends TestCase
{
    public function test_same_day_client_and_place_make_the_same_key(): void
    {
        $a = SameVenueKey::of('2026-11-28', '小島電機工業株式会社', '東京ビッグサイト東7.8ホール');
        $b = SameVenueKey::of('2026-11-28 00:00:00', '小島電機工業株式会社　様', '東京ビッグサイト 東7.8ホール');
        $this->assertNotNull($a);
        $this->assertSame($a, $b);
    }

    public function test_a_different_day_client_or_place_is_a_different_key(): void
    {
        $base = SameVenueKey::of('2026-11-28', 'A社', '会場1');
        $this->assertNotSame($base, SameVenueKey::of('2026-11-29', 'A社', '会場1'));
        $this->assertNotSame($base, SameVenueKey::of('2026-11-28', 'B社', '会場1'));
        $this->assertNotSame($base, SameVenueKey::of('2026-11-28', 'A社', '会場2'));
    }

    public function test_empty_client_or_place_is_never_merged(): void
    {
        $this->assertNull(SameVenueKey::of('2026-11-28', '', '会場1'));
        $this->assertNull(SameVenueKey::of('2026-11-28', 'A社', '　'));
        $this->assertNull(SameVenueKey::of(null, 'A社', '会場1'));
    }

    public function test_unique_drops_the_second_of_the_same_key_but_keeps_rows_without_a_key(): void
    {
        $rows = [['k' => 'x'], ['k' => 'x'], ['k' => null], ['k' => null], ['k' => 'y']];
        $this->assertCount(4, SameVenueKey::unique($rows, fn ($r) => $r['k']));
    }
}
