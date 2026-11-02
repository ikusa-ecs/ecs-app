<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\GoogleCalendarEvent;
use App\Models\GoogleCalendarLink;
use App\Support\MyCalendarEvents;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * マイページの「Googleカレンダーに登録」（2026-11-02 baba決定）。
 *   ・予定の形＝移動（集合-1h〜集合）／【役割】_コンテンツ_顧客（集合〜解散）／移動（解散〜+1h）＋前泊・後泊（正本 MyCalendarEvents）
 *   ・何回押しても重ならない（上書き）。アサインが外れた先の予定は消す。ECSが登録していない予定には触らない。
 *   ・鍵が入っていない（準備中）ときは連携ボタンを出さない。
 */
class GoogleCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function setUpCase(): array
    {
        $me = PersonFactory::new()->create(['role' => 'employee', 'permission' => 'employee', 'office' => '東京', 'email' => 'me@ikusa.co.jp']);
        $date = Carbon::today()->addDays(5);
        $p = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date->format('Y-m-d'), 'project_name' => '謎解き',
            'content_names' => ['謎解き'], 'client' => 'A社', 'start_time' => '9:00', 'end_time' => '18:30', 'lodging' => '前後泊あり', 'stay_pre_meet_time' => '19:00']);
        Assignment::create(['project_id' => $p->id, 'staff_id' => $me->id, 'date' => $date->format('Y-m-d'), 'role' => 'D', 'status' => '確定']);

        return [$me, $p, $date];
    }

    public function test_events_are_built_like_the_assign_sheet_gas(): void
    {
        [$me, $p, $date] = $this->setUpCase();
        $ev = collect(MyCalendarEvents::for($me))->keyBy(fn ($e) => substr($e['uid'], strlen($p->id.'-D-')));

        $this->assertSame(['move-before', 'main', 'move-after', 'stay-pre', 'stay-post'], $ev->keys()->all());
        $this->assertSame('移動', $ev['move-before']['title']);
        $this->assertSame('08:00', $ev['move-before']['start']->format('H:i'));
        $this->assertSame('【D】_謎解き_A社', $ev['main']['title']);
        $this->assertSame('09:00-18:30', $ev['main']['start']->format('H:i').'-'.$ev['main']['end']->format('H:i'));
        $this->assertSame('19:30', $ev['move-after']['end']->format('H:i'));
        $this->assertSame($date->copy()->subDay()->format('Y-m-d').' 19:00', $ev['stay-pre']['start']->format('Y-m-d H:i'));
        $this->assertSame($date->copy()->addDay()->format('Y-m-d'), $ev['stay-post']['day']->format('Y-m-d'));

        // 時間が未定なら本体だけ終日（移動は入れない）。
        $p->update(['start_time' => null, 'end_time' => null, 'lodging' => '無']);
        $ev = MyCalendarEvents::for($me);
        $this->assertCount(1, $ev);
        $this->assertSame($date->format('Y-m-d'), $ev[0]['day']->format('Y-m-d'));

        // .ics でも同じ予定が落とせる（予備の道）。
        $this->actingAsPerson($me)->get('/mypage/calendar.ics')->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->assertSee('UID:'.$p->id.'-D-main@ecs.ikusa.co.jp', false);
    }

    public function test_not_configured_shows_no_connect(): void
    {
        config(['services.google_calendar.client_id' => '', 'services.google_calendar.client_secret' => '']);
        [$me] = $this->setUpCase();
        $data = $this->actingAsPerson($me)->get('/mypage')->assertOk()->original->getData();
        $this->assertFalse($data['googleCal']['configured']);
        $this->actingAsPerson($me)->get('/google-calendar/connect')->assertRedirect('/mypage');
    }

    public function test_connect_and_sync_creates_updates_and_deletes(): void
    {
        config(['services.google_calendar.client_id' => 'cid', 'services.google_calendar.client_secret' => 'sec']);
        [$me, $p] = $this->setUpCase();

        // ① 連携：Googleへ飛ぶ → 戻ってきたら鍵を保存（state が合うときだけ）。
        $go = $this->actingAsPerson($me)->get('/google-calendar/connect');
        $go->assertRedirect();
        parse_str(parse_url($go->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertSame('offline', $q['access_type']);
        $this->assertStringContainsString('calendar.events', $q['scope']);

        $idToken = 'x.'.rtrim(strtr(base64_encode(json_encode(['email' => 'me@ikusa.co.jp'])), '+/', '-_'), '=').'.y';
        $n = 0;
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'AT', 'refresh_token' => 'RT', 'id_token' => $idToken]),
            'www.googleapis.com/calendar/v3/calendars/primary/events/*' => Http::response(['id' => 'same'], 200),
            'www.googleapis.com/calendar/v3/calendars/primary/events' => function () use (&$n) { return Http::response(['id' => 'ev'.(++$n)], 200); },
        ]);
        $this->actingAsPerson($me)->get('/google-calendar/callback?state=wrong&code=c')->assertRedirect('/mypage');
        $this->assertNull(GoogleCalendarLink::find($me->id), 'state が違えば保存しない');
        // ⚠ 違う state で戻ると、覚えていた state も捨てる（使い回させない）＝もう一度連携からやり直す。
        $go = $this->actingAsPerson($me)->get('/google-calendar/connect');
        parse_str(parse_url($go->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->actingAsPerson($me)->get('/google-calendar/callback?state='.$q['state'].'&code=c')->assertRedirect('/mypage');
        $link = GoogleCalendarLink::find($me->id);
        $this->assertSame('RT', $link->refresh_token);
        $this->assertSame('me@ikusa.co.jp', $link->google_email);
        $this->assertNotSame('RT', \DB::table('google_calendar_links')->value('refresh_token'), '鍵は暗号化して保存');

        // ② 1回目：5つ作る。
        $this->actingAsPerson($me)->postJson('/google-calendar/sync')->assertOk()->assertJson(['ok' => true, 'created' => 5, 'updated' => 0, 'cases' => 1]);
        $this->assertSame(5, GoogleCalendarEvent::where('person_id', $me->id)->count());

        // ③ 2回目：重ならない（全部上書き）。
        $this->actingAsPerson($me)->postJson('/google-calendar/sync')->assertOk()->assertJson(['created' => 0, 'updated' => 5]);

        // ④ アサインが外れたら、その予定を消す。
        Assignment::where('staff_id', $me->id)->update(['status' => 'キャンセル']);
        $this->actingAsPerson($me)->postJson('/google-calendar/sync')->assertOk()->assertJson(['deleted' => 5]);
        $this->assertSame(0, GoogleCalendarEvent::where('person_id', $me->id)->count());
        Http::assertSent(fn ($r) => $r->method() === 'DELETE');

        // ⑤ 連携をやめる。
        $this->actingAsPerson($me)->post('/google-calendar/disconnect')->assertRedirect('/mypage');
        $this->assertNull(GoogleCalendarLink::find($me->id));
    }

    public function test_staff_cannot_use_it(): void
    {
        $s = PersonFactory::new()->staff()->create();
        // スタッフはマイページ（社員用）を使わない＝門（tier:employee）でスタッフ画面へ戻される。
        $this->actingAsPerson($s)->postJson('/google-calendar/sync')->assertRedirect();
        $this->assertSame(0, GoogleCalendarEvent::count());
    }
}
