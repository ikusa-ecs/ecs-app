<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 案件。テーブルは projects。設計書8章の projects に対応。
 */
class Project extends Model
{
    protected $table = 'projects';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'content_ids' => 'array',
            'content_names' => 'array',
            'sales_owners' => 'array',
            'base_locations' => 'array',
            'arena_options' => 'array',
            'start_date' => 'date',
            'extra_published_at' => 'date',
            'is_recruiting' => 'boolean',
            'is_multi' => 'boolean',
            'is_outdoor' => 'boolean',
            'is_toc' => 'boolean',
            'count_tentative' => 'boolean',
            'team_tentative' => 'boolean',
            'is_repeat' => 'boolean',
            'alcohol' => 'boolean',
            'prep_line_sent' => 'boolean',
            'prep_handover' => 'boolean',
            'prep_script' => 'boolean',
            'staff_published' => 'boolean',
            'is_archived' => 'boolean',   // 手動アーカイブ（null=自動判定）
        ];
    }

    /** ディレクター（社員）。people を参照。 */
    public function director(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'director_id');
    }

    /** SD（サブディレクター・社員）。people を参照。 */
    public function subDirector(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'sd_id');
    }

    /** 物品担当（社員）。people を参照。 */
    public function goodsOwner(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'goods_owner_id');
    }

    /** 拠点間の共有（ヘルプ／巻き取りで関わっている拠点）。全拠点運用・設計書19.2。 */
    public function shares(): HasMany
    {
        return $this->hasMany(ProjectShare::class, 'project_id');
    }
}
