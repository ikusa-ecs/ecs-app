<?php

namespace Tests\Feature;

use App\Models\ProjectHistory;
use App\Support\DateChange;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 日付が変わった案件の印（2026-10-26）。7日間だけ・旧→新・同じ日なら出さない。
 */
class DateChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_start_date_change_is_shown_with_from_and_to(): void
    {
        $p = ProjectFactory::new()->create(['start_date' => '2026-11-20']);
        $p->forceFill(['start_date' => '2026-11-23'])->save();

        $got = DateChange::forProjects([$p->id]);

        $this->assertSame('11/20', $got[$p->id]['from']);
        $this->assertSame('11/23', $got[$p->id]['to']);
    }

    public function test_change_older_than_seven_days_is_not_shown(): void
    {
        $p = ProjectFactory::new()->create(['start_date' => '2026-11-20']);
        $p->forceFill(['start_date' => '2026-11-23'])->save();
        ProjectHistory::query()->update(['created_at' => Carbon::now()->subDays(8)]);

        $this->assertSame([], DateChange::forProjects([$p->id]));
    }

    public function test_unchanged_date_is_not_shown(): void
    {
        $p = ProjectFactory::new()->create(['start_date' => '2026-11-20']);
        $p->forceFill(['project_name' => '名前だけ変更'])->save();

        $this->assertSame([], DateChange::forProjects([$p->id]));
    }
}
