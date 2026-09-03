<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 案件ごとの「派遣依頼」（2026-09-03 baba要望）。
 *
 * ⚠ それまで日別ボードの「＋派遣」は**画面の中だけ**で、DBに何も残っていなかった
 *   ＝押しても読み込み直すと消え、「どの案件に派遣を頼んだか」の記録が無かった。
 *
 * 1行＝1つの案件に対する、1つの派遣先への依頼（同じ案件で2社に頼めば2行）。
 * ⚠ 名簿（people）には入れない。理由はマイグレーションのコメント。
 * ⚠ 状態の言葉は App\Support\DispatchStatus が正本。画面に直書きしないこと。
 */
class ProjectDispatch extends Model
{
    protected $guarded = [];

    protected $casts = [
        'count' => 'int',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** キャンセル以外＝いま生きている依頼。人数を数えるときはこれで絞る。 */
    public function scopeLive($query)
    {
        return $query->where('status', '!=', 'キャンセル');
    }
}
