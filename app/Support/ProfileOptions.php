<?php

namespace App\Support;

/**
 * マイプロフィールの「選択肢」の唯一の正（single source of truth）。
 *
 * 【なぜ要るか】
 * 本人が入力するプロフィールの選択肢（運転できる車のサイズ・英語のレベルなど）は、
 * これまでスタッフ画面（staff_portal.blade.php）の中に直書きしていた。
 * 同じ項目を社員の「マイプロフィール」にも出すことになったので、
 * 書き写すと必ず片方だけ直して食い違う（過去に実施形態で同じ事故を起こしている）。
 * 選択肢を足す・言い方を変えるときは、**このファイルだけ**を直せばよいようにする。
 *
 * 【入っているもの】
 * ・DRIVING         … 運転できる車のサイズ（people.driving_level に入る値）
 * ・ENGLISH         … 英語のレベル（people.english_level に入る値）
 * ・CHALLENGE_POSITIONS … これから挑戦してみたい役割（people.challenge_positions＝複数）
 * ・ONLINE_TOOLS    … 日常で使っているオンラインツール（people.online_tools＝複数）
 *
 * ⚠ ここに並べた「文字そのもの」がDBに入る（コード番号ではない）。
 *   すでに入力された人がいる状態で文字を書き換えると、その人の選択が外れて見える。
 *   言い方を変えたいときは、先に入っている値を一緒に直すこと。
 */
class ProfileOptions
{
    /** プルダウンの「選んでいない」を表す文字（DBには null で入れる）。 */
    public const NONE = '（なし）';

    /** 運転できる車のサイズ。※ハイエース＝機材を積む車。出せる人が分かると当日の手配が変わる。 */
    public const DRIVING = [
        '普通サイズなら運転可能',
        'ハイエースも普通サイズも運転可能',
    ];

    /** 英語のレベル。海外のお客様・英語進行の案件で誰に頼めるかを見るため。 */
    public const ENGLISH = [
        '片言レベル',
        '日常会話レベル',
        'ビジネス会話可能レベル',
    ];

    /**
     * これから挑戦してみたい役割（複数チェック・2026-08-31 baba選択の4つ）。
     * 「できる役割」とは別物＝**まだできなくてよい**。育成・次に任せる役割を決める参考にする。
     *
     * ⚠ アサインの役割は全部で8つあるが、ここは**手を挙げてほしい4つだけ**に絞っている
     *   （baba選択）。全部並べると選ぶ側が迷い、聞きたいことがぼやけるため。
     *   表示の文字は AssignmentRole::LABELS と同じ言い方にそろえてある。
     */
    public const CHALLENGE_POSITIONS = [
        'D（ディレクター）',
        'OP（音響）',
        'MC（司会進行）',
        '軍師・サポーター',
    ];

    /**
     * 日常で使っているオンラインツール（複数チェック・2026-08-31 baba選択の12個）。
     * オンライン案件でどのツールならすぐ入れるか、資料づくりを誰に頼めるかを見るため。
     * 一覧に無いものは online_tools_other（自由記入）に入れてもらう。
     */
    public const ONLINE_TOOLS = [
        'Zoom',
        'Google Meet',
        'Microsoft Teams',
        'Webex',
        'Slack',
        'チャットワーク',
        'LINE',
        'Discord',
        'Googleスプレッドシート・ドキュメント',
        'Excel・Word',
        'Notion',
        'Canva',
    ];

    /** 運転のプルダウンに並べる選択肢（先頭＝未選択）。 */
    public static function drivingChoices(): array
    {
        return array_merge([self::NONE], self::DRIVING);
    }

    /** 英語のプルダウンに並べる選択肢（先頭＝未選択）。 */
    public static function englishChoices(): array
    {
        return array_merge([self::NONE], self::ENGLISH);
    }

    /**
     * プルダウンの値をDBに入れる形にする＝未選択・一覧に無い値は null。
     * ⚠ 勝手に近い選択肢へ寄せない（打ち間違いをそれらしい値で残すと嘘の記録になる）。
     */
    public static function normalizeChoice(?string $value, array $allowed): ?string
    {
        $value = trim((string) $value);

        return ($value === '' || $value === self::NONE || ! in_array($value, $allowed, true)) ? null : $value;
    }

    /**
     * チェックボックスの値をDBに入れる形にする＝一覧に無いものは捨て、並び順は一覧の順にそろえる。
     * 何も選んでいなければ null（空配列を入れると「入力済みで全部外した」と見分けが付かない）。
     */
    public static function normalizeChecks($values, array $allowed): ?array
    {
        $values = array_filter((array) $values, 'is_string');
        $kept = array_values(array_intersect($allowed, $values));

        return $kept === [] ? null : $kept;
    }
}
