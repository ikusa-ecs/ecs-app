<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 謎解きの紙の追加発注（テーブル paper_orders・2026-10-15）。
 * arrived_on が空＝発注中。「届いた」を押すと、その拠点の入庫数（content_paper_stocks）に部数を足す。
 */
class PaperOrder extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'count' => 'integer',
            'ordered_on' => 'date',
            'expected_on' => 'date',
            'arrived_on' => 'date',
        ];
    }

    public function scopePending($q)
    {
        return $q->whereNull('arrived_on');
    }
}
