<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\CalendarSync;
use App\Models\Person;
use App\Models\Project;
use App\Support\CalendarSyncQueue;
use App\Support\CalendarTitle;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Googleカレンダー連携の土台（2026-08-27 baba要望）。
 *
 * 【なぜ待ち行列にしたか】
 * ⚠ 案件やDが変わる入口はとても多い（Dだけで5か所・日程で3か所・キャンセル／削除／
 *   アーカイブはそれぞれ別処理）。保存のたびにカレンダーへ送る作りにすると、
 *   必ずどこかを書き忘れて古い予定が残る。
 *   そこで「保存されたら印を付けるだけ」にして、あとでまとめて直す。
 *
 * このテストは「どの入口から変えても印が付くか」を見張るもの。
 */
class CalendarSyncQueueTest extends TestCase
{
    use RefreshDatabase;

    private function project(array $extra = []): Project
    {
        return Project::create(array_merge([
            'id' => 'P-1',
            'project_name' => '会議室',
            'content_names' => ['会議室'],
            'client' => 'コニカミノルタジャパン',
            'start_date' => Carbon::today()->copy()->addDays(10)->toDateString(),
            'event_start_time' => '15:40',
            'event_end_time' => '17:40',
            'guest_count' => 45,
            'office' => '東京',
            'yomi' => '確定',
            'status' => '確定',
            'sales_owners' => ['横山 更紗'],
        ], $extra));
    }

    private function row(string $projectId = 'P-1'): ?CalendarSync
    {
        return CalendarSync::where('project_id', $projectId)->first();
    }

    /** 案件を登録すると「直す必要がある」印が付く。 */
    public function test_creating_a_project_marks_the_queue(): void
    {
        $this->project();

        $row = $this->row();
        $this->assertNotNull($row);
        $this->assertTrue($row->needs_sync);
        $this->assertFalse($row->needs_delete);
    }

    /** 日程を変えても印が付く（案件登録・案件一覧・取込のどこから変えても同じ）。 */
    public function test_changing_the_date_marks_the_queue(): void
    {
        $p = $this->project();
        CalendarSync::where('project_id', 'P-1')->update(['needs_sync' => false, 'google_event_id' => 'ev-1']);

        $p->start_date = Carbon::today()->copy()->addDays(20)->toDateString();
        $p->save();

        $this->assertTrue($this->row()->needs_sync);
    }

    /**
     * ⚠ Dが決まったら印が付く（招待を足すため）。
     * Dが変わる入口は5か所あるが、どれも最後は assignments に書くのでここで拾える。
     */
    public function test_assigning_a_person_marks_the_queue(): void
    {
        $p = $this->project();
        $d = PersonFactory::new()->create(['id' => 'E-001', 'name' => '田中 健一']);
        CalendarSync::where('project_id', 'P-1')->update(['needs_sync' => false, 'google_event_id' => 'ev-1']);

        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $d->id,
            'date' => $p->start_date, 'role' => 'D', 'status' => '確定',
        ]);

        $this->assertTrue($this->row()->needs_sync);
    }

    /** ⚠ キャンセルにすると「消す」印が付く（実施しないので予定を残さない）。 */
    public function test_cancelling_marks_for_delete(): void
    {
        $p = $this->project();
        CalendarSync::where('project_id', 'P-1')->update(['google_event_id' => 'ev-1']);

        $p->is_cancelled = true;
        $p->save();

        $row = $this->row();
        $this->assertTrue($row->needs_delete);
        $this->assertFalse($row->needs_sync);
    }

    /** ⚠ 手で隠した（アーカイブ）ときも消す印が付く。 */
    public function test_archiving_marks_for_delete(): void
    {
        $p = $this->project();
        CalendarSync::where('project_id', 'P-1')->update(['google_event_id' => 'ev-1']);

        $p->is_archived = true;
        $p->save();

        $this->assertTrue($this->row()->needs_delete);
    }

    /** ⚠ 案件を消したときも消す印が付く（予定IDは待ち行列に残っている）。 */
    public function test_deleting_marks_for_delete(): void
    {
        $p = $this->project();
        CalendarSync::where('project_id', 'P-1')->update(['google_event_id' => 'ev-1']);

        $p->delete();

        $row = $this->row();
        $this->assertNotNull($row, '案件が消えても待ち行列の行は残す（予定を消すのに要る）');
        $this->assertTrue($row->needs_delete);
    }

    /** まだ予定を作っていない案件をキャンセルしても、消す印は立てない（消すものが無い）。 */
    public function test_cancelling_before_any_event_does_not_ask_for_delete(): void
    {
        $p = $this->project();

        $p->is_cancelled = true;
        $p->save();

        $row = $this->row();
        $this->assertFalse($row->needs_delete);
        $this->assertFalse($row->needs_sync);
    }

    /** 日程が未定の案件は予定を作らない（いつの予定か決められない）。 */
    public function test_project_without_a_date_is_not_queued(): void
    {
        $this->project(['start_date' => null]);

        $this->assertNull($this->row());
    }

    /** 下書きの案件は予定を作らない。 */
    public function test_draft_project_is_not_queued(): void
    {
        $this->project(['status' => '下書き']);

        $this->assertNull($this->row());
    }

    /** 一括取込・シーダーのあいだは印を付けない。 */
    public function test_marking_can_be_turned_off(): void
    {
        CalendarSyncQueue::withoutMarking(function () {
            $this->project();
        });

        $this->assertNull($this->row());
    }

    /**
     * 予定名は、いまの命名規則どおりに作られる。
     * 実物の例）【確定東】会議室_コニカミノルタジャパン様45名_1540-1740_横山
     */
    public function test_title_follows_the_naming_rule(): void
    {
        $p = $this->project();

        $this->assertSame(
            '【確定東】会議室_コニカミノルタジャパン様45名_1540-1740_横山',
            CalendarTitle::for($p)
        );
    }

    /**
     * ⚠ 空の項目はタグごと消える（「（）」や「_」が浮かない）。
     * ⚠ ECSは顧客名の「様」を外して保存しているので、予定名では付け直す。
     */
    public function test_title_drops_empty_parts(): void
    {
        $p = $this->project([
            'guest_count' => null,
            'event_start_time' => null,
            'event_end_time' => null,
            'sales_owners' => [],
        ]);

        $this->assertSame('【確定東】会議室_コニカミノルタジャパン様', CalendarTitle::for($p));
    }

    /** 命名規則は設定で変えられる（コードを直さなくてよい）。 */
    public function test_title_template_can_be_changed(): void
    {
        $p = $this->project();

        CalendarTitle::putTemplate('{月日} {コンテンツ}（{拠点}）');

        $expected = $p->start_date->month.'/'.$p->start_date->day.' 会議室（東京）';
        $this->assertSame($expected, CalendarTitle::for($p));
    }

    /** 設定を空にすると既定の規則に戻る。 */
    public function test_empty_template_falls_back_to_default(): void
    {
        CalendarTitle::putTemplate('');

        $this->assertSame(CalendarTitle::DEFAULT, CalendarTitle::template());
    }

    /** 「様」がすでに付いている顧客名は二重にしない。 */
    public function test_client_suffix_is_not_doubled(): void
    {
        $p = $this->project(['client' => 'テスト株式会社様']);

        $this->assertStringContainsString('テスト株式会社様45名', CalendarTitle::for($p));
        $this->assertStringNotContainsString('様様', CalendarTitle::for($p));
    }

    /** 待ち行列の件数が数えられる（画面の見出しに出す）。 */
    public function test_counts(): void
    {
        $this->project();

        $counts = CalendarSyncQueue::counts();
        $this->assertSame(1, $counts['sync']);
        $this->assertSame(0, $counts['delete']);
        $this->assertSame(0, $counts['error']);
    }
}
