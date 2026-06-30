<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 案件応募。テーブルは applications。設計書8章 applications に対応。
 * 「スタッフ × 案件」を1行で持つ＝本人が個別案件に応募したデータの単一ソース。
 * 「選ばれた率」（応募件数のうち実アサインされた割合）の元になる。
 */
class Application extends Model
{
    protected $table = 'applications';

    // id は自動採番（integer）。FK（staff_id/project_id）だけ文字列。
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'applied_at' => 'datetime',
        ];
    }

    /** スタッフ。people を参照。 */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'staff_id');
    }

    /** 応募先の案件。projects を参照。 */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
