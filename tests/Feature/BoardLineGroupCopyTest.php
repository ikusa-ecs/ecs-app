<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Support\LineGroupText;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 日別ボードの「📱 LINE」＝LINEグループを作るときのコピー（2026-09-16 baba要望）。
 *
 * 見張るのは3つ。
 *   ① カードが3つの文章（アイコン・グループ名・概要文）を持っていること
 *   ② 概要文のポジションが、その案件のアサイン人数どおりに出ること
 *   ③ 準備チェックが**案件一覧とまったく同じ列**に保存されること
 *      （画面ごとに別の場所へ保存すると「片方は押されているのに片方は押されていない」になる）
 */
class BoardLineGroupCopyTest extends TestCase
{
    use RefreshDatabase;

    private function manager()
    {
        return PersonFactory::new()->manager()->create(['office' => '東京']);
    }

    private function project()
    {
        return ProjectFactory::new()->published()->create([
            'office' => '東京',
            'start_date' => Carbon::today()->addDays(5)->format('Y-m-d'),
            'client' => '東京水道株式会社様',
            'content_names' => ['謎パ'],
            'start_time' => '7:30',
            'end_time' => '17:00',
            'status' => '調整中',
        ]);
    }

    /** 日別ボードのカードを1枚取り出す。 */
    private function card($me, string $projectId): ?array
    {
        return collect(
            $this->actingAsPerson($me)->get('/assign')->assertOk()->original->getData()['boardCases']
        )->firstWhere('id', $projectId);
    }

    /** ① カードが3つの文章を持っている（画面はこれをそのまま枠に出すだけ）。 */
    public function test_the_card_carries_the_three_texts(): void
    {
        $p = $this->project();
        $card = $this->card($this->manager(), $p->id);

        $this->assertNotNull($card, '日別ボードにこの案件が出ていません');

        $ymd = Carbon::parse($p->start_date)->format('ymd');
        $this->assertSame($ymd."\n謎パ\n東京水道株式会社", $card['lineIcon']);
        // グループ名には「様」を付ける（2026-09-16 baba）。省くのはアイコンだけ。
        $this->assertSame($ymd.'謎パ＠東京水道株式会社様', $card['lineName']);
        $this->assertStringContainsString('コンテンツ　謎パ', $card['lineText']);
        $this->assertStringContainsString('7:30', $card['lineText']);
    }

    /**
     * ② 概要文のポジションが、いまアサインされている人数どおりに出る。
     * ⚠ この画面に置いた理由がこれ。案件一覧では誰が入っているか分からない。
     */
    public function test_position_lines_follow_the_assignment(): void
    {
        $p = $this->project();
        $me = $this->manager();

        foreach (['OP', 'OP', 'MC'] as $i => $role) {
            $staff = PersonFactory::new()->create(['office' => '東京']);
            Assignment::create([
                'project_id' => $p->id,
                'staff_id' => $staff->id,
                'date' => $p->start_date,
                'role' => $role,
                'status' => '確定',
            ]);
        }

        $text = $this->card($me, $p->id)['lineText'];

        $this->assertSame(2, substr_count($text, 'OP予定 @'), 'OP2名ぶんの行が出ていません');
        $this->assertSame(1, substr_count($text, 'MC予定 @'));
        // 名前は出さない＝手で打ちながら、その人がグループに入っているか確かめるため。
        $this->assertStringNotContainsString('@' . '　', $text);
    }

    /**
     * ③ 日別ボードの準備チェックが、案件一覧と同じ列に保存される。
     * ⚠ baba「同期されて片方押されてるけど片方押されてないにならないようにして」（2026-09-16）。
     */
    public function test_the_prep_check_is_the_same_one_as_the_project_list(): void
    {
        $p = $this->project();
        $me = $this->manager();

        $this->actingAsPerson($me)
            ->postJson('/projects/cells', ['id' => $p->id, 'prep_line_created' => true])
            ->assertOk();

        // DBの列が1つ（＝どちらの画面も同じものを見る）。
        $this->assertTrue((bool) $p->fresh()->prep_line_created);

        // 日別ボードのカードにも出る。
        $this->assertTrue($this->card($me, $p->id)['lineMade'], '日別ボードに反映されていません');

        // 案件一覧にも出る（同じ列を読んでいる）。
        $row = collect(
            $this->actingAsPerson($me)->get('/projects')->assertOk()->original->getData()['cases']
        )->firstWhere('id', $p->id);
        $this->assertNotNull($row, '案件一覧にこの案件が出ていません');
        $this->assertTrue($row['lineMade'], '案件一覧に反映されていません＝保存先が分かれています');
    }

    /** ④ 外したときも両方から消える。 */
    public function test_unchecking_clears_it_everywhere(): void
    {
        $p = $this->project();
        $me = $this->manager();

        $this->actingAsPerson($me)->postJson('/projects/cells', ['id' => $p->id, 'prep_line_created' => true])->assertOk();
        $this->actingAsPerson($me)->postJson('/projects/cells', ['id' => $p->id, 'prep_line_created' => false])->assertOk();

        $this->assertFalse((bool) $p->fresh()->prep_line_created);
        $this->assertFalse($this->card($me, $p->id)['lineMade']);
    }

    /** ⑤ 定型文は共通設定から変えられ、その場で概要文に反映される。 */
    public function test_the_notice_is_editable_from_settings(): void
    {
        $p = $this->project();
        $me = $this->manager();

        $this->actingAsPerson($me)
            ->post('/settings/line-notice', ['notice' => "新しい決まり文句\nhttps://example.com/ecs"])
            ->assertRedirect();

        $text = $this->card($me, $p->id)['lineText'];
        $this->assertStringContainsString('新しい決まり文句', $text);
        $this->assertStringContainsString('https://example.com/ecs', $text);
        $this->assertStringNotContainsString('※前泊や同日案件がないか', $text, '古い定型文が残っています');
    }

    /**
     * ⑥ 画面（JS）がLINEの文章を「詰め替えて」いること。
     *
     * ⚠ 2026-09-16 の不具合はここだった。サーバーは lineIcon/lineName/lineText を渡していたのに、
     *   日別ボードのJSがカードを作り直すときに**写し忘れて**いたため、
     *   「📱 LINE」のパネルは開くのに**枠の中が空**だった（baba報告「肝心の文字が表示されてない」）。
     *   ⚠ この画面はサーバーから来たカードを作り直すつくりなので、
     *     コントローラに足しただけでは画面に出ない。この見張りを消さないこと。
     */
    public function test_the_board_view_keeps_the_line_fields_in_its_mapping(): void
    {
        $html = $this->actingAsPerson($this->manager())->get('/assign')->assertOk()->getContent();

        foreach (['lineIcon:c.lineIcon', 'lineName:c.lineName', 'lineText:c.lineText',
            'lineMade:c.lineMade', 'lineSent:c.lineSent', 'lineDouble:c.lineDouble'] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $html,
                "画面の詰め替えから {$needle} が消えると、「📱 LINE」の枠が空になります"
            );
        }
    }

    /** ⑦ 共通設定の画面に、いまの定型文が出ている。 */
    public function test_the_settings_screen_shows_the_notice(): void
    {
        $me = PersonFactory::new()->admin()->create(['office' => '東京']);
        LineGroupText::saveNotice('いまの決まり文句');

        $this->actingAsPerson($me)
            ->get('/settings')
            ->assertOk()
            ->assertSee('LINEの概要に付ける定型文')
            ->assertSee('いまの決まり文句');
    }

    /**
     * パネルを閉じる道が「上のLINEボタン」以外にもある
     * （2026-09-18 baba「下まで行った後、また閉じるためにラインのボタン押すの面倒」）。
     * ⚠ 概要文が長いので、下まで読んだあと上へ戻らないと閉じられない状態だった。
     */
    public function test_the_panel_can_be_closed_from_the_bottom(): void
    {
        $html = $this->actingAsPerson($this->manager())->get('/assign')->assertOk()->getContent();

        $this->assertStringContainsString('function closeLine(', $html, '閉じる処理');
        $this->assertStringContainsString('line-foot', $html, 'パネルの下の閉じる欄');
        // Escキーでも閉じられる。
        $this->assertStringContainsString("e.key !== 'Escape'", $html, 'Escキーで閉じる');
        // 閉じたあとは、その案件のLINEボタンまで画面を戻す（どの案件を見ていたか分かるように）。
        $this->assertStringContainsString("scrollIntoView", $html, '閉じたあと位置を戻す');
    }
}
