<?php

namespace Tests\Unit;

use App\Support\Headcount;
use PHPUnit\Framework\TestCase;

/**
 * お客様人数の読み方（2026-10-26）。「1部最大200名」を1と読んでいた。
 */
class GuestCountTest extends TestCase
{
    public function test_session_number_is_not_the_guest_count(): void
    {
        $this->assertSame(200, Headcount::guestCount('1部最大200名'));
        $this->assertSame(200, Headcount::guestCount('1部 最大200名'));
    }

    public function test_plain_numbers_keep_working(): void
    {
        $this->assertSame(100, Headcount::guestCount('100名'));
        $this->assertSame(1503, Headcount::guestCount('1,503名'));
        $this->assertSame(150, Headcount::guestCount('150'));
        $this->assertNull(Headcount::guestCount(''));
    }

    public function test_max_word_takes_the_number_after_it(): void
    {
        $this->assertSame(300, Headcount::guestCount('最大300名'));
        $this->assertSame(300, Headcount::guestCount('上限 300名'));
    }
}
