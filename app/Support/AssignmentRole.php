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
}
