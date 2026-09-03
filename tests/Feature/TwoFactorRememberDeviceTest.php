<?php

namespace Tests\Feature;

use App\Mail\LoginCodeMail;
use App\Models\Person;
use App\Support\TwoFactorDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * 2段階認証「この端末では次回からコードを省略する（30日間）」（2026-09-03）。
 *
 * スタッフから「開くたびにメールの6桁コードを入れさせられて面倒」という声への対応。
 * ここで確かめるのは、依頼書にあった4点そのもの：
 *   1. チェックを入れずにコードを入れた   → 次にログインし直すと今まで通りコードを聞かれる
 *   2. チェックを入れてコードを入れた     → ログインし直してもコードを聞かれない
 *   3. パスワードを変えた                 → 記憶が無効になり、またコードを聞かれる
 *   4. 別の人でログインした               → 前の人のクッキーでコードが飛ばされない
 */
class TwoFactorRememberDeviceTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $id = 'E-901', string $email = 'dev1@example.com'): Person
    {
        return Person::create([
            'id' => $id,
            'role' => 'employee',
            'permission' => 'admin',
            'name' => '端末記憶テスト',
            'email' => $email,
            'password' => 'secret-pass',
            'active' => true,
            'must_onboard' => false,
        ]);
    }

    /**
     * ログイン → コード入力ページ → 送られたコードで通過、までを一気に通す。
     * $rememberDevice が true のときだけ「この端末を覚える」にチェックを入れる。
     *
     * @return string|null 端末をおぼえたクッキーの中身（覚えなかったときは null）
     */
    private function loginAndPassCode(string $email, bool $rememberDevice): ?string
    {
        Mail::fake();

        $this->post('/login', ['email' => $email, 'password' => 'secret-pass']);
        $this->get('/otp')->assertOk();

        $code = null;
        Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code, $email) {
            $code = $mail->code;

            return $mail->hasTo($email);
        });

        $payload = ['code' => $code];
        if ($rememberDevice) {
            $payload['remember_device'] = '1';
        }

        $response = $this->post('/otp', $payload);

        $cookie = $response->getCookie(TwoFactorDevice::COOKIE, false);

        return $cookie?->getValue();
    }

    /** 1. チェックなし＝おぼえない。ログインし直すと、今まで通りコードを聞かれる。 */
    public function test_without_the_checkbox_the_device_is_not_remembered(): void
    {
        $this->makeUser();

        $cookie = $this->loginAndPassCode('dev1@example.com', rememberDevice: false);

        // クッキーは配られない。
        $this->assertNull($cookie);

        // ログインし直す（＝セッションが新しくなる。2時間で切れたのと同じ状態）。
        $this->post('/logout');
        $this->post('/login', ['email' => 'dev1@example.com', 'password' => 'secret-pass']);

        // これまで通りコード入力へ回される。
        $this->get('/dashboard')->assertRedirect('/otp');
    }

    /** 2. チェックあり＝おぼえる。ログインし直してもコードを聞かれない。 */
    public function test_with_the_checkbox_the_code_is_skipped_next_time(): void
    {
        $this->makeUser();

        $cookie = $this->loginAndPassCode('dev1@example.com', rememberDevice: true);
        $this->assertNotNull($cookie);

        // ⚠ 生のパスワードも「パスワードのハッシュそのもの」もクッキーに入っていないこと。
        $this->assertStringNotContainsString('secret-pass', $cookie);
        $this->assertStringNotContainsString(
            (string) Person::find('E-901')->password,
            $cookie
        );

        $this->post('/logout');
        $this->post('/login', ['email' => 'dev1@example.com', 'password' => 'secret-pass']);

        // おぼえた端末なので、コード入力をとばして業務画面がそのまま出る。
        $this->withUnencryptedCookie(TwoFactorDevice::COOKIE, $cookie)
            ->get('/dashboard')
            ->assertOk();
    }

    /** 3. パスワードを変えると、おぼえた端末は自動で無効になる。 */
    public function test_changing_the_password_invalidates_remembered_devices(): void
    {
        $user = $this->makeUser();

        $cookie = $this->loginAndPassCode('dev1@example.com', rememberDevice: true);
        $this->assertNotNull($cookie);

        // パスワードを変更（保存時に自動で暗号化される＝ハッシュが変わる）。
        $user->update(['password' => 'brand-new-pass']);

        $this->post('/logout');
        $this->post('/login', ['email' => 'dev1@example.com', 'password' => 'brand-new-pass']);

        // 古いクッキーは効かない＝またコードを聞かれる。
        $this->withUnencryptedCookie(TwoFactorDevice::COOKIE, $cookie)
            ->get('/dashboard')
            ->assertRedirect('/otp');
    }

    /** 4. 別の人のクッキーが残っていても、その人のぶんはとばされない。 */
    public function test_another_persons_cookie_does_not_skip_the_code(): void
    {
        $this->makeUser('E-901', 'dev1@example.com');
        $this->makeUser('E-902', 'dev2@example.com');

        // 1人目が、この端末をおぼえさせる。
        $cookie = $this->loginAndPassCode('dev1@example.com', rememberDevice: true);
        $this->assertNotNull($cookie);

        // 同じ端末で2人目がログインする。
        $this->post('/logout');
        $this->post('/login', ['email' => 'dev2@example.com', 'password' => 'secret-pass']);

        // 1人目のクッキーは2人目には効かない＝ちゃんとコードを聞かれる。
        $this->withUnencryptedCookie(TwoFactorDevice::COOKIE, $cookie)
            ->get('/dashboard')
            ->assertRedirect('/otp');
    }

    /** 壊れたクッキー・空のクッキーでは通れない。 */
    public function test_a_broken_cookie_does_not_skip_the_code(): void
    {
        $this->makeUser();

        $this->post('/login', ['email' => 'dev1@example.com', 'password' => 'secret-pass']);

        $this->withUnencryptedCookie(TwoFactorDevice::COOKIE, 'E-901.deadbeef')
            ->get('/dashboard')
            ->assertRedirect('/otp');
    }

    /** 「この端末の記憶を解除する」を押すと、クッキーが消される。 */
    public function test_forget_device_button_clears_the_cookie(): void
    {
        $this->makeUser();

        $cookie = $this->loginAndPassCode('dev1@example.com', rememberDevice: true);
        $this->assertNotNull($cookie);

        $response = $this->withUnencryptedCookie(TwoFactorDevice::COOKIE, $cookie)
            ->from('/mypage')
            ->post('/device/forget');

        $response->assertRedirect('/mypage');

        // ブラウザに「このクッキーはもう期限切れ＝消して」と伝えている。
        // ⚠ 中身が空かどうかでは見ない。クッキーは Laravel が暗号化して渡すので、
        //   空でも暗号化された文字列が入る（見た目は空にならない）。
        $response->assertCookieExpired(TwoFactorDevice::COOKIE);
    }

    /** 解除ボタンは、マイページとスタッフ画面の両方に出ている。 */
    public function test_the_forget_button_is_on_both_screens(): void
    {
        $this->makeUser();
        $cookie = $this->loginAndPassCode('dev1@example.com', rememberDevice: true);

        $this->withUnencryptedCookie(TwoFactorDevice::COOKIE, $cookie)
            ->get('/mypage')
            ->assertOk()
            ->assertSee('この端末の記憶を解除する');

        // スタッフ画面はスタッフ本人の画面なので、スタッフで確かめる。
        Person::create([
            'id' => 'S-901',
            'role' => 'staff',
            'permission' => 'staff',
            'name' => '端末記憶スタッフ',
            'email' => 'devstaff@example.com',
            'password' => 'secret-pass',
            'active' => true,
            'must_onboard' => false,
        ]);

        $this->post('/logout');
        $staffCookie = $this->loginAndPassCode('devstaff@example.com', rememberDevice: true);

        $this->withUnencryptedCookie(TwoFactorDevice::COOKIE, $staffCookie)
            ->get('/staff-portal')
            ->assertOk()
            ->assertSee('この端末の記憶を解除する（2段階認証）');
    }

    /** コード入力ページに、チェックボックスが「初期はOFF」で出ている。 */
    public function test_the_checkbox_is_shown_and_defaults_to_off(): void
    {
        Mail::fake();
        $this->makeUser();

        $this->post('/login', ['email' => 'dev1@example.com', 'password' => 'secret-pass']);

        $html = $this->get('/otp')->assertOk()->getContent();

        $this->assertStringContainsString('name="remember_device"', $html);
        $this->assertStringContainsString('この端末では次回からコードを省略する（30日間）', $html);

        // ⚠ 初期チェックなし。共用パソコンでうっかりおぼえさせないため。
        $this->assertDoesNotMatchRegularExpression(
            '~name="remember_device"[^>]*\schecked~',
            $html
        );
    }
}
