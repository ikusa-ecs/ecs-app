<?php

use App\Support\CountDeadlineReminderService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 手動でも動かせるコマンド（テスト・確認用）: php artisan reminder:count-deadline {dry|test|live}
Artisan::command('reminder:count-deadline {mode=dry}', function (string $mode) {
    $r = (new CountDeadlineReminderService())->run($mode);
    $this->info(($r['title'] ?? '') . ' / 対象' . ($r['hit'] ?? 0) . '件');
})->purpose('人数確定リマインドを実行（dry=件数確認 / test / live）');

// 人数確定リマインド：平日の毎朝9時に本番送信（GAS版と同じ運用）。
// ⚠ 実際に動くのは、常時稼働するサーバーで `php artisan schedule:work`（またはcron）が
//    走っているときだけ。ローカルの `php artisan serve` だけでは自動送信されない（＝デプロイ後に有効化）。
Schedule::call(function () {
    (new CountDeadlineReminderService())->run('live');
})->weekdays()->at('09:00')->timezone('Asia/Tokyo')->name('count-deadline-reminder');
