<?php

namespace Tests\Feature;

use App\Support\Departments;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 社員・ディレクター集計（/projects-agg）を所属でしぼれることを守るテスト（2026-09-07 baba要望）。
 *
 * ⚠ 名簿には本当の所属名（「経営管理」など11種類）が入っているが、しぼる単位は4グループ
 *   （イベプラ／セールス／クリエイティブ／その他）。正本＝App\Support\Departments。
 *   DBのwhereでは絞れない（グループへのまとめ方がPHP側にあるため）ので、
 *   「経営管理の人が『その他』で出るか」を必ず確かめる。
 */
class ProjectsAggDeptFilterTest extends TestCase
{
    use RefreshDatabase;

    /** 所属でしぼると、その所属の社員だけが並ぶ。 */
    public function test_filters_employees_by_department(): void
    {
        $admin = PersonFactory::new()->create(['permission' => 'admin', 'department' => 'イベプラ']);
        PersonFactory::new()->create(['name' => 'セールスの人', 'department' => 'セールス']);
        PersonFactory::new()->create(['name' => '経営管理の人', 'department' => '経営管理']);

        $names = fn (array $q) => collect(
            $this->actingAsPerson($admin)->get('/projects-agg?' . http_build_query($q))
                ->assertOk()->original->getData()['rows']
        )->pluck('name')->all();

        $this->assertContains('セールスの人', $names(['dept' => 'sales']));
        $this->assertNotContains('経営管理の人', $names(['dept' => 'sales']));

        // ⚠ 「経営管理」はまとめ先が「その他」。ここが崩れると、その他を選んでも誰も出なくなる。
        $this->assertContains('経営管理の人', $names(['dept' => 'other']));
        $this->assertSame(Departments::OTHER, Departments::group('経営管理'));

        // しぼらなければ全員出る。
        $all = $names([]);
        $this->assertContains('セールスの人', $all);
        $this->assertContains('経営管理の人', $all);
    }

    /** 知らない所属コードが来ても落とさず「すべて」に戻す（URLを手で書き換えられても壊れない）。 */
    public function test_unknown_dept_code_falls_back_to_all(): void
    {
        $admin = PersonFactory::new()->create(['permission' => 'admin', 'department' => 'イベプラ']);
        PersonFactory::new()->create(['name' => 'セールスの人', 'department' => 'セールス']);

        $data = $this->actingAsPerson($admin)->get('/projects-agg?dept=zzz')->assertOk()->original->getData();

        $this->assertSame('', $data['deptCode']);
        $this->assertSame('', $data['scopeDept']);
        $this->assertContains('セールスの人', collect($data['rows'])->pluck('name')->all());
    }

    /**
     * ⚠ しぼった結果が0人でも、見本データ（/ecs/data/cases.js）に戻らないこと。
     * 戻ると、架空の社員の数字が「しぼり込みの結果」として出てしまう。
     */
    public function test_empty_result_still_marked_as_real_data(): void
    {
        $admin = PersonFactory::new()->create(['permission' => 'admin', 'department' => 'イベプラ']);

        $data = $this->actingAsPerson($admin)->get('/projects-agg?dept=creative')->assertOk()->original->getData();

        $this->assertTrue($data['hasDbAgg'], '本物の集計だという印が立っていること');
        $this->assertSame([], $data['rows']);
    }
}
