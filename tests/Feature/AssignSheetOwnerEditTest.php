<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * アサイン表のカードから営業担当・物品担当を直せる（2026-11-09 小沼さん要望）。
 * 営業＝名前で持つ（2人以上は「・」区切り）／物品＝名簿にいる社員だけ。
 */
class AssignSheetOwnerEditTest extends TestCase
{
    use RefreshDatabase;

    private function manager()
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'role' => 'employee', 'permission' => 'manager', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    private function save(string $field, string $value, $project)
    {
        return $this->post('/assign-sheet/project', [
            'project_id' => $project->id, 'field' => $field, 'value' => $value,
        ]);
    }

    public function test_sales_owner_can_be_set_and_cleared(): void
    {
        $this->actingAsPerson($this->manager());
        $project = ProjectFactory::new()->create(['office' => '東京', 'sales_owners' => ['山田']]);

        $this->save('sales_owner', '佐藤・鈴木', $project)->assertOk();
        $this->assertSame(['佐藤', '鈴木'], $project->fresh()->sales_owners);

        $this->save('sales_owner', '', $project)->assertOk();
        $this->assertNull($project->fresh()->sales_owners);
    }

    public function test_goods_owner_accepts_only_employees(): void
    {
        $this->actingAsPerson($this->manager());
        PersonFactory::new()->create(['id' => 'E-002', 'role' => 'employee', 'office' => '東京']);
        PersonFactory::new()->create(['id' => 'S-001', 'role' => 'staff', 'office' => '東京']);
        $project = ProjectFactory::new()->create(['office' => '東京']);

        $this->save('goods_owner_id', 'E-002', $project)->assertOk();
        $this->assertSame('E-002', $project->fresh()->goods_owner_id);

        $this->save('goods_owner_id', 'S-001', $project)->assertStatus(422);
        $this->assertSame('E-002', $project->fresh()->goods_owner_id, 'スタッフは入らない');

        $this->save('goods_owner_id', '', $project)->assertOk();
        $this->assertNull($project->fresh()->goods_owner_id);
    }

    public function test_sheet_shows_owner_inputs(): void
    {
        $this->actingAsPerson($this->manager());
        $project = ProjectFactory::new()->create(['office' => '東京', 'start_date' => now()->toDateString()]);

        $this->get('/assign-sheet?month=' . now()->format('Y-m'))
            ->assertOk()
            ->assertSee('ecsSalesList', false)
            ->assertSee("'goods_owner_id'", false);
    }

    /** 人の名前でも絞り込める＝検索用の文字に人の名前が空白ぬきで入る（2026-11-09）。 */
    public function test_search_text_includes_people_without_spaces(): void
    {
        $this->actingAsPerson($this->manager());
        ProjectFactory::new()->create(['office' => '東京', 'start_date' => now()->toDateString(), 'sales_owners' => ['西本 蓮太郎']]);

        $this->get('/assign-sheet?month=' . now()->format('Y-m'))
            ->assertOk()
            ->assertSee('西本蓮太郎', false);
    }
}
