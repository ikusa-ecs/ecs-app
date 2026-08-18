<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminConsoleController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AssignBoardController;
use App\Http\Controllers\AssignDashboardController;
use App\Http\Controllers\AssignDetailController;
use App\Http\Controllers\AssignDirectorController;
use App\Http\Controllers\AssignHistoryController;
use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\AssignPublishController;
use App\Http\Controllers\AssignSheetController;
use App\Http\Controllers\AssignWishlistController;
use App\Http\Controllers\CountDeadlineReminderController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceListController;
use App\Http\Controllers\FinanceReminderController;
use App\Http\Controllers\MasterController;
use App\Http\Controllers\MasterImportController;
use App\Http\Controllers\EmployeeAvailabilityController;
use App\Http\Controllers\MyPageController;
use App\Http\Controllers\MyPageFinanceController;
use App\Http\Controllers\PaperStockController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\PersonImportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectHistoryController;
use App\Http\Controllers\ProjectsAggController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StaffPortalController;
use App\Http\Controllers\StaffStatusController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OtpController;
use Illuminate\Support\Facades\Route;

// ── ログイン（Laravel Fortify を利用・照合先は people 名簿）──
// トップページ＝ログイン画面。未ログインでの保護画面アクセスもここへ戻る（name='login'）。
Route::get('/', [AuthController::class, 'showLogin'])->name('login');
// ※ POST /login（ログイン実行）・POST /logout は Fortify が登録する。
//   照合の中身（people 照合・active チェック・テストログイン）は FortifyServiceProvider を参照。
// 新規登録画面（現状は見た目のみ。自己登録の扱いは Step 4 で確定＝管理者発行方針）
Route::get('/register', function () {
    return view('register');
});

// ── パスワード再設定（お忘れの方）＝ログイン前（ゲスト）──
//   独自実装。メールで再設定リンクを送る（2段階認証のメールコードと同じ SES 基盤）。
//   送信・保存は total 総当たり対策で軽く throttle をかける。
Route::get('/forgot-password', [PasswordResetController::class, 'showRequestForm'])->name('password.request');
Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->middleware('throttle:6,1')->name('password.email');
Route::get('/reset-password', [PasswordResetController::class, 'showResetForm'])->name('password.reset');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:6,1')->name('password.update');

// ── 2段階認証（メールでコード）の入力ページ ──
//   auth は必要だが twofa/onboarded は付けない（ここへ戻し続ける無限ループを防ぐ）。
Route::middleware('auth')->group(function () {
    Route::get('/otp', [OtpController::class, 'show'])->name('otp.challenge');
    Route::post('/otp', [OtpController::class, 'verify']);
    Route::post('/otp/resend', [OtpController::class, 'resend']);
});

// ── 初回ログインの初期設定（パスワード設定＋プロフィール入力）──
//   auth と twofa は必要だが onboarded は付けない（ここへ戻し続ける無限ループを防ぐ）。
//   2段階認証（メールコード）は初期設定より先に通す。
Route::middleware(['auth', 'twofa'])->group(function () {
    Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding');
    Route::post('/onboarding', [OnboardingController::class, 'complete']);
});

// ── ログイン済みなら誰でも見られる区画（スタッフ本人＋社員）──
Route::middleware(['auth', 'twofa', 'onboarded'])->group(function () {
    // スタッフ画面（サイドバー無しの独自レイアウト）。スタッフ本人が使う・社員も閲覧できる。
    Route::get('/staff-portal', [StaffPortalController::class, 'index']);
    // 設定タブの「自分の情報」をDB(people)へ本物保存（AJAX・本人分のみ）。
    Route::post('/staff-portal/profile', [StaffPortalController::class, 'saveProfile']);
    Route::post('/staff-portal/skills', [StaffPortalController::class, 'savePositions']);
    // 稼働希望カレンダー（その月の希望）をDB(shift_preferences)へ本物保存。
    Route::post('/staff-portal/availability', [StaffPortalController::class, 'saveAvailability']);
    // 案件へのエントリー（応募＋一言コメント）をDB(applications)へ本物保存。
    Route::post('/staff-portal/entry', [StaffPortalController::class, 'saveEntry']);
    // 使い方ガイド（社内向け・全役割）。サイドバー（社員以上）から開く。
    Route::view('/guide', 'guide')->name('guide');
    // 使い方ガイド（スタッフ向け）。スタッフ画面から開く＝スタッフがやることだけに絞った内容。
    Route::view('/guide-staff', 'guide_staff')->name('guide.staff');

    // 本人のパスワード変更（初回ログイン後などに自分で変える）。
    Route::get('/password', [PasswordController::class, 'edit']);
    Route::post('/password', [PasswordController::class, 'update']);

    // 本人のプロフィール入力・編集（旧・新規登録の項目を本人が埋める）。
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::post('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

// ══════════════════════════════════════════════════════════════════
// ここから下は「社員以上」だけ（スタッフは入れない＝自分のスタッフ画面へ戻される）。
// ══════════════════════════════════════════════════════════════════
Route::middleware(['auth', 'twofa', 'onboarded', 'tier:employee'])->group(function () {

// 社員側の画面（Blade化済み）
// ダッシュボードは DB（projects テーブル）から読む。KPI・危険日カレンダーが本物の案件で動く。
Route::get('/dashboard', [DashboardController::class, 'index']);
// 集計ダッシュボード＝イベント数（拠点別/オンライン・リアル）・メンバー別/部署別の出勤数を月/四半期/年で集計（baba 2026-07-24）。
Route::get('/stats', [StatsController::class, 'index']);
// 集計ダッシュボードのCSV出力（1枚に全情報・画面と同じ並び。画面と同じ期間・表示範囲）。
Route::get('/stats/export.csv', [StatsController::class, 'exportCsv']);
// 案件一覧は DB（projects テーブル）から読む。Controller が cases.js と同じ形に整える。
Route::get('/projects', [ProjectController::class, 'index']);
// 案件登録／編集フォーム。?project=<案件ID> が来たら既存案件を読み、各欄に埋めて開く。
Route::get('/project-form', [ProjectController::class, 'form']);
// 案件登録フォームの保存先（IDが来れば上書き更新、無ければ新規作成）。
Route::post('/project-form', [ProjectController::class, 'store']);
// 案件一覧の詳細から、ケータリングの種類・メモだけを保存する（公開ボードの時間保存と同じ方式）。
Route::post('/projects/catering', [ProjectController::class, 'saveCatering']);
// 案件一覧の詳細セル（D/SD/物品/移動/音響）を保存する。
Route::post('/projects/cells', [ProjectController::class, 'saveCells']);
// 手動アーカイブ（隠す／戻す）を保存する。
Route::post('/projects/archive', [ProjectController::class, 'setArchive']);
// 案件の削除（キャンセルになった案件を消す）。案件の削除＝社員以上でOK（baba 2026-07-14）。関連アサインも一緒に消す。
Route::post('/projects/{id}/delete', [ProjectController::class, 'destroy']);
// 案件の編集履歴（先-1・2026-08-18）。誰がいつ何を何に変えたかを見るだけの画面。
Route::get('/project-history', [ProjectHistoryController::class, 'index']);
Route::get('/project-import', function () {
    return view('project_import');
});
// CSV一括取込の保存先（記入済みCSVを読んで projects に複数登録）。
Route::post('/project-import', [ProjectController::class, 'import']);
// 名簿（people）のCSV一括取込。アカウント作成に準じるため「管理者以上」に限定。
Route::get('/person-import', [PersonImportController::class, 'show'])->middleware('tier:manager');
Route::post('/person-import', [PersonImportController::class, 'import'])->middleware('tier:manager');

// CSV一括取込。サイドバーは「CSV一括取込」1項目→ハブ(/imports)から各取込へ。名簿取込と同じく管理者以上のみ。
Route::get('/imports', [MasterImportController::class, 'hub'])->middleware('tier:manager');
Route::get('/content-import', [MasterImportController::class, 'showContent'])->middleware('tier:manager');
Route::post('/content-import', [MasterImportController::class, 'importContent'])->middleware('tier:manager');
// アカウント発行（1人ずつ）。最初はCSV一括、以降はここで発行。作成＝管理者以上。
Route::get('/account-new', [AccountController::class, 'create'])->middleware('tier:manager');
Route::post('/account-new', [AccountController::class, 'store'])->middleware('tier:manager');
// Administrator専用コンソール（権限変更などを集約）。全権のみ＝tier:admin。
Route::get('/admin-console', [AdminConsoleController::class, 'index'])->middleware('tier:admin');
Route::post('/admin-console/permission', [AdminConsoleController::class, 'updatePermission'])->middleware('tier:admin');
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
// アサイン表（東京アサイン表そっくりの縦カード）。案件情報＋割り当てメンバーを1画面で見る。月を選んで表示。
Route::get('/assign-sheet', [AssignSheetController::class, 'index']);
// アサイン表から案件の時間・人数・備考を直接保存（公開ボードの時間保存と同じ「選ぶ/入れると保存」方式）。
Route::post('/assign-sheet/project', [AssignSheetController::class, 'updateProject']);
// 他拠点の案件を自拠点にコピー（ヘルプ/巻き取り）／解除。アサイン担当＝管理者以上のみ（全拠点運用・設計書19.2）。
Route::post('/assign-sheet/share', [AssignSheetController::class, 'shareToOffice'])->middleware('tier:manager');
Route::post('/assign-sheet/share/remove', [AssignSheetController::class, 'removeShare'])->middleware('tier:manager');
// クライアント別アサイン履歴。お客様ごとに「常連スタッフ」と過去案件の顔ぶれを見る（見るだけ）。
Route::get('/assign-history', [AssignHistoryController::class, 'index']);
// クライアント履歴の照会（AJAX用）。案件登録フォームが ?client=名前 で呼び、リピート判定＋過去案件をJSONで返す。
Route::get('/clients/lookup', [AssignHistoryController::class, 'lookup']);
// アサインダッシュボード＝担当者向けの状況まとめ。「アサインが必要な案件」だけ本物の案件から作る。
Route::get('/assign-dashboard', [AssignDashboardController::class, 'index']);
// アサインダッシュボードのCSV出力（アサインが必要な案件の表）。
Route::get('/assign-dashboard/export.csv', [AssignDashboardController::class, 'exportCsv']);
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
// アサイン画面（案件詳細）。?case=<案件ID> で本物の案件ヘッダー＋提案チーム（実アサイン）＋代替候補（応募）を表示。
// 案件が見つからないときは従来の見本（水合戦サンプル）にフォールバック。
Route::get('/assign-detail', [AssignDetailController::class, 'show']);
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
// 公開ボードの案件ごとの「💬備考」（担当メモ）を projects.publish_memo へ保存。
Route::post('/assign-publish/memo', [AssignPublishController::class, 'setMemo']);
// 追加案件バッジの手動オン/オフ・通常案件の一斉締切日も DB に保存する。
Route::post('/assign-publish/category', [AssignPublishController::class, 'setCategory']);
Route::post('/assign-publish/category-bulk', [AssignPublishController::class, 'setCategoryBulk']);
Route::post('/assign-publish/deadline', [AssignPublishController::class, 'setDeadline']);
// 仮データの名前は DB（people のスタッフ）から渡す（NAME_POOL の単一ソース化）。
Route::get('/entries', [AssignBoardController::class, 'entries']);
// エントリー一覧「月ごと」の表からの1人ぶんアサイン切替（A案）。assignments に追加/削除する。
Route::post('/entries/assign', [AssignmentController::class, 'quickToggle']);
Route::get('/pickup', [AssignBoardController::class, 'pickup']);
// ピックアップのメンバーを assignments にDB保存（担当メモ・巡回数も一緒に上書き）。
Route::post('/pickup/save', [AssignBoardController::class, 'pickupSave']);

// スタッフ関連の画面（Blade化済み）
// スタッフ名簿は DB（people＋ポジション可否＋NGペア）から読む。
Route::get('/staff', [PersonController::class, 'staff']);
// スタッフ名簿のCSV出力（氏名・事務所・区分・できる役割・通算など）。
Route::get('/staff/export.csv', [PersonController::class, 'exportStaffCsv']);
// スタッフの「できるポジション・NGペア・専属・人柄・メモ」を保存（/staff 詳細パネルからAJAX）。
Route::post('/staff/{id}/edit', [PersonController::class, 'staffUpdate']);
// 社員名簿は DB（people テーブルの社員）から読む。
Route::get('/employees', [PersonController::class, 'employees']);
// 社員のサイズ（身長・靴・服）を保存（/employees 詳細パネルからAJAX）。
Route::post('/employees/{id}/profile', [PersonController::class, 'saveEmployeeProfile']);
// 社員名簿の詳細から「経験コンテンツ／Dの経験コンテンツ」だけを保存する。
Route::post('/employees/experience', [PersonController::class, 'saveExperience']);
// 稼働状況は「スタッフ」画面（/staff）の中のタブに統合済み。
// 旧URL・旧リンク（アサインダッシュボード等）から来ても迷子にならないよう /staff へ転送する。
Route::get('/staff-status', fn () => redirect('/staff'));

// （/staff-portal は上の「ログイン済みなら誰でも」区画へ移動＝スタッフも社員も見られる）

// その他の画面（Blade化済み）
// マイページ（S-015）。社員が自分の担当アサイン案件を assignments から読む。
// ※ 2026-06-25 別ターミナル（小沼さん）が MyPageController を作成済み＝本接続。
Route::get('/mypage', [MyPageController::class, 'index']);
// マイページの通知設定（新人フォロー所感/アサイン確定/締切）を people へ本物保存。
Route::post('/mypage/notify', [MyPageController::class, 'saveNotify']);
// 収支入力。案件一覧を DB から出す（D または営業担当の案件が対象）。
Route::get('/mypage-finance', [MyPageFinanceController::class, 'index']);
// 収支（売上・経費明細・メモ）を project_finances へ本物保存（イベプラ要望）。
// ※ 直せるのは担当のD／営業担当と管理者以上（FinanceAccess::canEdit）。
Route::post('/mypage-finance/save', [MyPageFinanceController::class, 'save']);
// 収支一覧（2026-08-06 baba確定）。月ごとに売上・経費・利益と「入力済みか」を並べる。
// 見るのは社員以上ぜんぶOK（このグループが tier:employee）。直すのは担当と管理者以上だけ。
Route::get('/finance-list', [FinanceListController::class, 'index']);
Route::get('/finance-list/export.csv', [FinanceListController::class, 'exportCsv']);
// 収支未入力リマインド。締切（イベント終了後3営業日）を過ぎて未入力の案件をDへチャットワークでタスク化。
// GET=対象一覧／POST send=mode(dry件数確認/test/live)。鍵は .env の CHATWORK_TOKEN。
Route::get('/finance-reminder', [FinanceReminderController::class, 'index']);
Route::post('/finance-reminder/send', [FinanceReminderController::class, 'send']);
// 設定画面。マスタ件数を DB の実データから表示し、アサインMTG日の予定表を DB(settings) に保存する。
Route::get('/settings', [SettingsController::class, 'index']);
Route::post('/settings/mtg-dates', [SettingsController::class, 'saveMtgDates']);
// 危険日（手動指定）を settings に保存。ダッシュボードの危険日カレンダーに反映される。
Route::post('/settings/danger-dates', [SettingsController::class, 'saveDangerDates']);
// スタッフ画面の便利リンク集（Notion・アンケートフォーム等）を settings に保存。
Route::post('/settings/staff-links', [SettingsController::class, 'saveStaffLinks']);

// マスタ管理（コンテンツ・拠点＝追加/編集/削除、ポジション＝表示のみ）。
Route::get('/masters', [MasterController::class, 'index']);
Route::post('/masters/contents', [MasterController::class, 'contentStore']);            // 新規追加
Route::post('/masters/contents/bulk', [MasterController::class, 'contentBulkStore']);    // まとめて保存
Route::get('/masters/contents/{id}/requirements', [MasterController::class, 'contentReqs']);    // 必要人数（規模×役割）
Route::post('/masters/contents/{id}/requirements', [MasterController::class, 'contentReqsSave']);
Route::post('/masters/contents/{id}/delete', [MasterController::class, 'contentDestroy'])->middleware('tier:admin'); // 削除はAdministratorのみ
Route::post('/masters/offices', [MasterController::class, 'officeStore']);               // 新規追加
Route::post('/masters/offices/bulk', [MasterController::class, 'officeBulkStore']);      // まとめて保存
Route::post('/masters/offices/{id}/{dir}/move', [MasterController::class, 'officeMove'])->where('dir', 'up|down'); // 上下並び替え
Route::post('/masters/offices/{id}/delete', [MasterController::class, 'officeDestroy'])->middleware('tier:admin'); // 削除はAdministratorのみ

}); // ← ログイン必須グループ ここまで
