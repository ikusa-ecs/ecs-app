<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * スタッフの手当の記録（2026-10-13 baba「運転手当・スライド手当・事前レクチャー手当を社員に追加してもらいたい」）。
 * アサインからは分からない手当だけを、社員が手で入れる（前泊・ロング・MC・前日設営・リハは自動で数える）。
 * 1行＝1人の1回ぶん（回数 count で同じ日の2回もまとめられる）。種類と単価の正本＝App\Support\StaffAllowances。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_allowances')) {
            return;
        }
        Schema::create('staff_allowances', function (Blueprint $table) {
            $table->id();
            $table->string('staff_id', 40);
            $table->string('project_id', 40)->nullable();   // どの案件の手当か（無くてもよい）
            $table->date('date');                           // 手当が付いた日（レクチャーなら実施した日）
            $table->string('kind', 40);                     // 運転手当／スライド手当／事前レクチャー手当／その他
            $table->unsignedSmallInteger('count')->default(1);
            $table->unsignedInteger('amount')->nullable();  // 1回の金額（その他のときだけ手で入れる）
            $table->string('note', 200)->nullable();
            $table->string('created_by', 100)->nullable();
            $table->timestamps();
            $table->index(['staff_id', 'date']);
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_allowances');
    }
};
