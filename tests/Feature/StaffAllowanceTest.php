<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\StaffAllowance;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 手当の記録（運転・スライド・事前レクチャー・その他）。2026-10-13 baba。
 * 確かめたいこと：
 *   ・請求書チェック（フォーム）と収支入力（JSON）の両方から入れられ、消せる。誰が入れたかが残る。
 *   ・入れた手当が「請求書の目安」に足される。手当だけの人（アサインなし）も一覧に出る。
 *   ・自動で数える手当（前泊・ロングなど）は選べない／その他は金額が要る／スタッフ以外には付けられない。
 */
class StaffAllowanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_from_both_screens_and_it_shows_in_the_draft(): void
    {
        $me = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京', 'name' => '担当花子']);
        $s1 = PersonFactory::new()->create(['role' => 'staff', 'office' => '東京', 'name' => '運転太郎']);
        $s2 = PersonFactory::new()->create(['role' => 'staff', 'office' => '東京', 'name' => '講師次郎']);
        $p = ProjectFactory::new()->create(['office' => '東京', 'start_date' => '2026-10-12', 'project_name' => '戦国運動会']);
        Assignment::create(['project_id' => $p->id, 'staff_id' => $s1->id, 'role' => 'FC', 'status' => '確定', 'date' => '2026-10-12']);

        // ① 請求書チェックの画面から（フォーム）。
        $this->actingAsPerson($me)->post('/staff-allowances', [
            'staff_id' => $s1->id, 'project_id' => $p->id, 'date' => '2026-10-12', 'kind' => '運転手当', 'count' => 1,
        ])->assertRedirect()->assertSessionHas('ok');

        // ② 収支入力から（JSON）。案件のスタッフが読める。
        $this->getJson('/staff-allowances/project?project='.$p->id)->assertOk()
            ->assertJsonPath('staff.0.name', '運転太郎')
            ->assertJsonPath('items.0.kind', '運転手当')
            ->assertJsonPath('items.0.by', '担当花子');
        $this->postJson('/staff-allowances', ['staff_id' => $s1->id, 'project_id' => $p->id, 'date' => '2026-10-12',
            'kind' => 'スライド手当', 'count' => 2])->assertOk()->assertJsonPath('item.amount', 2000);

        // アサインの無い人（レクチャーだけ）・その他（金額を手で）。
        $this->postJson('/staff-allowances', ['staff_id' => $s2->id, 'date' => '2026-10-10', 'kind' => '事前レクチャー手当',
            'note' => '本能寺のレクチャー'])->assertOk();
        $this->postJson('/staff-allowances', ['staff_id' => $s2->id, 'date' => '2026-10-11', 'kind' => 'その他', 'amount' => 1500,
            'note' => '資料印刷'])->assertOk();

        $res = $this->get('/staff-invoice-check?ym=2026-10')->assertOk()
            ->assertSee('請求書チェック・手当管理表')
            ->assertSee('運転手当：10/12')
            ->assertSee('スライド手当：10/12(2回)')
            ->assertSee('講師次郎')                                  // 手当だけの人も出る
            ->assertSee('事前レクチャー手当：10/10（本能寺のレクチャー）')
            ->assertSee('資料印刷：10/11');

        // 消せる。
        $id = StaffAllowance::where('kind', '運転手当')->value('id');
        $this->post('/staff-allowances/'.$id.'/delete')->assertRedirect();
        $this->assertSame(0, StaffAllowance::where('kind', '運転手当')->count());
    }

    public function test_rules(): void
    {
        $me = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京']);
        $s = PersonFactory::new()->create(['role' => 'staff', 'office' => '東京']);
        $emp = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京']);

        $this->actingAsPerson($me);
        // 自動で数える手当は選べない（二重に数えないため）。
        $this->postJson('/staff-allowances', ['staff_id' => $s->id, 'date' => '2026-10-12', 'kind' => 'ロング手当'])->assertStatus(422);
        // その他は金額が要る。
        $this->postJson('/staff-allowances', ['staff_id' => $s->id, 'date' => '2026-10-12', 'kind' => 'その他'])->assertStatus(422);
        // 社員には付けない（請求書を出さない）。
        $this->postJson('/staff-allowances', ['staff_id' => $emp->id, 'date' => '2026-10-12', 'kind' => '運転手当'])->assertStatus(422);
        $this->assertSame(0, StaffAllowance::count());
    }
}
