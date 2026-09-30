<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 取込で「シートの100行目のIDと違う案件」につないだ記録（2026-09-30）。
 *
 * 【なぜ要るか】毎朝の書き戻しは「シートに書いてあるIDが合っていれば触らない」にした
 *   （ブロックを並べ替えるとIDはブロックと一緒に動くが、前回の「列 → ID」の記録は古い位置のまま＝
 *    記録を先に使うと、同じ日のブロックを入れ替えたときに正しいIDを上書きしてしまうため）。
 *   そのままだと、下見で**わざと別の案件につなぎ直した**ときに、シートの古いIDが書き換わらない。
 *
 * 【形】{ "列番号(0はじまり)": {"from": "P-2026-0010", "to": "P-2026-0315"} }
 *   書き戻しでは「その列にまだ from が書いてある」ときだけ to にする（ブロックが動いていたら触らない）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheet_syncs', function (Blueprint $table) {
            $table->json('id_changes')->nullable()->after('project_ids');
        });
    }

    public function down(): void
    {
        Schema::table('sheet_syncs', function (Blueprint $table) {
            $table->dropColumn('id_changes');
        });
    }
};
