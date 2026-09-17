<?php

namespace App\Support;

use App\Models\SheetSync;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * アサイン表の自動取り込みを、毎朝チャットワークに知らせる（2026-09-15 baba要望）。
 *
 * 【なぜ要るか】
 * 毎朝の取り込みは GAS（スプレッドシート側の仕掛け）が動かしている。
 * ⚠ **GASが止まっても、失敗の知らせは「そのGASを作った人の個人メール」にしか届かない。**
 *   つまり作った人が休み・異動・退職すると、**誰も気づかないまま止まり続ける**。
 *   チャットワークに出せば、みんなが見ている場所で「今朝も動いた／動かなかった」が分かる。
 *
 * 【2通りある】
 *  ・{@see morningReport()}  … 届いたとき。GASが最後に1回だけ報告してくる。
 *  ・{@see missingWarning()} … **届かなかったとき**。ECS側の見張り（毎朝9時半）が出す。
 *    ⚠ こちらが本体。①は「届いた」ことしか言えないので、
 *      GASが丸ごと止まった日は①では気づけない（何も来ないだけ）。
 *
 * ⚠ 文面はここ1か所で作る。画面やコマンドに書き写さない。
 * ⚠ トークン（CHATWORK_TOKEN）が未設定なら、黙って何もしない
 *   （設定していない環境＝手元のPCでテストを流したときに送ってしまわないため）。
 */
final class SheetSyncNotice
{
    /** 見張りが「今朝の分が届いていない」と判断する時刻の目安（画面の文面に出す）。 */
    public const EXPECTED_BY = '9:30';

    /**
     * 届いたときの報告をチャットワークへ1通送る。
     *
     * @param  array{sent:int, changed:int, changedPeriods:list<string>, wrote:int, errors:list<string>, office:string}  $r
     * @return bool 送ったか（トークン未設定・送信失敗のときは false）
     */
    public static function morningReport(array $r): bool
    {
        $errors = $r['errors'] ?? [];
        $changed = (int) ($r['changed'] ?? 0);

        $title = $errors
            ? '⚠ ECS アサイン表の自動取り込み（エラーあり）'
            : 'ECS アサイン表の自動取り込み';

        $lines = [];
        $lines[] = '今朝のアサイン表を受け取りました。（'.($r['office'] ?? '東京').'）';
        $lines[] = '';
        $lines[] = '受け取った月：'.(int) ($r['sent'] ?? 0).'か月ぶん';

        if ($changed > 0) {
            $names = $r['changedPeriods'] ?? [];
            $lines[] = '中身が変わっていた月：'.$changed.'か月'
                .($names ? '（'.implode('、', $names).'）' : '');
            $lines[] = '→ 受信箱で、この月だけ見て取り込んでください。';
        } else {
            $lines[] = '中身が変わっていた月：なし（見るところはありません）';
        }

        if ((int) ($r['wrote'] ?? 0) > 0) {
            $lines[] = 'アサイン表に書き戻したECSの案件ID：'.(int) $r['wrote'].'件';
        }

        if ($errors) {
            $lines[] = '';
            $lines[] = '【読み取れなかったもの】';
            foreach (array_slice($errors, 0, 10) as $e) {
                $lines[] = '・'.$e;
            }
            if (count($errors) > 10) {
                $lines[] = '・ほか'.(count($errors) - 10).'件';
            }
        }

        $lines[] = '';
        $lines[] = '▼ 受信箱';
        $lines[] = rtrim(config('app.url'), '/').'/sheet-inbox';

        // ⚠ 毎朝の「届きました」は**既定でメンションを付けない**。
        //   毎日鳴らすと見なくなって、本命の「届いていません」に気づけなくなるため。
        //   付けたいときは共通設定のチェックで入れられる（正本＝ChatworkMentions）。
        return self::post($title, implode("\n", $lines), ChatworkMentions::mentionDailyReport());
    }

    /**
     * 「今朝は届いていません」の警告をチャットワークへ送る。
     *
     * ⚠ これが出たら、ほぼ GAS 側が止まっている。手順書の見かたまで書いておく
     *   （通知だけ出して「で、どうすれば？」にならないように）。
     */
    public static function missingWarning(?Carbon $lastReceived = null): bool
    {
        $lines = [];
        $lines[] = '毎朝8時に届くはずのアサイン表が、今日はECSに届いていません。';
        $lines[] = '';
        $lines[] = '最後に届いたのは：'
            .($lastReceived ? $lastReceived->format('Y年n月j日 H:i') : '記録がありません（一度も届いていません）');
        $lines[] = '';
        $lines[] = '【考えられること】';
        $lines[] = '・GASのトリガーが止まっている（作った人のアカウントが使えなくなった等）';
        $lines[] = '・合言葉（ECS_SHEET_SYNC_TOKEN）が変わった';
        $lines[] = '・ECSのサーバーが止まっていた';
        $lines[] = '';
        $lines[] = '【確かめかた】';
        $lines[] = 'script.google.com を開いて「ECSへアサイン表を送る」の実行ログを見てください。';
        $lines[] = '手順書＝稼働管理\\ECS\\アサイン表_毎朝ECSへ送るGAS.txt';
        $lines[] = '';
        $lines[] = '※ アサイン表は手でも取り込めます（CSV一括取込）。急ぐときはそちらで。';

        return self::post('⚠ ECS 今朝アサイン表が届いていません', implode("\n", $lines));
    }

    /**
     * 今日ぶんがもう届いているか。
     * ⚠ 見張りはこれだけを見る（「変わったか」ではなく「届いたか」）。
     */
    public static function receivedToday(?Carbon $today = null): bool
    {
        $day = ($today ?: Carbon::today())->format('Y-m-d');

        return SheetSync::whereBetween('received_at', [$day.' 00:00:00', $day.' 23:59:59'])->exists();
    }

    /** 最後に届いた日時（一度も届いていなければ null）。 */
    public static function lastReceivedAt(): ?Carbon
    {
        $at = SheetSync::max('received_at');

        return $at ? Carbon::parse($at) : null;
    }

    /**
     * チャットワークへ1通投稿する。
     *
     * ⚠ 送れなくても例外を投げない。ここで落ちると、
     *   受け取りそのものが失敗したように見えてしまう（本体は受け取れているのに）。
     */
    private static function post(string $title, string $body, bool $mention = true): bool
    {
        $client = new ChatworkClient();
        if (! $client->hasToken()) {
            return false;   // 設定していない環境（手元のPCなど）では何もしない
        }

        // 送り先の部屋は知らせごとに変えられる（2026-09-15 baba要望）。正本＝App\Support\ChatworkRooms。
        // ⚠ 決まっていなければ送らない。黙って既定の部屋へ投げない
        //   （誰も見ていない部屋に届いて「送れたのに気づけない」が実際に起きた）。
        $room = ChatworkRooms::for(ChatworkRooms::SHEET_SYNC);
        if ($room === '') {
            Log::warning('アサイン表の知らせを送れませんでした：送り先の部屋が未設定です。'
                .'共通設定 →「チャットワークの送り先」で決めてください。');

            return false;
        }

        // 宛名（メンション）。誰にメンションするかは共通設定で選ぶ＝正本 App\Support\ChatworkMentions。
        // ⚠ [info] の枠の**外（上）**に置く。枠の中に入れると本文に埋もれて見落としやすい。
        // ⚠ 誰も選んでいなければ空文字＝これまでとまったく同じ見た目になる。
        $head = $mention ? ChatworkMentions::head(ChatworkRooms::SHEET_SYNC) : '';

        try {
            $client->postMessage($room, $head."[info][title]{$title}[/title]{$body}[/info]");

            return true;
        } catch (\Throwable $e) {
            Log::warning('アサイン表の取り込みをチャットワークへ知らせられませんでした：'.$e->getMessage());

            return false;
        }
    }
}
