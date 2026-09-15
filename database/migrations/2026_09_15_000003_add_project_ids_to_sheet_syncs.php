<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ECSの案件IDをアサイン表へ書き戻すための対応表（2026-09-15 baba要望）。
 *
 * 【なぜ要るか】
 * いまは「日付・コンテンツ・お客様名」が同じかどうかで、シートの案件とECSの案件を
 * 結び付けている。シート側で名前を直されると別の案件と見なされ、
 * 同じ案件が2つできてしまう。**ECSのIDがシートに書いてあれば確実に分かる。**
 *
 * 【形】{ "列番号(0はじまり)": "P-2026-0012", ... }
 *  ・列番号＝その案件のブロックの左端（MonthlySheetReader が返す 'col'）。
 *  ・中身は「取り込んだ結果そのもの」＝あとで探し直さない
 *    （探し直すと、似ている別の案件のIDを書き戻すことがある）。
 *
 * 【どこに書くか】アサイン表の **100行目**（2026-09-15 baba指定）。
 *  ⚠ 行を増やすとレイアウトが崩れるので増やさない。100行目より下は今も空いている。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheet_syncs', function (Blueprint $table) {
            $table->json('project_ids')->nullable()->after('case_count');
        });
    }

    public function down(): void
    {
        Schema::table('sheet_syncs', function (Blueprint $table) {
            $table->dropColumn('project_ids');
        });
    }
};
