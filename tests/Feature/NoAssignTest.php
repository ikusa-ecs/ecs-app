<?php

namespace Tests\Feature;

use App\Models\Project;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 「アサイン不要（IKUSAは運営に入らない）」（2026-10-09 baba要望・正本=Project::scopeNeedsAssign）。
 * 例：会場備え付けのBBQ。運営人数0で登録でき、人を入れる画面には出さない。案件一覧には残す。
 */
class NoAssignTest extends TestCase
{
    use RefreshDatabase;

    private function manager()
    {
        return PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京', 'must_onboard' => false]);
    }

    public function test_チェックすれば運営人数0で登録できる(): void
    {
        $this->actingAsPerson($this->manager())->post('/project-form', [
            'content_names' => 'BBQ', 'start_date' => '2026-11-01',
            'required_count' => '0', 'ikusa_count' => '0', 'no_assign' => '1', 'intent' => 'publish',
        ])->assertRedirect('/projects');

        $p = Project::firstOrFail();
        $this->assertTrue($p->no_assign);
        $this->assertSame(0, $p->required_count);
    }

    public function test_チェックしなければこれまでどおり0は弾く(): void
    {
        $this->actingAsPerson($this->manager())->post('/project-form', [
            'content_names' => 'BBQ', 'start_date' => '2026-11-01',
            'required_count' => '0', 'ikusa_count' => '0', 'intent' => 'publish',
        ])->assertSessionHasErrors('required_count');
        $this->assertSame(0, Project::count());
    }

    public function test_日別ボードには出ず案件一覧には出る(): void
    {
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        $bbq = ProjectFactory::new()->published()->create(['office' => '東京', 'start_date' => $date, 'no_assign' => true, 'project_name' => 'BBQ']);
        $normal = ProjectFactory::new()->published()->create(['office' => '東京', 'start_date' => $date]);
        $me = $this->manager();

        $ids = collect($this->actingAsPerson($me)->get('/assign')->assertOk()->viewData('boardCases'))->pluck('id')->all();
        $this->assertContains($normal->id, $ids);
        $this->assertNotContains($bbq->id, $ids);

        $this->assertSame([$normal->id], Project::query()->needsAssign()->pluck('id')->all());
        $this->assertStringContainsString($bbq->id, $this->actingAsPerson($me)->get('/projects')->assertOk()->getContent());
    }
}
