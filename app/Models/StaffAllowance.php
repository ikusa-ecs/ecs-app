<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * スタッフの手当の記録（運転・スライド・事前レクチャー・その他）。2026-10-13 baba。
 * 書き込みは App\Http\Controllers\StaffAllowanceController からだけ。種類と単価の正本＝App\Support\StaffAllowances。
 */
class StaffAllowance extends Model
{
    protected $table = 'staff_allowances';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'date', 'count' => 'integer', 'amount' => 'integer'];
    }
}
