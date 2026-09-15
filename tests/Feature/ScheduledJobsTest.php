<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * 「時間が来たら勝手に動くもの」の一覧を見張る。
 *
 * ⚠ 自動送信は**気づかないうちに全員へ飛ぶ**ので、増やすときは必ず人が決める。
 *   2026-09-15 に実際にヒヤリとした：人数確定リマインドが平日9時の自動送信になっていたが、
 *   本番にチャットワークのトークンが入っていなかったため**一度も飛んでいなかった**。
 *   トークンを入れた瞬間、翌朝いきなり41件ぶんのメッセージとタスクが飛ぶ状態だった。
 *   → baba「一旦自動送信はやめたい」で止めた。
 */
class ScheduledJobsTest extends TestCase
{
    /** いま自動で動いてよいもの。⚠ ここに無いものが動いていたらテストが落ちる。 */
    private const ALLOWED = [
        // 届かなかった日に知らせるだけ（誰かへ一斉送信はしない）。
        'sheet-sync-watch',
    ];

    private function names(): array
    {
        return collect(app(Schedule::class)->events())
            ->map(fn ($e) => (string) $e->description)
            ->filter()
            ->values()
            ->all();
    }

    public function test_勝手に動くものが増えていない(): void
    {
        foreach ($this->names() as $name) {
            $this->assertContains($name, self::ALLOWED,
                "「{$name}」が自動で動く設定になっています。"
                .'人に一斉送信するものを自動にするときは、必ず baba の判断を取ってから '
                .'ScheduledJobsTest::ALLOWED に足してください。');
        }
    }

    /**
     * ⚠ 人数確定リマインドは自動送信しない（2026-09-15 baba決定）。
     * 送るのは 左メニュー「人数確定リマインド」から人が押したときだけ。
     */
    public function test_人数確定リマインドは自動送信しない(): void
    {
        $this->assertNotContains('count-deadline-reminder', $this->names(),
            '人数確定リマインドが自動送信に戻っています（押したときだけ送る決まりです）');
    }

    /** 収支未入力リマインドも自動送信しない（画面から押したときだけ）。 */
    public function test_収支リマインドは自動送信しない(): void
    {
        $this->assertNotContains('finance-reminder', $this->names());
    }
}
