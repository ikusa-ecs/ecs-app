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
    // ── 役割コード（assignments.role に入る値）＝2026-07-01 baba確定の正式定義 ──
    public const D = 'D';       // Director / ディレクター
    public const SD = 'SD';     // Sub-Director / サブディレクター（D決め画面のみ）
    public const MC = 'MC';     // MC
    public const FC = 'FC';     // Facili / ファシリテーター
    public const OP = 'OP';     // Operater / オペレーター
    public const SP = 'SP';     // Support / サポート
    public const RP = 'RP';     // Reception / レセプション
    public const ET = 'ET';     // Etc / その他

    /** コード → 表示ラベル（全コード）。mypage 等は括弧内を省いてコードだけ出す。 */
    public const LABELS = [
        self::D => 'D（ディレクター）',
        self::SD => 'SD（サブディレクター）',
        self::MC => 'MC',
        self::FC => 'FC（ファシリテーター）',
        self::OP => 'OP（オペレーター）',
        self::SP => 'SP（サポート）',
        self::RP => 'RP（レセプション）',
        self::ET => 'ET（その他）',
    ];

    /** コード → 英語名（正式）。 */
    public const ENGLISH = [
        self::D => 'Director',
        self::SD => 'Sub-Director',
        self::MC => 'MC',
        self::FC => 'Facili',
        self::OP => 'Operater',
        self::SP => 'Support',
        self::RP => 'Reception',
        self::ET => 'Etc',
    ];

    /** コード → 意味（役割の説明）。 */
    public const MEANINGS = [
        self::D => '案件全体の責任者・進行管理',
        self::SD => 'ディレクターの補佐（D決め画面で使用）',
        self::MC => '司会進行',
        self::FC => 'オンライン=巡回／リアル=企業のメイン軍師／自治体=大将 に相当',
        self::OP => 'オンライン=スライド／リアル=音響・映像',
        self::SP => 'オンライン=ひよこ／リアル=サポートスタッフ・自治体の大将サポート（目付）',
        self::RP => 'リアル=受付',
        self::ET => 'その他の役割（仏・特別対応・得点係 など）',
    ];

    /** スタッフの「できる役割（staff_role_eligibility）」で使うポジション（SDを除く）。 */
    public const POSITIONS = [self::D, self::MC, self::FC, self::OP, self::SP, self::RP, self::ET];

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
