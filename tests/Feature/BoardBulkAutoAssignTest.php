<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 日別ボードの「この日の◯件を自動アサイン」を守るテスト（2026-09-07 baba要望
 * 「日毎の自動アサインのボタンで一気にできたら理想」）。
 *
 * ⚠ 中身は画面の JavaScript なので、ここで確かめられるのは
 *   「仕掛けが消えていないか」だけ。**押して動くかは baba にしか確かめられない。**
 *   JavaScript が壊れていないこと自体は RenderedScriptSyntaxTest が全画面で見張っている。
 *
 * ⚠ とくに守りたい決まり：
 *   ・1件ずつ**順に**流す。autoAssign は「同じ日の他の案件にもう入っている人」を除くので、
 *     順に流せば同じ人が同じ日に2つの案件へ入らない。並行に流すと重複する。
 *   ・確定・公開ずみ（🔒 この人数で足りている）の案件には触らない。
 *   ・お知らせは最後に1回だけ（案件ごとに出すと件数ぶん「OK」を押すことになる）。
 */
class BoardBulkAutoAssignTest extends TestCase
{
    use RefreshDatabase;

    private function boardBlade(): string
    {
        return (string) file_get_contents(resource_path('views/assign.blade.php'));
    }

    /** 日の見出しにボタンを出す仕掛けが残っていること。 */
    public function test_the_day_button_exists(): void
    {
        $blade = $this->boardBlade();

        $this->assertStringContainsString('function bulkAutoDay(off){', $blade);
        $this->assertStringContainsString('bulkAutoDay(${off})', $blade);
    }

    /** ⚠ 1件ずつ順に流していること（並行に流すと同じ人が同じ日に重複する）。 */
    public function test_it_runs_one_by_one(): void
    {
        $this->assertStringContainsString(
            'list.forEach(c => { const r = autoAssign(c.id, true); if (r) results.push(r); });',
            $this->boardBlade(),
            '順に流す形が崩れています（並行に流すと同じ人が同じ日に重複します）'
        );
    }

    /** ⚠ 締めた案件（🔒 この人数で足りている）には触らないこと。 */
    public function test_it_skips_settled_projects(): void
    {
        $blade = $this->boardBlade();

        $this->assertStringContainsString('function bSettled(c){', $blade);
        $this->assertStringContainsString('c.off === off && !bSettled(c) && filledOf(c) < c.need', $blade);
    }

    /** 1件ずつの自動アサインは、これまでどおりお知らせを出すこと（silent は一括のときだけ）。 */
    public function test_single_auto_assign_still_alerts(): void
    {
        $blade = $this->boardBlade();

        $this->assertStringContainsString("onclick=\"autoAssign('\${c.id}')\"", $blade,
            'カードのボタンは silent なしで呼ぶこと（1件ずつのときは結果を知らせる）');
        $this->assertStringContainsString('if (silent) return {', $blade);
    }

    /** 画面が開けること（JavaScript が壊れていないかは RenderedScriptSyntaxTest が見張る）。 */
    public function test_the_board_opens(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin']);

        $this->actingAsPerson($me)->get('/assign')
            ->assertOk()
            ->assertSee('bulkAutoDay', false);
    }
}
