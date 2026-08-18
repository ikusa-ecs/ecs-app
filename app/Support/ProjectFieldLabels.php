<?php

namespace App\Support;

use App\Models\Content;
use App\Models\Person;
use Illuminate\Support\Carbon;

/**
 * 案件の「DBの列名 → 画面で使っている日本語名」と「値を人が読める形に直す」係。
 *
 * 編集履歴（先-1）で「is_outdoor が 0 → 1 に変わりました」では誰も読めないので、
 * 「屋内 / 屋外：屋内 → 屋外」と出せるように、名前と言い換えをここ1か所にまとめる。
 *
 * ※ 費目の正本が App\Support\FinanceItems の1か所なのと同じ考え方。
 *   画面（Blade）側で日本語名を書き直さない。列を増やしたらここに1行足す。
 */
class ProjectFieldLabels
{
    /**
     * 列名 => 画面で使っている日本語名。ここに無い列は履歴に残さない
     * （＝載っていない列は「まだ日本語名を決めていない列」なので、増えたらここに足す）。
     *
     * @var array<string, string>
     */
    public const LABELS = [
        // ── 基本 ──
        'project_name'       => '案件名',
        'content_ids'        => 'コンテンツ',
        'content_names'      => 'コンテンツ名（自由入力）',
        'category'           => '区分',
        'is_toc'             => 'toC',
        'yomi'               => '確度（ヨミ）',
        'yomi_expected'      => '確定見込み時期',
        'is_recruiting'      => 'スタッフ募集',
        'scale'              => '案件規模',
        'sales_owners'       => '営業担当',
        'office'             => '登録拠点',
        'lodging'            => '宿泊',
        // ── 形態・取引先 ──
        'format'             => '実施形態',
        'online_tool'        => 'オンラインツール',
        'base_locations'     => '対象の拠点',
        'arena_options'      => 'ARENAの詳細',
        'client'             => 'クライアント',
        'agency'             => '代理店名',
        'is_multi'           => '複数案件',
        'broadcast'          => '配信種別',
        'date_type'          => '日程種別',
        'parent_project_id'  => '紐づく本番案件',
        'operation_place'    => '運営場所',
        'site_category'      => '会場区分',
        // ── 当日の時間 ──
        'start_date'         => '開催日',
        'assembly_type'      => '担当体制',
        'start_time'         => '集合時間（スタッフ）',
        'end_time'           => '解散時間（スタッフ）',
        'staff_meeting_time' => '集合時間（メモ）',
        'staff_meet_time'    => 'スタッフ集合',
        'staff_leave_time'   => 'スタッフ解散',
        'event_enter_time'   => 'イベント入場',
        'event_start_time'   => 'イベント開始',
        'event_end_time'     => 'イベント終了',
        // ── 人数 ──
        'staff_role'         => '運営体制',
        'required_count'     => '運営人数',
        'count_tentative'    => '運営人数は仮',
        'guest_count'        => 'お客様人数',
        'guest_count_type'   => 'お客様人数の区分',
        'team_count'         => 'チーム数',
        'team_tentative'     => 'チーム数は仮',
        'is_repeat'          => 'リピート案件',
        // ── 会場・手配 ──
        'audio_equipment'    => '音響機材',
        'pub_logo'           => 'ロゴ',
        'pub_camera'         => 'カメラ',
        'pub_article'        => '事例記事',
        'pub_video'          => '動画',
        'location'           => '会場住所',
        'is_outdoor'         => '屋内 / 屋外',
        'alcohol'            => 'お酒',
        'catering'           => 'ケータリング',
        'catering_note'      => 'ケータリングのメモ',
        'transport'          => '移動・車両',
        // ── 運営（アサイン後） ──
        'director_id'        => 'ディレクター',
        'sd_id'              => 'SD（サブディレクター）',
        'goods_owner_id'     => '物品担当',
        'ops_sheet_url'      => '運営シートURL',
        'prep_line_sent'     => '準備：LINE概要送付',
        'prep_handover'      => '準備：引き継ぎ',
        'prep_script'        => '準備：台本',
        'note'               => 'メモ・連絡事項',
        // ── 状態 ──
        'status'             => 'アサイン状況',
        'staff_published'    => 'スタッフへの公開',
        'publish_memo'       => '公開ボードの備考',
        'extra_published_at' => '追加案件の公開日',
        'is_archived'        => 'アーカイブ',
    ];

    /**
     * 真偽値（0/1）の列 => [1のときの言い方, 0のときの言い方]。
     * 「1 → 0」ではなく「募集する → 募集しない」と読めるようにする。
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const BOOLEAN_WORDS = [
        'is_toc'          => ['toCの案件', 'toCではない'],
        'is_recruiting'   => ['募集する', '募集しない'],
        'is_multi'        => ['あり', 'なし'],
        'is_outdoor'      => ['屋外', '屋内'],
        'is_repeat'       => ['リピート', '初回'],
        'alcohol'         => ['あり', 'なし'],
        'count_tentative' => ['仮（未定）', '確定'],
        'team_tentative'  => ['仮（未定）', '確定'],
        'prep_line_sent'  => ['済', '未'],
        'prep_handover'   => ['済', '未'],
        'prep_script'     => ['済', '未'],
        'staff_published' => ['公開', '非公開'],
        'is_archived'     => ['アーカイブ', '通常'],
    ];

    /** 社員のIDで持っている列（表示は氏名に直す）。 */
    private const PERSON_FIELDS = ['director_id', 'sd_id', 'goods_owner_id'];

    /** 日付で持っている列（表示は 2026/09/20 の形に直す）。 */
    private const DATE_FIELDS = ['start_date', 'extra_published_at'];

    /** コンテンツのIDで持っている列（表示はコンテンツ名に直す）。 */
    private const CONTENT_FIELDS = ['content_ids'];

    /**
     * 引いた氏名・コンテンツ名の覚え書き（ID => 表示名）。同じIDを何度も問い合わせないための控え。
     * ※ 一覧をまとめて先読みはしない。先読みすると、その直後に登録された人・コンテンツを
     *   「見つからない」と判断してIDのまま出してしまうため。
     */
    private static array $personNames = [];

    private static array $contentNames = [];

    /** この列は履歴に残すか（日本語名を決めてある列だけ残す）。 */
    public static function isTracked(string $field): bool
    {
        return isset(self::LABELS[$field]);
    }

    /** 列の日本語名。決めていない列はそのまま列名を返す。 */
    public static function label(string $field): string
    {
        return self::LABELS[$field] ?? $field;
    }

    /**
     * 値を人が読める形にする。空・null は「（空）」にして「消した」ことが分かるようにする。
     *
     * @param  mixed  $value
     */
    public static function display(string $field, $value): string
    {
        if (in_array($field, self::PERSON_FIELDS, true)) {
            return self::wrapEmpty(self::personName($value));
        }

        if (in_array($field, self::CONTENT_FIELDS, true)) {
            return self::wrapEmpty(self::contentNames($value));
        }

        if (isset(self::BOOLEAN_WORDS[$field])) {
            if ($value === null || $value === '') {
                return '（空）';
            }
            [$on, $off] = self::BOOLEAN_WORDS[$field];

            return (bool) $value ? $on : $off;
        }

        if (in_array($field, self::DATE_FIELDS, true)) {
            return self::wrapEmpty(self::dateText($value));
        }

        if (is_array($value)) {
            $parts = array_filter(array_map('strval', $value), static fn ($v) => trim($v) !== '');

            return self::wrapEmpty(implode('、', $parts));
        }

        if (is_bool($value)) {
            return $value ? 'はい' : 'いいえ';
        }

        if ($value instanceof Carbon) {
            return self::wrapEmpty(self::dateText($value));
        }

        return self::wrapEmpty(trim((string) $value));
    }

    /** 空文字を「（空）」に置き換える。 */
    private static function wrapEmpty(string $text): string
    {
        return $text === '' ? '（空）' : $text;
    }

    /** 日付を 2026/09/20 の形に。読めないものはそのまま返す。 */
    private static function dateText($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        try {
            return Carbon::parse(is_string($value) ? $value : (string) $value)->format('Y/m/d');
        } catch (\Throwable $e) {
            return (string) $value;
        }
    }

    /** 社員IDを氏名に。見つからなければIDのまま出す（消された人かもしれないため）。 */
    private static function personName($value): string
    {
        $id = trim((string) $value);
        if ($id === '') {
            return '';
        }
        if (! array_key_exists($id, self::$personNames)) {
            // 見つからなければIDのまま覚える（消された人の記録も読めるようにするため）。
            self::$personNames[$id] = (string) (Person::where('id', $id)->value('name') ?: $id);
        }

        return self::$personNames[$id];
    }

    /** コンテンツIDの並びをコンテンツ名の並びに。 */
    private static function contentNames($value): string
    {
        $ids = is_array($value) ? $value : (is_string($value) && $value !== '' ? [$value] : []);
        if ($ids === []) {
            return '';
        }
        $names = [];
        foreach ($ids as $id) {
            $key = trim((string) $id);
            if ($key === '') {
                continue;
            }
            if (! array_key_exists($key, self::$contentNames)) {
                self::$contentNames[$key] = (string) (Content::where('id', $key)->value('content_name') ?: $key);
            }
            $names[] = self::$contentNames[$key];
        }

        return implode('、', $names);
    }
}
