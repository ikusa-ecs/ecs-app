<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Person;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 人（社員・スタッフ）の「退職にする」と「削除」（2026-08-24 baba要望）。
 *
 * どちらも Administrator だけができる（権限4段階の決まり）。
 *
 * 【なぜ2つに分けるか】
 *  辞めた人を「消す」と、その人が入った案件の記録（アサイン・出勤数・収支）まで
 *  辿れなくなる。辞めた＝在籍を外す（退職）が正しい。
 *  削除は「間違えて登録した人」「テストで作った人」を片づけるためのもので、
 *  記録が残っている人はサーバー側で拒否する。
 */
class PersonDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '全権', 'permission' => 'admin',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    private function someone(array $attrs = []): Person
    {
        return PersonFactory::new()->create(array_merge([
            'id' => 'S-500', 'name' => 'テストで作った人', 'role' => 'staff',
            'permission' => 'staff', 'office' => '東京', 'must_onboard' => false,
        ], $attrs));
    }

    /** 記録が無い人は削除できる（テストで作った人の片づけ）。 */
    public function test_admin_can_delete_person_without_records(): void
    {
        $target = $this->someone();

        $this->actingAsPerson($this->admin())
            ->postJson('/people/'.$target->id.'/delete')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('people', ['id' => $target->id]);
    }

    /** アサインの記録がある人は削除できない（過去の案件が追えなくなるため）。 */
    public function test_cannot_delete_person_with_assignments(): void
    {
        $target = $this->someone();
        $project = ProjectFactory::new()->create(['office' => '東京']);
        Assignment::create([
            'project_id' => $project->id,
            'staff_id' => $target->id,
            'role' => 'OP',
            'status' => '確定',
            'date' => Carbon::today()->format('Y-m-d'),
        ]);

        $res = $this->actingAsPerson($this->admin())
            ->postJson('/people/'.$target->id.'/delete')
            ->assertStatus(422);

        $this->assertStringContainsString('退職にする', $res->json('message'));
        $this->assertDatabaseHas('people', ['id' => $target->id]);
    }

    /** 記録がある人は「退職にする」で在籍を外せる（名簿には残る）。 */
    public function test_admin_can_mark_person_inactive(): void
    {
        $target = $this->someone(['active' => true]);
        $admin = $this->admin();   // ※ 1回だけ作る（同じIDで2回作ると重複エラーになる）

        $this->actingAsPerson($admin)
            ->postJson('/people/'.$target->id.'/active', ['active' => false])
            ->assertOk()
            ->assertJson(['ok' => true, 'active' => false]);

        $this->assertFalse((bool) $target->fresh()->active);
        $this->assertDatabaseHas('people', ['id' => $target->id]);

        // 在籍に戻せる。
        $this->actingAsPerson($admin)
            ->postJson('/people/'.$target->id.'/active', ['active' => true])
            ->assertOk();
        $this->assertTrue((bool) $target->fresh()->active);
    }

    /** 自分自身は削除できない・退職にもできない。 */
    public function test_cannot_delete_or_deactivate_self(): void
    {
        $me = $this->admin();

        $this->actingAsPerson($me)->postJson('/people/'.$me->id.'/delete')->assertStatus(422);
        $this->actingAsPerson($me)->postJson('/people/'.$me->id.'/active', ['active' => false])->assertStatus(422);

        $this->assertDatabaseHas('people', ['id' => $me->id]);
    }

    /** 最後のAdministratorは削除できない（権限を直せる人がいなくなる）。 */
    public function test_cannot_delete_last_administrator(): void
    {
        $me = $this->admin();
        // もう1人のAdministratorを作り、その人を消そうとする（＝残り1人になる）。
        $other = PersonFactory::new()->create([
            'id' => 'E-002', 'name' => 'もう一人の全権', 'permission' => 'admin',
            'office' => '東京', 'must_onboard' => false,
        ]);

        // いまAdministratorは2人なので、この人は消せる。
        $this->actingAsPerson($me)->postJson('/people/'.$other->id.'/delete')->assertOk();

        // 残り1人になったので、その1人（自分）は消せない（自分自身の判定でも止まる）。
        $this->actingAsPerson($me)->postJson('/people/'.$me->id.'/delete')->assertStatus(422);
        $this->assertDatabaseHas('people', ['id' => $me->id]);
    }

    /** 管理者（manager）や社員はできない＝Administratorだけ。 */
    public function test_only_administrator_can_delete(): void
    {
        $target = $this->someone();

        foreach (['manager', 'employee'] as $perm) {
            $actor = PersonFactory::new()->create([
                'id' => 'E-9'.($perm === 'manager' ? '1' : '2'), 'permission' => $perm,
                'office' => '東京', 'must_onboard' => false,
            ]);
            $this->actingAsPerson($actor)
                ->postJson('/people/'.$target->id.'/delete')
                ->assertStatus(403);
            $this->actingAsPerson($actor)
                ->postJson('/people/'.$target->id.'/active', ['active' => false])
                ->assertStatus(403);
        }

        $this->assertDatabaseHas('people', ['id' => $target->id]);
    }

    /** 削除すると、その人だけに付いていた情報（できるポジション等）も片づく。 */
    public function test_delete_cleans_up_related_rows(): void
    {
        $target = $this->someone();
        \App\Models\StaffRoleEligibility::create(['staff_id' => $target->id, 'position' => 'OP']);

        $this->actingAsPerson($this->admin())
            ->postJson('/people/'.$target->id.'/delete')
            ->assertOk();

        $this->assertDatabaseMissing('staff_role_eligibility', ['staff_id' => $target->id]);
    }
}
