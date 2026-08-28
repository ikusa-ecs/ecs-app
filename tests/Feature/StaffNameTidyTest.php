<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Support\StaffName;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 氏名の書き方をそろえる（2026-08-28 baba要望）。
 *
 * 運用の決まり＝スタッフは苗字と名前をつめる（山田太郎）／社員は半角スペースで空ける（山田 太郎）。
 * スタッフが自分でフォームに書いた名前をそのまま登録したため、空白入りが混ざっていた。
 */
class StaffNameTidyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** スタッフの氏名は、保存すると空白が取り除かれる（どの入口から入れても）。 */
    public function test_staff_name_spaces_are_removed_on_save(): void
    {
        $p = PersonFactory::new()->create([
            'id' => 'S-001', 'name' => '山田 太郎', 'role' => 'staff', 'permission' => 'staff',
        ]);

        $this->assertSame('山田太郎', $p->fresh()->name);
    }

    /** 全角の空白も取り除く。 */
    public function test_full_width_space_is_removed(): void
    {
        $p = PersonFactory::new()->create([
            'id' => 'S-002', 'name' => '鈴木　彩', 'role' => 'staff', 'permission' => 'staff',
        ]);

        $this->assertSame('鈴木彩', $p->fresh()->name);
    }

    /** 社員は空けたまま（運用の決まりが逆）。 */
    public function test_employee_name_keeps_the_space(): void
    {
        $p = PersonFactory::new()->create([
            'id' => 'E-100', 'name' => '佐藤 花子', 'role' => 'employee', 'permission' => 'employee',
        ]);

        $this->assertSame('佐藤 花子', $p->fresh()->name);
    }

    /** ローマ字の名前は詰めない（JohnSmith になって読めなくなるため）。 */
    public function test_roman_name_is_left_alone(): void
    {
        $p = PersonFactory::new()->create([
            'id' => 'S-003', 'name' => 'John Smith', 'role' => 'staff', 'permission' => 'staff',
        ]);

        $this->assertSame('John Smith', $p->fresh()->name);
    }

    /** 名簿から氏名を直せる（Administratorだけ）。 */
    public function test_admin_can_rename_a_staff(): void
    {
        PersonFactory::new()->create([
            'id' => 'S-004', 'name' => '旧姓子', 'role' => 'staff', 'permission' => 'staff',
        ]);

        $this->actingAsPerson($this->admin())
            ->postJson('/staff/S-004/edit', ['name' => '新姓 子'])   // 空白入りで送っても
            ->assertOk();

        $this->assertSame('新姓子', Person::findOrFail('S-004')->name, '詰めて保存されること');
    }

    /** 管理者（Administrator以外）は氏名を直せない。 */
    public function test_manager_cannot_rename(): void
    {
        PersonFactory::new()->create([
            'id' => 'S-005', 'name' => 'そのまま子', 'role' => 'staff', 'permission' => 'staff',
        ]);
        $manager = PersonFactory::new()->create([
            'id' => 'E-002', 'permission' => 'manager', 'office' => '東京', 'must_onboard' => false,
        ]);

        $this->actingAsPerson($manager)
            ->postJson('/staff/S-005/edit', ['name' => '書き換え子'])
            ->assertStatus(403);

        $this->assertSame('そのまま子', Person::findOrFail('S-005')->name);
    }

    /** 空の氏名にはできない（名前が消えると誰か分からなくなる）。 */
    public function test_name_cannot_be_emptied(): void
    {
        PersonFactory::new()->create([
            'id' => 'S-006', 'name' => '消えない子', 'role' => 'staff', 'permission' => 'staff',
        ]);

        $this->actingAsPerson($this->admin())
            ->postJson('/staff/S-006/edit', ['name' => '   '])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertSame('消えない子', Person::findOrFail('S-006')->name);
    }

    /** 氏名を送らないときは、これまでどおり他の項目だけ保存する（勝手に消さない）。 */
    public function test_saving_without_name_keeps_it(): void
    {
        PersonFactory::new()->create([
            'id' => 'S-007', 'name' => 'そのまま子', 'role' => 'staff', 'permission' => 'staff',
        ]);

        $this->actingAsPerson($this->admin())
            ->postJson('/staff/S-007/edit', ['impression' => 'メモだけ更新'])
            ->assertOk();

        $this->assertSame('そのまま子', Person::findOrFail('S-007')->name);
    }

    /** 一度きりの片づけコマンド：--apply を付けないと書き換えない。 */
    public function test_tidy_command_is_preview_by_default(): void
    {
        // ⚠ saving で自動的に詰まるので、片づけ前の状態はDBへ直に入れて作る。
        \Illuminate\Support\Facades\DB::table('people')->insert([
            'id' => 'S-008', 'name' => '空白 入子', 'role' => 'staff', 'permission' => 'staff', 'active' => 1,
        ]);

        $this->artisan('ecs:tidy-staff-names')->assertExitCode(0);
        $this->assertSame('空白 入子', Person::findOrFail('S-008')->name, 'まだ書き換えないこと');

        $this->artisan('ecs:tidy-staff-names --apply')->assertExitCode(0);
        $this->assertSame('空白入子', Person::findOrFail('S-008')->name);
    }

    /** 部品そのものの決まり。 */
    public function test_rules(): void
    {
        $this->assertSame('山田太郎', StaffName::tidy('山田 太郎', 'staff'));
        $this->assertSame('山田 太郎', StaffName::tidy('山田 太郎', 'employee'));
        $this->assertSame('John Smith', StaffName::tidy('John Smith', 'staff'));
        $this->assertNull(StaffName::tidy(null, 'staff'));
    }
}
