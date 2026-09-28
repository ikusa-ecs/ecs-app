<?php

namespace Tests\Unit;

use App\Support\WorkSpan;
use PHPUnit\Framework\TestCase;

/** 拘束時間（集合〜解散）の出し方。⚠ 読めない書き方は「出さない」のが正しい。 */
class WorkSpanTest extends TestCase
{
    public function test_ふつうの時間(): void
    {
        $this->assertSame('9時間', WorkSpan::label('9:00', '18:00'));
        $this->assertSame('8時間30分', WorkSpan::label('09:30', '18:00'));
        $this->assertSame('45分', WorkSpan::label('9:00', '9:45'));
    }

    public function test_日をまたぐ(): void
    {
        // 夜のイベント＝21:00集合 → 翌1:00解散。マイナスにせず24時間足す。
        $this->assertSame('4時間', WorkSpan::label('21:00', '1:00'));
    }

    public function test_全角でも読める(): void
    {
        $this->assertSame('9時間', WorkSpan::label('９：００', '１８：００'));
        $this->assertSame('9時間', WorkSpan::label('9時00', '18時00'));
    }

    public function test_読めないときは空(): void
    {
        // ⚠ 推測で埋めない。間違った拘束時間はそのまま人に伝わってしまう。
        $this->assertSame('', WorkSpan::label('', '18:00'));
        $this->assertSame('', WorkSpan::label('9:00', null));
        $this->assertSame('', WorkSpan::label('昼すぎ', '夕方'));
        $this->assertSame('', WorkSpan::label('9:99', '18:00'));
    }

    public function test_同じ時刻は0分でなく24時間にしない(): void
    {
        // 同時刻＝差0。またぎ扱いにはしない（e < s のときだけ足す）。
        $this->assertSame('0分', WorkSpan::label('9:00', '9:00'));
    }
}
