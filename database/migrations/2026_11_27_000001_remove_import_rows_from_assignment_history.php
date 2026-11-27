<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 編集履歴の「アサイン」ページから、11/25 の過去アサイン表の取込でまとめて入ったぶんを消す
 * （小沼さん 11/27「1,627件の履歴は消していいよ」）。
 *
 * 【なぜ要るか】11/25 のアサインを後から履歴に起こしたとき（2026_11_25_000001）、同じ日に
 *   「Claude確認用」のアカウントで流した名古屋の過去アサイン表の取込（30か月ぶん）も
 *   1件ずつ「入れた」として出てしまった。人が手で入れたアサインがうもれて読めない。
 *
 * 【消すもの】出どころが「後から起こした記録（11/25のぶん）」で、入れた人が「Claude確認用」の行だけ
 *   （本番で 11/27 に数えて 1,627件）。
 * 【消さないもの】同じ日に社員が手で入れたぶん（48件）・11/26 からあとの本物の履歴・アサインそのもの。
 *   ＝消すのは履歴の行だけ。だれがどの案件に入っているかは変わらない。
 * ⚠ 元には戻せない（down は何もしない）。2回流しても、もう無いので何も起きない。
 */
return new class extends Migration
{
    private const SOURCE = '後から起こした記録（11/25のぶん）';
    private const PERSON_NAME = 'Claude確認用';

    public function up(): void
    {
        if (! Schema::hasTable('project_histories') || ! Schema::hasColumn('project_histories', 'source')) {
            return;
        }

        DB::table('project_histories')
            ->where('field', 'assignment')
            ->where('source', self::SOURCE)
            ->where('person_name', self::PERSON_NAME)
            ->delete();
    }

    public function down(): void
    {
        // 消した履歴は戻せない。
    }
};
