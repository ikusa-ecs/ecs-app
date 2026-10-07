<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * スタッフ画面の「追加」と、案件の区分（追加案件）を分けた（2026-10-07 baba要望）。
 *
 *  ・公開ボードで「追加解除」しても、**区分は追加案件のまま**（集計の「追加で何件増えたか」が減らない）
 *  ・スタッフ画面では「追加」として上に出なくなる（締切も一斉締切に戻る）
 *  ・公開ボードでまだ押していない案件は、今までどおり区分に合わせる
 */
class StaffExtraTest extends TestCase
{
    use RefreshDatabase;

    private function soon(): string
    {
        return Carbon::today()->addDays(10)->format('Y-m-d');
    }

    private function staffJob($me, string $id): ?array
    {
        return collect(
            $this->actingAsPerson($me)->get('/staff-portal')->assertOk()->original->getData()['recruitJobs']
        )->firstWhere('id', $id);
    }

    public function test_removing_extra_on_publish_board_keeps_category(): void
    {
        $employee = PersonFactory::new()->create(['office' => '東京']);
        $staff = PersonFactory::new()->staff()->create(['office' => '東京']);
        $p = ProjectFactory::new()->published()->create([
            'office' => '東京', 'start_date' => $this->soon(), 'category' => '追加案件',
        ]);

        // まだ押していない＝区分に合わせて「追加」。
        $this->assertTrue($this->staffJob($staff, $p->id)['staffExtra']);

        $this->actingAsPerson($employee)->postJson('/assign-publish/category', ['id' => $p->id, 'is_extra' => false])
            ->assertOk();

        $fresh = $p->fresh();
        $this->assertSame('追加案件', $fresh->category, '区分（集計用）は変えない');
        $this->assertFalse($fresh->staff_extra);
        $this->assertFalse($this->staffJob($staff, $p->id)['staffExtra'], 'スタッフ画面では追加として出さない');
    }

    public function test_bulk_and_adding_extra_to_normal_project(): void
    {
        $employee = PersonFactory::new()->create(['office' => '東京']);
        $a = ProjectFactory::new()->published()->create(['office' => '東京', 'start_date' => $this->soon(), 'category' => '通常案件']);
        $b = ProjectFactory::new()->published()->create(['office' => '東京', 'start_date' => $this->soon(), 'category' => '追加案件']);

        $this->actingAsPerson($employee)->postJson('/assign-publish/category-bulk', ['ids' => [$a->id], 'is_extra' => true])->assertOk();
        $this->actingAsPerson($employee)->postJson('/assign-publish/category-bulk', ['ids' => [$b->id], 'is_extra' => false])->assertOk();

        $this->assertSame('通常案件', $a->fresh()->category, '追加を付けても区分は通常のまま');
        $this->assertTrue($a->fresh()->staff_extra);
        $this->assertNotNull($a->fresh()->extra_published_at, '締切（公開日＋3日）の起点を記録する');
        $this->assertSame('追加案件', $b->fresh()->category, '追加を外しても区分は追加のまま');
        $this->assertNull($b->fresh()->extra_published_at);
    }

    public function test_publish_board_shows_staff_extra_not_category(): void
    {
        $employee = PersonFactory::new()->create(['office' => '東京']);
        $p = ProjectFactory::new()->create([
            'office' => '東京', 'start_date' => $this->soon(), 'category' => '追加案件', 'staff_extra' => false,
        ]);

        $cases = collect($this->actingAsPerson($employee)->get('/assign-publish')->assertOk()->original->getData()['cases']);
        $row = $cases->firstWhere('id', $p->id);

        $this->assertSame('追加案件', $row['category']);
        $this->assertFalse($row['staffExtra']);
    }
}
