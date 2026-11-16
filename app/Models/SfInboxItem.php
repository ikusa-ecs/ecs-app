<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SFの商談の受信箱。テーブルは sf_inbox_items（2026-11-16）。
 * ⚠ ここに入っただけでは案件（projects）は変わらない。登録は App\Support\SfInboxRegister。
 */
class SfInboxItem extends Model
{
    protected $table = 'sf_inbox_items';

    protected $guarded = [];

    /** 取り込み待ち。 */
    public const WAITING = 'waiting';

    /** 案件として登録ずみ。 */
    public const REGISTERED = 'registered';

    /** 見送り（消さずに残す）。 */
    public const SKIPPED = 'skipped';

    public const LABELS = [
        self::WAITING => '取り込み待ち',
        self::REGISTERED => '登録ずみ',
        self::SKIPPED => '見送り',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'decided_at' => 'datetime',
            'is_sample' => 'boolean',
        ];
    }
}
