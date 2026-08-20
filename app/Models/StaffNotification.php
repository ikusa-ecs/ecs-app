<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * スタッフへ送った通知の記録。テーブルは staff_notifications。
 * 同じ知らせを二度送らないための dedup_key と、「誰にいつ送ったか」の証跡を持つ。
 */
class StaffNotification extends Model
{
    protected $table = 'staff_notifications';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'sent_at' => 'datetime',
        ];
    }
}
