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
            'event_time_tbd' => 'boolean',
            'prep_line_sent' => 'boolean',
            'prep_line_created' => 'boolean',
            'prep_line_double_check' => 'boolean',
            'prep_handover' => 'boolean',
            'prep_script' => 'boolean',
            'staff_published' => 'boolean',
            'is_archived' => 'boolean',   // 手動アーカイブ（null=自動判定）
            // イベント数に数えるか（null=自動判定／true=数える／false=数えない・先-2）
            'count_as_event' => 'boolean',
            'is_cancelled' => 'boolean',   // キャンセルになった案件（2026-08-26）
        ];
    }

    /**
     * 編集履歴（先-1／2026-08-18）を残すための入口。
     * 案件を書き換える画面はどれもここを通るので、画面ごとの書き足しは要らない。
     * 中身は App\Support\ProjectHistoryRecorder に置く（このモデルは薄いままにする）。
     */
    protected static function booted(): void
    {
        // 会場住所（location）から「都道府県・市区町村」を切り出して写す（2026-09-03）。
        // ⚠ location は自由記述の1本なので、そのままでは場所で探せない
        //   （体験先さがし /taiken-search で「千葉県流山市の近く」を出したい）。
        // ⚠ 切り出し方の正本は App\Support\AddressParts。ここには書かない。
        // ⚠ 都道府県が書かれていない住所は空のまま（推測で埋めない＝間違いに気づけなくなる）。
        // ⚠ 保存する画面がいくつもあるので、入口はここ1つにする（画面ごとに書くと片方だけ抜ける）。
        static::saving(function (Project $project) {
            if (! $project->isDirty('location') && $project->exists) {
                return;
            }
            $parts = \App\Support\AddressParts::of($project->location);
            $project->prefecture = $parts['prefecture'] !== '' ? $parts['prefecture'] : null;
            $project->city = $parts['city'] !== '' ? $parts['city'] : null;
        });

        static::created(fn (Project $project) => ProjectHistoryRecorder::recordCreated($project));
        static::updated(fn (Project $project) => ProjectHistoryRecorder::recordUpdated($project));
        static::deleted(fn (Project $project) => ProjectHistoryRecorder::recordDeleted($project));

        // Googleカレンダーの「直す必要がある」印（2026-08-27）。中身は CalendarSyncQueue に置く。
        // ⚠ ここで Google へは送らない。送るのは別のタイミング。
        //   通信が遅い・失敗したときに案件の保存そのものが止まらないようにするため。
        static::saved(fn (Project $project) => \App\Support\CalendarSyncQueue::markSaved($project));
        // ⚠ 削除は「消えたあと」に呼ばれる。予定IDは待ち行列に残っているので、それを頼りに消す。
        static::deleted(fn (Project $project) => \App\Support\CalendarSyncQueue::markDeleted($project));
    }

    /**
     * キャンセルになっていない案件だけ。
     *
     * 中止になった案件を「これからの仕事」として並べる画面（アサイン表・D決め・
     * 公開ボード・日別ボード・スタッフ画面など）で使う。
     * ⚠ 案件一覧・収支・編集履歴には出す（記録なので隅すと見つからなくなる。
     *   とくに収支はキャンセル料が発生する）。
     */
    public function scopeNotCancelled($query)
    {
        return $query->where(function ($q) {
            $q->where('is_cancelled', false)->orWhereNull('is_cancelled');
        });
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
