# ECS 実装一覧（詳細版）

引き継ぎ・技術共有向けの資料です。画面ごとに「ルート／コントローラ」「権限」「表示データの出どころ」「保存先テーブル・カラム」「入力チェック」「本物（DB連携）かモック（見本・未接続）か」を、実際のコードを読んで記載しています。モック・未実装・暫定の箇所は該当ファイルと行番号を添えています。

> **もう1冊「ECS操作マニュアル_詳細版.md」があります。** そちらは使う人向けの操作手順です。

作成日：2026-07-15（コードを実際に読んで作成）／2026-07-17 再更新（収支・エントリー・通知・案件セル編集・アーカイブ・公開備考・CSV出力・名簿できるポジション取込 を実装）

---

## 目次
- [共通の前提](#共通の前提)
- [1. 認証・本人設定](#1-認証本人設定)
- [2. スタッフ画面](#2-スタッフ画面)
- [3. 分析・本人業務](#3-分析本人業務)
- [4. 案件・CSV取込](#4-案件csv取込)
- [5. アサイン](#5-アサイン)
- [6. 通知・在庫・集計](#6-通知在庫集計)
- [7. 名簿・マスタ・管理](#7-名簿マスタ管理)
- [横断メモ（引き継ぎ注意）](#横断メモ引き継ぎ注意)

---

## 共通の前提

### ミドルウェア（`bootstrap/app.php`、`routes/web.php`）
- `auth`：ログイン必須。
- `twofa`：メール2段階認証を通過（`session('twofa_ok')`）するまで `/otp` へ誘導（`app/Http/Middleware/EnsureTwoFactor.php`）。
- `onboarded`：初回設定が未完（`people.must_onboard=true`）なら `/onboarding` へ誘導（`app/Http/Middleware/EnsureOnboarded.php`）。
- `tier:○○`：権限4段階チェック（`app/Http/Middleware/EnsureTier.php:23-28`）。`staff(1) < employee(2) < manager(3) < admin(4)`。不足時、スタッフは `/staff-portal` へ戻し、社員・管理者がadmin専用に触れると403（`EnsureTier.php:38-45`）。
- 業務画面の大半は `routes/web.php:79` の1グループ（`auth,twofa,onboarded,tier:employee`）内。取込系は個別に `tier:manager`、削除・権限変更は `tier:admin`。

### ログイン基盤
- **認証は `users` 表ではなく `people` 名簿**（`config/auth.php` でカスタムプロバイダ `person`、`app/Models/Person.php`）。`role`＝employee/staff、`permission`＝4段階。
- Fortify は **ログイン/ログアウトのPOSTだけ**担当（`config/fortify.php` の `views=false`, `features=[]`）。照合は `FortifyServiceProvider@authenticateUsing`（`active=true` のみ許可）。ログイン後の振り分けは `LoginResponse`（staff→/staff-portal、他→/dashboard）。
- 2段階認証はFortify標準ではなく**独自実装**（`OtpController`。6桁・ハッシュ化してセッション保持・平文はメール送信・有効期限10分・5回試行）。

### テスト用ログイン（DB不要）
- `app/Support/TestAccounts.php`、`.env` の `ECS_TEST_LOGIN`（既定true）。DBを見ずログイン可・2FA対象外・DB保存を伴う操作は保存されない。**本番前に `false`**（`TestAccounts.php:15-16,72-75`）。

### 「自分」＝baba固定
- マイページ・収支入力は「自分」を **E-007 / baba** に固定（`app/Support/PersonalCases.php:26-31`）。認証接続はMTG後。

### 役割コードの正本
- `app/Support/AssignmentRole.php`。コード＝**D / SD / OP / MC / FC / CK / SP / RP**（SDはD決め専用、スタッフの「できる役割」7種にSDは含まない）。旧 GUN→SP、UKE→RP に移行済み（`2026_07_01_000003_rename_uke_gun_codes.php`）。表記ゆれ防止のため全画面がここを参照。

### 主なテーブル
`people`（社員・スタッフ共通、role/permissionで区別。OPの区別用に `op_online`/`op_real` 追加済み＝2026-07-17、通知設定 `notify_settings` 追加済み＝2026-07-17）、`contents`（コンテンツ・マスタ、needs_paper/sheets_per_team）、`projects`（案件、公開の背骨=`staff_published`。2026-07-17 に `sd_id`（SD担当）・`is_archived`（手動アーカイブ、null=開催日で自動判定）・`publish_memo`（公開ボードの共有備考）を追加）、`project_finances`（2026-07-17 新設。案件1件=1行で収支を保存＝`revenue`（売上）/`items`（経費明細JSON）/`memo`）、`assignments`（案件×人×日で1行、role/status/score/assigned_by/assigned_at＋`note`（担当メモ=軍師/サポ等）/`patrol`（巡回数）/`remark`（人ごとの一言備考）/`role2`（兼任＝サブ役割）を追加済み、unique(project_id,staff_id,date)）、`shift_preferences`（稼働希望・出勤可否）、`applications`（案件応募）、`staff_role_eligibility`（できる役割）、`staff_relations`（NGペア）、`settings`（key/value）、`offices`（拠点）、`content_role_requirements`（規模別必要人数）、`content_paper_stocks`（紙の入庫数）、`count_deadline_reminder_logs`（リマインド送信済み）、`staff_content_experience`/`staff_role_experience`/`skills`/`staff_skills`（土台・未接続）。

---

## 1. 認証・本人設定

### 1-1. ログイン（`GET /` → `AuthController@showLogin`／POST は Fortify）
- **権限**：なし。
- **照合**：`FortifyServiceProvider@authenticateUsing`（`people`・`active=true`のみ）。バリデーション email(required,email)/password(required)。成功でセッションID再生成。レート制限5回/分。
- **保存先**：書き込みなし（remember時に `people.remember_token`）。
- **本物/モック**：本物。ただしログインフォームに初期値ハードコード（`login.blade.php:42,48`＝本番前削除）、テスト用ワンクリック（`TestAccounts`）は暫定。
- 補足：`AuthController@login/@logout` メソッドは存在するがルーティングされておらず、実処理はFortify（推測：旧コードの名残）。

### 1-2. 2段階認証（`/otp` → `OtpController@show/verify/resend`）
- **権限**：`auth` のみ（`twofa`/`onboarded` は付けない＝ループ防止）。
- **保存先**：DBではなくセッション `twofa`（code_hash・expires_at・attempts）。合致で `twofa_ok=true`。
- **設定値**：TTL 10分、最大5回（`OtpController.php:23-24`）。
- **メール**：`LoginCodeMail`（ローカル=ログ、本番=SES）。
- **本物/モック**：本物。

### 1-3. 初回設定（`/onboarding` → `OnboardingController@show/complete`）
- **権限**：`auth,twofa`（`onboarded` は付けない）。誘導は `EnsureOnboarded`。
- **入力チェック**：name(required)、email(nullable,email)、password(required,min:8,confirmed)。
- **保存先**：`people`（password[hashedキャスト]、name、email、office、height、shoe_size、shirt_size、prefecture、nearest_station、スタッフのみappeal、`must_onboard=false`）。
- **本物/モック**：本物。テスト用アカウントのみ保存せずセッションで完了扱い（`OnboardingController.php:44-49`）。

### 1-4. プロフィール編集（`/profile` → `ProfileController@edit/update`）
- **権限**：`auth,twofa,onboarded`（社員・スタッフ両方）。
- **入力チェック**：name(required)、email(nullable,email)。他は任意。
- **保存先**：`people`（共通：name/email/office/height/shoe_size/shirt_size/prefecture/nearest_station、社員：department、スタッフ：appeal/liked_contents/disliked_contents/strong_positions/weak_positions/can_stay_over/can_kigurumi）。
- **本物/モック**：本物。テスト用のみ保存不可。

### 1-5. パスワード変更（`/password` → `PasswordController@edit/update`）
- **入力チェック**：current_password(required)、password(required,min:8,confirmed)＋`Hash::check` で現PW一致確認。
- **保存先**：`people.password`（hashedキャスト）。
- **本物/モック**：本物。テスト用のみ不可。

---

## 2. スタッフ画面

### スタッフ画面（`/staff-portal` → `StaffPortalController@index`）
- **権限**：`auth,twofa,onboarded`（スタッフ本人＋社員以上も閲覧可）。POST系の保存は `/assign-publish` 側。
- **性格**：本物とモックが同居。

| タブ | 本物/モック | 根拠 |
|---|---|---|
| ③確定アサイン | 本物 | `StaffPortalController.php:47-82` |
| ①募集中（案件リスト） | 本物 | `StaffPortalController.php:90-160` |
| ①エントリー動作・コメント | **本物**（`applications` へ保存。応募＋一言コメント・取消可） | `POST /staff-portal/entry`＝`StaffPortalController::saveEntry` |
| ②稼働希望の提出 | **本物**（`shift_preferences` へ保存） | `POST /staff-portal/availability`＝`StaffPortalController::saveAvailability`（`:244-`） |
| ④設定：プロフィール保存 | **本物**（`people` へ保存） | `POST /staff-portal/profile`＝`saveProfile`（`:138-`） |
| ④設定：できるポジション保存 | **本物**（`staff_role_eligibility` へ保存） | `POST /staff-portal/skills`＝`savePositions`（`:169-`） |
| ④アカウント（メール表示/各リンク/ログアウト） | 本物 | `staff_portal.blade.php:452-456,862-875` |

- 稼働希望・プロフィール・できるポジション・エントリー（応募）は**本人分のみ**保存する（テスト用アカウントは保存しない＝`/profile` と同じ方針）。**2026-07-17 でエントリー（応募）も本物化**し、この画面に残る見本表示はほぼ解消。
- 確定アサインの条件：`projects.staff_published=true` かつ 本人が非キャンセルでアサイン済み（`:74`）。お知らせ＝`Setting::get('staff_notice')`。締切＝`StaffPortalController::deadlineLabel()`（通常＝`entry_deadline`／追加＝公開日+3日・土日は月曜、`:167-193`）。**稼働希望カレンダーは7月固定グリッドのまま**（月切替の動的生成は別タスク・未対応）。
- 旧localStorage方式（`ecs_publish_*`）の関数が一部残存（`:666-675`、推測）。

---

## 3. 分析・本人業務

### 3-1. ダッシュボード（`/dashboard` → `DashboardController@index`）
- **権限**：社員以上。
- **データ元**：`projects` 全件を cases.js 形式へ整形（`DashboardController.php:31-65`）。KPI・危険日判定はクライアントJS。
- **危険日判定**（`public/ecs/data/cases.js:220-237`）：①大型同日2件以上 ②リアル系同日5件以上 ③必要スタッフ合計≥28（稼働`ECS_ACTIVE_STAFF=40`の7割）。必要スタッフ数＝運営人数−1。
- **保存先**：なし。
- **本物/モック**：KPI・危険日カレンダーは本物。稼働数40はモック定数（本番はDB算出予定・未実装）。件数集計表は`format`文字列頼りの暫定で拠点/方向別は未集計（`dashboard.blade.php:138-141`）。「モックです」帯（`:63`）は旧表示の名残。

### 3-2. マイページ（`/mypage` → `MyPageController@index`）
- **権限**：社員以上。共通部品 `PersonalCases`（me/cases/myAssign）。
- **データ元**：`Project::with('director')` 全件を `toCase()` 整形、自分のアサイン＝`Assignment where staff_id=me.id, status!=キャンセル`。
- **保存先**：通知設定＝`people.notify_settings`（**2026-07-17 でDB保存化**。新人フォロー所感/アサイン確定/締切のオンオフ。`POST /mypage/notify`＝`MyPageController::saveNotify`）。他は表示のみ。
- **本物/モック**：案件・アサインは本物（空時のみモックへフォールバック）。「自分」＝baba固定。プロフィール編集/パスワード変更ボタンはダミー。**ログアウトが `/` へ遷移するだけでFortifyの `POST /logout` を叩いていない**（推測：実セッションが切れない可能性、`mypage.blade.php:732-734`）。

### 3-3. 収支入力（`/mypage-finance` → `MyPageFinanceController@index`）
- **権限**：社員以上。対象＝自分がD or 営業担当の案件（下書き除く）。
- **計算**：単価あり行＝単価×数量、実費行＝1000円単位切り上げ、利益＝売上−経費。当日スタッフ費の数量初期値＝`required_count`。
- **保存先**：`project_finances`（**2026-07-17 でDB保存化**。案件1件=1行に「明細まで」保存＝`revenue`（売上）/`items`（経費明細JSON）/`memo`。`POST /mypage-finance/save`＝`MyPageFinanceController::save`）。開き直すと前回入力が復元。
- **本物/モック**：案件一覧・収支保存とも本物（明細まで保存・復元）。実費の注記「四捨五入」（`:177`）は実処理（切り上げ`Math.ceil`）と不一致（実挙動は切り上げ）。

### 3-4. 社員・ディレクター集計（`/projects-agg` → `ProjectsAggController@index`）
- **権限**：社員以上。ビューは独立HTML（別ウィンドウ用）。
- **データ元**：`assignments where role in [D,SD] and status!=キャンセル`。案件（format/scale/status）と社員（name/department）を突合。
- **集計**：下書き除外、`project_id|staff_id|role` で複数日を1回に集約。realD/onlineD/bigD/bigSD を算出。total=d+sd 降順。
- **保存先**：なし。
- **本物/モック**：本物（D決めの保存＝`assignments`が元）。累計固定・月ナビなし。

---

## 4. 案件・CSV取込

### 4-1. 案件一覧（`/projects` → `ProjectController@index`）
- **権限**：社員以上。
- **データ元**：`Project::with(['director','goodsOwner'])->orderBy('start_date')` を cases.js 形式に整形（`ProjectController.php:37-105`）。常連クライアント（同名2件以上）を `$repeatClients` で判定。
- **保存先**：ケータリング（`POST /projects/catering` → `projects.catering`/`catering_note`）に加え、**2026-07-17 で詳細セル編集と手動アーカイブもDB保存化**。
  - **詳細セル編集**（D/SD/物品担当/移動/音響）→ `POST /projects/cells`＝`ProjectController::saveCells`。社員ドロップダウンは実データ、**1セルずつ保存**（他の項目は消えない）、非社員のidは null。SDは `sd_id` 列追加で保存されるようになり「未設定」固定は解消。
  - **手動アーカイブ** → `projects.is_archived`（`POST /projects/archive`＝`setArchive`）。null=開催日で自動判定／true=手動でアーカイブ／false=手動で戻す（手動が自動より優先）。
- **本物/モック**：一覧・絞り込み・詳細・削除・編集リンク・書き出し・詳細セル編集・手動アーカイブは本物。運営シート作成はモック（`:571-583`）。

### 4-2. 案件登録・編集（`/project-form` → `ProjectController@form/store`、削除=`destroy`）
- **権限**：社員以上（削除も社員以上）。
- **store の主な保存先**（`ProjectController.php:241-377`）：content_names→`content_ids`（マスタ未登録名は`CT-###`発番して`contents`作成）＋`project_name`、category/is_toc/yomi/scale/is_recruiting/is_multi/date_type/parent_project_id/sales_owners/agency/format/online_tool/base_locations/broadcast/operation_place/client/start_date・各時刻/location/is_outdoor/lodging/assembly_type/staff_role/required_count/count_tentative/guest_count/guest_count_type/team_count/team_tentative/is_repeat/alcohol/catering/audio_equipment/transport/pub_*/ops_sheet_url/note/status。ID＝`P-YYYY-NNNN`発番。新規のみ`staff_published=false`。
- **入力チェック**：start_date(date)。フロントで必須3点（案件名・開催日・運営人数）を担保（`project_form.blade.php:1032-1045`）。
- **store が保存しないカラム**（フォームに入力欄なし＝未接続/別工程）：`site_category`、`staff_meet_time`/`staff_leave_time`/`staff_meeting_time`、`extra_published_at`、`director_id`/`goods_owner_id`、`prep_line_sent/handover/script`、`catering_note`。
- **destroy**：子案件（parent一致）＋対象・子のアサインをトランザクションで削除。
- **本物/モック**：登録・編集・削除・ID発番・コンテンツ自動発番は本物。クライアント常連照会（`/clients/lookup`）は本物。コンテンツ候補配列`CONTENTS`は表示用の見本（送信時にサーバで実マスタ突合・発番）。

### 4-3. 案件CSV取込（`GET /project-import`＝ビュー、`POST` → `ProjectController@import`）
- **権限**：社員以上（managerは不要）。
- **処理**：BOM除去→改行分割→見出しを列名位置表→行ごと必須チェック（案件名・開催日=`\d{4}-\d{2}-\d{2}`＋checkdate・運営人数≥1）→OK行のみ`Project::create`。ディレクター/物品担当は名前→`people`ID解決（`personIdByName`、フォームと違い取込では保存）。固定＝status='未着手'、staff_published=false。
- **本物/モック**：本物。「サンプルで試す」は登録されない確認用。危険日判定はcases.js依存で取込は止めない。エラー行「直して登録」は別タブ＋localStorage連携（登録は通常のstore経由）。

### 4-4. CSV取込ハブ（`/imports` → `MasterImportController@hub`）
- **権限**：社員以上＋`tier:manager`（実質管理者以上）。静的カード3枚のみ（名簿/コンテンツ/案件へのリンク）。本物（静的ハブ）。

### 4-5. 名簿CSV取込（`/person-import` → `PersonImportController@show/import`）
- **権限**：社員以上＋`tier:manager`。
- **処理**：列＝種別/氏名/メール/事務所/所属/入社日/通算経験回数/**できるポジション（任意・スタッフのみ）**。行検証（種別が社員/スタッフ、氏名必須、メール形式＋重複、入社日実在、通算は数字）→`Person::create`。ID＝E-###/S-###発番、permission=種別から、active=true、パスワードは設定しない。
- **できるポジション取込（2026-07-17 実装）**：「できるポジション」列を**カンマ/スラッシュ/読点区切り**で分解し、`AssignmentRole` の正規コードへ変換して `staff_role_eligibility` に登録（スタッフのみ）。
- **本物/モック**：本物（できるポジション取込を含む）。

### 4-6. コンテンツCSV取込（`/content-import` → `MasterImportController@showContent/importContent`）
- **権限**：社員以上＋`tier:manager`。
- **処理**：列＝コンテンツ名/分類/体力系/紙が必要/1チーム枚数/利用中。名前重複NG、枚数は空でなければ≥1→`Content::create`（id=CT-###発番、needs_paper/is_physical/active は真偽語判定、sheets_per_team空なら1）。
- **本物/モック**：本物。拠点CSVは提供しない仕様（マスタ管理で手入力）。

---

## 5. アサイン

共通：保存先は `assignments`（`create_assignments_table.php`。unique(project_id,staff_id,date)、外部キー無し＝ダブルブッキングはハード制約でなく警告方針）。`assigned_by` は**全経路 null 固定**（認証導入後に必須化予定）。`score` 列は存在するが**書き込まれない**（自動提案スコアの保存は未実装）。
- **2026-07-17 で全アサイン画面（手動アサイン/日別ボード/ピックアップ/アサイン表）に共通で入るようになった項目**：`note`（担当メモ＝軍師/サポ等）・`patrol`（巡回数）・`remark`（人ごとの一言備考。担当メモとは別の自由記入）・`role2`（兼任＝サブ役割）。いずれも入力・保存・再表示できる。ポジション枠は**主役割＋兼任の両方に+1**（人数＝体は1人のまま）。兼任の無効値は null。
- **「基本1案件につきDは1名」**：日別ボードの自動アサインは2人目以降のDを役割なしで追加、手動アサインの自動仮置きはD枠を上限1で配置する。
- アサインのメンバー並び順は全画面で **D→SD→MC→OP→FC→CK→軍師→受付** に統一。

### 5-1. アサイン表（`/assign-sheet` → `AssignSheetController@index`）
- 月選択（`?month=YYYY-MM`、既定は今月以降で最初の月）。案件＝status「完了/下書き」以外で start_date あり。メンバー＝`assignments` 非キャンセル。表示項目は全て `projects` の各カラム。director_id ありでD行が無ければ先頭に補完。「担当内訳」行（軍師/サポ/巡回の内訳）表示、実施形態を色付きバッジで表示。
- **保存先**：`assignments`（**この画面からも編集・保存できるようになった**）。担当・巡回・役割・兼任・備考を `quickToggle` 経由（`POST /entries/assign`）で保存。**本物（閲覧＋編集）**。

### 5-2. アサインダッシュボード（`/assign-dashboard` → `AssignDashboardController@index`）
- **対象月＝当月 `now()` 自動**（2026-07-17 でハードコード解消）。募集中＝`staff_published=true`、未確定＝`required_count>0 かつ 決定<必要`。要注意スタッフは `StaffStatusController::buildStatus()` を単一ソースに算出。
- **保存先**：なし。**本物**。**「CSV出力」も実装**（`GET /assign-dashboard/export.csv`＝`exportCsv`。アサインが必要な案件の表を UTF-8 BOM付きで出力＝Excel文字化けなし）。

### 5-3. 日別ボード（`/assign` → `AssignBoardController@assign`）
- 今日〜21日。案件＋割当＋応募者、稼働可（shift_preferences）、月件数（`MONTH_CAP=20`）。
- **保存先**：**主要な割当操作はDB保存される**（`assignments`）。自動アサイン・希望者からの＋追加・名簿からの追加・×外す・ポジション/兼任/担当メモ/巡回/備考の変更が、いずれも `POST /entries/assign`（`quickToggle`）または unassign で保存される。
- **まだ画面内のみ（保存されない）**：「確定/公開」ボタンの状態遷移。
- **暫定**：ポジション充足ランプ＝`pos=[]`固定（`AssignBoardController.php:215`）等の一部見本表示。

### 5-4. アサイン画面・案件詳細（`/assign-detail` → `AssignDetailController@show`）
- **2026-07-17 改修で「案件を選ぶ入口」に変更**（baba 承認）。本物の手動アサインは `/project-assign` に一本化し、この画面はそこへ橋渡しするだけになった（見本と本物の混在を解消）。
  - `?case=案件ID` 付き（日別ボード・公開ボードの「詳細→」等）→ **`/project-assign` へ自動リダイレクト**（`AssignDetailController.php:32-34`）。
  - 案件指定なし（サイドバーから）→ **「アサインする案件を選ぶ一覧」**（`assign_pick` ビュー）を表示。日付でしぼる＋人が足りない案件だけ表示＋充足バッジ（need/done）付き。各行から本物画面へ。
- 旧・見本の提案チーム／暫定スコアは廃止（`legacyShow` として未使用のまま残置）。
- サイドバー表記は「案件別アサイン（案件を選ぶ）」に変更済み。
- **保存先**：この画面自体は保存しない（入口のみ・本物の保存は転送先の `/project-assign`）。

### 5-5. 手動アサイン（`/project-assign` → `AssignmentController@show/save`、切替=`quickToggle`）
- `?project=ID`。既存アサイン・この日の希望・スタッフ名簿（roleEligibilities/ngRelations）・ポジション雛型（`content_role_requirements`）を表示。
- **役割を選ぶと自動でアサイン（チェックが付く）**動作。チェックボックスは残す（役割なしでアサインする用）。
- 各行に**担当メモ（note）／巡回（patrol）／備考（remark）／＋兼任（role2）**の入力欄。ポジション枠は主役割＋兼任の両方に+1。
- 「できる役割」バッジは **D・MC・OP・軍師 のみ**表示（FC・CK・受付は全員できる/不要のため非表示）。判定用データ（posCodes）は全役割のまま保持。OPは名簿の `op_online`/`op_real` から OP（オンライン）/OP（リアル）/OP（オ/リ）と出し分け（未設定は OP（音響））。
- **save**：バリデーション project_id(required)/status(in:仮,確定)/staff_ids[]/role[]。**開催日必須**（無しは警告リダイレクト）。**上書き保存**（該当案件×日を delete→再作成、`whereDate`照合）。roleは`AssignmentRole::isValid()`通過のみ。自動仮置きはD枠を上限1で配置（基本1案件Dは1名）。
- **警告のみ（保存は止めない）**：月20件超（`MONTH_CAP=20`）・同日ダブルブッキング・NGペア同席・人数不一致。
- **quickToggle**：1セル即保存（未→仮→確定、外すのは×）。案件無し404・開催日無し422。unassign時 applications は残す。
- **本物/モック**：手動アサイン・quickToggle とも**本物のDB保存**。`score` 未書込・`assigned_by` null（未実装）。

### 5-6. D決め（`/assign-director` → `AssignDirectorController@index/save`）
- 社員＝`Person::employees()`。案件のD/SD/FC＝`assignments`。
- **save**：`dir[案件ID]`/`sd[案件ID]`/`fc[案件ID][]`/status（hiddenで常に仮）。`assignments` に role=D/SD/FC で保存（`whereDate`照合）、外れた人は削除。同一案件でD/SD兼任不可・FCと排他。**開催日なしはスキップ**。`assigned_by=null`。
- **本物/モック**：データ・保存は本物。**月切替ボタンはモック（alert）**（`assign_director.blade.php:264-266`）。表示月は「案件が最多の月」を自動選択。

### 5-7. 社員の出勤可能日（`/employee-availability` → `@index/save`）
- **save**：受取 employee_id/period/state{"Y-M-D":ok|ng|maybe|off}/memo。`shift_preferences` に `updateOrCreate(['staff_id','date'])`（値変換 ok→稼働可/ng→NG/maybe→未定/off→希望休）。
- **本物/モック**：保存は本物（AJAX）。ただし DBに社員が無いと `ME=null` で localStorage のみ。**「自分＝先頭社員」の仮運用**（`employee_availability.blade.php:276-279`・認証未接続）。祝日・大型日はビュー内モック。

### 5-8. エントリー一覧（`/entries` → `AssignBoardController@entries`、保存=`quickToggle`）
- 案件（完了/下書き以外）＋応募者（`applications`）＋アサイン済み（`assignments`）。主ポジションは優先順で1つ選出。
- **保存先**：月ごと表のマスクリック＝`POST /entries/assign`（`quickToggle`）で本物保存。確定＋公開済みの解除は確認。
- **本物/モック**：案件・応募者・保存は本物。募集状態判定はビュー側の簡易ルール（厳密な締切ではない）。空時のみ cases.js フォールバック。

### 5-9. ピックアップ（`/pickup` → `AssignBoardController@pickup`、保存=`pickupSave`）
- 案件（完了/下書き以外）＋候補者（応募∪現メンバー）＋当日稼働可（shift_preferences）。
- **保存先**：`assignments`。**＋追加/×外すは本物保存**（`POST /pickup/save`＝`AssignBoardController::pickupSave`（`:98-`）で上書き保存）。役割・兼任・担当メモ・巡回・備考もあわせて保存。
- **本物/モック**：本物のDB保存。

### 5-10. スタッフ集計・希望まとめ（`/assign-wishlist` → `@index`）
- 独立HTML（別ウィンドウ）。**対象月＝当月 `now()` 自動**（2026-07-17 でハードコード解消、`AssignWishlistController.php:44-45`）。希望日数（shift_preferences available）・アサイン済（本番のみ）・MC回数・できる役割。区分は`experience_count`基準（他画面と異なる）。
- **保存先**：なし。**全項目 本物**。

### 5-11. クライアント別履歴（`/assign-history` → `@index`、AJAX=`@lookup`）
- `assignments`非キャンセルを client 別に集計。常連スタッフ（案件数順）・過去案件（新しい順・メンバーは役割順）。`?client=` 完全一致で絞り込み。
- **lookup**：案件登録フォームが呼ぶAJAX。同名 client の件数＝isRepeat判定＋直近5件（日付/担当D/案件名）をJSON。
- **保存先**：なし（両方 読み取り専用）。**全項目 本物**（サーバサイドレンダリング）。

### 5-12. スタッフ公開ボード（`/assign-publish` → `@index` ＋ 各POST）
- **公開の背骨＝`projects.staff_published`**。
- **各POSTの保存先**：
  - setPublish → `projects.staff_published` 一括更新（ids[]/publish）
  - setTime → `projects.staff_meet_time`/`staff_leave_time`（空=null＝社員時間に戻す）
  - setNotice → `Setting::put('staff_notice')`
  - setCategory/setCategoryBulk → `projects.category`＋`extra_published_at`（追加時に空なら今日を記録）
  - setDeadline → `Setting::put('entry_deadline')`
  - setMemo → `projects.publish_memo`（**2026-07-17 でDB保存化**。`POST /assign-publish/memo`＝`AssignPublishController::setMemo`。全員に共有される。旧localStorage方式を置換）
- **締切の表示計算はこの画面ではなく `StaffPortalController::deadlineLabel`**（通常＝entry_deadline／追加＝extra_published_at+3日・土日は月曜）。締切は表示のみ（過ぎても応募受付）。
- **本物/モック**：公開・時間・お知らせ・締切・追加バッジ・**「💬備考」（`projects.publish_memo`）** まで**全て本物（DB）**。備考は 2026-07-17 に localStorage から DB化し、全員に共有される。

---

## 6. 通知・在庫・集計

### 6-1. 人数確定リマインド（`/count-reminder` → `@index/send`、処理=`CountDeadlineReminderService`）
- **権限**：社員以上。バリデーション mode(required,in:dry,test,live)。
- **モード**：dry＝送らず件数確認／test＝`services.chatwork.test_room`へ送信・重複ログに記録しない／live＝`services.chatwork.room`へ送信・成功時に重複ログ記録。非dryで token/room 空なら未設定エラー。
- **対象**：today〜today+14日（`DAYS_BEFORE=14`）、status「完了/下書き」除外、date_type='本番'のみ、開催日が過去/14日超は除外、コンテンツ・クライアント両方空は除外。director＝`assignments` role=D 先頭、sales＝sales_owners[0]。
- **送信本文/タスク**：未送信案件を1通に集約。宛先CWIDはルームメンバーAPIから氏名照合。Dへ「3営業日後18:00締切」のタスク付与（土日飛ばす・**祝日未対応**）。CWID未取得は画面表示。
- **Chatwork**：`ChatworkClient`（v2 API、`X-ChatWorkToken`）。設定＝`config/services.php:41-45`（token/room/test_room、env）。
- **保存先**：`count_deadline_reminder_logs`（dedup_key unique）に本番送信成功時のみ `firstOrCreate`。
- **本物/モック**：抽出・重複防止・タスク付与は本物。実送信はトークン設定次第。

### 6-2. 謎解きの紙 在庫（`/paper-stock` → `@index/updateReceipts`、集計=`PaperStockService`）
- **対象**：`Content where needs_paper=true`。入庫数＝`content_paper_stocks.received_count`。
- **計算**：下書き・オンライン・content_ids空はスキップ。チーム数＝team_count>0優先、無ければ`ceil(guest_count/6)`推定（`TEAM_SIZE=6`）。必要枚数＝`ceil(チーム数)×sheets_per_team`。今後/開催済み（start_date>=today）で振り分け。在庫＝入庫−消費、過不足＝在庫−必要(今後)。
- **保存先**：`content_paper_stocks` に `updateOrCreate(['content_id'],['received_count'])`。入力＝max(0,int)。
- **本物/モック**：全て本物。軽微：明細の `projectName` がカラム名取り違え（`project_name`が正）で常に空だがビュー未使用のため実害なし（`PaperStockService.php:125`・推測）。

---

## 7. 名簿・マスタ・管理

### 7-1. スタッフ名簿・稼働状況（`/staff` → `PersonController@staff`、保存=`staffUpdate`）
- **権限**：社員以上。旧 `/staff-status` は `/staff` へリダイレクト。
- **名簿データ元**：`Person::staff()`（experience_count降順）＋roleEligibilities＋ngRelations。稼働状況は `StaffStatusController::buildStatus()`（**対象月＝当月 `now()` 自動**＝2026-07-17 でハードコード解消、`StaffStatusController.php:41-42`）。稼働率＝月本番アサイン÷希望日数、cap=20（分母でなく上限）、連勤/ご無沙汰/選ばれ率/活性度を算出。
- **staffUpdate の保存先**：`staff_role_eligibility`（managed_positions=OP/MC/SPの範囲だけ入れ直し、D/FC/CK/RPは温存）、`staff_relations`（NGペア全入れ替え、relation_type='NG'）、`people`（is_exclusive/can_follow_newbie/self_starter/improves_atmosphere/planner_impression＋**OPの種類 op_online/op_real**）。
- **OP（音響）の区別（B案・2026-07-17）**：`people.op_online`（オンライン可）/`op_real`（リアル可）を追加。名簿の詳細編集に「OPの種類：オンライン可/リアル可」チェックを追加・保存（`PersonController.php:177-178,228-232`）。名簿一覧とアサイン画面の「できる役割」に OP（オンライン）/OP（リアル）/OP（オ/リ）と表示（未設定は OP（音響））。役割コード OP は1つのまま。
- **「できる役割」バッジは D・MC・OP・軍師 のみ表示**（FC・CK・受付は全員できる/不要のため非表示）。判定用データ（posCodes）は全役割のまま保持。
- **本物/モック**：名簿・編集・稼働数値は本物。**「本人プロフィール」欄も本物のデータ表示に**（`people` の appeal/height/shoe_size 等を表示）。**「CSV出力」も実装**（`GET /staff/export.csv`＝`exportStaffCsv`。ID/氏名/事務所/区分/できる役割（OPはオンライン/リアルを付記）/通算/専属を UTF-8 BOM付きで出力＝Excel文字化けなし）。「招待」は未実装（alert）。**区分の判定基準が2タブで異なる**（名簿=在籍年数／稼働=通算回数、`staff.blade.php:19-22,535`）。
- ※「スタッフ編集」は独立画面ではなく `POST /staff/{id}/edit`＝この名簿詳細パネルのAJAX保存先。people の appeal/liked/disliked/strong/weak_positions は**この保存では触らない**（未接続）。

### 7-2. 社員名簿（`/employees` → `@employees`、保存=`saveExperience`／`saveEmployeeProfile`）
- **データ元**：`Person::employees()`＋contentOptions（`contents` active）。
- **saveExperience**：id(required)、exp[]/dexp[]（各max100）→`people.experienced_contents`/`director_contents`（来た方だけ更新・重複除去）。
- **saveEmployeeProfile（2026-07-17 実装）**：サイズ（身長/靴/服）の編集・保存＝`POST /employees/{id}/profile` → `people.height`/`shoe_size`/`shirt_size`。
- **本物/モック**：一覧・経験保存・サイズ保存は本物。**「＋社員を追加」は `/account-new`（アカウント発行）へ集約**（旧 `/register` の見本は廃止）。

### 7-3. アカウント発行（`/account-new` → `@create/store`）
- **権限**：`tier:manager`。
- **store**：role(in:employee,staff)/name(required)/email(required,unique people)/permission(in:allowedPerms)/office/hire_date/temp_password(min6)。整合：staff→permission=staff固定、employee→permission≠staff。仮PW未入力なら8文字自動生成（紛らわしい文字除外）。ID＝E-###/S-### 発番。`Person::create`（password[hashed]、must_onboard=true、active=true）。発行結果はセッションで画面表示。
- **権限制約**：Administrator付与はadminのみ（`allowedPerms`＋`Rule::in` がサーバ側の本当の防御）。
- **本物/モック**：本物。**メール自動送信なし**（仮PWは画面表示で手渡し）。

### 7-4. Administratorコンソール（`/admin-console` → `@index/updatePermission`）
- **権限**：`tier:admin`。
- **updatePermission**：id(exists)/permission(in:employee,manager,admin)。制約：非employeeは変更不可／自分のadmin剥奪不可／最後のadmin降格不可／同一権限は「変わりませんでした」。
- **保存先**：`people.permission`。**本物**。

### 7-5. 共通設定（`/settings` → `@index/saveMtgDates`）
- **表示**：拠点件数＝**`people.office` の重複除去数**（offices表ではない・暫定、`SettingsController.php:16-19`）、コンテンツ件数＝`Content::count()`、ポジション件数＝`AssignmentRole::LABELS`数、MTG日＝`AssignMtg::dates()`。
- **saveMtgDates**：dates(present,array)/dates.*(date)→`settings` key='assign_mtg_dates'（trim・重複除去・昇順のJSON）。基準日＝`AssignMtg::current()`（今日以前で最新、無ければnull＝自動判定しない）。
- **危険日（手動指定）パネル（2026-07-17 追加）**：大型案件の日付一覧＋手動で危険日を追加/削除できる。`POST /settings/danger-dates`＝`SettingsController::saveDangerDates`（`:91-`）で `settings` key='manual_danger_dates' に保存。危険日判定（ダッシュボード等）に手動指定分が加わる。
- **本物/モック**：件数表示・MTG日保存・基準日計算・危険日手動指定は本物。拠点件数は people.office 集計のため offices 管理件数とずれる可能性（推測）。

### 7-6. マスタ管理（`/masters` → `MasterController` 各アクション）
- **権限**：社員以上。**削除2本（contentDestroy/officeDestroy）のみ `tier:admin`**。
- **コンテンツ**：contentStore（新規/更新、id無しは`CT-###`発番）、contentBulkStore（一括）、contentDestroy（＋`content_role_requirements`も削除）、contentReqs/contentReqsSave（規模×ポジションの必要人数を`content_role_requirements`に updateOrCreate、0は削除）。
- **拠点**：officeStore（末尾追加）、officeBulkStore、officeMove（up/down で sort_order 入れ替え）、officeDestroy。
- **ポジション**：`AssignmentRole::LABELS`（表示のみ・編集不可）。
- **本物/モック**：追加/編集/一括/削除/並び替え/必要人数は全て本物。ポジションは仕様上 表示のみ。

---

## 横断メモ（引き継ぎ注意）

- **`assigned_by`（アサイン操作者）は全経路 null 固定**＝認証導入後に必須化予定（`AssignDirectorController.php:234`、`AssignmentController.php:294`、migration コメント）。
- **`assignments.score`（自動提案スコア）は列だけ存在し未書込**。自動提案アルゴリズム自体が未実装（`AssignDetailController.php:148,152`）。
- **「自分」判定が未認証**：マイページ・収支＝baba固定（`PersonalCases.php:26-31`）、出勤可能日＝先頭社員（`employee_availability.blade.php:276-279`）。
- **対象月は当月 `now()` へ自動化済み**（2026-07-17、`AssignDashboardController.php:29-30`／`StaffStatusController.php:41-42`／`AssignWishlistController.php:44-45`）。**ただし `/staff-portal` の稼働希望カレンダーだけは7月固定グリッドのため未対応**（月切替の動的生成は別タスク）。
- **区分（新人/中堅/ベテラン）の基準が場所でブレる**：在籍年数（`Person::getSkillLevelAttribute`、名簿タブ、D決め等）と 通算回数（稼働状況タブ、希望まとめ）。
- **残っているモック/未保存の箇所**（2026-07-17 時点）：日別ボードの「確定/公開」状態遷移（割当操作自体は保存される）、マイページのダミーボタン（プロフィール/パスワード）、D決め月切替、`/staff-portal` の稼働希望カレンダーが7月固定グリッド。※以下は 2026-07-17 で本物化＝**もうモックではない**：スタッフ画面のエントリー（応募）、マイページ通知設定、収支保存、案件一覧の詳細セル編集・手動アーカイブ、公開ボードの💬備考、CSV出力（アサインダッシュボード・名簿）、社員名簿のサイズ編集、名簿CSVの「できるポジション」取込、日別ボードの割当・案件詳細（入口化）・ピックアップの±・アサイン表の編集。
- **cases.js 依存**：危険日判定・書き出し・サンプルはクライアントJS（`public/ecs/data/cases.js`）に依存。データ本体はDBだが、このJSが壊れると危険日警告等が動かない（推測：移行途中の依存）。
- **本番前チェック**：`ECS_TEST_LOGIN=false`、ログイン画面初期値削除、`CHATWORK_TOKEN`等の鍵設定、メール送信（SES）確認。
- **土台テーブル（未接続）**：`staff_content_experience`/`staff_role_experience`（経験の自動集計）、`skills`/`staff_skills`（スキル絞り込み）。自動アサインのスコアリング材料として準備済みだが画面・ロジック未接続（推測）。
