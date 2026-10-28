<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 案件一覧のキーワード検索は、月に関係なく全期間から探す（2026-10-28）。
 * 一致した案件だけを返すので、月ごとに表示するより重くならない。
 */
class ProjectSearchAllPeriodTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_finds_projects_in_any_month(): void
    {
        $me = PersonFactory::new()->create(['id' => 'E-001', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false]);
        $old = ProjectFactory::new()->create([
            'office' => '東京', 'start_date' => Carbon::today()->subYears(2)->format('Y-m-d'),
            'project_name' => 'ウラシマ謎解き', 'client' => '古い社',
        ]);
        ProjectFactory::new()->create([
            'office' => '東京', 'start_date' => Carbon::today()->format('Y-m-d'),
            'project_name' => '今月のイベント', 'client' => '新しい社',
        ]);

        $cases = collect($this->actingAsPerson($me)->get('/projects?q=ウラシマ')->assertOk()->viewData('cases'))->pluck('id');
        $this->assertSame([$old->id], $cases->all(), '2年前の案件も全期間から見つかること');

        $cases = collect($this->actingAsPerson($me)->get('/projects?q=%25')->assertOk()->viewData('cases'))->pluck('id');
        $this->assertSame(0, $cases->count(), '検索語の % はそのまま探す（全部には一致しない）');
    }
}
