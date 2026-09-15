<?php

namespace Tests\Feature;

use App\Models\SheetSync;
use App\Support\SheetSyncNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * アサイン表の自動取り込みを、毎朝チャットワークに知らせる（2026-09-15 baba要望）。
 *
 * ⚠ **いちばん大事なのは「届かなかったとき」。**
 *   GASが止まっても、失敗の知らせは「作った人の個人メール」にしか届かない
 *   ＝作った人が休み・異動・退職すると、誰も気づかないまま止まり続ける。
 *   だから ECS 側から「今朝のぶんが届いていない」を見張る。
 *
 * ⚠ トークン未設定のときは**送らない**（手元のPCでテストを流して送ってしまわないため）。
 */
class SheetSyncNoticeTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token-0123456789abcdef';

    private function withChatwork(): void
    {
        config([
            'services.chatwork.token' => 'cw-test-token',
            'services.chatwork.room' => '999',
        ]);
        Http::fake(['api.chatwork.com/*' => Http::response(['message_id' => '1'], 200)]);
    }

    private function received(string $period, ?Carbon $at = null): SheetSync
    {
        return SheetSync::create([
            'source' => 'gas', 'tab' => str_replace('-', '', $period), 'period' => $period,
            'office' => '東京', 'rows' => [[]], 'case_count' => 1,
            'fingerprint' => 'x'.$period, 'received_at' => $at ?: Carbon::now(),
        ]);
    }

    /** 今朝のぶんが届いていなければ、チャットワークに知らせる。 */
    public function test_届いていなければ知らせる(): void
    {
        $this->withChatwork();
        // 昨日は届いていたが、今日は届いていない。
        $this->received('2026-09', Carbon::now()->subDay());

        $this->assertFalse(SheetSyncNotice::receivedToday());
        $this->assertTrue(SheetSyncNotice::missingWarning(SheetSyncNotice::lastReceivedAt()));

        Http::assertSent(function ($req) {
            $body = urldecode((string) $req->body());

            return str_contains($body, '今朝アサイン表が届いていません')
                // ⚠ 「で、どうすれば？」にならないよう、確かめかたまで書いてあること。
                && str_contains($body, '手順書')
                && str_contains($body, '最後に届いたのは');
        });
    }

    /** 今朝のぶんが届いていれば、何も送らない（毎朝うるさくしない）。 */
    public function test_届いていれば何も送らない(): void
    {
        $this->withChatwork();
        $this->received('2026-09');

        $this->assertTrue(SheetSyncNotice::receivedToday());
        Http::assertNothingSent();
    }

    /** ⚠ トークンが無い環境では送らない（黙って何もしない）。 */
    public function test_トークンが無ければ送らない(): void
    {
        config(['services.chatwork.token' => null]);
        Http::fake();

        $this->assertFalse(SheetSyncNotice::missingWarning(null));
        Http::assertNothingSent();
    }

    /** GASからの「まとめ報告」で、変わった月だけがチャットワークに出る。 */
    public function test_まとめ報告を送れる(): void
    {
        $this->withChatwork();
        config(['ecs.sheet_sync_token' => self::TOKEN]);

        $this->postJson('/sheet-sync', [
            'token' => self::TOKEN,
            'kind' => 'report',
            'office' => '東京',
            'sent' => 10,
            'changed' => 2,
            'changedPeriods' => ['2026-09', '2026-10'],
            'wrote' => 12,
        ])->assertOk()->assertJsonPath('posted', true);

        Http::assertSent(function ($req) {
            $body = urldecode((string) $req->body());

            return str_contains($body, '受け取った月：10か月ぶん')
                && str_contains($body, '2026-09、2026-10')
                && str_contains($body, '書き戻したECSの案件ID：12件');
        });
    }

    /** エラーがあった日は、題に⚠を付けて中身も出す（黙って流さない）。 */
    public function test_エラーは題に出す(): void
    {
        $this->withChatwork();
        config(['ecs.sheet_sync_token' => self::TOKEN]);

        $this->postJson('/sheet-sync', [
            'token' => self::TOKEN, 'kind' => 'report',
            'sent' => 3, 'changed' => 0,
            'errors' => ['202611：タブ名から年月を読み取れません'],
        ])->assertOk();

        Http::assertSent(function ($req) {
            $body = urldecode((string) $req->body());

            return str_contains($body, '⚠ ECS アサイン表の自動取り込み（エラーあり）')
                && str_contains($body, 'タブ名から年月を読み取れません');
        });
    }

    /** ⚠ 合言葉が違えば、まとめ報告も受け取らない（入口の守りは受け取りと同じ）。 */
    public function test_合言葉が違えば報告も受け取らない(): void
    {
        $this->withChatwork();
        config(['ecs.sheet_sync_token' => self::TOKEN]);

        $this->postJson('/sheet-sync', [
            'token' => 'でたらめ', 'kind' => 'report', 'sent' => 1,
        ])->assertStatus(403);

        Http::assertNothingSent();
    }

    /**
     * ⚠ 入力が合わないときも、**必ずJSONで返す**（HTMLの画面を返さない）。
     *
     * 2026-09-15 に実際に踏んだ：GASのログに `<!DOCTYPE html>… ECS ログイン` と出て、
     * 何が悪いのか分からなかった。`$request->validate()` が「画面に戻そうとして転送する」ため。
     * 機械しか叩かない入口なので、失敗しても機械が読める形で返すこと。
     */
    public function test_入力が合わなくてもJSONで返す(): void
    {
        config(['ecs.sheet_sync_token' => self::TOKEN]);

        // rows が無い（受け取りのつもりで中身が足りない）。
        $res = $this->post('/sheet-sync', ['token' => self::TOKEN, 'tab' => '202609']);

        $res->assertStatus(422);
        $this->assertStringStartsNotWith('<', (string) $res->getContent(), 'HTMLが返っています');
        $res->assertJsonPath('ok', false);
        $this->assertStringContainsString('中身が合いません', (string) $res->json('message'));
    }

    /** 知らせに失敗しても、GAS側は止めない（受け取りは済んでいるため）。 */
    public function test_知らせに失敗しても200を返す(): void
    {
        config([
            'services.chatwork.token' => 'cw-test-token',
            'services.chatwork.room' => '999',
            'ecs.sheet_sync_token' => self::TOKEN,
        ]);
        Http::fake(['api.chatwork.com/*' => Http::response('NG', 500)]);

        $this->postJson('/sheet-sync', [
            'token' => self::TOKEN, 'kind' => 'report', 'sent' => 1,
        ])->assertOk()->assertJsonPath('posted', false);
    }
}
