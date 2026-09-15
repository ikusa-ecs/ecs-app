<?php

namespace App\Support;

use App\Models\Setting;

/**
 * チャットワークの「どの知らせを、どの部屋へ送るか」の正本（2026-09-15 baba要望）。
 *
 * 【babaの言葉】「チャットワークの部屋IDはそれぞれ贈る場所が違うから設定できるようにしてほしい」
 *
 * 【なぜ .env ではなく設定画面か】
 * ⚠ **部屋IDは鍵（秘密）ではありません。**（トークンは鍵なので .env のままにします）
 *   .env に置くと、送り先を変えるたびにエンジニアさんへ依頼することになります。
 *   送り先は運用で変わるもの（部屋を作り直した・担当が変わった）なので、
 *   **設定画面から自分で変えられる**ようにしました。
 *
 * 【決める順番】
 *   ① 設定画面で入れた番号（settings テーブル）
 *   ② .env（CHATWORK_ROOM_ID など）
 *   ③ 共通の部屋（config/services.php の既定値）
 *   ＝ **何も設定しなければ、今までとまったく同じ動き**になります。
 *
 * ⚠ 知らせを増やすときは、ここに1行足す。画面にもコントローラにも部屋番号を書かない。
 */
final class ChatworkRooms
{
    /** 共通の部屋（個別に決めていない知らせは、ここへ送る）。 */
    public const COMMON = 'common';

    /** テスト送信用の部屋（`reminder:count-deadline test` の行き先）。 */
    public const TEST = 'test';

    /** 人数確定リマインド（イベント2週間前に営業・Dへ）。 */
    public const COUNT_DEADLINE = 'count_deadline';

    /** 収支未入力リマインド。 */
    public const FINANCE = 'finance';

    /** アサイン表の自動取り込みの結果。 */
    public const SHEET_SYNC = 'sheet_sync';

    /**
     * 設定画面に出す一覧。[種類 => [見出し, 説明]]。
     * ⚠ 並び順がそのまま画面の並びになる。
     */
    public const KINDS = [
        self::COMMON => [
            '共通（個別に決めていないもの）',
            '下の3つで番号を入れなかった知らせは、すべてこの部屋へ送ります。',
        ],
        self::COUNT_DEADLINE => [
            '人数確定リマインド',
            'イベントの2週間前に、人数が未確定の案件を営業担当とディレクターへ知らせます。',
        ],
        self::FINANCE => [
            '収支未入力リマインド',
            'イベントが終わったのに収支が入っていない案件を知らせます。',
        ],
        self::SHEET_SYNC => [
            'アサイン表の自動取り込み',
            '毎朝の取り込みの結果と、届かなかった日の警告を知らせます。',
        ],
        self::TEST => [
            'テスト送信用',
            'お試しで送るときの部屋です（本番の部屋に流さずに確かめられます）。',
        ],
    ];

    /** settings テーブルのキーの頭。 */
    private const KEY_PREFIX = 'chatwork_room:';

    /**
     * その知らせを送る部屋の番号。決まっていなければ共通の部屋。
     *
     * ⚠ 番号が1つも決まっていなければ空文字を返す。呼ぶ側は空なら送らないこと
     *   （空のまま送るとチャットワークAPIが404を返すだけで、誰にも届かない）。
     */
    public static function for(string $kind): string
    {
        $own = self::stored($kind);
        if ($own !== '') {
            return $own;
        }

        // テスト用だけは共通の部屋に落とさない（本番の部屋へ誤爆させないため）。
        if ($kind === self::TEST) {
            return trim((string) config('services.chatwork.test_room'));
        }

        $common = self::stored(self::COMMON);
        if ($common !== '') {
            return $common;
        }

        return trim((string) config('services.chatwork.room'));
    }

    /** 設定画面で入れた番号（空＝決めていない）。 */
    public static function stored(string $kind): string
    {
        return self::clean((string) Setting::get(self::KEY_PREFIX.$kind, ''));
    }

    /**
     * 設定画面の保存。
     *
     * @param  array<string, string>  $values  [種類 => 番号]
     * @return list<string> 受け付けなかったもの（画面に出して知らせる）
     */
    public static function save(array $values): array
    {
        $rejected = [];

        foreach (self::KINDS as $kind => $_) {
            if (! array_key_exists($kind, $values)) {
                continue;
            }
            $raw = trim((string) $values[$kind]);
            $clean = self::clean($raw);

            // 空＝「決めない」。共通の部屋に戻す。
            if ($raw !== '' && $clean === '') {
                $rejected[] = self::KINDS[$kind][0].'：「'.$raw.'」は部屋の番号として読み取れませんでした';

                continue;
            }

            Setting::put(self::KEY_PREFIX.$kind, $clean);
        }

        return $rejected;
    }

    /**
     * 入れてもらった文字から部屋の番号だけを取り出す。
     *
     * ⚠ チャットワークのURLを丸ごと貼られても動くようにする
     *   （`https://www.chatwork.com/#!rid320609834` → `320609834`）。
     *   人に「番号だけ抜き出して」とお願いすると、必ず取り違えが起きる。
     */
    public static function clean(string $value): string
    {
        $v = trim($value);
        if ($v === '') {
            return '';
        }

        if (preg_match('/rid(\d+)/', $v, $m)) {
            return $m[1];
        }

        return preg_match('/^\d+$/', $v) ? $v : '';
    }
}
