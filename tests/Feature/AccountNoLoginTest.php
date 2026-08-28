<?php

namespace Tests\Feature;

use App\Models\Person;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 「メールアドレスはあとで」＝ログインを作らず名簿にだけ登録する（2026-08-26 baba要望）。
 *
 * ⚠ それまでメールアドレスが必須で、**メアドをもらうまで名簿に入れられなかった**。
 *   運用は「メアドが来た人から順にアカウントを作る」なので、人だけ先に登録したい。
 *
 * 登録した人は：
 *  ・すぐアサインに使える（名簿に入るので出勤数・集計にも数えられる）
 *  ・ログインは持たない（メール・パスワードなし・初期設定にも通さない）
 *  ・メアドが来たら「スタッフ名簿 → 詳細 → メールを入れて案内メールを送る」で発行できる（既存の入口）
 */
class AccountNoLoginTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'name' => 'アサイン担当', 'permission' => 'manager',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** メールが空でも登録できる（「あとで」にチェックしたとき）。 */
    public function test_person_can_be_registered_without_email(): void
    {
        $this->actingAsPerson($this->manager())
            ->post('/account-new', [
                'role' => 'staff', 'name' => '新井 みなみ', 'permission' => 'staff',
                'office' => '東京', 'no_login' => '1',
            ])
            ->assertRedirect('/account-new');

        $p = Person::where('name', '新井みなみ')->first();
        $this->assertNotNull($p, '名簿に入ること');
        $this->assertNull($p->email, 'メールは持たない');
        $this->assertNull($p->password, 'パスワードも持たない（発行済みに見せない）');
        $this->assertFalse((bool) $p->must_onboard, '初期設定にも通さない');
        $this->assertTrue((bool) $p->active, '在籍として扱う（アサインに使える）');
    }

    /** 「あとで」にチェックしていなければ、今までどおりメールは必須。 */
    public function test_email_is_still_required_without_the_checkbox(): void
    {
        $this->actingAsPerson($this->manager())
            ->post('/account-new', [
                'role' => 'staff', 'name' => '新井 みなみ', 'permission' => 'staff', 'office' => '東京',
            ])
            ->assertSessionHasErrors('email');

        $this->assertNull(Person::where('name', '新井みなみ')->first());
    }

    /** あとでメールが来たら、名簿から案内メールを送って発行できる（既存の入口）。 */
    public function test_login_can_be_issued_later_from_the_roster(): void
    {
        $me = $this->manager();
        $this->actingAsPerson($me)->post('/account-new', [
            'role' => 'staff', 'name' => '新井 みなみ', 'permission' => 'staff',
            'office' => '東京', 'no_login' => '1',
        ])->assertRedirect('/account-new');

        $p = Person::where('name', '新井みなみ')->first();

        $this->actingAsPerson($me)
            ->post('/people/' . $p->id . '/invite', ['email' => 'minami@example.com'])
            ->assertOk();

        $this->assertSame('minami@example.com', $p->fresh()->email);
        $this->assertNotNull($p->fresh()->invited_at, '案内メールを送った記録が残ること');
    }
}
