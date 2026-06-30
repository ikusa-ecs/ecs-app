<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 稼働希望。テーブルは shift_preferences。設計書8章 shift_preferences に対応。
 * 「スタッフ × 1日」を1行で持つ＝本人が出した可否の単一ソース。
 * 稼働率（アサイン日数 ÷ 希望日数）や活性度区分の計算の元になる。
 */
class ShiftPreference extends Model
{
    protected $table = 'shift_preferences';

    // id は自動採番（integer）。FK（staff_id）だけ文字列。
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    /** スタッフ。people を参照。 */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'staff_id');
    }

    /** 「稼働可」または「希望」の日だけ＝稼働率の分母（希望日数）に数える行。 */
    public function scopeAvailable($query)
    {
        return $query->whereIn('availability', ['稼働可', '希望']);
    }
}
