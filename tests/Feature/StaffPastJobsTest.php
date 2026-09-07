<?php

namespace Tests\Feature;

use App\Models\Assignment;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * スタッフ画面の「終わった案件」を守るテスト（2026-09-07）。
 *
 * 【なぜ要るか】
 * 「過去の案件が消えてしまっていて、請求書作成時に自分のスケジュールと照らし合わせて
 *   確認することができない」というご意見（2026-09-04 スタッフのフォーム回答）。
 *
 * ⚠ ここがいちばん壊れやすい：終わった案件はたいてい **status='完了'** になり、
 *   担当が片付ければ **is_archived=true** にもなる。以前はどちらも問答無用で消していたため、
 *   本人の履歴が運営の都合で消えていた。**この2つが past に残ることを必ず確かめる。**
 */
class StaffPastJobsTest extends TestCase
{
    use RefreshDatabase;

    /** 昨日までの確定アサインは「終わった案件」に入り、これからの分（published）には入らない。 */
    public function test_past_assignment_moves_to_past_list(): void
    {
        $me = PersonFactory::new()->staff()->create();
        $past = Carbon::today()->subDays(20)->format('Y-m-d');
        $p = ProjectFactory::new()->published()->create(['start_date' => $past]);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $me->id,
            'date' => $past, 'role' => 'OP', 'status' => '確定',
        ]);

        $data = $this->actingAsPerson($me)->get('/staff-portal')->assertOk()->original->getData();

        $this->assertNotNull(
            collect($data['pastJobs'])->firstWhere('id', $p->id),
            '終わった案件に出ること'
        );
        $this->assertNull(
            collect($data['published'])->firstWhere('id', $p->id),
            'これからの分には出ないこと'
        );
    }

    /** ⚠「完了」になった案件も、片付け（アーカイブ）された案件も、本人の履歴からは消さない。 */
    public function test_done_and_archived_projects_still_appear_in_past(): void
    {
        $me = PersonFactory::new()->staff()->create();
        $past = Carbon::today()->subDays(10)->format('Y-m-d');

        $done = ProjectFactory::new()->published()->create([
            'start_date' => $past, 'status' => '完了',
        ]);
        $archived = ProjectFactory::new()->published()->create([
            'start_date' => $past, 'is_archived' => true,
        ]);
        foreach ([$done, $archived] as $p) {
            Assignment::create([
                'project_id' => $p->id, 'staff_id' => $me->id,
                'date' => $past, 'role' => 'OP', 'status' => '確定',
            ]);
        }

        $past_ids = collect(
            $this->actingAsPerson($me)->get('/staff-portal')->assertOk()->original->getData()['pastJobs']
        )->pluck('id')->all();

        $this->assertContains($done->id, $past_ids, '「完了」にしても本人の履歴は残る');
        $this->assertContains($archived->id, $past_ids, '片付けても本人の履歴は残る');
    }

    /** これからの分は今までどおり＝「完了」・片付け済みは出さない。 */
    public function test_upcoming_still_hides_done_and_archived(): void
    {
        $me = PersonFactory::new()->staff()->create();
        $soon = Carbon::today()->addDays(5)->format('Y-m-d');

        $normal = ProjectFactory::new()->published()->create(['start_date' => $soon]);
        $done = ProjectFactory::new()->published()->create(['start_date' => $soon, 'status' => '完了']);
        $archived = ProjectFactory::new()->published()->create(['start_date' => $soon, 'is_archived' => true]);
        foreach ([$normal, $done, $archived] as $p) {
            Assignment::create([
                'project_id' => $p->id, 'staff_id' => $me->id,
                'date' => $soon, 'role' => 'OP', 'status' => '確定',
            ]);
        }

        $ids = collect(
            $this->actingAsPerson($me)->get('/staff-portal')->assertOk()->original->getData()['published']
        )->pluck('id')->all();

        $this->assertContains($normal->id, $ids);
        $this->assertNotContains($done->id, $ids, '完了はこれからの分に出さない');
        $this->assertNotContains($archived->id, $ids, '片付け済みはこれからの分に出さない');
    }

    /** 古すぎる案件は出さない（画面が重くなるため。区切りは PAST_DAYS＝400日）。 */
    public function test_very_old_assignment_is_not_listed(): void
    {
        $me = PersonFactory::new()->staff()->create();
        $veryOld = Carbon::today()->subDays(500)->format('Y-m-d');
        $p = ProjectFactory::new()->published()->create(['start_date' => $veryOld]);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $me->id,
            'date' => $veryOld, 'role' => 'OP', 'status' => '確定',
        ]);

        $data = $this->actingAsPerson($me)->get('/staff-portal')->assertOk()->original->getData();
        $this->assertNull(collect($data['pastJobs'])->firstWhere('id', $p->id));
    }

    /**
     * ⚠ エントリーの誤タップ防止（2回押し）が外れていないこと。
     * ボタンから直接 toggleApply を呼ぶと**1回のタップで保存**されてしまい、
     * 「気づいたらエントリーしてた」に逆戻りする（2026-09-02 佐賀熙さんのご意見）。
     * 押す入口は armApply だけ、という決まりをここで守る。
     */
    public function test_entry_button_goes_through_two_taps(): void
    {
        $blade = file_get_contents(resource_path('views/staff_portal.blade.php'));

        $this->assertStringContainsString('function armApply(', $blade, '2回押しの仕組みが消えていないこと');
        $this->assertStringNotContainsString(
            'onclick="toggleApply(',
            $blade,
            'ボタンから直接 toggleApply を呼ばないこと（必ず armApply を通す）'
        );
    }

    /**
     * ⚠「📋 募集中のみ」でしぼっているときに、エントリーした案件が一覧から消えないこと
     * （2026-09-07 baba指示）。エントリーすると状態が open → applied に変わるので、
     * 素直にしぼると押した瞬間に消える。どれに応募したか見返しながら選びたいので残す。
     */
    public function test_open_filter_keeps_applied_jobs(): void
    {
        $blade = file_get_contents(resource_path('views/staff_portal.blade.php'));

        $this->assertStringContainsString(
            "(state === 'open' && j.state === 'applied')",
            $blade,
            '「募集中のみ」にエントリー済みを残す条件が消えていないこと'
        );
    }

    /** 「仮」のままのアサインは、終わった案件にも出さない（確定したものだけが本人の記録）。 */
    public function test_tentative_assignment_is_not_listed(): void
    {
        $me = PersonFactory::new()->staff()->create();
        $past = Carbon::today()->subDays(7)->format('Y-m-d');
        $p = ProjectFactory::new()->published()->create(['start_date' => $past]);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $me->id,
            'date' => $past, 'role' => 'OP', 'status' => '仮',
        ]);

        $data = $this->actingAsPerson($me)->get('/staff-portal')->assertOk()->original->getData();
        $this->assertNull(collect($data['pastJobs'])->firstWhere('id', $p->id));
    }
}
