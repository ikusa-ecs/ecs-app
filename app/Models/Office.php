<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 拠点（事務所）マスタ。テーブルは offices。
 * people.office の選択肢の元データ。
 */
class Office extends Model
{
    protected $table = 'offices';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }
}
