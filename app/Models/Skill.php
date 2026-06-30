<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * スキルマスタ。テーブルは skills。設計書8章のテーブル一覧に対応。
 * staff_skills を中間表にしてスタッフ（people）と多対多。
 */
class Skill extends Model
{
    protected $table = 'skills';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /** このスキルを持つスタッフ（people）。中間表 staff_skills 経由。 */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'staff_skills', 'skill_id', 'staff_id')
            ->withPivot('note')
            ->withTimestamps();
    }
}
