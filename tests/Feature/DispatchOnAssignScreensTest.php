<?php

namespace Tests\Feature;

use App\Models\ProjectDispatch;
use App\Support\DispatchRows;
use App\Support\DispatchStatus;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 「派遣で入力したのにメンバーに出ていない」の見張り（2026-09-16 baba要望）。
 *
 * なぜ要るか
 * ・派遣の方は**名簿（people）に入れない**決まりなので、assignments には絶対に出てこない。
 *   ＝画面が project_dispatches を読みに行かないかぎり、何も表示されない。
 *   2026-09-16 まで読んでいたのは日別ボードの1画面だけで、アサイン表・案件別アサイン・
 *   ピックアップでは「派遣で埋めたのに、まだ足りないように見える」状態だった。
 * ・画面を足したり作り直したりすると、この読み込みは**静かに落ちる**（エラーにならない）。
 *   だから4画面ぶん、派遣会社名と状況（依頼中）が出ていることをここで見張る。
 *
 * ⚠ 画面を増やしたら、この見張りにも1行足すこと。
 */
class DispatchOnAssignScreensTest extends TestCase
{
    use RefreshDatabase;

    private const AGENCY = 'テスト派遣社';

    private function emp()
    {
        return PersonFactory::new()->create([
            'role' => 'employee', 'permission' => 'manager', 'office' => '東京',
            'must_onboard' => false, 'active' => true,
        ]);
    }

    /** 案件を1件作り、そこへ派遣を1件頼んだ状態にする。 */
    private function projectWithDispatch(array $attrs = [])
    {
        $p = ProjectFactory::new()->create(array_merge([
            'office' => '東京',
            'status' => '調整中',
            'required_count' => 8,
            'start_date' => Carbon::today()->addDays(5)->format('Y-m-d'),
        ], $attrs));

        ProjectDispatch::create([
            'project_id' => $p->id,
            'agency' => self::AGENCY,
            'count' => 2,
            'role' => '受付',
            'status' => DispatchStatus::ASKED,
        ]);

        return $p;
    }

    /**
     * 日別ボード：派遣会社と状況（依頼中）が画面へ渡っている。
     * ⚠ この画面は中身をJSONで渡してJSで描くので、HTMLの文字では探せない
     *   （日本語が \uXXXX に化ける）。画面へ渡した中身そのものを見る。
     */
    public function test_day_board_shows_agency_and_status(): void
    {
        $this->projectWithDispatch();

        $cases = $this->actingAsPerson($this->emp())->get('/assign')->assertOk()
            ->original->getData()['boardCases'];

        $rows = collect($cases)->flatMap(fn ($c) => $c['dispatches'] ?? []);
        $this->assertSame(self::AGENCY, $rows->first()['agency'] ?? null, '派遣会社名');
        $this->assertSame(DispatchStatus::ASKED, $rows->first()['status'] ?? null, '今の状況（依頼中）');

        $html = $this->actingAsPerson($this->emp())->get('/assign')->assertOk()->getContent();
        $this->assertStringContainsString('dispatchRowsHtml(c)', $html, 'メンバー欄に派遣の行を描く呼び出し');

        // ⚠ この画面は、サーバーから来たカードを**JSで作り直している**。
        //   作り直すところに dispatches を書き忘れると、サーバーが渡していても画面には届かない。
        //   2026-09-17 baba報告「アサイン表には出るのに日別ボードだけ出ない」＝まさにこれだった。
        $this->assertStringContainsString('dispatches:(c.dispatches||[])', $html, '詰め替え（これが無いと出ない）');
    }

    /** アサイン表：メンバー欄の下に派遣会社と状況が出る。 */
    public function test_assign_sheet_shows_agency_and_status(): void
    {
        $p = $this->projectWithDispatch();

        $html = $this->actingAsPerson($this->emp())
            ->get('/assign-sheet?month='.$p->start_date->format('Y-m'))->assertOk()->getContent();

        $this->assertStringContainsString(self::AGENCY, $html, '派遣会社名');
        $this->assertStringContainsString(DispatchStatus::ASKED, $html, '今の状況（依頼中）');
        $this->assertStringContainsString('ecs-dsp-row', $html, '共通の見た目（partials/dispatch_rows）を通っている');
    }

    /** 案件別アサイン：名簿から人を選ぶ画面にも、派遣で埋めたぶんを出す。 */
    public function test_project_assign_shows_agency_and_status(): void
    {
        $p = $this->projectWithDispatch();

        $html = $this->actingAsPerson($this->emp())
            ->get('/project-assign?project='.urlencode($p->id))->assertOk()->getContent();

        $this->assertStringContainsString(self::AGENCY, $html, '派遣会社名');
        $this->assertStringContainsString(DispatchStatus::ASKED, $html, '今の状況（依頼中）');
        $this->assertStringContainsString('ecs-dsp-row', $html);
    }

    /**
     * ピックアップ：カードのメンバー欄の下に派遣会社と状況が出る。
     * ⚠ ここもJSONで渡してJSで描く画面（→ 上と同じ理由で中身を見る）。
     */
    public function test_pickup_shows_agency_and_status(): void
    {
        $this->projectWithDispatch();

        $res = $this->actingAsPerson($this->emp())->get('/pickup')->assertOk();

        $rows = collect($res->original->getData()['pickupCases'])
            ->flatMap(fn ($c) => $c['dispatches'] ?? []);
        $this->assertSame(self::AGENCY, $rows->first()['agency'] ?? null, '派遣会社名');
        $this->assertSame(DispatchStatus::ASKED, $rows->first()['status'] ?? null, '今の状況（依頼中）');

        $this->assertStringContainsString('dispatchHtml(c)', $res->getContent(), 'カードに派遣の行を描く呼び出し');
    }

    /**
     * キャンセルした依頼も消さずに残す（頼んだ事実が消えると経緯が追えない）。
     * ただし「頼んでいる人数」には数えない。
     */
    public function test_cancelled_rows_stay_but_are_not_counted(): void
    {
        $p = $this->projectWithDispatch();
        ProjectDispatch::create([
            'project_id' => $p->id, 'agency' => 'やめた派遣社', 'count' => 3,
            'status' => DispatchStatus::CANCELLED,
        ]);

        $rows = DispatchRows::forProject($p->id);

        $this->assertCount(2, $rows, 'キャンセルも残す');
        $this->assertSame(2, DispatchRows::liveCount($rows), '人数に数えるのはキャンセル以外だけ');

        $html = $this->actingAsPerson($this->emp())
            ->get('/assign-sheet?month='.$p->start_date->format('Y-m'))->assertOk()->getContent();
        $this->assertStringContainsString('やめた派遣社', $html, 'キャンセルも画面に残す（薄く出す）');
    }

    /** 状況の言葉と色の種類は DispatchStatus が正本＝画面ごとに作り直さない。 */
    public function test_rows_carry_the_status_class_from_the_single_source(): void
    {
        $p = $this->projectWithDispatch();
        $rows = DispatchRows::forProject($p->id);

        $this->assertSame(DispatchStatus::cls(DispatchStatus::ASKED), $rows[0]['cls']);
        $this->assertStringContainsString(self::AGENCY, $rows[0]['tip']);
        $this->assertStringContainsString('受付', $rows[0]['tip']);
        $this->assertStringContainsString('派遣一覧', $rows[0]['tip'], '直す入口の案内を必ず入れる');
    }
}
