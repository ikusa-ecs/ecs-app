<?php

namespace Tests\Feature;

use App\Models\Assignment;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * スタッフ画面（/staff-portal）に「何が見えて、何が見えないか」を固定するテスト。
 *
 * ここが崩れると本人に見せてはいけないものが見えるので、
 *   ・募集中タブ ＝ 公開ボードで公開ON（staff_published）にした案件だけ
 *   ・確定アサインタブ ＝ 自分の「確定」アサインだけ（仮＝調整中は出さない）
 * を必ず守る。2026-08-20 に「登録した瞬間からスタッフに見えていた」不具合を直した際に追加。
 */
class StaffPortalVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function soon(int $plusDays = 3): string
    {
        return Carbon::today()->addDays($plusDays)->format('Y-m-d');
    }

    /** 画面に渡された募集案件リスト（recruitJobs）を取り出す。 */
    private function recruitJobs($me): array
    {
        return collect(
            $this->actingAsPerson($me)->get('/staff-portal')->assertOk()
                ->original->getData()['recruitJobs']
        )->all();
    }

    /** 画面に渡された確定アサインリスト（published）を取り出す。 */
    private function confirmed($me): array
    {
        return collect(
            $this->actingAsPerson($me)->get('/staff-portal')->assertOk()
                ->original->getData()['published']
        )->all();
    }

    /**
     * 稼働希望カレンダーは「当月」で開く（見出し・締切・日数・1日の曜日）。
     * 以前は画面に2026年7月が直書きされており、保存される月とズレていた。
     */
    public function test_preference_calendar_uses_current_month(): void
    {
        $me = PersonFactory::new()->staff()->create();
        $now = Carbon::now();

        $data = $this->actingAsPerson($me)->get('/staff-portal')->assertOk()->original->getData();

        $this->assertSame($now->format('Y-m'), $data['prefPeriod']);
        $meta = $data['prefMeta'];
        $this->assertSame((int) $now->year, $meta['year']);
        $this->assertSame((int) $now->month, $meta['month']);
        $this->assertSame($now->daysInMonth, $meta['days']);
        $this->assertSame((int) $now->copy()->startOfMonth()->dayOfWeek, $meta['firstDow']);
        // 締切＝前月25日
        $this->assertSame(
            $now->copy()->startOfMonth()->subMonthNoOverflow()->day(25)->format('n月j日'),
            $meta['deadline']
        );
    }

    /** ?period= を渡せばその月で開く（見出し・日数もその月になる）。 */
    public function test_preference_calendar_follows_period_parameter(): void
    {
        $me = PersonFactory::new()->staff()->create();

        $meta = $this->actingAsPerson($me)->get('/staff-portal?period=2027-02')
            ->assertOk()->original->getData()['prefMeta'];

        $this->assertSame(2027, $meta['year']);
        $this->assertSame(2, $meta['month']);
        $this->assertSame(28, $meta['days'], '2027年2月は28日');
        $this->assertSame('1月25日', $meta['deadline']);
    }

    /** 募集タブ：公開ONの案件だけ出る。登録しただけ（非公開）の案件は出さない。 */
    public function test_recruiting_tab_shows_published_projects_only(): void
    {
        $me = PersonFactory::new()->staff()->create(['office' => '東京']);
        $open = ProjectFactory::new()->published()->create([
            'office' => '東京', 'start_date' => $this->soon(),
        ]);
        $notYet = ProjectFactory::new()->create([
            'office' => '東京', 'start_date' => $this->soon(4),
        ]);

        $ids = collect($this->recruitJobs($me))->pluck('id')->all();

        $this->assertContains($open->id, $ids);
        $this->assertNotContains($notYet->id, $ids, '公開していない案件はスタッフに見せない');
    }

    /** 募集タブ：募集人数が未定（空欄・0）なら 5名として見せる（満員扱いにしない）。 */
    public function test_recruiting_tab_defaults_need_to_five_when_blank(): void
    {
        $me = PersonFactory::new()->staff()->create(['office' => '東京']);
        $blank = ProjectFactory::new()->published()->create([
            'office' => '東京', 'start_date' => $this->soon(), 'required_count' => null,
        ]);
        $zero = ProjectFactory::new()->published()->create([
            'office' => '東京', 'start_date' => $this->soon(2), 'required_count' => 0,
        ]);
        $filled = ProjectFactory::new()->published()->create([
            'office' => '東京', 'start_date' => $this->soon(3), 'required_count' => 8,
        ]);

        $jobs = collect($this->recruitJobs($me))->keyBy('id');

        $this->assertSame(5, $jobs[$blank->id]['need'], '未入力は5名');
        $this->assertSame(5, $jobs[$zero->id]['need'], '0も未入力あつかいで5名');
        $this->assertSame(8, $jobs[$filled->id]['need'], '入っている数はそのまま');
    }

    /** 確定アサインタブ：status=確定 かつ 公開ON のときだけ本人に出る。 */
    public function test_confirmed_tab_requires_confirmed_status_and_publish(): void
    {
        $me = PersonFactory::new()->staff()->create(['office' => '東京']);

        $ok = ProjectFactory::new()->published()->create(['start_date' => $this->soon()]);
        $tentative = ProjectFactory::new()->published()->create(['start_date' => $this->soon(4)]);
        $unpublished = ProjectFactory::new()->create(['start_date' => $this->soon(5)]);

        Assignment::create([
            'project_id' => $ok->id, 'staff_id' => $me->id,
            'date' => $this->soon(), 'role' => 'OP', 'status' => '確定',
        ]);
        Assignment::create([
            'project_id' => $tentative->id, 'staff_id' => $me->id,
            'date' => $this->soon(4), 'role' => 'OP', 'status' => '仮',
        ]);
        Assignment::create([
            'project_id' => $unpublished->id, 'staff_id' => $me->id,
            'date' => $this->soon(5), 'role' => 'OP', 'status' => '確定',
        ]);

        $ids = collect($this->confirmed($me))->pluck('id')->all();

        $this->assertContains($ok->id, $ids);
        $this->assertNotContains($tentative->id, $ids, '仮（調整中）は本人に見せない');
        $this->assertNotContains($unpublished->id, $ids, '公開していない案件は本人にも見せない');
    }

    /** 確定アサインタブ：他人のアサインは出ない／下書き・完了・過去も出ない。 */
    public function test_confirmed_tab_excludes_others_and_past_and_draft(): void
    {
        $me = PersonFactory::new()->staff()->create();
        $other = PersonFactory::new()->staff()->create();

        $mineOk = ProjectFactory::new()->published()->create(['start_date' => $this->soon()]);
        $othersOk = ProjectFactory::new()->published()->create(['start_date' => $this->soon(2)]);
        $past = ProjectFactory::new()->published()->create(['start_date' => $this->soon(-5)]);
        $draft = ProjectFactory::new()->published()->draft()->create(['start_date' => $this->soon(6)]);

        foreach ([[$mineOk, $me], [$othersOk, $other], [$past, $me], [$draft, $me]] as [$p, $person]) {
            Assignment::create([
                'project_id' => $p->id, 'staff_id' => $person->id,
                'date' => $p->start_date, 'role' => 'OP', 'status' => '確定',
            ]);
        }

        $ids = collect($this->confirmed($me))->pluck('id')->all();

        $this->assertContains($mineOk->id, $ids);
        $this->assertNotContains($othersOk->id, $ids, '他人のアサインは見せない');
        $this->assertNotContains($past->id, $ids, '過ぎた日は出さない');
        $this->assertNotContains($draft->id, $ids, '下書きの案件は出さない');
    }

    /**
     * 確定アサインタブ：同じ案件で複数日アサインされていれば、日ごとに1件ずつ出る。
     * 以前は案件ごとに1件へ丸めていたため、2日目だけの担当が初日として見えていた。
     */
    public function test_confirmed_tab_lists_each_assigned_day(): void
    {
        $me = PersonFactory::new()->staff()->create();
        $p = ProjectFactory::new()->published()->create(['start_date' => $this->soon(2)]);

        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $me->id,
            'date' => $this->soon(2), 'role' => 'OP', 'status' => '確定',
        ]);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $me->id,
            'date' => $this->soon(3), 'role' => 'FC', 'status' => '確定',
        ]);

        $rows = collect($this->confirmed($me));

        $this->assertCount(2, $rows, '2日ぶん出る');
        $this->assertSame([2, 3], $rows->pluck('off')->sort()->values()->all());
    }
}
