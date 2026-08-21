<?php

namespace Tests\Feature;

use App\Models\Assignment;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * アサイン系の画面に「案件の備考（直せる帯）」と「案件名のリンク」が出ているかの見張り（2026-08-21 baba要望）。
 *
 * なぜ画面の中身を文字で確かめるか
 * ・この手の部品は「表示の詰め替え」で静かに消えることがある（例：日別ボードで応募者が消えた 2026-08-21）。
 *   帯を出す呼び出しと、飛び先のリンクが view に残っているかを、ここで見張る。
 * ・備考の見た目と保存は partials/project_note.blade.php の1か所にまとめている。
 */
class AssignScreensNoteTest extends TestCase
{
    use RefreshDatabase;

    private function emp()
    {
        return PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京']);
    }

    private function project(array $attrs = [])
    {
        return ProjectFactory::new()->create(array_merge([
            'office' => '東京',
            'start_date' => Carbon::today()->addDays(5)->format('Y-m-d'),
            'note' => '前日設営あり',
        ], $attrs));
    }

    /** 日別ボード：備考の帯（直せる）と案件名リンクがある。 */
    public function test_day_board_has_editable_note_and_name_link(): void
    {
        $this->project();

        $html = $this->actingAsPerson($this->emp())->get('/assign')->assertOk()->getContent();

        $this->assertStringContainsString('window.ecsNoteHtml(c.id, c.note, USING_DB)', $html, '備考の帯を出す呼び出し');
        $this->assertStringContainsString('window.ecsNoteSave', $html, '共通部品（保存する処理）が読み込まれている');
        $this->assertStringContainsString('/project-form?project=${encodeURIComponent(c.id)}', $html, '案件名のリンク');
    }

    /** ピックアップ：同じく備考の帯と案件名リンクがある。 */
    public function test_pickup_has_editable_note_and_name_link(): void
    {
        $this->project();

        $html = $this->actingAsPerson($this->emp())->get('/pickup')->assertOk()->getContent();

        $this->assertStringContainsString('window.ecsNoteHtml(c.id, c.note, USING_DB)', $html);
        $this->assertStringContainsString('window.ecsNoteSave', $html);
        $this->assertStringContainsString('/project-form?project=${encodeURIComponent(c.id)}', $html);
    }

    /** 案件別アサイン：備考の帯（案件IDと今の備考を持つ）と案件名リンクがある。 */
    public function test_project_assign_has_editable_note_and_name_link(): void
    {
        $p = $this->project();

        $html = $this->actingAsPerson($this->emp())
            ->get('/project-assign?project=' . urlencode($p->id))->assertOk()->getContent();

        $this->assertStringContainsString('class="pnote-slot" data-id="' . $p->id . '"', $html);
        $this->assertStringContainsString('前日設営あり', $html, '今の備考が帯に入っている');
        $this->assertStringContainsString('/project-form?project=' . $p->id, $html, '案件名のリンク');
    }

    /** アサイン表：メンバーごとの備考（自由記述）の欄がある。中身も出る。 */
    public function test_assign_sheet_has_member_remark_input(): void
    {
        $p = $this->project();
        $staff = PersonFactory::new()->staff()->create(['name' => 'アサインされた人', 'office' => '東京']);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $staff->id,
            'date' => $p->start_date->format('Y-m-d'), 'role' => 'OP', 'status' => '確定',
            'remark' => '当日は直行直帰',
        ]);

        $html = $this->actingAsPerson($this->emp())
            ->get('/assign-sheet?month=' . $p->start_date->format('Y-m'))->assertOk()->getContent();

        $this->assertStringContainsString("ecsSheetSave(this,'remark',this.value)", $html, '備考の入力欄（入れると保存）');
        $this->assertStringContainsString('当日は直行直帰', $html, '入っている備考が表示される');
    }

    /** アサイン表：備考を入れると assignments.remark に保存される（他画面と同じ欄）。 */
    public function test_assign_sheet_remark_is_saved(): void
    {
        $p = $this->project();
        $staff = PersonFactory::new()->staff()->create(['office' => '東京']);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $staff->id,
            'date' => $p->start_date->format('Y-m-d'), 'role' => 'OP', 'status' => '確定',
        ]);

        $this->actingAsPerson($this->emp())->postJson('/entries/assign', [
            'project_id' => $p->id, 'staff_id' => $staff->id, 'action' => 'assign',
            'status' => '確定', 'remark' => '9時に現地集合',
        ])->assertOk();

        $row = Assignment::where('project_id', $p->id)->where('staff_id', $staff->id)->first();
        $this->assertSame('9時に現地集合', $row->remark);
        $this->assertSame('確定', $row->status, '備考を入れても確定のままにする');
    }

    /** D決め：月の切替が本物になっている（以前は「モックのため」と出るだけだった）。 */
    public function test_director_screen_month_switch_is_real(): void
    {
        $this->project();

        $html = $this->actingAsPerson($this->emp())->get('/assign-director')->assertOk()->getContent();

        $this->assertStringContainsString('shiftMonth(-1)', $html);
        $this->assertStringContainsString('shiftMonth(1)', $html);
        $this->assertStringNotContainsString('モックのため、月の切替はしません', $html);
    }
}
