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

    // ── 同姓同名の二重登録を止める（2026-08-28 baba要望）──────────────
    // 臨時で入ってもらった方のメアドが分かって、ふつうに登録し直した結果
    // 同じ人が名簿に2人できた。アサインの記録とログインが別々になってしまう。

    /** すでに名簿にいる名前は、一度止める。 */
    public function test_duplicate_name_is_blocked(): void
    {
        PersonFactory::new()->create([
            'id' => 'S-200', 'name' => '重複 太郎', 'role' => 'staff', 'permission' => 'staff',
            'office' => '東京', 'is_spot' => true,
        ]);

        $this->actingAsPerson($this->admin())
            ->post('/account-new', [
                'role' => 'staff', 'name' => '重複太郎',   // 空白の有無は無視して見る
                'email' => 'dup@example.com', 'office' => '東京', 'permission' => 'staff',
            ])
            ->assertSessionHasErrors(['duplicate_name']);

        $this->assertNull(Person::where('email', 'dup@example.com')->first(), '作られていないこと');
        $this->assertSame(2, Person::count());
    }

    /** 臨時の人が相手のときは、解除する方法を案内する。 */
    public function test_duplicate_message_points_to_release_for_spot_staff(): void
    {
        PersonFactory::new()->create([
            'id' => 'S-201', 'name' => '助っ人 花子', 'role' => 'staff', 'permission' => 'staff',
            'office' => '東京', 'is_spot' => true,
        ]);

        // 戻された画面に、相手が誰か・どうすればよいかが出ていること
        // （followingRedirects＝人が見るのと同じく、戻された発行画面まで進んで中身を見る）。
        $html = $this->actingAsPerson($this->admin())
            ->from('/account-new')
            ->followingRedirects()
            ->post('/account-new', [
                'role' => 'staff', 'name' => '助っ人 花子',
                'email' => 'spot-dup@example.com', 'office' => '東京', 'permission' => 'staff',
            ])
            ->assertOk()->getContent();
        $this->assertStringContainsString('S-201', $html);
        $this->assertStringContainsString('臨時を解除', $html);
        $this->assertStringContainsString('allow_duplicate_name', $html, '別人として登録するチェックが出ること');
    }

    /** 同姓同名の別人は、チェックを入れれば登録できる（止めるのは一度だけ）。 */
    public function test_duplicate_name_can_be_forced(): void
    {
        PersonFactory::new()->create([
            'id' => 'S-202', 'name' => '同姓 同名', 'role' => 'staff', 'permission' => 'staff',
        ]);

        $this->actingAsPerson($this->admin())
            ->post('/account-new', [
                'role' => 'staff', 'name' => '同姓 同名',
                'email' => 'same-name@example.com', 'office' => '東京', 'permission' => 'staff',
                'allow_duplicate_name' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(Person::where('email', 'same-name@example.com')->first());
    }

    /** 名簿にいない名前は、これまでどおりそのまま登録できる。 */
    public function test_new_name_is_not_blocked(): void
    {
        $this->actingAsPerson($this->admin())
            ->post('/account-new', [
                'role' => 'staff', 'name' => 'はじめて 太郎',
                'email' => 'new-name@example.com', 'office' => '東京', 'permission' => 'staff',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(Person::where('email', 'new-name@example.com')->first());
    }
}
