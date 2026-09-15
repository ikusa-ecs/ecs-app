<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Person;
use App\Models\ShiftPreference;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 案件別アサイン（/project-assign）の「候補に入れてよい人」の決まり。
 *
 * ⚠ FBシート No.17・No.18（2026-09-10 報告／2026-09-15 修正）
 *   17＝「エントリーが『この日希望・稼働可の人だけ』に出ない」
 *      … 絞り込みが稼働可カレンダーしか見ていなかったので、
 *        「エントリーする」を押した人でもカレンダーが空なら候補から消えていた。
 *   18＝「自動仮置きでエントリーも稼働可でもない人が入る」
 *      … ✨自動で仮置きが、絞り込みで隠れている行もふくめ名簿の全員から選んでいた。
 *
 * 決まり＝**エントリーした人 または その日を希望・稼働可にした人**。
 * 月まとめ自動アサインの MonthAutoAssign::candidatesFor() と同じ考え方。
 * 判定はサーバーで1回だけ作り（'eligible'）、画面は data-eligible を見るだけにしてある。
 */
class ProjectAssignCandidateGateTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'name' => 'アサイン担当', 'permission' => 'manager',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    private function staff(string $id, string $name): Person
    {
        return PersonFactory::new()->staff()->create([
            'id' => $id, 'name' => $name, 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** その人の「候補に入れてよいか」の印を取り出す。 */
    private function eligible(array $staffList, string $id): ?bool
    {
        foreach ($staffList as $s) {
            if (($s['id'] ?? '') === $id) {
                return (bool) $s['eligible'];
            }
        }

        return null;
    }

    public function test_エントリーした人はカレンダーが空でも候補になる(): void
    {
        $me = $this->manager();
        $day = Carbon::today()->addDays(10);
        $p = ProjectFactory::new()->published()->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 2, 'office' => '東京',
        ]);

        $entered = $this->staff('S-101', 'エントリーした子');
        Application::create([
            'staff_id' => $entered->id, 'project_id' => $p->id, 'intent' => '希望', 'applied_at' => now(),
        ]);

        $staff = $this->actingAsPerson($me)
            ->get('/project-assign?project='.$p->id)->assertOk()->viewData('staff')->all();

        // これが No.17。カレンダーを1日も入れていなくても、応募した人は候補に残る。
        $this->assertTrue($this->eligible($staff, 'S-101'), 'エントリーした人が候補から外れている');
    }

    public function test_その日を稼働可にした人も候補になる(): void
    {
        $me = $this->manager();
        $day = Carbon::today()->addDays(10);
        $p = ProjectFactory::new()->published()->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 2, 'office' => '東京',
        ]);

        $avail = $this->staff('S-102', '稼働可の子');
        ShiftPreference::create([
            'staff_id' => $avail->id, 'period' => $day->format('Y-m'),
            'date' => $day->format('Y-m-d'), 'availability' => '稼働可',
        ]);

        $staff = $this->actingAsPerson($me)
            ->get('/project-assign?project='.$p->id)->assertOk()->viewData('staff')->all();

        $this->assertTrue($this->eligible($staff, 'S-102'));
    }

    public function test_何もしていない人は候補にしない(): void
    {
        $me = $this->manager();
        $day = Carbon::today()->addDays(10);
        $p = ProjectFactory::new()->published()->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 2, 'office' => '東京',
        ]);

        $this->staff('S-103', '何もしていない子');

        $staff = $this->actingAsPerson($me)
            ->get('/project-assign?project='.$p->id)->assertOk()->viewData('staff')->all();

        // 名簿には出る（手で選ぶことはできる）が、「候補」の印は付かない。
        $this->assertNotNull($this->eligible($staff, 'S-103'), '名簿から消えてしまっている');
        $this->assertFalse($this->eligible($staff, 'S-103'), '応募も稼働可もしていない人に候補の印が付いている');
    }

    public function test_NGにした日は候補にしない(): void
    {
        $me = $this->manager();
        $day = Carbon::today()->addDays(10);
        $p = ProjectFactory::new()->published()->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 2, 'office' => '東京',
        ]);

        $ng = $this->staff('S-104', 'この日NGの子');
        ShiftPreference::create([
            'staff_id' => $ng->id, 'period' => $day->format('Y-m'),
            'date' => $day->format('Y-m-d'), 'availability' => 'NG',
        ]);

        $staff = $this->actingAsPerson($me)
            ->get('/project-assign?project='.$p->id)->assertOk()->viewData('staff')->all();

        $this->assertFalse($this->eligible($staff, 'S-104'));
    }

    /**
     * 画面側も、絞り込みと✨自動で仮置きの両方が同じ印（data-eligible）を見ていること。
     *
     * ⚠ 文字列で見張っている理由＝この2つは JS なので、片方だけ直すと
     *   「絞り込みでは消えているのに自動で入る」という食い違いに戻る（それが No.18 だった）。
     */
    public function test_画面の絞り込みと自動仮置きが同じ印を見ている(): void
    {
        $blade = file_get_contents(resource_path('views/assignment.blade.php'));

        $this->assertStringContainsString('data-eligible=', $blade, '候補の印が画面に出ていません');
        $this->assertStringContainsString(
            "const availOk = !availOnly || tr.dataset.eligible === '1' || tr.dataset.assigned === '1';",
            $blade, '絞り込みが候補の印を見ていません（エントリーした人が消えます）'
        );
        $this->assertStringContainsString(
            ".filter(tr => tr.dataset.eligible === '1' || tr.dataset.assigned === '1')",
            $blade, '自動仮置きが名簿の全員から選ぶ状態に戻っています'
        );
    }
}
