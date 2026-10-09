<?php

namespace Tests\Feature;

use App\Models\Assignment;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 過去案件（/past-projects）。2026-10-09 baba要望＝年→月のフォルダで、終わった案件とメンバーを見る。
 *
 * 見張るのは4つ。
 *   ① 年・月のフォルダに件数が出て、選んだ月の案件だけが並ぶ
 *   ② これからの案件・下書きは出さない
 *   ③ メンバーはキャンセル以外・複数日でも1人1回
 *   ④ スタッフは入れない（社員以上）
 */
class PastProjectsTest extends TestCase
{
    use RefreshDatabase;

    private function emp()
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'permission' => 'employee', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    private function project(string $date, array $attrs = [])
    {
        return ProjectFactory::new()->create(array_merge([
            'office' => '東京', 'start_date' => $date, 'status' => '確定',
        ], $attrs));
    }

    public function test_フォルダと選んだ月の案件(): void
    {
        $this->project('2025-07-12', ['client' => '株式会社ナナガツ']);
        $this->project('2025-07-20', ['client' => '株式会社ナナガツ2']);
        $this->project('2024-03-03', ['client' => '株式会社サンガツ']);

        $me = $this->emp();
        $res = $this->actingAsPerson($me)->get('/past-projects?ym=2024-03')->assertOk();

        $years = $res->viewData('years');
        $this->assertSame([2025, 2024], array_column($years, 'year'));
        $this->assertSame(2, $years[0]['total']);
        $res->assertSee('株式会社サンガツ')->assertDontSee('株式会社ナナガツ');

        // 月を選ばなければ、いちばん新しい月を開く。
        $this->actingAsPerson($me)->get('/past-projects')
            ->assertOk()->assertViewHas('ym', '2025-07')->assertSee('株式会社ナナガツ2');
    }

    public function test_これからの案件と下書きは出さない(): void
    {
        $this->project(Carbon::today()->addDays(3)->format('Y-m-d'), ['client' => 'ミライ社']);
        $this->project('2025-05-05', ['client' => 'シタガキ社', 'status' => '下書き']);
        $this->project('2025-05-06', ['client' => 'スンダ社']);

        $this->actingAsPerson($this->emp())->get('/past-projects?ym=2025-05')
            ->assertOk()->assertSee('スンダ社')->assertDontSee('シタガキ社')->assertDontSee('ミライ社');
    }

    public function test_メンバーはキャンセル以外で1人1回(): void
    {
        $p = $this->project('2025-08-01');
        $a = PersonFactory::new()->create(['id' => 'S-101', 'name' => '出た太郎']);
        $b = PersonFactory::new()->create(['id' => 'S-102', 'name' => '取消花子']);
        foreach (['2025-08-01', '2025-08-02'] as $d) {
            Assignment::create(['project_id' => $p->id, 'staff_id' => $a->id, 'date' => $d, 'role' => 'FC', 'status' => '確定']);
        }
        Assignment::create(['project_id' => $p->id, 'staff_id' => $b->id, 'date' => '2025-08-01', 'role' => 'FC', 'status' => 'キャンセル']);

        $res = $this->actingAsPerson($this->emp())->get('/past-projects?ym=2025-08')->assertOk();
        $members = $res->viewData('cases')[0]['members'];
        $this->assertCount(1, $members);
        $this->assertSame('出た太郎', $members[0]['name']);
        $this->assertFalse($members[0]['tentative']);
    }

    public function test_スタッフは入れない(): void
    {
        $staff = PersonFactory::new()->create(['permission' => 'staff', 'must_onboard' => false]);
        $res = $this->actingAsPerson($staff)->get('/past-projects');
        $this->assertNotSame(200, $res->status());
    }
}
