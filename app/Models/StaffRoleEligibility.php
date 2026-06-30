<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ポジション可否（スタッフ×役割の「できる」）。テーブルは staff_role_eligibility。
 */
class StaffRoleEligibility extends Model
{
    protected $table = 'staff_role_eligibility';

    protected $guarded = [];
}
