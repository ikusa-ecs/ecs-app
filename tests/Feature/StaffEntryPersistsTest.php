<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Person;
use App\Models\Project;
use App\Support\TestAccounts;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 【調査】スタッフのエントリーが本当に保存され、担当の画面にも出るか。
 * 「エントリーしても毎回未エントリーになる／エントリー一覧にも無い」との報告（2026-08-28 baba）。
 */
class StaffEntryPersistsTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        return Project::create([
            'id' => 'P-ENT', 'project_name' => '水合戦', 'content_names' => ['水合戦'],
            'client' => 'テスト株式会社',
            'start_date' => Carbon::today()->copy()->addDays(12)->toDateString(),
            'office' => '東京', 'status' => '調整中',
            'is_recruiting' => true, 'staff_published' => true, 'required_count' => 5,
        ]);
    }

    private function staff(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'S-810', 'name' => '応募 花子', 'role' => 'staff',
            'permission' => 'staff', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** 本物のスタッフが押せば、保存されて本人の画面にも残る。 */
    public function test_entry_is_saved_and_stays(): void
    {
        $p = $this->project();
        $staff = $this->staff();

        $this->actingAsPerson($staff)->post('/staff-portal/entry', [
            'project_id' => $p->id, 'action' => 'apply', 'intent' => '希望', 'note' => '入れます',
        ])->assertOk()->assertJson(['ok' => true, 'saved' => true, 'applied' => true]);

        $this->assertSame(1, Application::count(), 'エントリーが保存されていない');

        $jobs = $this->actingAsPerson($staff)->get('/staff-portal')->assertOk()->viewData('recruitJobs');
        $mine = collect($jobs)->firstWhere('id', $p->id);
        $this->assertTrue($mine['applied'], '開き直したらエントリーが外れている');
    }

    /** 担当の「エントリー一覧」にもその人が出る。 */
    public function test_entry_shows_on_the_entries_screen(): void
    {
        $p = $this->project();
        $staff = $this->staff();
        $admin = PersonFactory::new()->create([
            'id' => 'E-001', 'role' => 'employee', 'permission' => 'admin',
            'office' => '東京', 'must_onboard' => false,
        ]);

        $this->actingAsPerson($staff)->post('/staff-portal/entry', [
            'project_id' => $p->id, 'action' => 'apply', 'intent' => '希望', 'note' => '入れます',
        ])->assertOk();

        $cases = $this->actingAsPerson($admin)->get('/entries')->assertOk()->viewData('entriesCases');
        $c = collect($cases)->firstWhere('id', $p->id);

        $this->assertNotNull($c, 'エントリー一覧にその案件が出ていない');
        $names = collect($c['entrants'] ?? [])->pluck('name')->all();
        $this->assertContains('応募 花子', $names, 'エントリー一覧にその人が出ていない');
    }

    /**
     * ⚠ 体験用（見本）アカウントは保存されない＝これが起きていると
     * 「エントリーしたのに一覧に無い」に見える。画面は注意を出して元に戻す作り。
     */
    public function test_demo_account_is_not_saved(): void
    {
        $p = $this->project();

        // 体験用のスタッフ（DBに居ない見本アカウント）。
        $demo = TestAccounts::findByEmail('test-staff@ecs.local');
        $this->assertNotNull($demo, '体験用スタッフの定義が消えている');
        $demoUser = TestAccounts::toPerson($demo);
        $this->assertTrue(TestAccounts::isMockOnly($demoUser), '体験用が見本あつかいになっていない');

        // このアカウントでエントリーしても保存されない（saved:false で返す）。
        $this->actingAs($demoUser)->post('/staff-portal/entry', [
            'project_id' => $p->id, 'action' => 'apply', 'intent' => '希望',
        ])->assertOk()->assertJson(['ok' => true, 'saved' => false]);

        $this->assertSame(0, Application::count(),
            '体験用アカウントのエントリーが実データに入ってしまっている');

        // ⚠ 押す前に分かるように、画面にも印が出ていること。
        $this->actingAs($demoUser)->get('/staff-portal')
            ->assertOk()
            ->assertViewHas('mockOnly', true)
            ->assertSee('これは体験用のアカウントです', false)
            ->assertSee('体験用・保存されません', false);
    }

    /** 本物のスタッフには「体験用」の印を出さない（不安にさせない）。 */
    public function test_real_staff_has_no_demo_warning(): void
    {
        $this->project();
        $staff = $this->staff();

        $this->actingAsPerson($staff)->get('/staff-portal')
            ->assertOk()
            ->assertViewHas('mockOnly', false)
            ->assertDontSee('これは体験用のアカウントです', false);
    }
}
