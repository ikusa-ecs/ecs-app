<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 「コンテンツ難易度」シートの1行（2026-10-09 baba・新人ページ）。
 * kind＝リアル／オンライン。content_id＝つないだECSのコンテンツ（空＝まだつないでいない＝使わない）。
 * 正本＝App\Support\RookieDifficulty。
 */
class ContentDifficulty extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'difficulty' => 'integer',
            'must' => 'boolean',
            'recommend' => 'boolean',
            'one_of' => 'boolean',
        ];
    }
}
