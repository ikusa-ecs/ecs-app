<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 「アサインの候補に出す社員／出さない社員」を分ける印（2026-08-26 baba要望）。
 *
 * 【なぜ要るか】
 * 社員名簿には、現場のアサインに入る人と入らない人（管理部門・営業だけの人など）が
 * 混ざっている。それがアサインの画面に全員並ぶので、探しにくく取り違えの元になっていた。
 *
 * ・true（既定）… 今までどおり、アサインの候補として画面に出る
 * ・false        … 社員の出勤可能日の一覧・D決め・D/SD/物品担当のプルダウンに出さない
 *
 * ⚠ 名簿から消すわけではない。名簿・集計・営業担当のプルダウンには今までどおり出る
 *   （営業だけの人も「営業担当」には選べないと困るため）。
 * ⚠ すでにD/SD/FC・物品担当に入っている人は、対象外にしても**その画面には残す**。
 *   候補から消すと「いま画面に出ている人で上書き」する保存で担当が外れてしまうため。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->boolean('in_assign_pool')->default(true)->after('is_spot');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('in_assign_pool');
        });
    }
};
