<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 案件の編集履歴の1行（先-1／2026-08-18）。テーブルは project_histories。
 * 1行＝1項目の変更。「いつ・誰が・どの項目を・何から何に」変えたかを持つ。
 *
 * 書き込みは App\Support\ProjectHistoryRecorder からのみ（画面から直接は作らない）。
 */
class ProjectHistory extends Model
{
    protected $table = 'project_histories';

    protected $guarded = [];

    /** どの案件か（消された案件を指すこともあるので、表示は project_name も併用する）。 */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
