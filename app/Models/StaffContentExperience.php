<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * コンテンツ経験。テーブルは staff_content_experience。設計書8章に対応。
 * total_count（経験回数の合計）は保存せず、auto_count＋manual_adjust を都度計算する。
 */
class StaffContentExperience extends Model
{
    protected $table = 'staff_content_experience';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_date' => 'date',
        ];
    }

    /** 合計経験回数＝自動集計＋手動補正（保存しない・都度計算）。 */
    public function getTotalCountAttribute(): int
    {
        return (int) $this->auto_count + (int) $this->manual_adjust;
    }

    /** スタッフ。people を参照。 */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'staff_id');
    }

    /** コンテンツ。contents を参照。 */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'content_id');
    }
}
