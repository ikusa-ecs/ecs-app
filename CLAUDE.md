# ECS Laravel 作業の入口

このフォルダ（`C:\Users\onuma\ecs_dev\ecs_laravel`）は ECS の本番コード（Laravel/Blade）。
画面編集はここの `resources/views/*.blade.php` で行う。
**モックは見本として凍結・触らない**：`G:\.shortcut-targets-by-id\1wtRKd0z73TyKcNSAd4TEDbrM_27KXL5H\ECS再開発2026_Claude開発\ECS_モック\*.html`、および本フォルダ内の `public/ecs/*.html`・`public/ecs/data/*.js`（実体はBladeが動く＝本実装の参照元ではない）。

## 作業を始める前に、必ず下記を読むこと（設計の「正」はすべて共有Googleドライブ `ECS再開発2026_Claude開発` にある。コピーは作らない＝唯一の正。※2026-06-30にOneDriveからここへ移行）
> 共有ドライブの実体パス＝`G:\.shortcut-targets-by-id\1wtRKd0z73TyKcNSAd4TEDbrM_27KXL5H\ECS再開発2026_Claude開発`
- `G:\.shortcut-targets-by-id\1wtRKd0z73TyKcNSAd4TEDbrM_27KXL5H\ECS再開発2026_Claude開発\エンジニア引き継ぎ資料.md`   ← 全体の入口
- `G:\.shortcut-targets-by-id\1wtRKd0z73TyKcNSAd4TEDbrM_27KXL5H\ECS再開発2026_Claude開発\作業リスト_共有.md`          ← 現在地・次にやること・設計の宿題
- `G:\.shortcut-targets-by-id\1wtRKd0z73TyKcNSAd4TEDbrM_27KXL5H\ECS再開発2026_Claude開発\ECS_設計書_v1.1.md`        ← 要件・データ設計の本体（正）
- `G:\.shortcut-targets-by-id\1wtRKd0z73TyKcNSAd4TEDbrM_27KXL5H\ECS再開発2026_Claude開発\ECS_図解_v1.1.md`          ← 図解（Mermaid）
- `G:\.shortcut-targets-by-id\1wtRKd0z73TyKcNSAd4TEDbrM_27KXL5H\ECS再開発2026_Claude開発\エンジニア確認事項_Laravel.md`  ← 技術の確定事項＋MTGで決めたいこと
- `G:\.shortcut-targets-by-id\1wtRKd0z73TyKcNSAd4TEDbrM_27KXL5H\ECS再開発2026_Claude開発\要検討事項まとめ.md`           ← 決定事項の一覧

> 設計の「正」は常に最新版（現在 v1.1）。v0.7〜v1.0 は過去版。
> Cursorでは共有ドライブの実体が見えないことがある。その場合の確実な開き方＝上の `G:\.shortcut-targets-by-id\...` パスを直接指定（マイドライブのショートカット経由は不可）。

## 作業ルール（小沼さんは非エンジニア）
- 専門用語を避け、日本語でわかりやすく・「なぜそうするか」を一言添える。
- 既存ファイルの変更/上書き/削除の前に、必ず一度確認を取る。
- 大きな変更・複数ファイルにまたがる変更は、先に「何をするか」を日本語で説明し、小さく分けて進める。
- 不確かなときは推測で進めず質問する。

## チャットワークに送る文章を用意するとき
- 送る文章は必ず `稼働管理\ECS\チャットワーク送信用.txt` に書く（毎回上書きでOK）。
- 理由：ターミナルから直接コピーすると日本語が文字化けするため。ファイルに書けばメモ帳で開いてそのままコピーできる。
- 表・罫線などの装飾記号を避け、そのまま読めるプレーンな文章にする。

## DB・起動（現状：SQLite）
- 現状＝5テーブル作成・シード済み（`people`/`contents`/`projects`/`staff_role_eligibility`/`staff_relations`）。
- 起動：`ECS起動.bat`（ダブルクリック）または `php artisan serve` → http://127.0.0.1:8000
- よく使うコマンド：
  - `php artisan migrate` … 新しいテーブルを追加で作る
  - `php artisan db:seed` … 見本データを入れ直す（追加）
  - `php artisan migrate:fresh --seed` … **DBを全消ししてゼロから作り直す（⚠登録した実データも消える。実行前に必ず確認を取る）**
- ⚠ 本番DB（MySQL）・認証・デプロイは **MTG後**（手戻り防止）。今は SQLite のまま進める。
