<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 収支未入力リマインドの送信済み記録。二度催促しないための重複防止に使う。
 * テーブルは finance_reminder_logs。
 */
class FinanceReminderLog extends Model
{
    protected $table = 'finance_reminder_logs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'deadline' => 'date',
            'sent_at' => 'datetime',
        ];
    }
}
