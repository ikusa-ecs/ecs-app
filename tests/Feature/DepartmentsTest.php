<?php

namespace Tests\Feature;

use App\Support\Departments;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 所属（部署）の扱い（2026-08-24 baba）。
 *
 * 何が起きていたか：所属は実際には10種類あるのに、画面・集計・色分けが
 * 「イベプラ／セールス／クリエイティブ」の3つだけを想定していた。
 * しかも対応表の既定値が 'plan' だったため、経営管理やARENAの人が
 * 名簿で「イベプラ」と表示されていた（本人の所属を誤って伝える表示）。
 *
 * これから：本当の所属名はそのまま保存し、色分け・絞り込み・集計は
 * 4つ（イベプラ／セールス／クリエイティブ／その他）にまとめる。
 */
class DepartmentsTest extends TestCase
{
    use RefreshDatabase;

    /** イベプラ／セールス／クリエイティブはそのまま、それ以外は「その他」にまとまる。 */
    public function test_groups_others_together(): void
    {
        $this->assertSame('イベプラ', Departments::group('イベプラ'));
        $this->assertSame('セールス', Departments::group('セールス'));
        $this->assertSame('クリエイティブ', Departments::group('クリエイティブ'));

        foreach (['経営管理', 'マーケティング', 'イベプロ', 'プロダクション', 'ビジパ', 'ARENA', 'あそ研'] as $d) {
            $this->assertSame(Departments::OTHER, Departments::group($d), $d.' は「その他」にまとまること');
        }

        // 空は空（未設定として別扱いにする）。
        $this->assertSame('', Departments::group(null));
        $this->assertSame('', Departments::group(''));
    }

    /** 色分けのコード。3つ以外は other、空は none。 */
    public function test_code_falls_back_to_other_not_plan(): void
    {
        $this->assertSame('plan', Departments::code('イベプラ'));
        $this->assertSame('other', Departments::code('経営管理'));
        $this->assertSame('other', Departments::code('ARENA'));
        $this->assertSame('none', Departments::code(null));
        // ⚠ ここが 'plan' に戻ると、また「イベプラ」と誤表示される。
        $this->assertNotSame('plan', Departments::code('経営管理'));
    }

    /** 名簿に出す文字は本当の所属名。空なら「未設定」。 */
    public function test_label_keeps_real_department_name(): void
    {
        $this->assertSame('経営管理', Departments::label('経営管理'));
        $this->assertSame('未設定', Departments::label(null));
    }

    /** 社員名簿で、3つ以外の所属の人が「イベプラ」と表示されない。 */
    public function test_employee_list_does_not_mislabel_other_departments(): void
    {
        $me = PersonFactory::new()->create([
            'id' => 'E-701', 'name' => '経営の人', 'department' => '経営管理',
            'role' => 'employee', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false,
        ]);

        $html = $this->actingAsPerson($me)->get('/employees')->assertOk()->getContent();

        // 画面へは @json で渡るので、値の形で確かめる（日本語は \uXXXX になる）。
        $this->assertStringContainsString('"dept":"other"', $html);
        $this->assertStringNotContainsString('"dept":"plan"', $html);
        // 表示名は本当の所属名（経営管理）が入っていること。
        $this->assertStringContainsString(
            '"deptName":'.json_encode('経営管理', JSON_UNESCAPED_SLASHES),
            $html
        );
    }

    /** D決め画面でも、3つ以外は other 扱い（名前の色）。 */
    public function test_director_screen_uses_other_code(): void
    {
        $me = PersonFactory::new()->create([
            'id' => 'E-702', 'name' => 'ARENAの人', 'department' => 'ARENA',
            'role' => 'employee', 'permission' => 'manager', 'office' => '東京', 'must_onboard' => false,
        ]);

        $html = $this->actingAsPerson($me)->get('/assign-director')->assertOk()->getContent();

        $this->assertStringContainsString('"deptCode":"other"', $html);
    }

    /** 集計の部署の単位は4つ（その他を含む）。 */
    public function test_stats_groups_include_other(): void
    {
        $this->assertSame(['イベプラ', 'セールス', 'クリエイティブ', 'その他'], Departments::GROUPS);
    }
}
