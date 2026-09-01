<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 社員の出勤可能日「全社員の一覧」の並び順と色分け（2026-09-01 baba要望）。
 *
 * 並び＝**自分 → イベプラ → セールス → その他**、それぞれの中は**五十音順**。
 *
 * ⚠ 自分は必ず先頭。画面が「先頭の行＝自分」という決まりで動いているため、
 *   ここが崩れると**他人の行に自分の入力が出る**（過去に実際に起きている）。
 * ⚠ 五十音順は name_kana で並べる。漢字のままだと「青山」より「渡辺」が先に来ることがある。
 */
class EmployeeAvailabilityOrderTest extends TestCase
{
    use RefreshDatabase;

    private function emp(string $id, string $name, string $kana, ?string $dept)
    {
        return PersonFactory::new()->create([
            'id' => $id, 'role' => 'employee', 'permission' => 'employee',
            'name' => $name, 'name_kana' => $kana, 'department' => $dept,
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** 自分 → イベプラ → セールス → その他 の順。各組の中は五十音順。 */
    public function test_the_order_is_self_then_plan_then_sales_then_others(): void
    {
        // わざと「五十音では逆」「所属では逆」に作る＝並べ替えが効いていないと落ちる。
        $me = $this->emp('E-ME', '自分 太郎', 'わたし たろう', 'クリエイティブ');
        $this->emp('E-S2', '佐藤 二郎', 'さとう じろう', 'セールス');
        $this->emp('E-P2', '田中 花子', 'たなか はなこ', 'イベプラ');
        $this->emp('E-O1', '山田 三郎', 'やまだ さぶろう', '経営管理');
        $this->emp('E-P1', '青山 一郎', 'あおやま いちろう', 'イベプラ');
        $this->emp('E-S1', '大野 五郎', 'おおの ごろう', 'セールス');

        $this->actingAsPerson($me)->get('/employee-availability')
            ->assertOk()
            ->assertViewHas('employees', function ($employees) {
                $ids = collect($employees)->pluck('id')->all();

                return $ids === ['E-ME', 'E-P1', 'E-P2', 'E-S1', 'E-S2', 'E-O1'];
            });
    }

    /** 所属の色分け用のコードが画面に渡っている（色の正本＝App\Support\Departments）。 */
    public function test_each_row_carries_its_department_code(): void
    {
        $me = $this->emp('E-ME', '自分 太郎', 'わたし たろう', 'イベプラ');
        $this->emp('E-S1', '大野 五郎', 'おおの ごろう', 'セールス');
        $this->emp('E-N1', '無所属 さん', 'むしょぞく さん', null);

        $this->actingAsPerson($me)->get('/employee-availability')
            ->assertOk()
            ->assertViewHas('employees', function ($employees) {
                $by = collect($employees)->keyBy('id');

                return ($by['E-ME']['dept'] ?? null) === 'plan'
                    && ($by['E-S1']['dept'] ?? null) === 'sales'
                    && ($by['E-N1']['dept'] ?? null) === 'none';
            });
    }

    /**
     * ⚠ 色を画面に直書きしない。名簿のバッジと同じ色（正本＝Departments）を流し込んでいるか。
     *   直書きに戻ると、名簿と出勤可能日で同じ所属が違う色になる。
     */
    public function test_the_colours_come_from_one_place(): void
    {
        $blade = (string) file_get_contents(resource_path('views/employee_availability.blade.php'));

        $this->assertStringContainsString('Departments::rowBgCss', $blade);
        // 色そのものが画面に戻っていないか（イベプラの水色）。
        $this->assertStringNotContainsString('#e0f2fe', $blade,
            '所属の色が画面に直書きされています。App\Support\Departments に寄せてください。');
    }

    /** 行に所属のクラスが付いている（@verbatim の中なので消えても気づけない）。 */
    public function test_the_row_gets_the_department_class(): void
    {
        $me = $this->emp('E-ME', '自分 太郎', 'わたし たろう', 'イベプラ');

        $this->actingAsPerson($me)->get('/employee-availability')
            ->assertOk()
            ->assertSee("'<tr class=\"dep-'", false);
    }
}
