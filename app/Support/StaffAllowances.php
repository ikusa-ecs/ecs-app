<?php

namespace App\Support;

use App\Models\StaffAllowance;

/**
 * 手で入れる手当の種類と単価（正本・2026-10-13 baba）。単価は「【マニュアル】請求書作成（26年5月～）」。
 * ⚠ アサインから自動で数えられる手当（前泊・ロング・MC・前日設営・事前リハーサル）は**ここに入れない**
 *   ＝手で入れると請求書の目安で二重に数えてしまう（自動の正本＝StaffInvoiceDraft）。
 */
final class StaffAllowances
{
    /** 種類 => 1回の金額（null＝その他＝金額を手で入れる）。 */
    public const KINDS = [
        '運転手当' => 2000,        // 20分以上運転したとき
        'スライド手当' => 1000,    // 1案件ごと
        '事前レクチャー手当' => 2000,
        'その他' => null,
    ];

    /** 種類ごとの説明（入力欄の横に出す）。 */
    public const HINTS = [
        '運転手当' => '20分以上運転したとき（1回2,000円）',
        'スライド手当' => 'スライドを担当（1案件1,000円）',
        '事前レクチャー手当' => '事前レクチャーをした日（1回2,000円）※動画視聴・事前ブリーフィング・体験会は対象外',
        'その他' => '金額とメモを入れてください',
    ];

    /** 1回の金額（その他は入れた金額）。 */
    public static function unit(StaffAllowance $a): int
    {
        return (int) (self::KINDS[$a->kind] ?? $a->amount ?? 0);
    }

    /** 画面に渡す形。 */
    public static function row(StaffAllowance $a, array $projectNames = [], array $staffNames = []): array
    {
        return [
            'id' => $a->id,
            'staffId' => $a->staff_id,
            'staff' => $staffNames[$a->staff_id] ?? $a->staff_id,
            'projectId' => (string) ($a->project_id ?? ''),
            'project' => $a->project_id ? ($projectNames[$a->project_id] ?? $a->project_id) : '',
            'date' => $a->date->format('Y-m-d'),
            'md' => $a->date->format('n/j'),
            'kind' => $a->kind,
            'count' => $a->count,
            'unit' => self::unit($a),
            'amount' => self::unit($a) * $a->count,
            'note' => (string) ($a->note ?? ''),
            'by' => (string) ($a->created_by ?? ''),
        ];
    }
}
