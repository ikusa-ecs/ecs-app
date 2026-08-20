<?php

namespace Tests\Feature;

use App\Mail\StaffNotifyMail;
use App\Models\Assignment;
use App\Models\StaffNotification;
use App\Support\StaffNotifyService;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * スタッフへのお知らせ送信（/assign-notify）のテスト。
 *
 * いちばん大事なのは「勝手に送らない」「見本データ（@example.com）へ送らない」
 * 「同じ知らせを二度送らない」の3点。ここが崩れると誤送信の事故になる。
 */
class StaffNotifyTest extends TestCase
{
    use RefreshDatabase;

    private function soon(int $plus = 3): string
    {
        return Carbon::today()->addDays($plus)->format('Y-m-d');
    }

    /** 確定＋公開の案件だけが「アサイン確定のお知らせ」の候補に出る。 */
    public function test_collects_only_confirmed_and_published(): void
    {
        $me = PersonFactory::new()->staff()->create(['email' => 'staff@ikusa.co.jp']);
        $ok = ProjectFactory::new()->published()->create(['start_date' => $this->soon()]);
        $tentative = ProjectFactory::new()->published()->create(['start_date' => $this->soon(4)]);
        $unpublished = ProjectFactory::new()->create(['start_date' => $this->soon(5)]);
        $past = ProjectFactory::new()->published()->create(['start_date' => $this->soon(-3)]);

        foreach ([[$ok, '確定'], [$tentative, '仮'], [$unpublished, '確定'], [$past, '確定']] as [$p, $st]) {
            Assignment::create([
                'project_id' => $p->id, 'staff_id' => $me->id,
                'date' => $p->start_date, 'role' => 'OP', 'status' => $st,
            ]);
        }

        $ids = collect(app(StaffNotifyService::class)->collect(StaffNotifyService::KIND_CONFIRMED))
            ->pluck('project_id')->all();

        $this->assertSame([$ok->id], $ids);
    }

    /** 「件数を数えるだけ」ではメールを送らない。 */
    public function test_dry_run_sends_nothing(): void
    {
        Mail::fake();
        $emp = PersonFactory::new()->create(['permission' => 'manager', 'email' => 'emp@ikusa.co.jp']);
        $staff = PersonFactory::new()->staff()->create(['email' => 'staff@ikusa.co.jp']);
        $p = ProjectFactory::new()->published()->create(['start_date' => $this->soon()]);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $staff->id,
            'date' => $this->soon(), 'role' => 'OP', 'status' => '確定',
        ]);

        $this->actingAsPerson($emp)
            ->post('/assign-notify/send', ['kind' => StaffNotifyService::KIND_CONFIRMED, 'mode' => 'dry'])
            ->assertRedirect();

        Mail::assertNothingSent();
        $this->assertSame(0, StaffNotification::count(), '数えるだけでは記録も残さない');
    }

    /** 本送信：実アドレスには送り、見本データ（@example.com）には送らず記録に残す。 */
    public function test_live_send_skips_dummy_addresses(): void
    {
        Mail::fake();
        $emp = PersonFactory::new()->create(['permission' => 'manager', 'email' => 'emp@ikusa.co.jp']);
        $real = PersonFactory::new()->staff()->create(['email' => 'real@ikusa.co.jp']);
        $dummy = PersonFactory::new()->staff()->create(['email' => 'dummy@example.com']);
        $p = ProjectFactory::new()->published()->create(['start_date' => $this->soon()]);

        foreach ([$real, $dummy] as $person) {
            Assignment::create([
                'project_id' => $p->id, 'staff_id' => $person->id,
                'date' => $this->soon(), 'role' => 'OP', 'status' => '確定',
            ]);
        }

        $this->actingAsPerson($emp)
            ->post('/assign-notify/send', ['kind' => StaffNotifyService::KIND_CONFIRMED, 'mode' => 'live'])
            ->assertRedirect();

        Mail::assertSent(StaffNotifyMail::class, 1);
        Mail::assertSent(StaffNotifyMail::class, fn ($m) => $m->hasTo('real@ikusa.co.jp'));
        Mail::assertNotSent(StaffNotifyMail::class, fn ($m) => $m->hasTo('dummy@example.com'));

        $this->assertSame('sent', StaffNotification::where('staff_id', $real->id)->first()->status);
        $this->assertSame('skipped', StaffNotification::where('staff_id', $dummy->id)->first()->status);
    }

    /** 二度目は送らない（同じ知らせの重複防止）。 */
    public function test_does_not_send_twice(): void
    {
        Mail::fake();
        $emp = PersonFactory::new()->create(['permission' => 'manager', 'email' => 'emp@ikusa.co.jp']);
        $staff = PersonFactory::new()->staff()->create(['email' => 'real@ikusa.co.jp']);
        $p = ProjectFactory::new()->published()->create(['start_date' => $this->soon()]);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $staff->id,
            'date' => $this->soon(), 'role' => 'OP', 'status' => '確定',
        ]);

        $send = fn () => $this->actingAsPerson($emp)
            ->post('/assign-notify/send', ['kind' => StaffNotifyService::KIND_CONFIRMED, 'mode' => 'live']);

        $send()->assertRedirect();
        $send()->assertRedirect();

        Mail::assertSent(StaffNotifyMail::class, 1);
        $this->assertSame(1, StaffNotification::count());
    }

    /** 本人が通知をオフにしていたら送らない。 */
    public function test_respects_staff_notify_off(): void
    {
        Mail::fake();
        $emp = PersonFactory::new()->create(['permission' => 'manager', 'email' => 'emp@ikusa.co.jp']);
        $staff = PersonFactory::new()->staff()->create([
            'email' => 'real@ikusa.co.jp',
            'notify_settings' => ['follow' => false, 'assign' => false, 'deadline' => false],
        ]);
        $p = ProjectFactory::new()->published()->create(['start_date' => $this->soon()]);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $staff->id,
            'date' => $this->soon(), 'role' => 'OP', 'status' => '確定',
        ]);

        $this->actingAsPerson($emp)
            ->post('/assign-notify/send', ['kind' => StaffNotifyService::KIND_CONFIRMED, 'mode' => 'live'])
            ->assertRedirect();

        Mail::assertNothingSent();
        $this->assertSame('skipped', StaffNotification::first()->status);
    }

    /** 募集開始のお知らせは、その拠点のスタッフだけが対象。 */
    public function test_published_notice_is_limited_to_project_office(): void
    {
        $tokyo = PersonFactory::new()->staff()->create(['office' => '東京', 'email' => 't@ikusa.co.jp']);
        $osaka = PersonFactory::new()->staff()->create(['office' => '大阪', 'email' => 'o@ikusa.co.jp']);
        ProjectFactory::new()->published()->create(['office' => '東京', 'start_date' => $this->soon()]);

        $ids = collect(app(StaffNotifyService::class)->collect(StaffNotifyService::KIND_PUBLISHED))
            ->pluck('staff_id')->all();

        $this->assertContains($tokyo->id, $ids);
        $this->assertNotContains($osaka->id, $ids);
    }

    /** 画面が開ける（社員以上）。 */
    public function test_screen_renders(): void
    {
        $emp = PersonFactory::new()->create(['permission' => 'employee']);

        $this->actingAsPerson($emp)->get('/assign-notify')->assertOk();
        $this->actingAsPerson($emp)->get('/assign-notify?kind=project_published')->assertOk();
    }

    /** スタッフは開けない（社員向けの画面）。 */
    public function test_staff_cannot_open(): void
    {
        $staff = PersonFactory::new()->staff()->create();

        $this->actingAsPerson($staff)->get('/assign-notify')->assertRedirect('/staff-portal');
    }
}
