<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 謎解きの紙（印刷物）の在庫。テーブルは content_paper_stocks。
 * 「入庫数（手入力）」だけを保存する（必要数・消費数は案件から自動計算）。
 */
class ContentPaperStock extends Model
{
    protected $table = 'content_paper_stocks';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'received_count' => 'integer',
        ];
    }
}
