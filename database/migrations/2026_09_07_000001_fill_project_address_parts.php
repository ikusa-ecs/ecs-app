<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * 既存の案件の「都道府県・市区町村」を、会場住所（location）から埋める（2026-09-07）。
 *
 * 【なぜ migration にしたか】
 * 中身は `php artisan ecs:fill-project-address` と**まったく同じ**。
 * ただ、本番へ反映する作業は**ボタン1つ**（git pull → composer install → migrate → optimize）で、
 * そこに artisan コマンドを足すにはエンジニアに依頼が要る。
 * migrate はボタンの中に**もともと入っている**ので、ここに置けば
 * **ボタンを押すだけで自動的に流れる**（2026-09-07 baba）。
 *
 * ⚠ 中身は書き写さず、コマンドを**呼ぶだけ**にしている。
 *   切り出し方を直すときに2か所を直す羽目になり、食い違うのを防ぐため。
 *   正本＝`App\Console\Commands\FillProjectAddress`（さらにその中身は `App\Support\AddressParts`）。
 *
 * ⚠ 何度流しても安全。すでに入っている案件は飛ばす／保存イベントを通さない
 *   （＝編集履歴に「誰かが直した」と出ない）／都道府県が読めない住所は空のまま。
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('ecs:fill-project-address');
    }

    public function down(): void
    {
        // 何もしない。
        // ⚠ ここで都道府県・市区町村を空に戻すと、戻したあとに手で直した内容まで消える。
        //    列そのものを消すのは 2026_09_03_000003 の down の役目。
    }
};
