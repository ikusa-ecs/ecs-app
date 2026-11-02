<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** ECSがGoogleカレンダーに登録した予定の控え（uid → Googleの予定ID・2026-11-02）。 */
class GoogleCalendarEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['case_date' => 'date'];
    }
}
