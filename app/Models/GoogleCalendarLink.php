<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 人ごとのGoogleカレンダーとのつながり（2026-11-02）。書き込みは GoogleCalendarController からだけ。
 * ⚠ refresh_token は暗号化して保存する（画面やJSONに出さない＝$hidden）。
 */
class GoogleCalendarLink extends Model
{
    protected $primaryKey = 'person_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['refresh_token'];

    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
            'synced_at' => 'datetime',
        ];
    }
}
