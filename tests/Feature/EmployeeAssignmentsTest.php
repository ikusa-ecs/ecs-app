<?php

namespace Tests\Feature;

use App\Models\Assignment;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 社員別アサイン一覧（2026-10-13 baba「自分の分はマイページでしか見られない。他の社員の分も一覧で」）。
 * ・その月にアサインのある社員と明細が出る（スタッフは出さない・キャンセルは出さない）。
 * ・アサインの無い社員は下に名前だけ。
 */
class EmployeeAssignmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_employees_are_listed_with_their_month(): void
    {
        $me = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京', 'name' => '見る人']);
        $e1 = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京', 'name' => 'D花子']);
        $st = PersonFactory::new()->create(['role' => 'staff', 'office' => '東京', 'name' => 'スタッフ太郎']);
        $p = ProjectFactory::new()->create(['office' => '東京', 'start_date' => '2026-10-12', 'project_name' => '戦国運動会',
            'client' => 'A社', 'format' => 'リアル', 'start_time' => '8:00', 'location' => '代々木公園']);
        Assignment::create(['project_id' => $p->id, 'staff_id' => $e1->id, 'role' => 'D', 'status' => '確定', 'date' => '2026-10-12']);
        Assignment::create(['project_id' => $p->id, 'staff_id' => $st->id, 'role' => 'FC', 'status' => '確定', 'date' => '2026-10-12']);

        $this->actingAsPerson($me)->get('/employee-assignments?ym=2026-10')->assertOk()
            ->assertSee('社員別アサイン一覧')
            ->assertSee('D花子')
            ->assertSee('戦国運動会')
            ->assertSee('D（ディレクター）')
            ->assertSee('集合 8:00')
            ->assertSee('代々木公園')
            ->assertSee('D 1／SD 0')
            ->assertDontSee('スタッフ太郎')
            ->assertSee('この月にアサインの無い社員：見る人');
    }
}
