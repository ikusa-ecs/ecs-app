<?php

namespace Tests\Feature;

use App\Mail\BroadcastNoticeMail;
use App\Models\Person;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * お知らせメールの一斉送信（2026-10-22 baba要望・正本＝App\Support\BroadcastMail）。
 * 送り先＝ログインできる人だけ／1人1通／Administratorだけ／本文は保存しない。
 */
class BroadcastMailTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '管理太郎', 'email' => 'admin@ikusa.co.jp',
            'permission' => 'admin', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    private function staff(string $id, array $attrs = []): Person
    {
        return PersonFactory::new()->create(array_merge([
            'id' => $id, 'name' => 'スタッフ'.$id, 'email' => strtolower($id).'@example.com',
            'role' => 'staff', 'permission' => 'staff', 'office' => '東京',
            'must_onboard' => false, 'active' => true,
        ], $attrs));
    }

    public function test_preview_counts_only_people_who_can_log_in(): void
    {
        $admin = $this->admin();
        $this->staff('S-001');
        $this->staff('S-002', ['active' => false]);       // 退職
        $this->staff('S-003', ['email' => '']);            // メールなし
        $this->staff('S-004', ['is_spot' => true]);        // 臨時
        $noPw = $this->staff('S-005');                     // パスワード未設定
        Person::whereKey($noPw->id)->update(['password' => null]);

        $res = $this->actingAsPerson($admin)
            ->postJson('/admin-console/broadcast/preview', ['target' => 'staff'])
            ->assertOk();
        $this->assertSame(['S-001'], $res->json('ids'));

        $all = $this->actingAsPerson($admin)
            ->postJson('/admin-console/broadcast/preview', ['target' => 'all'])->json('ids');
        $this->assertEqualsCanonicalizing(['E-001', 'S-001'], $all);
    }

    public function test_sends_one_mail_per_person_and_refuses_outsiders(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $this->staff('S-001');
        $this->staff('S-002', ['active' => false]);

        $res = $this->actingAsPerson($admin)->postJson('/admin-console/broadcast/send', [
            'target' => 'staff', 'ids' => ['S-001', 'S-002'],
            'subject' => '件名テスト', 'body' => "ID：company\nパスワード：a&b!",
        ])->assertOk();

        $this->assertSame(1, $res->json('sent'));
        $this->assertCount(1, $res->json('skipped'), '条件外の人は送らずに理由を返す');
        Mail::assertSent(BroadcastNoticeMail::class, 1);
        Mail::assertSent(BroadcastNoticeMail::class, function (BroadcastNoticeMail $m) {
            $text = $m->render();

            return $m->hasTo('s-001@example.com') && count($m->to) === 1
                && str_contains($text, 'スタッフS-001 様') && str_contains($text, 'a&b!');
        });
    }

    public function test_only_administrator_can_use_it(): void
    {
        Mail::fake();
        $manager = PersonFactory::new()->create([
            'id' => 'E-002', 'permission' => 'manager', 'office' => '東京', 'must_onboard' => false,
        ]);

        $this->actingAsPerson($manager)
            ->postJson('/admin-console/broadcast/preview', ['target' => 'all'])->assertForbidden();
        $this->actingAsPerson($manager)->postJson('/admin-console/broadcast/send', [
            'target' => 'all', 'ids' => ['E-002'], 'subject' => 'x', 'body' => 'y',
        ])->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_admin_console_shows_the_form(): void
    {
        $this->actingAsPerson($this->admin())->get('/admin-console')
            ->assertOk()->assertSee('お知らせメールの一斉送信')->assertSee('bcSend', false);
    }
}
