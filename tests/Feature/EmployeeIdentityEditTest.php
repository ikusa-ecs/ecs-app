<?php

namespace Tests\Feature;

use App\Models\Person;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 社員名簿から、氏名・ふりがな・所属を直せるようにした（2026-08-24 baba要望）。
 *
 * きっかけ：名簿CSVの見本行（山田花子／やまだ はなこ）を消し忘れて取り込んでしまい、
 * 社長のふりがなが「やまだ はなこ」になった。それまで直せるのは本人だけ
 * （マイプロフィール）だったので、本人がログインするまで間違いが残る状態だった。
 *
 * ⚠ 他人の氏名・ふりがな・所属を書き換えるので Administrator だけ。
 *   サイズ（身長・靴・服）は今までどおり管理者以上ができる（そこを止めない）。
 */
class EmployeeIdentityEditTest extends TestCase
{
    use RefreshDatabase;

    private function target(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-100', 'name' => '社長', 'name_kana' => 'やまだ はなこ',
            'role' => 'employee', 'permission' => 'employee',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** Administrator はふりがなを直せる。 */
    public function test_admin_can_fix_kana(): void
    {
        $admin = PersonFactory::new()->create([
            'id' => 'E-001', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false,
        ]);
        $target = $this->target();

        $this->actingAsPerson($admin)
            ->post('/employees/'.$target->id.'/profile', [
                'name' => '社長',
                'name_kana' => 'いくさ たろう',
                'department' => '経営管理',
                'departments' => ['経営管理', 'イベプラ'],
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $fresh = $target->fresh();
        $this->assertSame('いくさ たろう', $fresh->name_kana);
        $this->assertSame('経営管理', $fresh->department);
        $this->assertSame(['経営管理', 'イベプラ'], $fresh->departments);
    }

    /** 氏名は空にできない。 */
    public function test_name_cannot_be_emptied(): void
    {
        $admin = PersonFactory::new()->create([
            'id' => 'E-001', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false,
        ]);
        $target = $this->target();

        // このアプリは /api/* 以外にJSONのエラーを返さない設定（bootstrap/app.php）なので、
        // 検証エラーはリダイレクト＋セッションのエラーで受け取る。
        $this->actingAsPerson($admin)
            ->post('/employees/'.$target->id.'/profile', ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertSame('社長', $target->fresh()->name);
    }

    /** 管理者（manager）は氏名・ふりがな・所属を直せない。 */
    public function test_manager_cannot_change_identity(): void
    {
        $manager = PersonFactory::new()->create([
            'id' => 'E-002', 'permission' => 'manager', 'office' => '東京', 'must_onboard' => false,
        ]);
        $target = $this->target();

        $this->actingAsPerson($manager)
            ->postJson('/employees/'.$target->id.'/profile', ['name_kana' => 'かえたい'])
            ->assertStatus(403);

        $this->assertSame('やまだ はなこ', $target->fresh()->name_kana);
    }

    /** 管理者（manager）は今までどおりサイズを直せる（そこは止めない）。 */
    public function test_manager_can_still_edit_sizes(): void
    {
        $manager = PersonFactory::new()->create([
            'id' => 'E-002', 'permission' => 'manager', 'office' => '東京', 'must_onboard' => false,
        ]);
        $target = $this->target();

        $this->actingAsPerson($manager)
            ->post('/employees/'.$target->id.'/profile', ['shoe_size' => '27.0'])
            ->assertOk();

        $this->assertSame('27.0', $target->fresh()->shoe_size);
    }

    /** Administrator の画面には編集欄が出る。管理者には出ない。 */
    public function test_editor_is_shown_only_to_admin(): void
    {
        $admin = PersonFactory::new()->create([
            'id' => 'E-001', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false,
        ]);
        $manager = PersonFactory::new()->create([
            'id' => 'E-002', 'permission' => 'manager', 'office' => '東京', 'must_onboard' => false,
        ]);

        $html = $this->actingAsPerson($admin)->get('/employees')->assertOk()->getContent();
        $this->assertStringContainsString('ECS_CAN_MANAGE_PEOPLE = true', $html);
        $this->assertStringContainsString('function identityEditorHtml', $html);

        $html2 = $this->actingAsPerson($manager)->get('/employees')->assertOk()->getContent();
        $this->assertStringContainsString('ECS_CAN_MANAGE_PEOPLE = false', $html2);
    }
}
