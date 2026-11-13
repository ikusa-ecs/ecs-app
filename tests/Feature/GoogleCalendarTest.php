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
            'www.googleapis.com/calendar/v3/calendars/primary/events?*' => Http::response(['items' => []], 200),
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

    /**
     * 同じ時刻の予定（アサイン表のGASや手入力で入れたもの）がすでにあれば、ECSからは入れない（2026-11-13 小沼さん）。
     * すでにECSが入れて二重になっていた分は、ECSが入れた方だけ消す。
     */
    public function test_sync_skips_slots_already_on_the_calendar(): void
    {
        config(['services.google_calendar.client_id' => 'cid', 'services.google_calendar.client_secret' => 'sec']);
        [$me] = $this->setUpCase();
        GoogleCalendarLink::create(['person_id' => $me->id, 'refresh_token' => 'RT', 'google_email' => 'me@ikusa.co.jp']);

        $n = 0;
        $foreign = [];
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'AT']),
            'www.googleapis.com/calendar/v3/calendars/primary/events?*' => function () use (&$foreign) { return Http::response(['items' => $foreign], 200); },
            'www.googleapis.com/calendar/v3/calendars/primary/events/*' => Http::response(['id' => 'same'], 200),
            'www.googleapis.com/calendar/v3/calendars/primary/events' => function () use (&$n) { return Http::response(['id' => 'ev'.(++$n)], 200); },
        ]);

        // ① カレンダーが空なら、今までどおり5つ入る。
        $this->actingAsPerson($me)->postJson('/google-calendar/sync')->assertOk()->assertJson(['created' => 5, 'skipped' => 0]);
        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/events'))->values();
        $this->assertCount(5, $sent);

        // ② そのうち2つと同じ時刻の予定が、ECSの印なしで入っていた（＝スプシで登録ずみ）。ECSが入れた予定（印あり）は数えない。
        //    時間帯の書き方が違っても（UTC）同じ時刻なら同じとみなす。
        $utc = function (array $p) {
            return isset($p['dateTime']) ? ['dateTime' => \Illuminate\Support\Carbon::parse($p['dateTime'])->utc()->format('Y-m-d\TH:i:s\Z')] : $p;
        };
        $a = $sent[0]->data();
        $b = $sent[1]->data();
        $c = $sent[2]->data();
        $foreign = [
            ['id' => 'gas1', 'summary' => 'スプシから', 'start' => $utc($a['start']), 'end' => $utc($a['end'])],
            ['id' => 'gas2', 'summary' => '別の題名', 'start' => $b['start'], 'end' => $b['end']],
            ['id' => 'ecs3', 'start' => $c['start'], 'end' => $c['end'], 'extendedProperties' => ['private' => ['ecsUid' => 'x']]],
        ];
        // まず聞き返す＝何も書かない（どうするかは本人が決める）。
        $this->actingAsPerson($me)->postJson('/google-calendar/sync')->assertOk()
            ->assertJson(['ok' => true, 'ask' => true, 'overlaps' => 2, 'created' => 0, 'updated' => 0]);
        $this->assertSame(5, GoogleCalendarEvent::where('person_id', $me->id)->count());
        Http::assertSentCount(1 + 1 + 5 + 1 + 1);   // 1回目＝鍵・一覧・作る5／聞き返し＝鍵・一覧だけ

        // 「飛ばす」を選んだ＝二重になっていたECS側の2つを消し、残り3つは上書き。
        $this->actingAsPerson($me)->postJson('/google-calendar/sync', ['dup' => 'skip'])->assertOk()
            ->assertJson(['created' => 0, 'updated' => 3, 'skipped' => 2, 'failed' => 0]);
        $this->assertSame(3, GoogleCalendarEvent::where('person_id', $me->id)->count(), '二重になっていたECS側の2つは消す');
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'gas'));   // 相手の予定には触らない

        // ③ もう一度「飛ばす」でも、飛ばした2つは入れ直さない。
        $this->actingAsPerson($me)->postJson('/google-calendar/sync', ['dup' => 'skip'])->assertOk()->assertJson(['created' => 0, 'updated' => 3, 'skipped' => 2]);

        // ④ 「かぶっていても入れる」を選んだ＝飛ばさずに全部入る。
        $this->actingAsPerson($me)->postJson('/google-calendar/sync', ['dup' => 'add'])->assertOk()->assertJson(['created' => 2, 'updated' => 3, 'skipped' => 0]);
    }

    public function test_staff_cannot_use_it(): void
    {
        $s = PersonFactory::new()->staff()->create();
        // スタッフはマイページ（社員用）を使わない＝門（tier:employee）でスタッフ画面へ戻される。
        $this->actingAsPerson($s)->postJson('/google-calendar/sync')->assertRedirect();
        $this->assertSame(0, GoogleCalendarEvent::count());
    }
}
