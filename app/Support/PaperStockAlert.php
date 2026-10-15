<?php

namespace App\Support;

use App\Models\ContentPaperStock;
use Illuminate\Support\Facades\Log;

/**
 * 謎解きの紙が 120部（{@see PaperStockService::ALERT_BELOW}）を切ったら、チャットワークへ知らせる（2026-10-15 baba要望）。
 *
 * baba決定：
 *   ・見る数字＝**いま手元にある数**（入庫数−開催済みで使った数）。今後の予定のぶんは引かない。
 *   ・**切った時に1回だけ**送る。120部以上に戻ったら（入庫したら）印を外す＝また切ったら送る。
 *   ・拠点ごと（紙は拠点ごとに別々に置いている）。
 *
 * 1回だけ＝content_paper_stocks.alerted_at に送った日時を残す。
 * ⚠ 送れなかったとき（鍵や部屋が未設定・通信失敗）は印を付けない＝次の朝にもう一度送る。
 * ⚠ 入庫数を入れていない「コンテンツ×拠点」は見ない（在庫を管理していないところに毎回0部と言わない）。
 *
 * 動くのは毎朝（routes/console.php）。手で試す＝ php artisan paper-stock:alert
 */
final class PaperStockAlert
{
    /**
     * @return array{low: list<string>, sent: bool} low＝今回はじめて切ったもの（「拠点・コンテンツ名 ◯部」）
     */
    public static function run(): array
    {
        $byOffice = (new PaperStockService())->compute()['byOffice'];

        $newlyLow = [];
        $lines = [];
        foreach (ContentPaperStock::all() as $s) {
            $r = $byOffice[(string) $s->office][(string) $s->content_id] ?? null;
            if ($r === null) {
                continue;   // 紙が要らなくなったコンテンツ
            }

            if (! $r['low']) {
                if ($s->alerted_at !== null) {
                    $s->forceFill(['alerted_at' => null])->save();   // 入庫して戻った＝次に切ったらまた送る
                }

                continue;
            }

            if ($s->alerted_at === null) {
                $newlyLow[] = $s;
                $lines[] = '・'.$s->office.'／'.$r['name'].'：残り '.$r['zaiko'].'部'
                    .'（これからの予定で '.$r['future'].'部 使う見込み）';
            }
        }

        if (! $newlyLow) {
            return ['low' => [], 'sent' => false];
        }

        $sent = self::post($lines);
        if ($sent) {
            foreach ($newlyLow as $s) {
                $s->forceFill(['alerted_at' => now()])->save();
            }
        }

        return ['low' => $lines, 'sent' => $sent];
    }

    /** @param  list<string>  $lines */
    private static function post(array $lines): bool
    {
        $client = new ChatworkClient();
        if (! $client->hasToken()) {
            return false;   // 設定していない環境（手元のPCなど）では何もしない
        }

        $room = ChatworkRooms::for(ChatworkRooms::PAPER_STOCK);
        if ($room === '') {
            Log::warning('紙の在庫の知らせを送れませんでした：送り先の部屋が未設定です。'
                .'共通設定 →「チャットワークの送り先」で決めてください。');

            return false;
        }

        $title = '謎解きの紙が '.PaperStockService::ALERT_BELOW.'部を切りました';
        $body = implode("\n", $lines)."\n\n印刷の手配をお願いします。入庫したら「謎解きの紙 在庫」の入庫数を直してください。\n"
            .rtrim((string) config('app.url'), '/').'/paper-stock';
        $head = ChatworkMentions::head(ChatworkRooms::PAPER_STOCK);

        try {
            $client->postMessage($room, $head."[info][title]{$title}[/title]{$body}[/info]");

            return true;
        } catch (\Throwable $e) {
            Log::warning('紙の在庫の知らせをチャットワークへ送れませんでした：'.$e->getMessage());

            return false;
        }
    }
}
