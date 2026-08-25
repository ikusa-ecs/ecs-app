<?php

namespace App\Support;

/**
 * アサインの「役割コード」の唯一の正（single source of truth）。
 *
 * assignments.role と staff_role_eligibility.position は、ここで定義したコードだけを使う。
 * 画面・コントローラ・シーダーは必ずこのクラスを参照すること。
 * 日本語の別表記（「ディレクター」「サブディレクター」等）を直書きしない
 * ＝表記ゆれ（同じ役割が別物として二重に見える事故）を防ぐため。
 *
 * 区別：
 *   - POSITIONS … スタッフの「できる役割（staff_role_eligibility）」で使う7種。SDは含まない。
 *   - LABELS    … 上記＋SD（サブディレクター。D決め画面だけで使う）まで含む全コードの表示名。
 */
class AssignmentRole
{
    // ── 役割コード（assignments.role に入る値）──
    // ※2026-07-01 baba：受付=RP・軍師=SP にコードを変更（表示は「受付」「軍師・サポーター」のまま）。
    public const D = 'D';       // ディレクター
    public const SD = 'SD';     // サブディレクター（D決め画面のみ）
    public const OP = 'OP';     // 音響
    public const MC = 'MC';     // 司会進行
    public const FC = 'FC';     // 巡回ファシリ
    public const CK = 'CK';     // チェッカー
    public const SP = 'SP';     // 軍師・サポーター（旧コード GUN）
    public const RP = 'RP';     // 受付（旧コード UKE）

    /** コード → 表示ラベル（全コード）。表示は従来どおり。 */
    public const LABELS = [
        self::D => 'D（ディレクター）',
        self::SD => 'SD（サブディレクター）',
        self::OP => 'OP（音響）',
        self::MC => 'MC（司会進行）',
        self::FC => 'FC（巡回ファシリ）',
        self::CK => 'CK（チェッカー）',
        self::SP => '軍師・サポーター',
        self::RP => '受付',
    ];

    /**
     * コード → 英語名（参考。baba提供・2026-07-01）。表示には使わない＝コード整理の参考メモ。
     */
    public const ENGLISH = [
        self::D => 'Director',
        self::SD => 'Sub-Director',
        self::OP => 'Operater',
        self::MC => 'MC',
        self::FC => 'Facili',
        self::CK => 'Checker',
        self::SP => 'Support',
        self::RP => 'Reception',
    ];

    /** スタッフの「できる役割（staff_role_eligibility）」で使うポジション（SDを除く7種）。 */
    public const POSITIONS = [self::D, self::OP, self::MC, self::FC, self::CK, self::SP, self::RP];

    /** assignments.role に入りうる全コード（配列）。 */
    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    /** コード → ラベル（未知のコードはそのまま返す）。 */
    public static function label(?string $code): string
    {
        return self::LABELS[$code] ?? (string) $code;
    }

    /** ポジション（できる役割）のラベル表＝[コード => ラベル]（SDを除く）。プルダウン用。 */
    public static function positionLabels(): array
    {
        return array_intersect_key(self::LABELS, array_flip(self::POSITIONS));
    }

    /** 正規の役割コードかどうか（空文字・null・別表記は false）。 */
    public static function isValid(?string $code): bool
    {
        return $code !== null && $code !== '' && in_array($code, self::all(), true);
    }

    /**
     * アサイン表に書かれた役割の言い方 → 役割コード。読めなければ null。
     *
     * なぜ要るか＝月ごとのアサイン表の「P」列には、D／MC／OP／FC のほか
     * 日本語や旧コードが書かれることがある（2026-08-25 baba要望の取込で使う）。
     * ⚠ 読めない書き方は勝手に決めず null を返す＝呼ぶ側が一覧で知らせる。
     *   （知らない語を FC などに寄せてしまうと、間違った役割で記録が残る）
     *
     * 旧コードの読み替え＝GUN→SP（軍師）／UKE→RP（受付）。DBは2026-07-01に変換済み。
     */
    public static function fromLabel(?string $text): ?string
    {
        $t = preg_replace('/[\s　]+/u', '', trim((string) $text));
        if ($t === '') {
            return null;
        }

        // コードそのもの（大文字にそろえる）。旧コードはここで読み替える。
        $upper = mb_strtoupper($t);
        $upper = ['GUN' => self::SP, 'UKE' => self::RP][$upper] ?? $upper;
        if (self::isValid($upper)) {
            return $upper;
        }

        // 日本語・別名から。
        $aliases = [
            'ディレクター' => self::D,
            'サブディレクター' => self::SD,
            'サブD' => self::SD,
            '音響' => self::OP,
            'オペレーター' => self::OP,
            '司会' => self::MC,
            '司会進行' => self::MC,
            '巡回' => self::FC,
            'ファシリ' => self::FC,
            '巡回ファシリ' => self::FC,
            'チェッカー' => self::CK,
            '軍師' => self::SP,
            'サポーター' => self::SP,
            '軍師・サポーター' => self::SP,
            '受付' => self::RP,
        ];

        foreach ($aliases as $name => $code) {
            if ($t === preg_replace('/[\s　]+/u', '', $name)) {
                return $code;
            }
        }

        // 「D（ディレクター）」のようにコード＋説明の形も拾う。
        foreach (self::LABELS as $code => $label) {
            if ($t === preg_replace('/[\s　]+/u', '', $label)) {
                return $code;
            }
        }

        return null;
    }
}
