<?php

use App\Support\CountDeadlineReminderService;
use App\Support\SheetSyncNotice;
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

// ──────────────────────────────────────────────────────────────────────
// アサイン表の自動取り込みの見張り（2026-09-15 baba要望）。
//
// ⚠ **これが本命。** 毎朝の取り込みは GAS（スプレッドシート側の仕掛け）が動かしていて、
//   GASが止まっても失敗の知らせは「作った人の個人メール」にしか届かない。
//   ＝作った人が休み・異動・退職すると、**誰も気づかないまま止まり続ける**。
//   届いたときの報告（POST /sheet-sync の kind=report）だけでは、
//   GASが丸ごと止まった日は「何も来ない」だけなので気づけない。
//   そこで ECS 側から「今朝のぶんが届いていない」を見張って、チャットワークに出す。
//
// ⚠ 平日だけ見る（土日はアサイン表を触らないので、毎週2回の空振りを出さない）。
// ⚠ 実際に動くのは、サーバーで `php artisan schedule:work`（またはcron）が
//    走っているときだけ。ローカルの `php artisan serve` では動かない。
// 手動でも試せる： php artisan sheet-sync:watch
Artisan::command('sheet-sync:watch', function () {
    if (SheetSyncNotice::receivedToday()) {
        $this->info('今朝のアサイン表は届いています。');

        return;
    }

    $sent = SheetSyncNotice::missingWarning(SheetSyncNotice::lastReceivedAt());
    $this->warn('今朝のアサイン表が届いていません。'
        .($sent ? 'チャットワークに知らせました。' : 'チャットワークの設定が無いので知らせていません。'));
})->purpose('今朝アサイン表が届いたかを見て、届いていなければチャットワークに知らせる');

Schedule::call(function () {
    if (! SheetSyncNotice::receivedToday()) {
        SheetSyncNotice::missingWarning(SheetSyncNotice::lastReceivedAt());
    }
})->weekdays()->at(SheetSyncNotice::EXPECTED_BY)->timezone('Asia/Tokyo')->name('sheet-sync-watch');
