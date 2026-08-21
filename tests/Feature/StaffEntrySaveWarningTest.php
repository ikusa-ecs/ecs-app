<?php

namespace Tests\Feature;

use App\Models\Application;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * エントリー（応募）が保存されたかどうかを、画面にはっきり返す（2026-08-21 baba指摘）。
 *
 * 体験用アカウントは実DBに本人が居ないため保存しない仕様。ところが画面は成功として
 * 「エントリー中」に変えていたので、担当の画面（日別ボード・エントリー一覧）に出ず
 * 「エントリーしたのに出てこない」ように見えていた。
 * サーバーは saved:false を返しており、画面はそれを見て元に戻し、理由を知らせる。
 */
class StaffEntrySaveWarningTest extends TestCase
{
    use RefreshDatabase;

    /** 本物のスタッフ：応募が applications に保存され、saved:true が返る。 */
    public function test_real_staff_entry_is_saved(): void
    {
        $staff = PersonFactory::new()->staff()->create();
        $p = ProjectFactory::new()->published()->create([
            'start_date' => Carbon::today()->addDays(5)->format('Y-m-d'),
        ]);

        $this->actingAsPerson($staff)
            ->postJson('/staff-portal/entry', ['project_id' => $p->id, 'action' => 'apply'])
            ->assertOk()
            ->assertJson(['ok' => true, 'saved' => true]);

        $this->assertDatabaseHas('applications', ['staff_id' => $staff->id, 'project_id' => $p->id]);
    }

    /** 取り消しもDBから消える（担当の画面からも消える）。 */
    public function test_cancel_removes_the_entry(): void
    {
        $staff = PersonFactory::new()->staff()->create();
        $p = ProjectFactory::new()->published()->create([
            'start_date' => Carbon::today()->addDays(5)->format('Y-m-d'),
        ]);
        Application::create(['staff_id' => $staff->id, 'project_id' => $p->id, 'intent' => '希望']);

        $this->actingAsPerson($staff)
            ->postJson('/staff-portal/entry', ['project_id' => $p->id, 'action' => 'cancel'])
            ->assertOk()
            ->assertJson(['saved' => true, 'applied' => false]);

        $this->assertDatabaseMissing('applications', ['staff_id' => $staff->id, 'project_id' => $p->id]);
    }

    /** 本物のスタッフには「体験用」の注意を出さない。 */
    public function test_real_staff_does_not_see_the_mock_warning(): void
    {
        $staff = PersonFactory::new()->staff()->create();

        $data = $this->actingAsPerson($staff)->get('/staff-portal')->assertOk()->original->getData();

        $this->assertFalse($data['mockOnly']);
    }
}
