<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * セールスの新人のOJTを2人に（2026-10-19 baba「セールスのOJTはイベプラとセールスのOJTがつく」）。
 * rookie_ojt_sales_id＝セールス側のOJT担当（people.id）。イベプラ側は今までの rookie_ojt_id のまま。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('people', 'rookie_ojt_sales_id')) {
            Schema::table('people', function (Blueprint $table) {
                $table->string('rookie_ojt_sales_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('people', 'rookie_ojt_sales_id')) {
            Schema::table('people', function (Blueprint $table) {
                $table->dropColumn('rookie_ojt_sales_id');
            });
        }
    }
};
