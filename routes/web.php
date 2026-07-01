<?php

use App\Http\Controllers\AssignBoardController;
use App\Http\Controllers\AssignDashboardController;
use App\Http\Controllers\AssignDirectorController;
use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\AssignPublishController;
use App\Http\Controllers\AssignWishlistController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeAvailabilityController;
use App\Http\Controllers\MyPageController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectsAggController;
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
Route::get('/project-import', function () {
    return view('project_import');
});
// CSV一括取込の保存先（記入済みCSVを読んで projects に複数登録）。
Route::post('/project-import', [ProjectController::class, 'import']);
// 別ウィンドウで開くポップアップ画面（Blade化済み）
// 社員・ディレクター集計（別ウィンドウ）。D決め(/assign-director)の保存先＝assignments(role=D/SD)から集計。
Route::get('/projects-agg', [ProjectsAggController::class, 'index']);
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
Route::get('/pickup', [AssignBoardController::class, 'pickup']);

// スタッフ関連の画面（Blade化済み）
// スタッフ名簿は DB（people＋ポジション可否＋NGペア）から読む。
Route::get('/staff', [PersonController::class, 'staff']);
// 社員名簿は DB（people テーブルの社員）から読む。
Route::get('/employees', [PersonController::class, 'employees']);
// 稼働状況は DB（assignments＋shift_preferences＋applications＋people）から計算して読む。
Route::get('/staff-status', [StaffStatusController::class, 'index']);

// スタッフ側の画面（Blade化済み・サイドバー無しの独自レイアウト）。
// 「確定アサイン」と希望カレンダーの確定表示は DB（公開ON=staff_published）から作る。
Route::get('/staff-portal', [StaffPortalController::class, 'index']);

// その他の画面（Blade化済み）
// マイページ（S-015）。社員が自分の担当アサイン案件を assignments から読む。
// ※ 2026-06-25 別ターミナル（小沼さん）が MyPageController を作成済み＝本接続。
Route::get('/mypage', [MyPageController::class, 'index']);
Route::get('/mypage-finance', function () {
    return view('mypage_finance');
});
Route::get('/settings', function () {
    return view('settings');
});
