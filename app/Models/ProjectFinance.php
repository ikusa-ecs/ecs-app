<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 案件の収支（売上・経費明細・メモ）。テーブルは project_finances（案件1件＝1行）。
 * items は経費明細のJSON（費目キー→数量/金額）。マイページ収支入力の保存先。
 */
class ProjectFinance extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'revenue' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
