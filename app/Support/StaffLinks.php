<?php

namespace App\Support;

use App\Models\Setting;

/**
 * スタッフ画面に出す「便利リンク集」の共通設定。
 *
 * settings テーブルの key='staff_links' に [{title,url,memo}, ...] をJSONで保存する。
 * スタッフNotion・アンケートフォームなど、案件とは別に毎回開く外部ページを
 * スタッフ画面から1タップで開けるようにするためのもの。
 *
 * 中身は共通設定画面（/settings）から社員が追加・並べ替え・削除できる。
 * ＝URLが変わるたびにコードを直す必要がない（変更が多いのでDBに持たせた）。
 */
class StaffLinks
{
    /** 1つのリンクに入れられる文字数の上限（画面が崩れないように）。 */
    public const MAX_TITLE = 40;
    public const MAX_MEMO = 60;
    public const MAX_URL = 500;

    /**
     * リンク一覧。[['title'=>..., 'url'=>..., 'memo'=>...], ...]（登録順）。
     * 壊れた保存値が入っていても画面が落ちないよう、ここで必ず整えて返す。
     */
    public static function all(): array
    {
        $raw = Setting::get('staff_links');
        $arr = is_array($raw) ? $raw : json_decode((string) $raw, true);

        if (! is_array($arr)) {
            return [];
        }

        $list = [];
        foreach ($arr as $row) {
            if (! is_array($row)) {
                continue;
            }
            $clean = self::clean($row);
            if ($clean !== null) {
                $list[] = $clean;
            }
        }

        return $list;
    }

    /** リンク一覧を保存する（不正な行は捨てる）。保存後の配列を返す。 */
    public static function save(array $links): array
    {
        $list = [];
        foreach ($links as $row) {
            if (! is_array($row)) {
                continue;
            }
            $clean = self::clean($row);
            if ($clean !== null) {
                $list[] = $clean;
            }
        }

        Setting::put('staff_links', json_encode($list, JSON_UNESCAPED_UNICODE));

        return $list;
    }

    /**
     * 1行を整える。名前とURLの両方が入っていて、URLが http(s) のものだけ通す。
     * javascript: などを弾くのが目的（スタッフ画面にそのままリンクとして出るため）。
     */
    private static function clean(array $row): ?array
    {
        $title = trim((string) ($row['title'] ?? ''));
        $url = trim((string) ($row['url'] ?? ''));
        $memo = trim((string) ($row['memo'] ?? ''));

        if ($title === '' || $url === '') {
            return null;
        }

        if (! preg_match('#^https?://#i', $url)) {
            return null;
        }

        return [
            'title' => mb_substr($title, 0, self::MAX_TITLE),
            'url' => mb_substr($url, 0, self::MAX_URL),
            'memo' => mb_substr($memo, 0, self::MAX_MEMO),
        ];
    }
}
