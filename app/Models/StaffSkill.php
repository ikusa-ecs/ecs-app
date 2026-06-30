<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * スタッフ保有スキル（中間）。テーブルは staff_skills。設計書8章に対応。
 * people（スタッフ）× skills（スキル）を結ぶ1行。
 */
class StaffSkill extends Model
{
    protected $table = 'staff_skills';

    protected $guarded = [];

    /** スタッフ。people を参照。 */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'staff_id');
    }

    /** スキル。skills を参照。 */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class, 'skill_id');
    }
}
