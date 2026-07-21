<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * パスワード再設定（お忘れの方）の結合テスト。
 *
 * 流れ：メール入力 → 再設定リンク送信 → 新しいパスワード設定。
 * ここでは「登録済みなら送る／未登録は送らない（同じ応答）」「有効なトークンで
 * パスワードが変わる／期限切れ・不正トークンは拒否」を確認する。
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    /** ゲスト用の2ページ（メール入力／新パスワード設定）がログイン前でも開ける。 */
    public function test_guest_pages_load(): void
    {
        $this->get('/forgot-password')->assertOk();
        $this->get('/reset-password?token=abc&email=taro@example.com')->assertOk();
    }

    /** 登録済み・在籍中の本人には再設定リンクメールが送られ、トークン行が作られる。 */
    public function test_sends_reset_link_to_registered_user(): void
    {
        Mail::fake();
        $me = PersonFactory::new()->create(['email' => 'taro@example.com']);

        $this->post('/forgot-password', ['email' => 'taro@example.com'])
            ->assertSessionHas('status', 'password-reset-link-sent');

        Mail::assertSent(PasswordResetMail::class);
        $this->assertDatabaseHas('password_resets', ['email' => 'taro@example.com']);
    }

    /** 未登録のアドレスにはメールを送らない（が、応答は同じ＝存在を悟らせない）。 */
    public function test_does_not_send_for_unknown_email_but_same_response(): void
    {
        Mail::fake();

        $this->post('/forgot-password', ['email' => 'nobody@example.com'])
            ->assertSessionHas('status', 'password-reset-link-sent');

        Mail::assertNothingSent();
        $this->assertDatabaseMissing('password_resets', ['email' => 'nobody@example.com']);
    }

    /** 有効なトークンで新しいパスワードに変わり、使ったトークンは削除される。 */
    public function test_resets_password_with_valid_token(): void
    {
        $me = PersonFactory::new()->create(['email' => 'hanako@example.com', 'password' => 'oldpassword']);

        DB::table('password_resets')->insert([
            'email'      => 'hanako@example.com',
            'token'      => Hash::make('valid-token'),
            'created_at' => now(),
        ]);

        $this->post('/reset-password', [
            'token'                 => 'valid-token',
            'email'                 => 'hanako@example.com',
            'password'              => 'brandnew123',
            'password_confirmation' => 'brandnew123',
        ])->assertRedirect('/');

        $fresh = $me->fresh();
        $this->assertTrue(Hash::check('brandnew123', $fresh->password), '新しいパスワードで照合できること');
        $this->assertFalse(Hash::check('oldpassword', $fresh->password), '古いパスワードは通らないこと');
        $this->assertDatabaseMissing('password_resets', ['email' => 'hanako@example.com']);
    }

    /** 期限切れ（60分より前に発行）のトークンは拒否し、パスワードは変わらない。 */
    public function test_rejects_expired_token(): void
    {
        $me = PersonFactory::new()->create(['email' => 'ken@example.com', 'password' => 'oldpassword']);

        DB::table('password_resets')->insert([
            'email'      => 'ken@example.com',
            'token'      => Hash::make('valid-token'),
            'created_at' => Carbon::now()->subMinutes(61),
        ]);

        $this->post('/reset-password', [
            'token'                 => 'valid-token',
            'email'                 => 'ken@example.com',
            'password'              => 'brandnew123',
            'password_confirmation' => 'brandnew123',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('oldpassword', $me->fresh()->password), 'パスワードは変わっていないこと');
    }

    /** 合わないトークンは拒否し、パスワードは変わらない。 */
    public function test_rejects_wrong_token(): void
    {
        $me = PersonFactory::new()->create(['email' => 'mai@example.com', 'password' => 'oldpassword']);

        DB::table('password_resets')->insert([
            'email'      => 'mai@example.com',
            'token'      => Hash::make('valid-token'),
            'created_at' => now(),
        ]);

        $this->post('/reset-password', [
            'token'                 => 'WRONG-token',
            'email'                 => 'mai@example.com',
            'password'              => 'brandnew123',
            'password_confirmation' => 'brandnew123',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('oldpassword', $me->fresh()->password), 'パスワードは変わっていないこと');
    }
}
