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
            'patrol' => 'integer',
            'assigned_at' => 'datetime',
            'confirmed_at' => 'datetime',
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

    /**
     * Googleカレンダーの「直す必要がある」印（2026-08-27）。
     *
     * ⚠ これが要る理由＝**Dが決まったらカレンダーに招待を足す**決まりにしたため（baba選択）。
     *   Dが変わる入口は5か所あるが（D決め画面／案件一覧のセル／保守コマンド／取込×2）、
     *   どの入口も最後は assignments に書くので、ここ1か所で拾える。
     * ⚠ ここで Google へは送らない（印を付けるだけ）。
     */
    protected static function booted(): void
    {
        static::saved(fn (Assignment $a) => \App\Support\CalendarSyncQueue::markAssignmentChanged($a));
        static::deleted(fn (Assignment $a) => \App\Support\CalendarSyncQueue::markAssignmentChanged($a));
    }

    /** 確定だけを取り出すクエリスコープ（回数集計などに使う）。 */
    public function scopeConfirmed($query)
    {
        return $query->where('status', '確定');
    }

    /**
     * キャンセルになった案件のアサインを外す（2026-10-21 baba決定）。
     *
     * 案件をキャンセルにしても、入っていた人の記録は消さない（別の案件へお引越しするときの参考にするため）。
     * そのかわり、**人の回数・稼働率・同じ日の重なり・月の上限など、数えるところでは必ずこれを通す**。
     * ⚠ 一覧に「案件中止」と出して見せたい画面（請求書チェック・社員別アサイン一覧）は、件数だけこれで数える。
     * ⚠ アサインの status 'キャンセル'（人を外した）とは別物。こちらは案件ごとのキャンセル（projects.is_cancelled）。
     */
    public function scopeOnLiveProject($query)
    {
        return $query->whereNotIn($query->qualifyColumn('project_id'),
            Project::query()->select('id')->where('is_cancelled', true));
    }
}
