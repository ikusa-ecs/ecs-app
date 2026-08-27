<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Googleカレンダー連携の「同期の待ち行列」1行＝1案件。テーブルは calendar_syncs。
 *
 * ⚠ 書き込みは App\Support\CalendarSync（係）からだけ。画面から直接作らない。
 */
class CalendarSync extends Model
{
    protected $table = 'calendar_syncs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'needs_sync' => 'boolean',
            'needs_delete' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    /** 案件。projects を参照。 */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
