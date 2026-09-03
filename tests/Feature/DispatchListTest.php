<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\ProjectDispatch;
use App\Support\DispatchStatus;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 派遣一覧（/dispatch-list）と、日別ボードの「＋派遣」の保存
 * （2026-09-03 baba要望「派遣で書いた案件が一覧で出るシートを作りたい」）。
 *
 * ⚠ それまで日別ボードの「＋派遣」は**画面の中だけ**の動きで、DBに何も残っていなかった
 *   （assign.blade.php の addHaken が画面の配列に足すだけ）。
 *   ＝押しても読み込み直すと消え、「どの案件に、どこへ、何名頼んだか」の記録が無かった。
 *   この見張りは「保存されなくなっていないか」を見る。
 *
 * ⚠ 名簿（people）には入れない。派遣の方を名簿に入れると、アサイン表・集計・スタッフ名簿
 *   すべてに並び、同じ人が毎回違うと増え続ける（2026-09-03 baba決定）。
 */
class DispatchListTest extends TestCase
{
    use RefreshDatabase;

    private function emp(string $permission = 'manager', string $office = '東京'): Person
    {
        return PersonFactory::new()->create([
            'role' => 'employee', 'permission' => $permission,
            'office' => $office, 'must_onboard' => false, 'active' => true,
        ]);
    }

    private function project(string $id = 'P-D1', string $office = '東京', int $plusDays = 10): string
    {
        ProjectFactory::new()->create([
            'id' => $id, 'project_name' => 'テスト案件', 'client' => 'テスト会社',
            'office' => $office, 'status' => '調整中', 'required_count' => 8,
            'start_date' => Carbon::today()->addDays($plusDays)->format('Y-m-d'),
            'location' => '千葉県流山市南流山1-2-3 ○○ホール',
        ]);

        return $id;
    }

    /** 日別ボードの「＋派遣」が、ちゃんとDBに保存されること。 */
    public function test_a_dispatch_request_is_saved(): void
    {
        $pid = $this->project();

        $this->actingAsPerson($this->emp())
            ->postJson('/dispatches', ['project_id' => $pid, 'agency' => '○○スタッフ', 'count' => 2, 'role' => '受付'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('project_dispatches', [
            'project_id' => $pid, 'agency' => '○○スタッフ', 'count' => 2,
            'role' => '受付', 'status' => DispatchStatus::ASKED,
        ]);
    }

    /** 派遣先を書かずに送ったら止める（空の行が増えないように）。 */
    public function test_an_empty_agency_is_refused(): void
    {
        $pid = $this->project();

        $this->actingAsPerson($this->emp())
            ->postJson('/dispatches', ['project_id' => $pid, 'agency' => ''])
            ->assertStatus(422);

        $this->assertDatabaseCount('project_dispatches', 0);
    }

    /** 他の拠点の案件には入れられない（保存の入口で止める）。 */
    public function test_another_office_project_is_refused(): void
    {
        $pid = $this->project('P-OSA', '大阪');

        $this->actingAsPerson($this->emp('employee', '東京'))
            ->postJson('/dispatches', ['project_id' => $pid, 'agency' => '○○スタッフ'])
            ->assertStatus(403);

        $this->assertDatabaseCount('project_dispatches', 0);
    }

    /** 一覧に、開催日・案件・会場（市区町村まで）・派遣先が並ぶこと。 */
    public function test_the_sheet_lists_what_was_asked(): void
    {
        $pid = $this->project();
        ProjectDispatch::create([
            'project_id' => $pid, 'agency' => '△△人材', 'count' => 3,
            'role' => 'FC', 'status' => DispatchStatus::ASKED, 'note' => '返事待ち',
        ]);

        $this->actingAsPerson($this->emp())->get('/dispatch-list')
            ->assertOk()
            ->assertSee('△△人材')
            ->assertSee('返事待ち')
            ->assertSee('千葉県流山市')   // 住所そのままではなく市区町村まで
            ->assertSee('テスト案件');
    }

    /** 人数・役割・状態・備考をあとから直せること。送っていない欄は触らないこと。 */
    public function test_a_row_can_be_fixed_without_wiping_other_fields(): void
    {
        $pid = $this->project();
        $d = ProjectDispatch::create([
            'project_id' => $pid, 'agency' => '○○スタッフ', 'count' => 1,
            'role' => '受付', 'status' => DispatchStatus::ASKED, 'note' => '返事待ち',
        ]);

        $this->actingAsPerson($this->emp())
            ->postJson('/dispatches/'.$d->id, ['status' => DispatchStatus::FIXED])
            ->assertOk();

        $d->refresh();
        $this->assertSame(DispatchStatus::FIXED, $d->status);
        $this->assertSame('受付', $d->role, '送っていない欄が空で上書きされています。');
        $this->assertSame('返事待ち', $d->note, '送っていない欄が空で上書きされています。');
    }

    /** キャンセルにしても行は残る（頼んだ事実が消えると経緯が追えない）。 */
    public function test_cancelling_keeps_the_record(): void
    {
        $pid = $this->project();
        $d = ProjectDispatch::create([
            'project_id' => $pid, 'agency' => '○○スタッフ', 'count' => 1, 'status' => DispatchStatus::ASKED,
        ]);

        $this->actingAsPerson($this->emp())
            ->postJson('/dispatches/'.$d->id, ['status' => DispatchStatus::CANCELLED])
            ->assertOk();

        $this->assertDatabaseHas('project_dispatches', ['id' => $d->id, 'status' => DispatchStatus::CANCELLED]);
        $this->actingAsPerson($this->emp())->get('/dispatch-list')->assertOk()->assertSee('○○スタッフ');
    }

    /** 打ち間違いの行は消せること。 */
    public function test_a_row_can_be_deleted(): void
    {
        $pid = $this->project();
        $d = ProjectDispatch::create(['project_id' => $pid, 'agency' => 'うち間違い', 'count' => 1]);

        $this->actingAsPerson($this->emp())
            ->deleteJson('/dispatches/'.$d->id)
            ->assertOk();

        $this->assertDatabaseCount('project_dispatches', 0);
    }

    /** 他の拠点の派遣依頼は直せない。 */
    public function test_another_office_row_cannot_be_fixed(): void
    {
        $pid = $this->project('P-OSA', '大阪');
        $d = ProjectDispatch::create(['project_id' => $pid, 'agency' => '大阪の派遣', 'count' => 1]);

        $this->actingAsPerson($this->emp('employee', '東京'))
            ->postJson('/dispatches/'.$d->id, ['count' => 9])
            ->assertStatus(403);

        $this->assertSame(1, $d->refresh()->count);
    }

    /** 日別ボードで、保存済みの派遣がメンバー欄に出ること（＝読み込み直しても消えない）。 */
    public function test_the_day_board_shows_saved_dispatches(): void
    {
        $pid = $this->project('P-D1', '東京', 3);
        ProjectDispatch::create([
            'project_id' => $pid, 'agency' => '○○スタッフ', 'count' => 2, 'status' => DispatchStatus::ASKED,
        ]);

        $res = $this->actingAsPerson($this->emp())->get('/assign')->assertOk();
        $case = collect($res->original->getData()['boardCases'])->firstWhere('id', $pid);

        $this->assertSame('○○スタッフ', $case['dispatches'][0]['agency']);
        $res->assertSee('function dispatchRowsHtml', false)
            ->assertSee("fetch('/dispatches'", false);
    }

    /** 過去の案件も「過去も出す」で見られること。 */
    public function test_past_rows_can_be_shown(): void
    {
        $pid = $this->project('P-OLD', '東京', -30);
        ProjectDispatch::create(['project_id' => $pid, 'agency' => 'むかしの派遣', 'count' => 1]);

        $this->actingAsPerson($this->emp())->get('/dispatch-list')
            ->assertOk()->assertDontSee('むかしの派遣');

        $this->actingAsPerson($this->emp())->get('/dispatch-list?past=1')
            ->assertOk()->assertSee('むかしの派遣');
    }

    /** 左メニューから行けること（作っても入口が無ければ誰も使えない）。 */
    public function test_the_sidebar_has_a_link(): void
    {
        $this->actingAsPerson($this->emp())->get('/dispatch-list')
            ->assertOk()
            ->assertSee('href="/dispatch-list"', false)
            ->assertSee('派遣一覧');
    }
}
