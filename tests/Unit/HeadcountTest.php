<?php

namespace Tests\Unit;

use App\Support\Headcount;
use PHPUnit\Framework\TestCase;

/**
 * 運営人数の「6〜8人」の読み取り（2026-08-25 baba要望）。
 *
 * ⚠ 数字だけ抜き出す書き方だと「6〜8」が「68」になる（CSV取込で実際にそうなっていた）。
 *   ここを正本にして、その事故が戻らないようにする。
 */
class HeadcountTest extends TestCase
{
    /** 1つの数字は今までどおり（範囲ではない）。 */
    public function test_single_number(): void
    {
        $this->assertSame(['min' => null, 'max' => 8], Headcount::parse('8'));
        $this->assertSame(['min' => null, 'max' => 8], Headcount::parse('8名'));
        $this->assertSame(['min' => null, 'max' => 8], Headcount::parse(' 8 人 '));
    }

    /** 区切りの書き方がいろいろでも「6〜8」と読める。 */
    public function test_range_with_various_separators(): void
    {
        foreach (['6〜8', '6～8', '6~8', '6-8', '6－8', '6ー8', '6から8', '6〜8人', '6名～8名'] as $text) {
            $this->assertSame(['min' => 6, 'max' => 8], Headcount::parse($text), $text.' が読めること');
        }
    }

    /** 全角の数字でも読める（スプレッドシートから来ることがある）。 */
    public function test_full_width_digits(): void
    {
        $this->assertSame(['min' => 6, 'max' => 8], Headcount::parse('６〜８'));
        $this->assertSame(['min' => null, 'max' => 12], Headcount::parse('１２'));
    }

    /** 逆に書かれていても直して受ける／同じ数字なら範囲にしない。 */
    public function test_reversed_and_same(): void
    {
        $this->assertSame(['min' => 6, 'max' => 8], Headcount::parse('8〜6'));
        $this->assertSame(['min' => null, 'max' => 6], Headcount::parse('6〜6'));
    }

    /** 空・数字なしは「未入力」。勘で数字を作らない。 */
    public function test_empty(): void
    {
        $this->assertSame(['min' => null, 'max' => null], Headcount::parse(''));
        $this->assertSame(['min' => null, 'max' => null], Headcount::parse(null));
        $this->assertSame(['min' => null, 'max' => null], Headcount::parse('未定'));
    }

    /** 画面に出す文字。 */
    public function test_label(): void
    {
        $this->assertSame('6〜8', Headcount::label(6, 8));
        $this->assertSame('8', Headcount::label(null, 8));
        $this->assertSame('8', Headcount::label(8, 8));
        $this->assertSame('', Headcount::label(null, null));
        $this->assertSame('', Headcount::label(6, null), '下限だけ残っていても、計算に使う数が無ければ未入力扱い');
    }

    /** 範囲かどうか。 */
    public function test_is_range(): void
    {
        $this->assertTrue(Headcount::isRange(6, 8));
        $this->assertFalse(Headcount::isRange(null, 8));
        $this->assertFalse(Headcount::isRange(8, 8));
    }
}
