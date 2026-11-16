<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salesforce（SF）の商談の受信箱（2026-11-16）。
 *
 * 【決まり＝受信箱方式】SFの商談はまずここに入るだけ。
 *   人が「案件として登録」を押したときに初めて projects に1件作る。それまで案件は1文字も変えない。
 *   「見送り」にしても消さずに残す（あとで「取り込み待ちに戻す」ができる）。
 *
 * ⚠ 受け取った中身（payload）はSFから来たままのJSONで持つ。
 *   ECSの項目への読み替えは App\Support\SfOpportunityMap の1か所だけで行う
 *   （SF側の項目が固まったら、そこだけ直せばよいように）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sf_inbox_items', function (Blueprint $table) {
            $table->id();
            $table->string('sf_opportunity_id', 18)->unique();      // SFの商談Id（18桁）＝同じ商談は1行だけ
            $table->longText('payload');                            // 受け取った中身（SFの項目名のままのJSON）
            $table->string('status', 16)->default('waiting');       // waiting=取り込み待ち / registered=登録ずみ / skipped=見送り
            $table->string('project_id')->nullable();               // 登録した案件のID（projects.id）
            $table->string('owner_email')->nullable();              // 担当営業のメール（Owner.Email）
            $table->timestamp('received_at')->nullable();           // 受け取った日時
            $table->string('decided_by')->nullable();               // 登録・見送りを押した人（people.id）
            $table->timestamp('decided_at')->nullable();            // 押した日時
            $table->boolean('is_sample')->default(false);           // 見本データの印（本物と見分ける・消すコマンドで使う）
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sf_inbox_items');
    }
};
