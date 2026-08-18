<?php

namespace App\Models;

use App\Support\ProjectHistoryRecorder;
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

    /**
     * 編集履歴（先-1／2026-08-18）を残すための入口。
     * 案件を書き換える画面はどれもここを通るので、画面ごとの書き足しは要らない。
     * 中身は App\Support\ProjectHistoryRecorder に置く（このモデルは薄いままにする）。
     */
    protected static function booted(): void
    {
        static::created(fn (Project $project) => ProjectHistoryRecorder::recordCreated($project));
        static::updated(fn (Project $project) => ProjectHistoryRecorder::recordUpdated($project));
        static::deleted(fn (Project $project) => ProjectHistoryRecorder::recordDeleted($project));
    }

    /** 編集履歴（新しい順に取り出すのは呼び出し側で）。 */
    public function histories(): HasMany
    {
        return $this->hasMany(ProjectHistory::class, 'project_id');
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
