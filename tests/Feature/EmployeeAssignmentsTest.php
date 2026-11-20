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

    /**
     * D・SDの件数は本番日だけ（2026-11-16 小沼さん決定）。前日設営のDは一覧には出すが、件数には入れない。
     * 明細○件・のべ○件も本番日だけ（2026-11-20 小沼さん決定）。設営・リハ日は「設営/リハ ○」に分ける。
     */
    public function test_d_count_is_main_days_only(): void
    {
        $me = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京', 'name' => '見る人']);
        $e1 = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京', 'name' => 'D花子']);
        $main = ProjectFactory::new()->create(['office' => '東京', 'start_date' => '2026-10-12', 'project_name' => '本番の運動会', 'date_type' => '本番']);
        $setup = ProjectFactory::new()->create(['office' => '東京', 'start_date' => '2026-10-11', 'project_name' => '設営の運動会', 'date_type' => '前日設営']);
        Assignment::create(['project_id' => $main->id, 'staff_id' => $e1->id, 'role' => 'D', 'status' => '確定', 'date' => '2026-10-12']);
        Assignment::create(['project_id' => $setup->id, 'staff_id' => $e1->id, 'role' => 'D', 'status' => '確定', 'date' => '2026-10-11']);

        $this->actingAsPerson($me)->get('/employee-assignments?ym=2026-10')->assertOk()
            ->assertSee('設営の運動会')
            ->assertSee('D 1／SD 0')
            ->assertSee('明細 1件・設営/リハ 1')
            ->assertSee('のアサイン 1件（ほかに設営・リハ 1件）')
            ->assertViewHas('total', 1)
            ->assertViewHas('list', fn ($list) => collect($list)->firstWhere('name', 'D花子')['d'] === 1
                && collect($list)->firstWhere('name', 'D花子')['liveCount'] === 1
                && collect($list)->firstWhere('name', 'D花子')['otherDays'] === 1);
    }

    /**
     * 同じ日・同じ会場・同じお客様の案件をかけもちしたDは1件（2026-11-20 小沼さん決定・正本 SameVenueKey）。
     * 明細には2行とも出す。会場が違う案件はまとめない。
     */
    public function test_same_day_same_venue_same_client_counts_once(): void
    {
        $me = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京', 'name' => '見る人']);
        $e1 = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京', 'name' => 'D花子']);
        $same = ['office' => '東京', 'start_date' => '2026-10-12', 'date_type' => '本番', 'client' => 'A社', 'location' => 'ビッグサイト'];
        $a = ProjectFactory::new()->create($same + ['project_name' => 'かけもちのチャンバラ']);
        $b = ProjectFactory::new()->create($same + ['project_name' => 'かけもちの防災']);
        $c = ProjectFactory::new()->create(['location' => '別の会場', 'project_name' => '別会場の運動会'] + $same);
        foreach ([$a, $b, $c] as $p) {
            Assignment::create(['project_id' => $p->id, 'staff_id' => $e1->id, 'role' => 'D', 'status' => '確定', 'date' => '2026-10-12']);
        }

        $this->actingAsPerson($me)->get('/employee-assignments?ym=2026-10')->assertOk()
            ->assertSee('かけもちのチャンバラ')
            ->assertSee('かけもちの防災')
            ->assertSee('D 2／SD 0')
            ->assertSee('明細 2件・かけもち 1')
            ->assertSee('のアサイン 2件（ほかに同じ会場のかけもち 1件）')
            ->assertViewHas('total', 2);
    }
}
