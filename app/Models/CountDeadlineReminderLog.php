<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 人数確定リマインドの送信済み記録。二度送りを防ぐための重複防止に使う。
 * テーブルは count_deadline_reminder_logs。
 */
class CountDeadlineReminderLog extends Model
{
    protected $table = 'count_deadline_reminder_logs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'sent_at' => 'datetime',
        ];
    }
}
