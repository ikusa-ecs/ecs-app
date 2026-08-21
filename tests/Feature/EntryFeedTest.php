<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Assignment;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * エントリー新着（/entry-feed）。2026-08-21 baba要望。
 *
 * 「エントリー一覧」は案件ごとなので「いつ・誰から来たか」が追えなかった。
 * この画面は来た順（新しい順）に並べ、追加案件の反応と新人の応募先が分かるようにする。
 */
class EntryFeedTest extends TestCase
{
    use RefreshDatabase;

    private function emp()
    {
        return PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京']);
    }

    private function project(array $attrs = [])
    {
        return ProjectFactory::new()->create(array_merge([
            'office'     => '東京',
            'start_date' => Carbon::today()->addDays(10)->format('Y-m-d'),
        ], $attrs));
    }

    /** 新しい順に並ぶ。 */
    public function test_rows_are_sorted_newest_first(): void
    {
        $old = PersonFactory::new()->staff()->create(['name' => '先に応募した人']);
        $new = PersonFactory::new()->staff()->create(['name' => 'あとで応募した人']);
        $p = $this->project();

        Application::create([
            'staff_id' => $old->id, 'project_id' => $p->id, 'intent' => '希望',
            'applied_at' => Carbon::now()->subDays(2),
        ]);
        Application::create([
            'staff_id' => $new->id, 'project_id' => $p->id, 'intent' => '希望',
            'applied_at' => Carbon::now()->subHour(),
        ]);

        $rows = collect(
            $this->actingAsPerson($this->emp())->get('/entry-feed')->assertOk()->original->getData()['rows']
        );

        $this->assertSame(['あとで応募した人', '先に応募した人'], $rows->pluck('staffName')->all());
    }

    /** 新人（入社1年未満）に印が付く。入社日が無い人には付けない。 */
    public function test_newcomer_is_flagged(): void
    {
        $rookie = PersonFactory::new()->staff()->create([
            'name' => '新人さん', 'hire_date' => Carbon::today()->subMonths(2)->format('Y-m-d'),
        ]);
        $veteran = PersonFactory::new()->staff()->create([
            'name' => 'ベテランさん', 'hire_date' => Carbon::today()->subYears(4)->format('Y-m-d'),
        ]);
        $p = $this->project();
        Application::create(['staff_id' => $rookie->id, 'project_id' => $p->id, 'intent' => '希望', 'applied_at' => now()]);
        Application::create(['staff_id' => $veteran->id, 'project_id' => $p->id, 'intent' => '希望', 'applied_at' => now()]);

        $data = $this->actingAsPerson($this->emp())->get('/entry-feed')->assertOk()->original->getData();
        $rows = collect($data['rows'])->keyBy('staffName');

        $this->assertTrue($rows['新人さん']['isNew']);
        $this->assertFalse($rows['ベテランさん']['isNew']);
        $this->assertSame(1, $data['newCount']);
    }

    /** 「追加案件のみ」で絞れる。 */
    public function test_extra_filter(): void
    {
        $staff = PersonFactory::new()->staff()->create();
        $extra = $this->project(['category' => '追加案件']);
        $normal = $this->project(['category' => '通常案件']);
        Application::create(['staff_id' => $staff->id, 'project_id' => $extra->id, 'intent' => '希望', 'applied_at' => now()]);
        Application::create(['staff_id' => $staff->id, 'project_id' => $normal->id, 'intent' => '希望', 'applied_at' => now()]);

        $rows = collect(
            $this->actingAsPerson($this->emp())->get('/entry-feed?extra=1')->assertOk()->original->getData()['rows']
        );

        $this->assertSame([$extra->id], $rows->pluck('projectId')->all());
    }

    /** すでにアサイン済みの応募は「確定／仮」、それ以外は未対応として数える。 */
    public function test_assign_status_is_shown(): void
    {
        $staff = PersonFactory::new()->staff()->create();
        $p = $this->project();
        Application::create(['staff_id' => $staff->id, 'project_id' => $p->id, 'intent' => '希望', 'applied_at' => now()]);

        $before = $this->actingAsPerson($this->emp())->get('/entry-feed')->assertOk()->original->getData();
        $this->assertNull(collect($before['rows'])->first()['assignStatus']);
        $this->assertSame(1, $before['todoCount']);

        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $staff->id,
            'date' => $p->start_date->format('Y-m-d'), 'role' => 'OP', 'status' => '確定',
        ]);

        $after = $this->actingAsPerson($this->emp())->get('/entry-feed')->assertOk()->original->getData();
        $this->assertSame('確定', collect($after['rows'])->first()['assignStatus']);
        $this->assertSame(0, $after['todoCount']);
    }

    /** スタッフは入れない（社員以上の画面）。 */
    public function test_staff_cannot_open(): void
    {
        $staff = PersonFactory::new()->staff()->create();

        $this->actingAsPerson($staff)->get('/entry-feed')->assertRedirect('/staff-portal');
    }
}
