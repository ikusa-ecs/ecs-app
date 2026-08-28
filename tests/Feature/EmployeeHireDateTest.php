<?php

namespace Tests\Feature;

use App\Models\Person;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 社員名簿で入社年月日を見られる・直せる（2026-08-28 baba要望）。
 *
 * 【なぜ要るか】
 * 「新入社員がベテランになっている」との報告。
 * ⚠ 区分（新人／中堅／ベテラン）は**保存しておらず、入社年月日からその場で計算**している
 *   （新人＝1年未満／中堅＝1年以上3年未満／ベテラン＝3年以上）。
 *   つまり入社年月日が間違っていれば必ずおかしくなるのだが、
 *   **その日付が画面のどこにも出ておらず、確認も修正もできなかった**。
 */
class EmployeeHireDateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '管理者', 'role' => 'employee',
            'permission' => 'admin', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    private function employee(string $id, ?string $hire): Person
    {
        return PersonFactory::new()->create([
            'id' => $id, 'name' => '対象 社員', 'role' => 'employee',
            'permission' => 'employee', 'office' => '東京', 'must_onboard' => false,
            'hire_date' => $hire,
        ]);
    }

    /** 区分は入社年月日から決まる（保存していない）。 */
    public function test_level_comes_from_the_hire_date(): void
    {
        $today = Carbon::today();

        $this->assertSame('新人', $this->employee('E-101', $today->copy()->subMonths(6)->toDateString())->skill_level);
        $this->assertSame('中堅', $this->employee('E-102', $today->copy()->subYears(2)->toDateString())->skill_level);
        $this->assertSame('ベテラン', $this->employee('E-103', $today->copy()->subYears(5)->toDateString())->skill_level);
        $this->assertNull($this->employee('E-104', null)->skill_level, '入社日が空なら区分は決まらない');
    }

    /** 画面が入社年月日と区分の両方を受け取る（なぜベテランなのか分かるように）。 */
    public function test_screen_receives_hire_date_and_level(): void
    {
        $admin = $this->admin();
        $hire = Carbon::today()->copy()->subYears(5)->toDateString();
        $this->employee('E-105', $hire);

        $this->actingAsPerson($admin)->get('/employees')
            ->assertOk()
            ->assertViewHas('employees', function ($rows) use ($hire) {
                $r = collect($rows)->firstWhere('id', 'E-105');

                return $r && $r['hireDate'] === $hire && $r['level'] === 'ベテラン';
            })
            // ⚠ Blade の @verbatim の切れ目でスクリプトが途中から消えても真っ白にならない＝見張る。
            ->assertSee('function hireDateHtml', false)
            ->assertSee('function saveHireDate', false);
    }

    /** 管理者以上は直せる。直すと区分も一緒に変わる（保存していないので当然そうなる）。 */
    public function test_manager_can_fix_a_wrong_hire_date(): void
    {
        $admin = $this->admin();
        // 取込のときに古い日付が入ってしまった新入社員、という想定。
        $p = $this->employee('E-106', Carbon::today()->copy()->subYears(9)->toDateString());
        $this->assertSame('ベテラン', $p->skill_level);

        $correct = Carbon::today()->copy()->subMonths(3)->toDateString();
        $this->actingAsPerson($admin)
            ->post('/employees/'.$p->id.'/profile', ['hire_date' => $correct])
            ->assertOk()->assertJson(['ok' => true]);

        $fresh = $p->fresh();
        $this->assertSame($correct, $fresh->hire_date->toDateString());
        $this->assertSame('新人', $fresh->skill_level);
    }

    /** 空にすると未入力に戻せる（間違って入っていた日付を消したいことがある）。 */
    public function test_hire_date_can_be_cleared(): void
    {
        $admin = $this->admin();
        $p = $this->employee('E-107', Carbon::today()->copy()->subYears(4)->toDateString());

        $this->actingAsPerson($admin)
            ->post('/employees/'.$p->id.'/profile', ['hire_date' => ''])
            ->assertOk();

        $this->assertNull($p->fresh()->hire_date);
    }

    /** ⚠ 一般社員は直せない（他人の区分を勝手に変えられないように）。 */
    public function test_plain_employee_cannot_change_it(): void
    {
        $me = PersonFactory::new()->create([
            'id' => 'E-200', 'name' => '一般社員', 'role' => 'employee',
            'permission' => 'employee', 'office' => '東京', 'must_onboard' => false,
        ]);
        $hire = Carbon::today()->copy()->subYears(4)->toDateString();
        $p = $this->employee('E-108', $hire);

        $this->actingAsPerson($me)
            ->post('/employees/'.$p->id.'/profile', ['hire_date' => '2020-01-01'])
            ->assertStatus(403);

        $this->assertSame($hire, $p->fresh()->hire_date->toDateString());
    }
}
