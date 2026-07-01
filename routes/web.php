<?php

use App\Http\Controllers\AssignBoardController;
use App\Http\Controllers\AssignDashboardController;
use App\Http\Controllers\AssignDirectorController;
use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\AssignPublishController;
use App\Http\Controllers\AssignWishlistController;
use App\Http\Controllers\CountDeadlineReminderController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MasterController;
use App\Http\Controllers\EmployeeAvailabilityController;
use App\Http\Controllers\MyPageController;
use App\Http\Controllers\MyPageFinanceController;
use App\Http\Controllers\PaperStockController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectsAggController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StaffPortalController;
use App\Http\Controllers\StaffStatusController;
use Illuminate\Support\Facades\Route;

// トップページ＝ECSのログイン画面（Laravelが組み立てるBlade画面）
Route::get('/', function () {
    return view('login');
});
// 新規登録画面（スタッフ・社員ともにここから登録）
Route::get('/register', function () {
    return view('register');
});

// 社員側の画面（Blade化済み）
// ダッシュボードは DB（projects テーブル）から読む。KPI・危険日カレンダーが本物の案件で動く。
Route::get('/dashboard', [DashboardController::class, 'index']);
// 案件一覧は DB（projects テーブル）から読む。Controller が cases.js と同じ形に整える。
Route::get('/projects', [ProjectController::class, 'index']);
// 案件登録／編集フォーム。?project=<案件ID> が来たら既存案件を読み、各欄に埋めて開く。
Route::get('/project-form', [ProjectController::class, 'form']);
// 案件登録フォームの保存先（IDが来れば上書き更新、無ければ新規作成）。
Route::post('/project-form', [ProjectController::class, 'store']);
// 案件一覧の詳細から、ケータリングの種類・メモだけを保存する（公開ボードの時間保存と同じ方式）。
Route::post('/projects/catering', [ProjectController::class, 'saveCatering']);
Route::get('/project-import', function () {
    return view('project_import');
});
// CSV一括取込の保存先（記入済みCSVを読んで projects に複数登録）。
Route::post('/project-import', [ProjectController::class, 'import']);
// 別ウィンドウで開くポップアップ画面（Blade化済み）
// 社員・ディレクター集計（別ウィンドウ）。D決め(/assign-director)の保存先＝assignments(role=D/SD)から集計。
Route::get('/projects-agg', [ProjectsAggController::class, 'index']);
// 人数確定リマインド。イベント2週間前を迎えた案件を拾い、営業＋Dへチャットワークで知らせる。
// GET=対象一覧の表示／POST send=mode(dry件数確認/test/live)で実行。鍵は .env の CHATWORK_TOKEN。
Route::get('/count-reminder', [CountDeadlineReminderController::class, 'index']);
Route::post('/count-reminder/send', [CountDeadlineReminderController::class, 'send']);

// 謎解きの紙 在庫・必要数
Route::get('/paper-stock', [PaperStockController::class, 'index']);
Route::post('/paper-stock/receipts', [PaperStockController::class, 'updateReceipts']);
// 希望まとめ（別ウィンドウ）。対象月の希望者一覧を DB（希望＋アサイン＋ポジション可否）から作る。
Route::get('/assign-wishlist', [AssignWishlistController::class, 'index']);

// アサイン関連の画面（Blade化済み）
// アサインダッシュボード＝担当者向けの状況まとめ。「アサインが必要な案件」だけ本物の案件から作る。
Route::get('/assign-dashboard', [AssignDashboardController::class, 'index']);
// 割当メンバーの仮データに使う名前は DB（people のスタッフ）から渡す（NAME_POOL の単一ソース化）。
Route::get('/assign', [AssignBoardController::class, 'assign']);
// D決め（S-017）。本物の案件＋社員（people）をカレンダーに表示し、D/SDを保存する。
// ※ 2026-06-25 並行作業：サブエージェント2が AssignDirectorController を作成中。
Route::get('/assign-director', [AssignDirectorController::class, 'index']);
Route::post('/assign-director/save', [AssignDirectorController::class, 'save']);
// 社員の出勤可能日（S-018）。社員が自分の参加希望日を入力・保存する。
// ※ 2026-06-25 並行作業：サブエージェント1が EmployeeAvailabilityController を作成中。
Route::get('/employee-availability', [EmployeeAvailabilityController::class, 'index']);
Route::post('/employee-availability/save', [EmployeeAvailabilityController::class, 'save']);
Route::get('/assign-detail', function () {
    return view('assign_detail');
});
// 手動アサインのDB保存（A-2）。案件一覧の「アサイン」から ?project=<案件ID> で開く。
// 本物の案件×本物のスタッフ（people）を assignments テーブルに保存する。
Route::get('/project-assign', [AssignmentController::class, 'show']);
Route::post('/project-assign/save', [AssignmentController::class, 'save']);
// スタッフ公開ボードは DB（projects）から読む。公開ON/OFFは staff_published に保存。
Route::get('/assign-publish', [AssignPublishController::class, 'index']);
Route::post('/assign-publish/set', [AssignPublishController::class, 'setPublish']);
// スタッフ向けの集合・解散時間／スタッフ画面のお知らせ文も DB に保存する。
Route::post('/assign-publish/time', [AssignPublishController::class, 'setTime']);
Route::post('/assign-publish/notice', [AssignPublishController::class, 'setNotice']);
// 仮データの名前は DB（people のスタッフ）から渡す（NAME_POOL の単一ソース化）。
Route::get('/entries', [AssignBoardController::class, 'entries']);
// エントリー一覧「月ごと」の表からの1人ぶんアサイン切替（A案）。assignments に追加/削除する。
Route::post('/entries/assign', [AssignmentController::class, 'quickToggle']);
Route::get('/pickup', [AssignBoardController::class, 'pickup']);

// スタッフ関連の画面（Blade化済み）
// スタッフ名簿は DB（people＋ポジション可否＋NGペア）から読む。
Route::get('/staff', [PersonController::class, 'staff']);
// 社員名簿は DB（people テーブルの社員）から読む。
Route::get('/employees', [PersonController::class, 'employees']);
// 稼働状況は「スタッフ」画面（/staff）の中のタブに統合済み。
// 旧URL・旧リンク（アサインダッシュボード等）から来ても迷子にならないよう /staff へ転送する。
Route::get('/staff-status', fn () => redirect('/staff'));

// スタッフ側の画面（Blade化済み・サイドバー無しの独自レイアウト）。
// 「確定アサイン」と希望カレンダーの確定表示は DB（公開ON=staff_published）から作る。
Route::get('/staff-portal', [StaffPortalController::class, 'index']);

// その他の画面（Blade化済み）
// マイページ（S-015）。社員が自分の担当アサイン案件を assignments から読む。
// ※ 2026-06-25 別ターミナル（小沼さん）が MyPageController を作成済み＝本接続。
Route::get('/mypage', [MyPageController::class, 'index']);
// 収支入力。案件一覧を DB から出す（D または営業担当の案件が対象）。保存はMTG後。
Route::get('/mypage-finance', [MyPageFinanceController::class, 'index']);
// 設定画面。マスタ件数を DB の実データから数えて表示（保存はまだモック）。
Route::get('/settings', [SettingsController::class, 'index']);

// マスタ管理（コンテンツ・拠点＝追加/編集/削除、ポジション＝表示のみ）。
Route::get('/masters', [MasterController::class, 'index']);
Route::post('/masters/contents', [MasterController::class, 'contentStore']);            // 新規追加
Route::post('/masters/contents/bulk', [MasterController::class, 'contentBulkStore']);    // まとめて保存
Route::get('/masters/contents/{id}/requirements', [MasterController::class, 'contentReqs']);    // 必要人数（規模×役割）
Route::post('/masters/contents/{id}/requirements', [MasterController::class, 'contentReqsSave']);
Route::post('/masters/contents/{id}/delete', [MasterController::class, 'contentDestroy']);
Route::post('/masters/offices', [MasterController::class, 'officeStore']);               // 新規追加
Route::post('/masters/offices/bulk', [MasterController::class, 'officeBulkStore']);      // まとめて保存
Route::post('/masters/offices/{id}/{dir}/move', [MasterController::class, 'officeMove'])->where('dir', 'up|down'); // 上下並び替え
Route::post('/masters/offices/{id}/delete', [MasterController::class, 'officeDestroy']);
