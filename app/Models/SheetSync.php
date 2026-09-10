<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * アサイン表の受信箱の1行（＝1つの月のタブ）。テーブルは sheet_syncs。
 *
 * ⚠ ここに届いても**ECSのデータは変わらない**。反映は人が画面で押したときだけ。
 *   受け取りの入口は SheetSyncController、反映は既存のアサイン表取込を通る。
 */
class SheetSync extends Model
{
    protected $table = 'sheet_syncs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rows' => 'array',
            'case_count' => 'integer',
            'received_at' => 'datetime',
            'changed_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * 反映したあとに中身が変わったか（＝いま見るべきか）。
     *
     * ⚠ 「反映済み」の印だけだと、そのあとシートが直されたことに気づけない。
     *   届いた中身が変わった日時（changed_at）と反映した日時（applied_at）を比べる。
     */
    public function needsAttention(): bool
    {
        if ($this->applied_at === null) {
            return true;    // まだ一度も反映していない
        }

        return $this->changed_at !== null && $this->changed_at->greaterThan($this->applied_at);
    }
}
