<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * NGペア（同じ現場を避ける組合せ）。テーブルは staff_relations。
 */
class StaffRelation extends Model
{
    protected $table = 'staff_relations';

    protected $guarded = [];
}
