<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 月まとめ自動アサインの「1回ぶん」の記録（2026-09-07）。テーブルは auto_assign_runs。
 *
 * ⚠ これがあるおかげで「この回に入れたぶんだけ取り消す」ができる。
 *   1か月ぶんをまとめて入れる操作は取り返しがつきにくく、戻せないと誰も押せない。
 *   入れたアサインは assignments.auto_run_id にこの id を持つ。
 */
class AutoAssignRun extends Model
{
    protected $table = 'auto_assign_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'added' => 'integer',
            'undone' => 'integer',
        ];
    }
}
