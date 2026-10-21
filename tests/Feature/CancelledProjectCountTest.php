<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Support\ActiveBonus;
use App\Support\EventCount;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * キャンセルになった案件の人は、記録には残すが数えない（2026-10-21 baba決定）。
 *
 * 【babaの言葉】「案件一覧でキャンセルにしたら日別ボードの人は残したままキャンセルにして、
 *   アサインされてた人の数は数えない。別案件にお引越ししてもらうときの参考にできるかもしれないから」
 *
 * 【守りたいこと】
 *  ・キャンセルにしても assignments は消さない
 *  ・数えるところは Assignment::onLiveProject() を通す（ボーナス回数・稼働状況・ダブルブッキング・月の上限・自動アサイン…）
 *  ・手で「イベント数に数える」にしていても、キャンセルなら数えない
 */
class CancelledProjectCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_キャンセル案件の人は残るが数えない(): void
    {
        $staff = PersonFactory::new()->create(['role' => 'staff', 'office' => '東京']);
        $live = ProjectFactory::new()->create(['start_date' => '2026-11-10']);
        $gone = ProjectFactory::new()->create(['start_date' => '2026-11-11', 'is_cancelled' => true]);
        foreach ([$live, $gone] as $p) {
            Assignment::create(['project_id' => $p->id, 'staff_id' => $staff->id, 'role' => 'FC', 'status' => '確定',
                'date' => $p->start_date->format('Y-m-d')]);
        }

        $this->assertSame(2, Assignment::count(), 'キャンセルにしても人の記録は消さない');
        $this->assertSame([$live->id], Assignment::onLiveProject()->pluck('project_id')->all());
        $this->assertSame(1, ActiveBonus::forStaff($staff->id, '2026-11')['count'], 'ボーナスの回数にキャンセル案件が入っている');
    }

    public function test_手で数えるにしてもキャンセルなら数えない(): void
    {
        $p = ProjectFactory::new()->create(['count_as_event' => true, 'is_cancelled' => true]);

        $this->assertFalse(EventCount::counts($p));
    }
}
