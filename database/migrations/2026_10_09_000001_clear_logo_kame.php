<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ロゴに「カメ」と入ってしまった案件を空に戻す（2026-10-09 baba了承）。
 *
 * アサイン表の「ロゴ [ ] カメ [ ] 記事 [ ] 動画」で、ロゴが空だと見出しの「カメ」をロゴとして
 * 読んでいた（0693ca7 で直した）。直す前の取込（2026-10-08 12:38 など）で本番に入ってしまったぶんを片づける。
 * ⚠ 「カメ」はロゴの値としてありえない（不要・ほしい・OK・NG・- のどれか）ので、まとめて空にしてよい。
 * ⚠ 取込は「シートが空ならECSの値を消さない」ので、放っておくと残り続ける。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('projects')->where('pub_logo', 'カメ')->update(['pub_logo' => null]);
    }

    public function down(): void
    {
        // 戻さない（間違った値を戻す理由がない）。
    }
};
