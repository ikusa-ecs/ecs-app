<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\JsSyntaxCheck;
use Tests\TestCase;

/**
 * 文字化けしたデータが1つあるだけで画面が丸ごと動かなくなる、を防ぐ（2026-08-31）。
 *
 * 【なぜ要るか＝本番でこれが起きた】
 * 画面へデータを渡すときは `@json(...)` を使っている。これは中で json_encode を呼ぶが、
 * **文字コードが壊れた文字が1つでも混ざっていると json_encode は「失敗」を返す**。
 * Blade はその失敗（false）をそのまま出すので、出来上がるのは
 *      window.ECS_PUBLISHED = ;
 * という**文法として成り立たない行**になる。すると：
 *   ・その画面の JavaScript が**丸ごと読み込みに失敗する**
 *   ・案件一覧が空になる／タブもボタンも一切押せなくなる
 *   ・画面は普通に出るので、目でもテストでも気づけない
 * ＝ 2026-08-31 に本番のスタッフ画面で起きた形。
 *
 * 文字化けは、CSVの取り込み（Excelの文字コード違い）やコピペで簡単に入ってくる。
 * **1件の壊れたデータで全員の画面が止まる**のが問題なので、壊れた文字は
 * 「?」などに置き換えてでも**画面を動かし続ける**のが正しい（正本＝App\Support\SafeJson）。
 */
class BrokenTextDoesNotKillScriptTest extends TestCase
{
    use RefreshDatabase;

    /** 文字コードが壊れた文字列（UTF-8として不正なバイト）。 */
    private const BROKEN = "\xB0\xA1\xFF 徳川 満里花";

    public function test_the_staff_portal_survives_broken_text(): void
    {
        $staff = PersonFactory::new()->staff()->create(['office' => '東京']);
        $p = ProjectFactory::new()->create([
            'is_recruiting' => true,
            'staff_published' => true,
            'status' => '募集する',
            'office' => '東京',
            'start_date' => now()->addDays(20)->format('Y-m-d'),
        ]);
        // 取り込みで入りうる形＝DBに直接、壊れた文字を入れる。
        DB::table('projects')->where('id', $p->id)->update(['client' => self::BROKEN]);

        $res = $this->actingAsPerson($staff)->get('/staff-portal')->assertOk();

        // ⚠ まず「壊れたデータが画面まで届いている」ことを確かめる。
        //   届いていなければ、このテストは何も見張っていないことになる。
        preg_match('~window\.ECS_RECRUIT_JOBS = (.{0,10})~s', $res->getContent(), $m);
        $this->assertStringStartsWith('[{', $m[1] ?? '',
            '@json が文字化けで空になり、JavaScriptの文法が壊れています。実際の出力＝'.($m[1] ?? '(見つからず)'));

        $bad = [];
        foreach (JsSyntaxCheck::extractScripts($res->getContent()) as $n => $js) {
            foreach (JsSyntaxCheck::problems($js) as $prob) {
                $bad[] = "{$n}個目の<script>： {$prob}";
            }
        }

        $this->assertSame([], $bad,
            "文字化けしたデータが1件あるだけで、スタッフ画面のJavaScriptが壊れました。\n"
            ."この状態になると、案件一覧が空になり、タブもボタンも押せなくなります。\n"
            .implode("\n", $bad));
    }

    /** 名簿（社員の名前が化けている場合）も同じ。 */
    public function test_the_roster_survives_broken_text(): void
    {
        $admin = PersonFactory::new()->admin()->create();
        $staff = PersonFactory::new()->staff()->create();
        DB::table('people')->where('id', $staff->id)->update(['appeal' => self::BROKEN]);

        $res = $this->actingAsPerson($admin)->get('/staff')->assertOk();

        $bad = [];
        foreach (JsSyntaxCheck::extractScripts($res->getContent()) as $n => $js) {
            foreach (JsSyntaxCheck::problems($js) as $prob) {
                $bad[] = "{$n}個目の<script>： {$prob}";
            }
        }

        $this->assertSame([], $bad, '文字化けしたデータで名簿のJavaScriptが壊れました。');
    }
}
