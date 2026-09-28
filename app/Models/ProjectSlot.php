<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * アサイン表の「まだ人が決まっていない枠」（2026-09-28 baba要望）。
 *
 * 1行＝1つの空き枠。ポジション・担当メモ・巡回・備考（IKUSAマスト／派遣でOK 等）を持つ。
 *
 * ⚠ **ここに staff_id は置かない。** 誰がアサインされているかの正本は assignments ひとつ。
 *   人が決まったら、この枠は消して assignments の行に置き換わる。
 * ⚠ 並び順（no）は枠どうしの順番。画面のNOは App\Support\AssignSlots::rows が振り直す。
 */
class ProjectSlot extends Model
{
    protected $guarded = [];

    protected $casts = [
        'no' => 'int',
        'patrol' => 'int',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
