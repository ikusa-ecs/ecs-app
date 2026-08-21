<?php

namespace Tests\Feature;

use App\Models\Project;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * スタッフ公開ボードから必要人数（運営人数）を直せる（2026-08-21 baba要望）。
 *
 * 募集をかける直前に人数を直したい場面が多いのに、これまでは案件登録かアサイン表へ
 * 行かないと変えられなかった。保存先は projects.required_count＝どの画面から直しても同じ列。
 */
class PublishBoardCountTest extends TestCase
{
    use RefreshDatabase;

    private function project()
    {
        return ProjectFactory::new()->create([
            'start_date'     => Carbon::today()->addDays(5)->format('Y-m-d'),
            'required_count' => 8,
        ]);
    }

    /** 人数を直すと保存される。 */
    public function test_count_can_be_updated(): void
    {
        $emp = PersonFactory::new()->create(['permission' => 'manager']);
        $p   = $this->project();

        $this->actingAsPerson($emp)
            ->postJson('/assign-publish/count', ['id' => $p->id, 'count' => 12])
            ->assertOk()
            ->assertJson(['ok' => true, 'count' => 12]);

        $this->assertSame(12, Project::find($p->id)->required_count);
    }

    /** 空で送ると「未定」（null）に戻る。 */
    public function test_blank_clears_the_count(): void
    {
        $emp = PersonFactory::new()->create(['permission' => 'manager']);
        $p   = $this->project();

        $this->actingAsPerson($emp)
            ->postJson('/assign-publish/count', ['id' => $p->id, 'count' => null])
            ->assertOk();

        $this->assertNull(Project::find($p->id)->required_count);
    }

    /** 数字以外・大きすぎる値は受け付けない。 */
    public function test_invalid_count_is_rejected(): void
    {
        $emp = PersonFactory::new()->create(['permission' => 'manager']);
        $p   = $this->project();

        try {
            $this->withoutExceptionHandling()->actingAsPerson($emp)
                ->postJson('/assign-publish/count', ['id' => $p->id, 'count' => 1000]);
            $this->fail('弾かれるはず');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('count', $e->errors());
        }

        $this->assertSame(8, Project::find($p->id)->required_count, '弾かれたときは元のまま');
    }
}
