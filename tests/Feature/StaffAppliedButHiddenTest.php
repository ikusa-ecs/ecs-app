<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\ProjectShare;
use App\Support\StaffProjectNews;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * エントリーした人にも、非公開・他拠点に巻き取られた案件は見せない（2026-10-07 baba要望）。
 *
 * 前は「応募したのに一覧から消えると取り消せない」ので、エントリーした人にだけ残していた。
 * そのため、他拠点に巻き取られた（＝この拠点のスタッフは要らなくなった）案件や、
 * 非公開に戻した案件が、エントリーした人にだけ見え続けていた。
 *  ・募集中タブ・「🔔 最近の変更」の両方から外す
 *  ・確定した人は、非公開でも確定アサインに出る（2026-10-05 の決まりはそのまま）
 *  ・応募の記録（applications）は消さない
 */
class StaffAppliedButHiddenTest extends TestCase
{
    use RefreshDatabase;

    private function soon(): string
    {
        return Carbon::today()->addDays(5)->format('Y-m-d');
    }

    private function data($me): array
    {
        return $this->actingAsPerson($me)->get('/staff-portal')->assertOk()->original->getData();
    }

    public function test_unpublished_project_is_hidden_from_applicant(): void
    {
        $me = PersonFactory::new()->staff()->create(['office' => '東京']);
        $p = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $this->soon()]);   // 非公開
        Application::create(['project_id' => $p->id, 'staff_id' => $me->id, 'intent' => '希望']);
        $p->update(['location' => '新しい会場']);   // 会場の変更＝「最近の変更」に出る種類の変更

        $data = $this->data($me);

        $this->assertNotContains($p->id, collect($data['recruitJobs'])->pluck('id')->all(), '非公開の案件がエントリーした人に見えている');
        $this->assertNotContains($p->id, collect(StaffProjectNews::forPerson($me))->pluck('projectId')->all());
        $this->assertSame(1, Application::where('project_id', $p->id)->count(), '応募の記録は消さない');
    }

    public function test_taken_over_project_is_hidden_from_applicant(): void
    {
        $me = PersonFactory::new()->staff()->create(['office' => '東京']);
        $p = ProjectFactory::new()->published()->create(['office' => '東京', 'start_date' => $this->soon()]);
        Application::create(['project_id' => $p->id, 'staff_id' => $me->id, 'intent' => '希望']);
        ProjectShare::create(['project_id' => $p->id, 'office' => '大阪', 'kind' => '巻き取り']);

        $ids = collect($this->data($me)['recruitJobs'])->pluck('id')->all();

        $this->assertNotContains($p->id, $ids, '他拠点に巻き取られた案件がエントリーした人に見えている');
    }

    public function test_published_project_still_shows_as_applied(): void
    {
        $me = PersonFactory::new()->staff()->create(['office' => '東京']);
        $p = ProjectFactory::new()->published()->create(['office' => '東京', 'start_date' => $this->soon()]);
        Application::create(['project_id' => $p->id, 'staff_id' => $me->id, 'intent' => '希望']);

        $job = collect($this->data($me)['recruitJobs'])->firstWhere('id', $p->id);

        $this->assertNotNull($job);
        $this->assertTrue($job['applied']);
    }

    public function test_confirmed_member_still_sees_unpublished_project(): void
    {
        $me = PersonFactory::new()->staff()->create(['office' => '東京']);
        $p = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $this->soon()]);   // 非公開
        Application::create(['project_id' => $p->id, 'staff_id' => $me->id, 'intent' => '希望']);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $me->id, 'date' => $this->soon(), 'role' => '', 'status' => '確定',
        ]);

        $data = $this->data($me);

        $this->assertContains($p->id, collect($data['published'])->pluck('id')->all(), '確定した人には確定アサインに出す');
        $this->assertNotContains($p->id, collect($data['recruitJobs'])->pluck('id')->all());
    }
}
