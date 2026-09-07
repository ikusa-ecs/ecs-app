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

    /**
     * 並べ替え（所属順／社歴順）に必要な材料が画面へ渡っていること（2026-09-07 baba要望）。
     *
     * ⚠ 並べ替えるのは画面側。EMP_LIST そのものを並べ替えないこと
     *   （idx＝元の並びの番号で本人の入力を引いているので、崩すと他人の行に自分の入力が出る）。
     * ⚠ 入社年月日が空の人は '' で渡し、社歴順ではいちばん下にまとめる
     *   （空を「いちばん古い」と扱うと、新人が先輩より上に来て意味が逆になる）。
     */
    public function test_rows_carry_sort_keys(): void
    {
        $me = $this->emp('E-ME', '自分 太郎', 'わたし たろう', 'イベプラ');
        \App\Models\Person::where('id', 'E-ME')->update(['hire_date' => '2020-04-01']);
        $this->emp('E-N1', '入社日なし', 'にゅうしゃび なし', 'セールス');

        $this->actingAsPerson($me)->get('/employee-availability')
            ->assertOk()
            ->assertViewHas('employees', function ($employees) {
                $by = collect($employees)->keyBy('id');

                return ($by['E-ME']['hire'] ?? null) === '2020-04-01'
                    && ($by['E-N1']['hire'] ?? null) === ''
                    && ($by['E-ME']['deptRank'] ?? null) === 0
                    && ($by['E-N1']['deptRank'] ?? null) === 1;
            });
    }

    /** 並べ替えの選択そのものが画面から消えていないこと（@ の区間の中なので気づけない）。 */
    public function test_the_sort_selector_exists(): void
    {
        $me = $this->emp('E-ME', '自分 太郎', 'わたし たろう', 'イベプラ');

        $this->actingAsPerson($me)->get('/employee-availability')
            ->assertOk()
            ->assertSee('id="ovSort"', false)
            ->assertSee('社歴順（入社が古い人が上）', false);
    }
}
