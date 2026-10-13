<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * スタッフ別アサイン（請求書チェック）の印。1行＝1人×1か月（2026-10-13 baba）。
 * 書き込みは App\Http\Controllers\StaffInvoiceCheckController からだけ。
 */
class StaffInvoiceCheck extends Model
{
    protected $table = 'staff_invoice_checks';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'checked_at' => 'datetime'];
    }
}
