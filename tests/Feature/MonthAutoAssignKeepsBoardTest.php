<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\ShiftPreference;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⚠ **日別ボードで手で仮埋めしたものに、月まとめ自動アサインは触らない**（2026-09-08 baba指摘
 * 「日別ボードで仮埋めしてたのに、月まとめ自動アサインで変わったんだけど」「触らないでほしい」）。
 *
 * 【なぜこのテストが要るか】
 * 月まとめ自動アサインは1か月ぶんをまとめて入れる操作なので、人が先に決めたものを
 * 機械が動かすと**どこが自分の判断だったのか分からなくなる**。
 * 「機械は空いている枠を埋めるだけ・人の決めたものは動かさない」を守る。
 */
class MonthAutoAssignKeepsBoardTest extends TestCase
{
    use RefreshDatabase;

    private function wish($staff, Carbon $day): void
    {
        ShiftPreference::create([
            'staff_id' => $staff->id, 'period' => $day->format('Y-m'),
            'date' => $day->format('Y-m-d'), 'availability' => '稼働可',
        ]);
    }

    private function canDo($staff, array $roles): void
    {
        foreach ($roles as $r) {
            \App\Models\StaffRoleEligibility::create(['staff_id' => $staff->id, 'position' => $r]);
        }
    }

    private function requireRoles(string $contentId, string $scale, array $roles): void
    {
        foreach ($roles as $pos => $n) {
            \App\Models\ContentRoleRequirement::create([
                'content_id' => $contentId, 'scale' => $scale, 'position' => $pos, 'count' => $n,
            ]);
        }
    }

    /** アサインを実行できる人（管理者）。 */
    private function manager()
    {
        return PersonFactory::new()->manager()->create(['office' => '東京', 'must_onboard' => false]);
    }

    /**
     * 日別ボードで手で入れた「仮」の行は、月まとめ自動アサインを実行しても
     * **1文字も変わらない**（消えない・役割も status も動かない）。
     */
    public function test_hand_made_provisional_row_is_untouched(): void
    {
        $day = Carbon::today()->startOfMonth()->addDays(10);
        $p = ProjectFactory::new()->published()->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 3, 'office' => '東京',
        ]);

        // 日別ボードで手で入れた1名（quickToggle と同じ形＝auto_run_id は無い）。
        $mine = PersonFactory::new()->staff()->create(['name' => '手で入れた人', 'office' => '東京']);
        $row = Assignment::create([
            'project_id' => $p->id, 'staff_id' => $mine->id, 'date' => $day->format('Y-m-d'),
            'role' => 'MC', 'status' => '仮', 'remark' => 'この日は前泊',
        ]);

        // 自動アサインで入れられる人を2名用意する。
        foreach (['あ子', 'い子'] as $n) {
            $s = PersonFactory::new()->staff()->create(['name' => $n, 'office' => '東京']);
            $this->wish($s, $day);
        }
        $this->wish($mine, $day);

        $this->actingAsPerson($this->manager())
            ->post('/auto-assign-month/run', ['period' => $day->format('Y-m'), 'office' => '東京'])
            ->assertRedirect();

        $after = $row->fresh();
        $this->assertNotNull($after, '手で入れた行が消えている');
        $this->assertSame('MC', $after->role, '手で決めた役割が変わっている');
        $this->assertSame('仮', $after->status);
        $this->assertSame('この日は前泊', $after->remark, '手で書いた備考が消えている');
        $this->assertNull($after->auto_run_id, '手で入れた行に自動の番号が付いている');

        // ⚠ 2026-09-08 の baba選択で「手で入れた案件はまるごと触らない」に変えた＝
        //   足りていても足さない（それまでは残り2名を足していた）。1名のまま。
        $this->assertSame(1, Assignment::where('project_id', $p->id)->count(),
            '手で入れた案件に機械が足している');
    }

    /**
     * ⚠ 手で「MC」を入れてある案件に、機械がもう1人MCを足さない
     * （必要ポジションのMCは1名なので、すでに埋まっている）。
     */
    public function test_does_not_add_a_second_mc_when_the_board_already_has_one(): void
    {
        $day = Carbon::today()->startOfMonth()->addDays(10);
        $this->requireRoles('CT-TEST', '中型', ['MC' => 1, 'FC' => 1]);
        $p = ProjectFactory::new()->published()->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 2,
            'content_ids' => ['CT-TEST'], 'scale' => '中型', 'office' => '東京',
        ]);

        // 日別ボードで手で決めたMC。
        $mine = PersonFactory::new()->staff()->create(['name' => '手で決めたMC', 'office' => '東京']);
        $this->canDo($mine, ['MC']);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $mine->id, 'date' => $day->format('Y-m-d'),
            'role' => 'MC', 'status' => '仮',
        ]);

        // MCもFCもできる人を2名（機械が選べる状態にする）。
        foreach (['なんでも1', 'なんでも2'] as $n) {
            $s = PersonFactory::new()->staff()->create(['name' => $n, 'office' => '東京']);
            $this->canDo($s, ['MC', 'FC']);
            $this->wish($s, $day);
        }

        $this->actingAsPerson($this->manager())
            ->post('/auto-assign-month/run', ['period' => $day->format('Y-m'), 'office' => '東京'])
            ->assertRedirect();

        $mcCount = Assignment::where('project_id', $p->id)->where('role', 'MC')->count();
        $this->assertSame(1, $mcCount, 'MCが2人になっている');
    }

    /**
     * ⚠⚠ **手でスタッフを入れた案件には、1人も足さない**（2026-09-08 baba選択）。
     * 人が組み始めた案件に機械が足すと、どこまでが自分の判断か分からなくなるため。
     */
    public function test_project_with_hand_made_staff_is_skipped(): void
    {
        $day = Carbon::today()->startOfMonth()->addDays(10);
        $mine = ProjectFactory::new()->published()->create([
            'project_name' => '手で組んだ案件', 'start_date' => $day->format('Y-m-d'),
            'required_count' => 3, 'office' => '東京',
        ]);
        $other = ProjectFactory::new()->published()->create([
            'project_name' => 'まだ手つかずの案件', 'start_date' => $day->format('Y-m-d'),
            'required_count' => 1, 'office' => '東京',
        ]);

        // 「手で組んだ案件」に1名だけ手で入れてある（足りていない＝機械が足したくなる状態）。
        $hand = PersonFactory::new()->staff()->create(['name' => '手で入れた人', 'office' => '東京']);
        Assignment::create([
            'project_id' => $mine->id, 'staff_id' => $hand->id, 'date' => $day->format('Y-m-d'),
            'role' => '', 'status' => '仮',
        ]);

        // 入れられる人を3名（〇を出している）。
        foreach (['あ子', 'い子', 'う子'] as $n) {
            $s = PersonFactory::new()->staff()->create(['name' => $n, 'office' => '東京']);
            $this->wish($s, $day);
        }

        $this->actingAsPerson($this->manager())
            ->post('/auto-assign-month/run', ['period' => $day->format('Y-m'), 'office' => '東京'])
            ->assertRedirect();

        // 手で組んだ案件は1名のまま＝機械は触っていない。
        $this->assertSame(1, Assignment::where('project_id', $mine->id)->count(),
            '手で入れた案件に機械が足している');
        // 手つかずの案件はふつうに埋まる（機能そのものは生きている）。
        $this->assertSame(1, Assignment::where('project_id', $other->id)->count());
    }

    /** チェックを付けたときだけ、その案件も自動で埋める（戻せる道を残す）。 */
    public function test_hand_made_project_can_be_opted_back_in(): void
    {
        $day = Carbon::today()->startOfMonth()->addDays(10);
        $p = ProjectFactory::new()->published()->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 2, 'office' => '東京',
        ]);
        $hand = PersonFactory::new()->staff()->create(['name' => '手で入れた人', 'office' => '東京']);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $hand->id, 'date' => $day->format('Y-m-d'),
            'role' => '', 'status' => '仮',
        ]);
        $s = PersonFactory::new()->staff()->create(['name' => 'あ子', 'office' => '東京']);
        $this->wish($s, $day);

        $this->actingAsPerson($this->manager())->post('/auto-assign-month/run', [
            'period' => $day->format('Y-m'), 'office' => '東京',
            'includeHand' => [$p->id],   // 「この案件も自動で埋める」にチェックを付けた状態
        ])->assertRedirect();

        $this->assertSame(2, Assignment::where('project_id', $p->id)->count());
        // ⚠ それでも、手で入れた行そのものは変わらない。
        $this->assertSame('', Assignment::where('project_id', $p->id)->where('staff_id', $hand->id)->first()->role);
    }

    /** 下見（プレビュー）に「手で入っているので外した」ことが出る（黙って外さない）。 */
    public function test_preview_shows_why_the_project_was_skipped(): void
    {
        $day = Carbon::today()->startOfMonth()->addDays(10);
        $p = ProjectFactory::new()->published()->create([
            'project_name' => '手で組んだ案件', 'start_date' => $day->format('Y-m-d'),
            'required_count' => 3, 'office' => '東京',
        ]);
        $hand = PersonFactory::new()->staff()->create(['name' => '手で入れた人', 'office' => '東京']);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $hand->id, 'date' => $day->format('Y-m-d'),
            'role' => 'MC', 'status' => '仮',
        ]);

        $data = $this->actingAsPerson($this->manager())
            ->get('/auto-assign-month?period='.$day->format('Y-m').'&office=東京')
            ->assertOk()
            ->assertSee('手で入っている案件')
            ->assertSee('手で入れた人')
            ->original->getData();

        $row = collect($data['handMade'])->firstWhere('id', $p->id);
        $this->assertNotNull($row, '外した案件が画面に出ていない＝理由が分からない');
        $this->assertSame(['手で入れた人'], $row['people']);
        $this->assertFalse($row['included']);
    }

    /**
     * ⚠ 社員のD決め（D・SD）だけの案件は、今までどおり自動アサインの対象。
     * ここを外すと、Dが決まっている案件＝ほとんどの案件が対象外になり、機能が使えなくなる。
     */
    public function test_director_only_project_is_still_filled(): void
    {
        $day = Carbon::today()->startOfMonth()->addDays(10);
        $p = ProjectFactory::new()->published()->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 2, 'office' => '東京',
        ]);
        // 社員をDとして入れてある（D決め画面で決めたもの）。
        $dir = PersonFactory::new()->create(['name' => 'D社員', 'office' => '東京']);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $dir->id, 'date' => $day->format('Y-m-d'),
            'role' => 'D', 'status' => '仮',
        ]);
        $s = PersonFactory::new()->staff()->create(['name' => 'あ子', 'office' => '東京']);
        $this->wish($s, $day);

        $this->actingAsPerson($this->manager())
            ->post('/auto-assign-month/run', ['period' => $day->format('Y-m'), 'office' => '東京'])
            ->assertRedirect();

        $this->assertSame(2, Assignment::where('project_id', $p->id)->count(),
            'Dだけ決まっている案件が対象外になっている＝機能が使えなくなる');
    }

    /**
     * ⚠ 人が手で入れた行は、取り消し（undo）でも消えない。
     * 取り消すのは**その回に機械が入れたぶんだけ**。
     */
    public function test_undo_never_removes_hand_made_rows(): void
    {
        $day = Carbon::today()->startOfMonth()->addDays(10);
        $p = ProjectFactory::new()->published()->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 2, 'office' => '東京',
        ]);

        $mine = PersonFactory::new()->staff()->create(['name' => '手で入れた人', 'office' => '東京']);
        $row = Assignment::create([
            'project_id' => $p->id, 'staff_id' => $mine->id, 'date' => $day->format('Y-m-d'),
            'role' => '', 'status' => '仮',
        ]);
        $auto = PersonFactory::new()->staff()->create(['name' => '機械が入れた人', 'office' => '東京']);
        $this->wish($auto, $day);

        $me = $this->manager();
        $this->actingAsPerson($me)
            ->post('/auto-assign-month/run', ['period' => $day->format('Y-m'), 'office' => '東京'])
            ->assertRedirect();

        $run = \App\Models\AutoAssignRun::latest('id')->first();
        $this->assertNotNull($run);

        $this->actingAsPerson($me)
            ->post('/auto-assign-month/undo', ['run_id' => $run->id])
            ->assertRedirect();

        $this->assertNotNull($row->fresh(), '取り消しで手で入れた行まで消えている');
        $this->assertSame(1, Assignment::where('project_id', $p->id)->count());
    }

    /**
     * ⚠⚠ 機械が入れたあとに**人が手で直した行**は、取り消しでも消さない（2026-09-08）。
     * 役割を替えた・備考を書いた＝そこには人の判断が入っているため。
     */
    public function test_undo_keeps_rows_edited_by_hand_afterwards(): void
    {
        $day = Carbon::today()->startOfMonth()->addDays(10);
        $p = ProjectFactory::new()->published()->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 2, 'office' => '東京',
        ]);
        foreach (['あ子', 'い子'] as $n) {
            $s = PersonFactory::new()->staff()->create(['name' => $n, 'office' => '東京']);
            $this->wish($s, $day);
        }

        $me = $this->manager();
        $this->actingAsPerson($me)
            ->post('/auto-assign-month/run', ['period' => $day->format('Y-m'), 'office' => '東京'])
            ->assertRedirect();
        $this->assertSame(2, Assignment::where('project_id', $p->id)->count());

        // 機械が入れた2名のうち1名を、日別ボードで手で直した（役割を決めた）。
        $edited = Assignment::where('project_id', $p->id)->first();
        // ⚠ created_at と updated_at が同じ秒に並ぶと「触った」と分からないので、時刻を進める。
        Carbon::setTestNow(Carbon::now()->addMinutes(5));
        $this->actingAsPerson($me)->postJson('/entries/assign', [
            'project_id' => $p->id, 'staff_id' => $edited->staff_id,
            'action' => 'assign', 'role' => 'MC', 'status' => '仮',
        ])->assertOk();
        Carbon::setTestNow();

        $run = \App\Models\AutoAssignRun::latest('id')->first();
        $this->actingAsPerson($me)->post('/auto-assign-month/undo', ['run_id' => $run->id])->assertRedirect();

        $left = Assignment::where('project_id', $p->id)->get();
        $this->assertCount(1, $left, '手で直した行が取り消しで消えている');
        $this->assertSame($edited->staff_id, $left->first()->staff_id);
        $this->assertSame('MC', $left->first()->role);
    }
}
