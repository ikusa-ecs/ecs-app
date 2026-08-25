<?php

namespace Tests\Feature;

use App\Mail\LoginInviteMail;
use App\Models\Person;
use App\Support\LoginInvite;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ログイン案内（招待）メール。2026-08-25 baba要望。
 *
 * 【方針】仮パスワードをメールで送るのではなく、
 *   「自分でパスワードを決めるリンク」だけを送る（baba選択）。
 *   メールは受信箱に残り転送もされうるので、パスワードそのものは載せない。
 *
 * 【運用】スタッフは先に名簿だけ作り、メールアドレスをもらった人から順に送る。
 *   そのため、名簿の画面でメールを登録してその場で送れる。
 *
 * ⚠ 社員は既に仮パスワードを配布済みなので、そちらの流れは変えていない
 *   （仮パスワードの発行・表示はそのまま。案内メールは「送りたいときだけ」使う）。
 */
class LoginInviteTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'permission' => 'manager', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    private function staff(array $attrs = []): Person
    {
        return PersonFactory::new()->create(array_merge([
            'id' => 'S-001', 'name' => 'スタッフ花子', 'role' => 'staff', 'permission' => 'staff',
            'office' => '東京', 'must_onboard' => true, 'active' => true,
        ], $attrs));
    }

    /** メールが送られ、「送った日時」が記録される。 */
    public function test_sends_invite_and_records_time(): void
    {
        Mail::fake();
        $target = $this->staff(['email' => 'hanako@ikusa.co.jp']);

        $this->actingAsPerson($this->manager())
            ->postJson('/people/'.$target->id.'/invite')
            ->assertOk()
            ->assertJson(['ok' => true]);

        Mail::assertSent(LoginInviteMail::class, fn ($m) => $m->hasTo('hanako@ikusa.co.jp'));
        $this->assertNotNull($target->fresh()->invited_at, '送った日時が残ること');
    }

    /** メールが未登録の人は、その場で登録してから送れる（もらった順に送る運用のため）。 */
    public function test_can_register_email_and_send(): void
    {
        Mail::fake();
        $target = $this->staff(['email' => null]);

        $this->actingAsPerson($this->manager())
            ->postJson('/people/'.$target->id.'/invite', ['email' => 'new@ikusa.co.jp'])
            ->assertOk();

        $this->assertSame('new@ikusa.co.jp', $target->fresh()->email);
        Mail::assertSent(LoginInviteMail::class);
    }

    /** ⚠ メール本文にパスワードを書かない（リンクだけ）。 */
    public function test_mail_body_contains_link_but_no_password(): void
    {
        $target = $this->staff(['email' => 'hanako@ikusa.co.jp']);
        $mail = new LoginInviteMail('https://example.test/reset-password?token=abc', $target->name, 7);

        $body = $mail->render();

        $this->assertStringContainsString('reset-password', $body, 'パスワードを決めるリンクが入っていること');
        $this->assertStringContainsString('有効期限は7日間', $body);

        // ⚠ ログイン画面のURLはトップページ。/login はブラウザで開くとエラーになる
        //   （POST専用の裏口）ので、絶対に案内に載せない。
        $this->assertStringContainsString(route('login'), $body, 'ログインURLが入っていること');
        $this->assertStringNotContainsString('/login', $body, '/login を案内に載せないこと');

        // パスワードそのものを本文に書いていないこと（リンクだけを送る方針）。
        $this->assertStringNotContainsString('仮パスワード', $body);

        // プロフィール登録のお願いが入っていること（baba要望 2026-08-25）。
        // ⚠ スタッフが実際に開くのは画面上部の「設定」タブ。呼び名がずれると迷わせるので、
        //   スタッフ画面のタブ名と同じ言い方が本文にあることを固定する。
        $this->assertStringContainsString('設定', $body);
        $this->assertStringContainsString('マイプロフィール', $body);
        $this->assertStringContainsString('一言アピール', $body);
    }

    /** メールが無い人・退職した人には送らず、理由を返す（黙って失敗しない）。 */
    public function test_refuses_when_cannot_send(): void
    {
        Mail::fake();

        $noMail = $this->staff(['id' => 'S-002', 'email' => null]);
        $r1 = LoginInvite::send($noMail);
        $this->assertFalse($r1['ok']);
        $this->assertStringContainsString('メールアドレスが未登録', $r1['message']);

        $retired = $this->staff(['id' => 'S-003', 'email' => 'x@ikusa.co.jp', 'active' => false]);
        $r2 = LoginInvite::send($retired);
        $this->assertFalse($r2['ok']);
        $this->assertStringContainsString('退職', $r2['message']);

        Mail::assertNothingSent();
    }

    /** リンクからパスワードを決めると「本人が設定した」印が付く。 */
    public function test_setting_password_via_link_marks_it(): void
    {
        Mail::fake();
        $target = $this->staff(['email' => 'hanako@ikusa.co.jp']);
        $this->actingAsPerson($this->manager())->postJson('/people/'.$target->id.'/invite')->assertOk();

        // メールに載せたトークンを取り出して、実際に設定してみる。
        $token = null;
        Mail::assertSent(LoginInviteMail::class, function ($m) use (&$token) {
            preg_match('/token=([A-Za-z0-9]+)/', $m->url, $mt);
            $token = $mt[1] ?? null;

            return true;
        });
        $this->assertNotNull($token);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'hanako@ikusa.co.jp',
            'password' => 'mypassword123',
            'password_confirmation' => 'mypassword123',
        ])->assertRedirect('/');

        $this->assertNotNull($target->fresh()->password_set_at, '本人が決めた印が付くこと');
    }

    /** 本人が決めたあとの初回設定では、パスワードを二度聞かない。 */
    public function test_onboarding_skips_password_when_already_set(): void
    {
        $target = $this->staff(['email' => 'hanako@ikusa.co.jp', 'password_set_at' => now()]);

        $html = $this->actingAsPerson($target)->get('/onboarding')->assertOk()->getContent();
        $this->assertStringNotContainsString('name="password"', $html);

        // パスワードを送らなくても完了できる。
        $this->actingAsPerson($target)->post('/onboarding', [
            'name' => $target->name,
            'name_kana' => 'すたっふ はなこ',
        ])->assertRedirect();

        $this->assertFalse((bool) $target->fresh()->must_onboard);
    }

    /** まだ決めていない人には、今までどおりパスワード欄が出る。 */
    public function test_onboarding_still_asks_password_when_not_set(): void
    {
        $target = $this->staff(['email' => 'x@ikusa.co.jp', 'password_set_at' => null]);

        $html = $this->actingAsPerson($target)->get('/onboarding')->assertOk()->getContent();
        $this->assertStringContainsString('name="password"', $html);
    }

    /** 一般社員は送れない（管理者以上のみ）。 */
    public function test_employee_cannot_send(): void
    {
        Mail::fake();
        $employee = PersonFactory::new()->create([
            'id' => 'E-050', 'permission' => 'employee', 'office' => '東京', 'must_onboard' => false,
        ]);
        $target = $this->staff(['email' => 'x@ikusa.co.jp']);

        $this->actingAsPerson($employee)
            ->postJson('/people/'.$target->id.'/invite')
            ->assertStatus(403);

        Mail::assertNothingSent();
    }

    /** 名簿の画面に「ログインできる／まだ」が出る。 */
    public function test_roster_shows_login_status(): void
    {
        // パスワードが空＝CSVで名簿だけ作った人（まだログインできない）。
        $this->staff(['id' => 'S-010', 'email' => null, 'password' => null]);
        $this->staff(['id' => 'S-011', 'email' => 'a@ikusa.co.jp', 'invited_at' => now()]);        // 案内送信済み
        $this->staff(['id' => 'S-012', 'email' => 'b@ikusa.co.jp', 'password_set_at' => now()]);   // ログインできる

        $html = $this->actingAsPerson($this->manager())->get('/staff')->assertOk()->getContent();

        $this->assertStringContainsString('"login":"none"', $html);
        $this->assertStringContainsString('"login":"invited"', $html);
        $this->assertStringContainsString('"login":"ready"', $html);
        // 管理者以上には送信ボタンの受け口が出る。
        $this->assertStringContainsString('ECS_CAN_INVITE = true', $html);
    }
}
