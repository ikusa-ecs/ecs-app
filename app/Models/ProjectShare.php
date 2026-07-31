<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 案件の拠点間共有（設計書19.2）。テーブルは project_shares。
 * 「登録拠点ではない拠点が、この案件にヘルプ／巻き取りで関わっている」という1関係。
 */
class ProjectShare extends Model
{
    protected $table = 'project_shares';

    protected $guarded = [];

    /** 対象案件（おおもと）。 */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
