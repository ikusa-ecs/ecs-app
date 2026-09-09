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

    /**
     * ⚠ 締めた案件（🔒 この人数で足りている）と、**まだスタッフに公開していない案件**には触らないこと。
     *   公開していない案件を埋めない、は 2026-09-09 baba要望
     *   （公開＝募集を出す、なので、公開前はエントリーがまだ集まっていない）。
     * ⚠ 判定は canAuto の1か所にまとめている。ボタンの出し分け・1件ずつ・まとめて、で同じものを使う。
     */
    public function test_it_skips_settled_and_unpublished_projects(): void
    {
        $blade = $this->boardBlade();

        $this->assertStringContainsString('function bSettled(c){', $blade);
        $this->assertStringContainsString('function canAuto(c){ return bPubOn(c) && !bSettled(c); }', $blade);
        $this->assertStringContainsString('c.off === off && canAuto(c) && filledOf(c) < c.need', $blade);
        // 1件ずつの入口（⚡ボタン）でも同じ判定を通していること。
        $this->assertStringContainsString('if (!canAuto(c)) {', $blade);
        // ボタンそのものも、公開していない案件では出さない。
        $this->assertStringContainsString('${(filled < c.need && canAuto(c)) ?', $blade);
    }


    /**
     * ⚠ 自動アサインが選ぶのは**スタッフだけ**（2026-09-09 baba
     * 「日別ボードで自動アサインを押したら社員も選ばれるから社員は外してほしい」）。
     *
     * 社員の出勤可能日も、スタッフの稼働希望と同じ表（shift_preferences）に入るので、
     * 印（emp）で外さないと候補に混ざる。社員を入れるかどうかは人が決めること
     * ＝「手動編集」から足せる（機械が勝手に入れない）。
     * ⚠ 月まとめ自動アサインは、はじめからスタッフだけを見ている（MonthAutoAssign::candidatePeople）。
     */
    public function test_it_does_not_pick_employees(): void
    {
        $this->assertStringContainsString(
            '.filter(p => !p.emp)',
            $this->boardBlade(),
            '自動アサインの候補から社員を外す仕掛けが消えています'
        );
    }


    /**
     * ⚠ 日別ボードも、月まとめと同じ2つの決まりで動くこと（2026-09-09 baba）。
     *   ① FC・CK は「みんなできる」＝一覧は window.ECS_ANYONE_ROLES から受け取る
     *      （画面に役割名を書き写すと、月まとめと食い違って
     *        「日別では入るのに月まとめでは入らない」になる）
     *   ② 役割が埋まらない枠は「未定」で埋める（候補が残っているのに人数が足りない、を無くす）
     */
    public function test_it_shares_the_role_rules_with_the_month_engine(): void
    {
        $blade = $this->boardBlade();

        $this->assertStringContainsString("window.ECS_ANYONE_ROLES", $blade,
            'FC・CKを「みんなできる」とする一覧を画面が受け取っていません');
        $this->assertStringContainsString("if ((window.ECS_ANYONE_ROLES || []).indexOf(role) >= 0) return true;", $blade);
        $this->assertStringContainsString('function fillOneSlot(role){', $blade);
        $this->assertStringContainsString("for (let i = 0; i < unfilled; i++) { if (!fillOneSlot('')) break; }", $blade,
            '役割が埋まらない枠を「未定」で埋める仕掛けが消えています');
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

    /**
     * ⚠ 日別ボードの自動アサインも「必要ポジション」を見ること（2026-09-07 baba指摘）。
     *
     * 前は**その人の主ポジションをそのまま付けていた**ので、
     * 「MCが2人いる」「謎解きなのに軍師がいる」が起きていた。
     * 中身は画面のJavaScriptなので、ここで確かめられるのは
     * 「必要ポジションのデータが画面に届いているか」と「仕掛けが消えていないか」。
     */
    public function test_the_board_gets_the_position_template(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin', 'office' => '東京']);
        $day = \Illuminate\Support\Carbon::today()->addDays(3);
        \App\Models\ContentRoleRequirement::create([
            'content_id' => 'CT-TEST', 'scale' => '中型', 'position' => 'MC', 'count' => 1,
        ]);
        $p = \Database\Factories\ProjectFactory::new()->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 2,
            'content_ids' => ['CT-TEST'], 'scale' => '中型', 'office' => '東京',
        ]);

        $card = collect(
            $this->actingAsPerson($me)->get('/assign')->assertOk()->original->getData()['boardCases']
        )->firstWhere('id', $p->id);

        $this->assertSame(['MC' => 1], $card['template'], '必要ポジションが画面に届くこと');
    }

    /** ⚠ 画面での詰め替え・枠づくりの仕掛けが消えていないこと。 */
    public function test_the_view_fills_by_slots(): void
    {
        $blade = $this->boardBlade();

        // 詰め替え（忘れるとカードに届かない＝この画面でよくある事故）
        $this->assertStringContainsString('template:(c.template || {})', $blade);
        // 枠づくりと「その役割ができるか」
        $this->assertStringContainsString('function openSlotsOf(c, room){', $blade);
        // ⚠ 案件（c）も渡す＝MCの「どの規模までできるか」を見るため（2026-09-09）。
        $this->assertStringContainsString('function canDoRole(cand, role, c){', $blade);
        // ⚠ 主ポジションをそのまま役割にしていないこと（ここに戻ると不具合が再発する）
        $this->assertStringNotContainsString('let rc = p.roleCode || ', $blade,
            '主ポジションをそのまま役割にしています。必要ポジションの枠から入れてください。');
    }

    /** ⚠ 「できる役割ぜんぶ」が届いていること（1つしか渡さないと枠に入れられない）。 */
    public function test_candidates_carry_all_their_roles(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin', 'office' => '東京']);
        $day = \Illuminate\Support\Carbon::today()->addDays(3);
        $p = \Database\Factories\ProjectFactory::new()->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 1, 'office' => '東京',
        ]);
        $s = PersonFactory::new()->staff()->create(['office' => '東京']);
        foreach (['MC', 'FC'] as $r) {
            \App\Models\StaffRoleEligibility::create(['staff_id' => $s->id, 'position' => $r]);
        }
        \App\Models\Application::create([
            'project_id' => $p->id, 'staff_id' => $s->id, 'intent' => '希望',
        ]);

        $card = collect(
            $this->actingAsPerson($me)->get('/assign')->assertOk()->original->getData()['boardCases']
        )->firstWhere('id', $p->id);

        $roles = $card['entrants'][0]['roles'] ?? ($card['applicants'][0]['roles'] ?? []);
        sort($roles);
        $this->assertSame(['FC', 'MC'], $roles);
    }
}
