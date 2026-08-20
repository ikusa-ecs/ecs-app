<?php

namespace App\Support;

use App\Models\Assignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * アサインの「誰が・いつ」を記録する係（1か所に集約）。
 *
 * これまで assignments.assigned_by は全画面で null 固定で、確定した記録も残らなかった。
 * アサインを書き込む画面は6つ（手動アサイン／エントリー一覧／日別ボード／ピックアップ／
 * アサイン表／D決め）＋案件一覧のセルがあるので、記録の付け方はここ1か所に置く。
 * 画面を増やすときは create/update の配列にこのメソッドの戻り値をマージするだけ。
 *
 * ルール：
 *   - 新規に作るとき     … assigned_by / assigned_at ＝ 操作した人・いま
 *   - 「仮 → 確定」のとき … confirmed_by / confirmed_at ＝ 操作した人・いま
 *   - 「確定 → 仮」のとき … confirmed_by / confirmed_at ＝ 空に戻す
 *   - すでに確定の行を確定で保存し直したとき … 確定の記録は動かさない（最初の確定を残す）
 */
class AssignmentStamp
{
    /** 新しいアサイン行を作るときに足す項目。 */
    public static function forCreate(?string $status): array
    {
        $now = Carbon::now();
        $by = Auth::id();

        $attrs = [
            'assigned_by' => $by,
            'assigned_at' => $now,
        ];

        if ($status === '確定') {
            $attrs['confirmed_by'] = $by;
            $attrs['confirmed_at'] = $now;
        }

        return $attrs;
    }

    /**
     * 既存のアサイン行を更新するときに足す項目。
     *
     * $newStatus が null＝「状態は変えない」＝確定の記録も触らない。
     * assigned_at（最初にアサインした時刻）はここでは動かさない。
     */
    public static function forUpdate(Assignment $existing, ?string $newStatus): array
    {
        if ($newStatus === null || $newStatus === $existing->status) {
            return [];
        }

        if ($newStatus === '確定') {
            return [
                'confirmed_by' => Auth::id(),
                'confirmed_at' => Carbon::now(),
            ];
        }

        // 確定を仮（またはキャンセル）に戻した＝確定の記録は消す。
        return [
            'confirmed_by' => null,
            'confirmed_at' => null,
        ];
    }
}
