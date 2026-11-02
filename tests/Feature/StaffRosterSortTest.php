<?php

namespace Tests\Feature;

use App\Models\Assignment;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * スタッフ名簿の「経験回数の多い順」（2026-11-02 小沼さん）。
 * 保存された参考値（people.experience_count・本番はほぼ空）で並べていたので全員0になり、番号順と同じになっていた。
 * ＝アサインから数え直した数（ExperienceCount・詳細の「通算 ○件」）で並べ、表の「通算」も同じ数にする。
 */
class StaffRosterSortTest extends TestCase
{
    use RefreshDatabase;

    public function test_roster_uses_counted_experience(): void
    {
        $me = PersonFactory::new()->create(['role' => 'admin']);
        $few = PersonFactory::new()->staff()->create(['name' => '少ない人', 'experience_count' => null]);
        $many = PersonFactory::new()->staff()->create(['name' => '多い人', 'experience_count' => null]);
        foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $i => $d) {
            $p = ProjectFactory::new()->create(['start_date' => $d]);
            Assignment::create(['project_id' => $p->id, 'staff_id' => $many->id, 'date' => $d, 'role' => 'FC', 'status' => '確定']);
            if ($i === 0) {
                Assignment::create(['project_id' => $p->id, 'staff_id' => $few->id, 'date' => $d, 'role' => 'FC', 'status' => '確定']);
            }
        }

        $data = $this->actingAsPerson($me)->get('/staff')->assertOk()->original->getData();
        // 稼働状況タブの「通算」（区分の新人/中堅/ベテランもここから）も同じ数。
        $status = collect($data['status']);
        $this->assertSame(3, $status->firstWhere('id', $many->id)['total']);
        $this->assertSame(1, $status->firstWhere('id', $few->id)['total']);

        $people = collect($data['people']);
        $this->assertSame(3, $people->firstWhere('id', $many->id)['total']);
        $this->assertSame(1, $people->firstWhere('id', $few->id)['total']);
        $ids = $people->pluck('id')->all();
        $this->assertLessThan(array_search($few->id, $ids), array_search($many->id, $ids));
    }
}
