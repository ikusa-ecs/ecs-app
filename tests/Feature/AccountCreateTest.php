<?php

namespace Tests\Feature;

use App\Models\Person;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * アカウント発行（/account-new）。
 *
 * 2026-08-21 の不具合：種別で「スタッフ」を選ぶと画面が権限欄をグレーアウトするが、
 * グレーアウトした欄はブラウザが送信しないため、権限が空でサーバーに届き
 * 「権限は必ず入力してください」で登録できなかった。
 * 画面側（hidden で一緒に送る）とサーバー側（届かなければスタッフとみなす）の両方で直した。
 */
class AccountCreateTest extends TestCase
{
    use RefreshDatabase;

    private function admin()
    {
        return PersonFactory::new()->create(['permission' => 'admin', 'office' => '東京']);
    }

    /** スタッフ発行：権限が届かなくても「スタッフ」として登録できる。 */
    public function test_staff_account_is_created_even_without_permission_field(): void
    {
        $this->actingAsPerson($this->admin())
            ->post('/account-new', [
                'role'   => 'staff',
                'name'   => '確認用スタッフ',
                'email'  => 'kakunin-staff@example.com',
                'office' => '東京',
                // permission はわざと送らない（グレーアウトされた欄の再現）
            ])
            ->assertSessionHasNoErrors();

        $person = Person::where('email', 'kakunin-staff@example.com')->first();

        $this->assertNotNull($person, 'スタッフのアカウントが作られる');
        $this->assertSame('staff', $person->role);
        $this->assertSame('staff', $person->permission, 'スタッフの権限は必ずスタッフ');
    }

    /** 社員発行で権限が空のときは、これまでどおり止める（勝手に決めない）。 */
    public function test_employee_account_still_requires_permission(): void
    {
        $this->actingAsPerson($this->admin())
            ->post('/account-new', [
                'role'  => 'employee',
                'name'  => '確認用社員',
                'email' => 'kakunin-emp@example.com',
            ])
            ->assertSessionHasErrors(['permission']);

        $this->assertNull(Person::where('email', 'kakunin-emp@example.com')->first());
    }

    /** エラー文が日本語で出る（「validation.required」のような合言葉が裸で出ない）。 */
    public function test_validation_message_is_japanese(): void
    {
        $message = trans('validation.required', ['attribute' => trans('validation.attributes.permission')]);

        $this->assertStringNotContainsString('validation.', $message);
        $this->assertSame('権限は必ず入力してください。', $message);
    }
}
