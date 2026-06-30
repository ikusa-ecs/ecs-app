<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * コンテンツ・マスタ（催し物の種類）。テーブルは contents。
 */
class Content extends Model
{
    protected $table = 'contents';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_physical' => 'boolean',
            'active' => 'boolean',
        ];
    }
}
