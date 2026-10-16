<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Person;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 退職した人の出し方（2026-10-16 baba決定）。
 * 【決まり】退職した人は、**その月（その案件）にアサイン・担当がある場合だけ**出す。
 *   ＝過去の実績は消さず、これからの一覧・候補からは消える。正本＝Person::inService。
 */
class RetiredPersonTest extends TestCase
{
    use RefreshDatabase;

    public function test_inService_scope(): void
    {
        $a = PersonFactory::new()->create(['id' => 'E-A', 'active' => true]);
        $r = PersonFactory::new()->create(['id' => 'E-R', 'active' => false]);

        $this->assertSame(['E-A'], Person::whereIn('id', ['E-A', 'E-R'])->inService()->pluck('id')->all());
        $this->assertEqualsCanonicalizing(['E-A', 'E-R'], Person::whereIn('id', ['E-A', 'E-R'])->inService(['E-R'])->pluck('id')->all());
    }

    /** 社員別アサイン一覧：退職しても、アサインがある月には出る（以前は過去の月からも消えていた）。 */
    public function test_employee_assignments_keeps_retired_in_months_they_worked(): void
    {
        $me = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京', 'name' => '見る人']);
        $ret = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京', 'name' => '退職花子', 'active' => false]);
        $p = ProjectFactory::new()->create(['office' => '東京', 'start_date' => '2026-09-12', 'project_name' => '戦国運動会']);
        Assignment::create(['project_id' => $p->id, 'staff_id' => $ret->id, 'role' => 'D', 'status' => '確定', 'date' => '2026-09-12']);

        $this->actingAsPerson($me)->get('/employee-assignments?ym=2026-09')->assertOk()->assertSee('退職花子');
        // 仕事の無い月には出ない（「アサインの無い社員」の欄にも並べない）。
        $this->actingAsPerson($me)->get('/employee-assignments?ym=2026-10')->assertOk()->assertDontSee('退職花子');
    }

    /** D決め・出勤可能日：退職した社員は並べない。 */
    public function test_director_board_and_availability_hide_retired(): void
    {
        $me = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京', 'name' => '見る人', 'permission' => 'manager']);
        PersonFactory::new()->create(['role' => 'employee', 'office' => '東京', 'name' => '退職花子', 'active' => false, 'in_assign_pool' => true]);
        PersonFactory::new()->create(['role' => 'employee', 'office' => '東京', 'name' => '在籍太郎', 'in_assign_pool' => true]);

        // ⚠ 名前は画面のJSONに文字コードで入る＝assertSee では確かめられない。渡しているデータで見る。
        foreach (['/assign-director', '/employee-availability'] as $url) {
            $names = collect($this->actingAsPerson($me)->get($url)->assertOk()->original->getData()['employees'])
                ->map(fn ($e) => is_array($e) ? ($e['name'] ?? '') : $e->name)->all();
            $this->assertContains('在籍太郎', $names, $url);
            $this->assertNotContains('退職花子', $names, $url);
        }
    }
}
