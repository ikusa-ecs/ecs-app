<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * アサイン表の「受信箱」（2026-09-10 baba要望）。
 *
 * 【なぜ要るか】
 * ECSとアサイン表の二重管理をやめたいが、いきなり片方を消すことはできない。
 * そこで**毎朝アサイン表の中身をECSへ送ってもらい、変わったところだけ人が承認して反映する**。
 *
 * ⚠ 届いた時点では**ECSのデータを1文字も変えない**。ここに置くだけ。
 *   勝手に反映すると、ECS側で人が直した内容を機械が上書きしてしまう。
 *   （Salesforce連携で決めた「受信箱方式」と同じ考え方に合わせる）
 *
 * 【1行＝1つの月のタブ】
 * アサイン表は「1ファイル＝2か月ぶん」で、月ごとのタブ（202610 のような名前）に分かれている。
 * 同じタブが毎朝届くので、**月ごとに1行だけ持って上書きしていく**（履歴は溜めない）。
 * 溜めない理由＝見るのは「いまのシートとECSの違い」だけで、過去の受信内容に用が無いため。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheet_syncs', function (Blueprint $table) {
            $table->id();

            // どこから届いたか。'gas'＝毎朝の自動／'manual'＝画面から手で入れたもの。
            $table->string('source', 10)->default('gas');

            // シートの居場所（人が「どのファイルの話か」を分かるように残す）。
            $table->string('book')->nullable();       // ファイル名（例 2026年東京アサイン表9月～10月）
            $table->string('tab', 40)->nullable();    // タブ名（例 202610）

            // 何年何月ぶんか（例 2026-10）。タブ名から読む。
            $table->string('period', 7);
            // どの拠点の案件として入れるか（当面は東京のみ。福岡は対象外＝2026-09-10 baba）。
            $table->string('office', 20)->default('東京');

            // 届いたシートの中身そのまま（CSVと同じ「行×列」の形）。
            // ⚠ ここを持っておくことで、反映は**既存のアサイン表取込とまったく同じ道**を通せる
            //   （読み取りの決まりを2つ持たない）。
            $table->json('rows');

            // 読めた案件の数（一覧に出す目安）。
            $table->unsignedInteger('case_count')->default(0);

            // 中身の指紋。前回届いたものと同じかどうかを一瞬で見るために持つ。
            $table->string('fingerprint', 64)->nullable();

            $table->timestamp('received_at')->nullable();   // 最後に届いた日時
            $table->timestamp('changed_at')->nullable();    // 中身が最後に変わった日時
            $table->timestamp('applied_at')->nullable();    // 最後にECSへ反映した日時
            $table->string('applied_by', 40)->nullable();   // 反映した人（people.id）

            $table->timestamps();

            // 月×拠点で1行（同じタブが毎朝届いても増えない）。
            $table->unique(['office', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheet_syncs');
    }
};
