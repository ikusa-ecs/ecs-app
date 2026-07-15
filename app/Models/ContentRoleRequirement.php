<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * コンテンツ別・規模別の必要ポジション人数。テーブルは content_role_requirements。
 */
class ContentRoleRequirement extends Model
{
    protected $table = 'content_role_requirements';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'count' => 'integer',
            'patrol' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
