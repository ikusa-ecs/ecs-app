<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * アサイン（割り当て）。テーブルは assignments。設計書8章 assignments に対応。
 * 「案件 × スタッフ × 日」を1行で持つ＝割り当ての単一ソース。
 * 稼働状況（今月のアサイン数・連勤・ご無沙汰）や同日ダブルブッキング検知の元になる。
 */
class Assignment extends Model
{
    protected $table = 'assignments';

    // id は自動採番（integer）。FK（project_id/staff_id）だけ文字列。
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'score' => 'decimal:2',
            'assigned_at' => 'datetime',
        ];
    }

    /** 案件。projects を参照。 */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** スタッフ。people を参照。 */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'staff_id');
    }

    /** 確定だけを取り出すクエリスコープ（回数集計などに使う）。 */
    public function scopeConfirmed($query)
    {
        return $query->where('status', '確定');
    }
}
