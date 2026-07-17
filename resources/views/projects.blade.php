@extends('layouts.app')
@section('title', '案件一覧')
@section('h1', '案件一覧')
@php($active = 'projects')

@push('head')
{{-- 案件データは DB から（Controller が cases.js と同じ形に整えて渡す）。
     これまでの <script src="/ecs/data/cases.js"> の代わり。表示JSはそのまま動く。 --}}
<script>
  window.ECS_CASES = @json($cases);
  window.ECS_CSRF = '{{ csrf_token() }}';   // ケータリング等の保存に使う合言葉
  window.ECS_REPEAT_CLIENTS = @json($repeatClients ?? []);   // リピート（常連）クライアント名の集合（名前→true）
  window.ECS_EMPLOYEES = @json($employees ?? []);   // D／SD／物品担当プルダウン用の社員一覧（id,name）
</script>
@verbatim
<style>
    /* 案件一覧モック専用スタイル */

    /* 絞り込みバー */
    .filter-bar {
      display: flex; align-items: flex-end; gap: 14px; flex-wrap: wrap;
    }
    .filter-bar .f-item { display: flex; flex-direction: column; gap: 5px; }
    .filter-bar .f-item label { font-size: 12px; font-weight: 600; color: var(--muted); }
    .filter-bar .f-item input,
    .filter-bar .f-item select {
      padding: 8px 11px; border: 1px solid var(--line); border-radius: 8px;
      font-size: 13.5px; font-family: inherit; background: #fff; min-width: 130px;
    }
    .filter-bar .f-item input:focus,
    .filter-bar .f-item select:focus { outline: 2px solid var(--brand-soft); border-color: var(--brand); }
    .filter-bar .spacer { flex: 1; }

    /* 件数表示 */
    .count-line { font-size: 13px; color: var(--muted); margin: 16px 0 8px; }
    .count-line b { color: var(--ink); }

    /* 見出しは折り返さない */
    table.tbl th { white-space: nowrap; }
    table.tbl td { vertical-align: middle; }

    /* 日程グループの見出し行 */
    tr.group-row td {
      background: var(--brand-soft); color: var(--brand-dark);
      font-weight: 700; font-size: 13px; padding: 9px 12px;
      border-bottom: 1px solid var(--line);
    }
    tr.group-row .g-count { color: var(--muted); font-weight: 600; margin-left: 8px; font-size: 12px; }
    tr.group-row.past td { background: #f1ece3; color: var(--muted); }
    /* 見出しはクリックでその月を開閉できる */
    tr.group-row { cursor: pointer; }
    tr.group-row td:hover { filter: brightness(0.97); }
    .gcaret { display: inline-block; width: 14px; font-size: 11px; }

    /* クリックで開く案件行 */
    tr.main-row { cursor: pointer; }
    tr.main-row:hover { background: #f7f1e6; }
    td.caret-cell { width: 24px; text-align: center; color: var(--muted); }
    td.caret-cell .caret { font-size: 11px; }

    /* 日付セル */
    td.date-cell { white-space: nowrap; font-variant-numeric: tabular-nums; }
    td.date-cell .dow { font-size: 11.5px; color: var(--muted); margin-left: 3px; }
    td.date-cell .dow.sun { color: var(--danger); }
    td.date-cell .dow.sat { color: var(--brand); }

    /* 確度（ヨミ）の小マーク（日程の横）。確定は表示しない */
    .ymk { font-size: 11px; font-weight: 700; padding: 0 7px; border-radius: 999px; margin-left: 6px; }
    .ymk.a { background: var(--brand-soft); color: var(--brand-dark); }
    .ymk.b { background: var(--warn-soft);  color: #b45309; }
    .ymk.c { background: #ece3d4;           color: #7a6a58; }
    /* 大型マーク（日程のヨミの横） */
    .big-mark { font-size: 11px; font-weight: 700; padding: 0 7px; border-radius: 999px; margin-left: 6px; background: var(--brand); color: #fff; }

    /* 案件名セル（3行：コンテンツ名／実施形態／会社名） */
    td.proj-cell { min-width: 190px; }
    td.proj-cell strong { font-size: 14px; display: inline-block; margin-bottom: 3px; }
    .sub-info { font-size: 11.5px; color: var(--muted); margin-top: 3px; line-height: 1.45; }

    /* 案件名の横の小タグ */
    .tag-mini { font-size: 10.5px; padding: 1px 6px; border-radius: 999px; font-weight: 700; margin-left: 5px; }
    .tag-mini.add   { background: var(--danger-soft); color: #b91c1c; }
    .tag-mini.multi { background: var(--brand-soft);  color: var(--brand-dark); }
    .tag-mini.yobi  { background: var(--warn-soft);   color: #b45309; }
    .tag-mini.reha  { background: #ece3d4;            color: #7a6a58; }
    .tag-mini.stay  { background: #e8833a;            color: #fff; }
    .tag-mini.draft { background: #6b5544;            color: #fff; }
    .tag-mini.repeat{ background: var(--brand);       color: #fff; }  /* リピート（常連）クライアント */

    /* 下書きの行（準備中とわかるよう薄い色＋左に印） */
    tr.main-row.draft { background: #f3eee4; }
    tr.main-row.draft:hover { background: #ede5d6; }
    tr.main-row.draft td.caret-cell { box-shadow: inset 3px 0 0 #6b5544; }

    /* 日程の下に出すタグ（追加案件・前泊） */
    td.date-cell .date-tags { margin-top: 5px; white-space: normal; }
    td.date-cell .date-tags .tag-mini { margin-left: 0; margin-right: 4px; }

    /* 実施形態の色分けバッジ */
    .fbadge { display: inline-block; font-size: 11px; font-weight: 700; padding: 1px 8px; border-radius: 6px; }
    .fbadge.fmt-real   { background: #e7f0e9; color: #3f7d52; }  /* リアル */
    .fbadge.fmt-long   { background: #fdecd9; color: #b4530a; }  /* リアルロング */
    .fbadge.fmt-online { background: #e3edf7; color: #2c6ca0; }  /* オンライン */
    .fbadge.fmt-arena  { background: #efe6f6; color: #6d28d9; }  /* ARENA場所貸し */
    .fbadge.fmt-other  { background: #e1f1ee; color: #0f766e; }  /* 他拠点 */
    .fbadge.fmt-etc    { background: #ece3d4; color: #7a6a58; }  /* その他 */

    /* 集合・解散時間（＋下に小さく 入場/開始/終了） */
    td.time-cell { white-space: nowrap; font-variant-numeric: tabular-nums; font-size: 13px; }
    td.time-cell .ev { font-size: 11px; color: var(--muted); margin-top: 2px; white-space: nowrap; }

    /* 参加者 / チーム数 */
    td.pt-cell { white-space: nowrap; font-variant-numeric: tabular-nums; }
    td.pt-cell .sep { color: var(--muted); margin: 0 2px; }

    /* 担当（営業＋ディレクターを上下2段に） */
    td.person { white-space: nowrap; }
    td.person .dir-line { font-size: 11.5px; color: var(--muted); margin-top: 2px; }

    /* 状況（募集＋アサインを上下2段に） */
    td.status-cell .st-asgn { margin-top: 4px; }

    /* 状況 */
    td.status-cell { white-space: nowrap; }
    td.status-cell .na, td.recruit-cell .na { color: var(--muted); }

    /* 募集状態 */
    td.recruit-cell { white-space: nowrap; }
    .recruit-badge { font-size: 11.5px; font-weight: 700; padding: 2px 9px; border-radius: 999px; }
    .recruit-badge.open   { background: #16a34a; color: #fff; }            /* 募集中＝目立つ緑 */
    .recruit-badge.closed { background: #ece3d4; color: #7a6a58; }         /* 締切 */
    .recruit-badge.pre    { background: var(--warn-soft); color: #b45309; }/* 募集前 */
    .recruit-badge.draft  { background: #6b5544; color: #fff; }            /* 下書き＝準備中 */

    /* 操作リンク */
    td.ops a { font-size: 12.5px; margin-right: 10px; white-space: nowrap; }
    /* 削除は目立たせすぎない補助的な赤リンク（キャンセル案件を消す用・元に戻せない） */
    td.ops a.del-link { color: var(--danger); }

    /* ===== 詳細（折りたたみ）行 ===== */
    tr.detail-row > td { background: #faf6ee; padding: 10px 16px 11px; border-bottom: 1px solid var(--line); }
    .detail-panel { display: flex; flex-wrap: wrap; gap: 10px 22px; align-items: flex-start; }
    .detail-panel .d-item { display: flex; flex-direction: column; gap: 4px; min-width: 120px; }
    .detail-panel .d-label { font-size: 11px; font-weight: 700; color: var(--muted); }
    .detail-panel .checks { display: flex; gap: 12px; align-items: center; padding-top: 3px; }
    .detail-panel .checks label { font-size: 12.5px; display: inline-flex; align-items: center; gap: 4px; cursor: pointer; }
    .detail-panel .checks span { font-size: 12.5px; display: inline-flex; align-items: center; gap: 4px; }

    /* 準備チェックの横に制作・記録（ロゴ/カメラ/事例記事/動画）を並べる */
    .detail-panel .prep-pub { display: flex; gap: 14px 28px; align-items: flex-start; flex-wrap: wrap; padding-top: 3px; }
    .detail-panel .pub-inline { display: flex; gap: 6px 16px; align-items: center; flex-wrap: wrap; }
    .detail-panel .pub-inline .pub-cap { font-size: 11px; font-weight: 700; color: var(--muted); }
    .detail-panel .pub-inline .pub-item { font-size: 12.5px; }
    .detail-panel .pub-inline .pub-item .pub-k { color: var(--muted); margin-right: 4px; }

    /* ケータリング：選べるプルダウン＋「無」以外のとき出るメモ欄 */
    .detail-panel .pub-inline .cat-ctl { display: inline-flex; align-items: center; gap: 6px; }
    .detail-panel .cat-select {
      border: 1px solid var(--line); background: #fff; border-radius: 8px;
      padding: 4px 6px; font-family: inherit; font-size: 12.5px; color: var(--ink); cursor: pointer;
    }
    .detail-panel .cat-note {
      border: 1px solid var(--line); border-radius: 8px; padding: 5px 8px;
      font-family: inherit; font-size: 12.5px; min-width: 220px;
    }
    .detail-panel .cat-select:focus, .detail-panel .cat-note:focus { outline: 2px solid var(--brand-soft); border-color: var(--brand); }

    /* 運営シート（スプレッドシート）リンク */
    .sheet-link {
      display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px;
      padding: 6px 10px; border: 1px solid var(--line); border-radius: 8px;
      background: #fff; white-space: nowrap; color: var(--ink);
    }
    .sheet-link:hover { background: #f3ece0; text-decoration: none; }
    .sheet-link.create { color: var(--brand-dark); }

    /* セル内インライン編集プルダウン（コンパクト） */
    .cell-edit {
      min-width: 92px; border: 1px solid var(--line); background: #fff;
      border-radius: 8px; padding: 5px 7px; font-family: inherit; font-size: 12.5px;
      color: var(--ink); cursor: pointer; appearance: auto;
    }
    .cell-edit:focus { outline: 2px solid var(--brand-soft); border-color: var(--brand); }

    /* 移動・音響を縦に並べる小項目 */
    .mini-field { display: flex; align-items: center; gap: 5px; }
    .mini-field + .mini-field { margin-top: 5px; }
    .mini-field .mini-label { font-size: 11px; color: var(--muted); white-space: nowrap; width: 28px; }

    /* 備考の📝マーク（案件名の横） */
    .note-flag { font-size: 13px; margin-left: 5px; cursor: pointer; }

    /* 詳細内の備考テキスト */
    .note-text {
      font-size: 12.5px; color: var(--ink); line-height: 1.5;
      background: #fff; border: 1px solid var(--line); border-radius: 8px;
      padding: 7px 10px; max-width: 640px; white-space: pre-wrap;
    }

    /* 該当なし表示 */
    .empty-row td { text-align: center; color: var(--muted); padding: 28px 0; }

    /* 一覧 / 下書き の切替タブ */
    .list-tabs { display: flex; gap: 6px; margin: 0 0 14px; }
    .list-tab {
      padding: 9px 18px; border: 1px solid var(--line); border-radius: 8px 8px 0 0;
      background: #fff; color: var(--muted); font-size: 13.5px; font-weight: 600; cursor: pointer;
    }
    .list-tab:hover { background: #f3ece0; text-decoration: none; }
    .list-tab.active { background: var(--brand); border-color: var(--brand); color: #fff; }
    .tab-badge {
      display: inline-block; min-width: 18px; padding: 0 6px; margin-left: 4px;
      border-radius: 999px; background: #6b5544; color: #fff;
      font-size: 11.5px; font-weight: 700; text-align: center;
    }
    .list-tab.active .tab-badge { background: rgba(255,255,255,.35); }

    /* 社員・ディレクター集計（ボタンで開くパネル） */
    .agg-bg {
      display: none; position: fixed; inset: 0; background: rgba(15,23,42,.5);
      z-index: 60; align-items: flex-start; justify-content: center; padding: 40px 16px; overflow: auto;
    }
    .agg-bg.show { display: flex; }
    .agg-modal { background: #fff; border-radius: 14px; width: 920px; max-width: 96vw; box-shadow: 0 24px 60px rgba(0,0,0,.4); }
    .agg-head { display: flex; align-items: center; gap: 12px; padding: 16px 22px; border-bottom: 1px solid var(--line); flex-wrap: wrap; }
    .agg-head h2 { margin: 0; font-size: 17px; }
    .agg-head .month-nav { display: flex; align-items: center; gap: 8px; }
    .agg-head .month-nav button { border: 1px solid var(--line); background: #fff; border-radius: 8px; width: 30px; height: 30px; font-size: 15px; cursor: pointer; font-family: inherit; }
    .agg-head .month-nav .mon { font-size: 14px; font-weight: 700; min-width: 96px; text-align: center; }
    .agg-head .spacer { flex: 1; }
    .agg-close { border: 1px solid var(--line); background: #fff; border-radius: 8px; width: 34px; height: 34px; font-size: 18px; cursor: pointer; font-family: inherit; }
    .agg-body { padding: 16px 22px 24px; }
    .agg-body .note { font-size: 12.5px; color: var(--muted); margin: 0 0 12px; line-height: 1.6; }
    .agg-body table.tbl th.num, .agg-body table.tbl td.num { text-align: right; font-variant-numeric: tabular-nums; }
    .agg-body table.tbl td.nm { font-weight: 600; }
    .agg-body tr.agg-total td { font-weight: 700; background: var(--brand-soft); color: var(--brand-dark); }
    .agg-body .col-grp { color: var(--muted); font-size: 11px; font-weight: 600; }

    /* ===== サイドバーの年月フォルダ ===== */
    .ym-tree { margin: 2px 0 2px 8px; display: flex; flex-direction: column; gap: 1px; }
    .ym-year-btn, .ym-month-btn {
      display: flex; align-items: center; gap: 6px; width: 100%;
      border: none; background: none; cursor: pointer; font-family: inherit;
      color: #6e5b49; text-align: left; border-radius: 8px;
    }
    .ym-year-btn  { padding: 6px 10px; font-size: 12.5px; font-weight: 700; }
    .ym-month-btn { padding: 5px 10px 5px 26px; font-size: 12.5px; }
    .ym-year-btn:hover, .ym-month-btn:hover { background: #dccbb1; color: #4f4338; }
    .ym-month-btn.active { background: var(--brand); color: #fff; font-weight: 700; }
    .ym-caret { width: 12px; font-size: 10px; color: #a08a73; flex-shrink: 0; }
    .ym-year-btn .ym-ycount, .ym-month-btn .ym-mcount {
      margin-left: auto; font-size: 11px; color: #a08a73; font-weight: 600;
    }
    .ym-month-btn.active .ym-mcount { color: rgba(255,255,255,.85); }
    .ym-months { display: flex; flex-direction: column; gap: 1px; }
    .ym-months.collapsed { display: none; }

    /* 月へジャンプしたときの見出し点滅 */
    @keyframes groupFlash {
      0%   { background: var(--brand); color: #fff; }
      100% { background: var(--brand-soft); color: var(--brand-dark); }
    }
    tr.group-row.flash td { animation: groupFlash 1.4s ease-out; }

    /* ===== アサイン表へ書き出し モーダル ===== */
    .exp-bg {
      display: none; position: fixed; inset: 0; background: rgba(15,23,42,.5);
      z-index: 70; align-items: flex-start; justify-content: center; padding: 40px 16px; overflow: auto;
    }
    .exp-bg.show { display: flex; }
    .exp-modal { background: #fff; border-radius: 14px; width: 780px; max-width: 96vw; box-shadow: 0 24px 60px rgba(0,0,0,.4); }
    .exp-head { display: flex; align-items: center; gap: 12px; padding: 16px 22px; border-bottom: 1px solid var(--line); }
    .exp-head h2 { margin: 0; font-size: 17px; }
    .exp-head .spacer { flex: 1; }
    .exp-body { padding: 16px 22px 22px; }
    .exp-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
    .exp-row label { font-size: 13px; font-weight: 600; color: var(--muted); }
    .exp-row select { padding: 8px 11px; border: 1px solid var(--line); border-radius: 8px; font-size: 13.5px; font-family: inherit; background: #fff; }
    .exp-count { font-size: 13px; color: var(--muted); }
    .exp-count b { color: var(--ink); }
    .exp-ta {
      width: 100%; height: 200px; box-sizing: border-box; font-family: Consolas, "Courier New", monospace;
      font-size: 12px; border: 1px solid var(--line); border-radius: 8px; padding: 10px;
      white-space: pre; overflow: auto; background: #faf6ee; color: var(--ink);
    }
    .exp-steps { font-size: 12.5px; color: var(--ink); line-height: 1.8; background: #f3ece0; border-radius: 8px; padding: 11px 14px; margin-top: 12px; }
    .exp-steps b { color: var(--brand-dark); }
    .copied-msg { color: #16a34a; font-weight: 700; font-size: 13px; margin-left: 8px; display: none; }
    .copied-msg.show { display: inline; }
</style>
@endverbatim
@endpush

@section('content')
@if (session('status'))
<div class="mock-note" style="background:#e7f0e9; border-color:#cdeccf; color:#15803d;">✓ {{ session('status') }}</div>
@endif
@verbatim
      <div class="mock-note">ここに出ている案件は<b>登録された本物のデータ</b>です。<b>各行をクリックすると下に開き</b>、内容を確認できます。案件の中身を直すときは各行の「編集」からどうぞ。<b>リピート（常連）のクライアントは、クライアント名を押すと過去のアサインをさかのぼれます。</b><br><b>開催日が過ぎた案件は自動で「🗄 アーカイブ」タブに移ります。</b>各行の「🗄 アーカイブ」で手動でも隠せ、アーカイブタブの「↩ 戻す」で元に戻せます。<br>※ 詳細を開いたときのプルダウン（ディレクター・SD・物品担当・移動・音響）と、手動での「🗄 アーカイブ／↩ 戻す」は、<b>その場で保存されます</b>（読み込み直しても残ります）。準備チェックだけは次の工程で対応予定です。</div>

      <!-- 絞り込みバー -->
      <div class="panel">
        <div class="filter-bar">
          <div class="f-item">
            <label>キーワード（案件名・会場）</label>
            <input type="text" id="kw" placeholder="例）水合戦、〇〇公園" oninput="applyFilter()">
          </div>
          <div class="f-item">
            <label>確度（ヨミ）</label>
            <select id="yomi" onchange="applyFilter()">
              <option value="">すべて</option>
              <option value="確定">確定</option>
              <option value="Aヨミ">Aヨミ</option>
              <option value="Bヨミ">Bヨミ</option>
              <option value="Cヨミ">Cヨミ</option>
            </select>
          </div>
          <div class="f-item">
            <label>実施形態</label>
            <select id="format" onchange="applyFilter()">
              <option value="">すべて</option>
              <option value="リアル">リアル系</option>
              <option value="オンライン">オンライン</option>
              <option value="ARENA">ARENA場所貸し</option>
              <option value="他拠点">他拠点（依頼・巻き取り）</option>
            </select>
          </div>
          <div class="f-item">
            <label>toC / toB</label>
            <select id="toc" onchange="applyFilter()">
              <option value="">すべて</option>
              <option value="toc">toC</option>
              <option value="tob">toB</option>
            </select>
          </div>
          <div class="f-item">
            <label>ケータリング</label>
            <select id="catering" onchange="applyFilter()">
              <option value="">すべて</option>
              <option value="あり">あり</option>
              <option value="なし">なし</option>
            </select>
          </div>
          <div class="f-item">
            <label>日程種別</label>
            <select id="kbn" onchange="applyFilter()">
              <option value="">すべて</option>
              <option value="本番">本番</option>
              <option value="予備日">予備日</option>
              <option value="リハ">リハ</option>
            </select>
          </div>
          <div class="spacer"></div>
          <div class="f-item">
            <label>&nbsp;</label>
            <div style="display:flex; gap:8px;">
              <button class="btn" type="button" onclick="openExport()">📤 アサイン表へ書き出し</button>
              <a class="btn" href="/project-import">⬆ CSVで取込</a>
              <a class="btn primary" href="/project-form">＋ 案件を登録</a>
            </div>
          </div>
        </div>
      </div>

      <!-- 案件一覧 / 下書き の切替タブ -->
      <div class="list-tabs">
        <a class="list-tab active" id="tab-list" onclick="switchTab('list')">案件一覧</a>
        <a class="list-tab" id="tab-draft" onclick="switchTab('draft')">下書き <span class="tab-badge" id="draftCount">0</span></a>
        <a class="list-tab" id="tab-archived" onclick="switchTab('archived')">🗄 アーカイブ <span class="tab-badge" id="archivedCount">0</span></a>
      </div>

      <div class="count-line">全 <b id="totalCount">0</b> 件中 <b id="shownCount">0</b> 件を表示（日程の近い順）</div>

      <!-- 案件テーブル（日程グループごと・クリックで詳細展開） -->
      <div class="panel">
        <table class="tbl">
          <thead>
            <tr>
              <th></th>
              <th>日程</th>
              <th>案件名</th>
              <th>担当</th>
              <th>集合・解散</th>
              <th>状況</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody id="projBody">
            <!-- 行はJSで生成 -->
          </tbody>
        </table>
      </div>

      <p class="muted" style="font-size:11.5px; margin:12px 0 0;">
        ※ 日程の横の「A／B／C」は営業の受注確度（ヨミ）です。「確定」は表示していません。<br>
        ※ 集合・解散の下の小さい数字は「入場 / 開始 / 終了」です。<br>
        ※「状況」はアサインの進み具合です。スタッフ募集をしない案件（会場貸し等）は「—」になります。
      </p>

      <!-- ===== アサイン表へ書き出し モーダル ===== -->
      <div class="exp-bg" id="expBg" onclick="if(event.target===this) closeExport()">
        <div class="exp-modal">
          <div class="exp-head">
            <h2>📤 アサイン表へ書き出し</h2>
            <div class="spacer"></div>
            <button class="agg-close" type="button" onclick="closeExport()">×</button>
          </div>
          <div class="exp-body">
            <div class="exp-row">
              <label>対象月</label>
              <select id="expMonth" onchange="renderExport()"></select>
              <span class="exp-count">この月の案件：<b id="expCount">0</b> 件</span>
              <div class="spacer" style="flex:1;"></div>
              <button class="btn primary" type="button" onclick="copyExport()">全選択してコピー</button>
              <span class="copied-msg" id="expCopied">✓ コピーしました</span>
            </div>
            <textarea class="exp-ta" id="expTa" readonly onclick="this.select()"></textarea>
            <div class="exp-steps">
              <b>使い方：</b><br>
              ① 上の「全選択してコピー」を押す（下の表がコピーされます）<br>
              ② アサイン表（スプレッドシート）の <b>「ECS取込」シート</b> を開き、<b>A1のセル</b>を選んで貼り付け（Ctrl+V）<br>
              ③ メニュー「拡張機能 → Apps Script」を開き、関数 <b>ECS反映</b> を実行<br>
              → 日付から行き先の月シート（例 <b>202607</b>）を自動で判断して流し込みます。
            </div>
          </div>
        </div>
      </div>
@endverbatim
@endsection

@push('scripts')
@verbatim
<script>
  // ===== 選択肢（プルダウン） =====
  // ディレクター／SD／物品担当は「本物の社員一覧」（window.ECS_EMPLOYEES＝id,name）から作る。
  const EMPLOYEES = Array.isArray(window.ECS_EMPLOYEES) ? window.ECS_EMPLOYEES : [];
  const TRANSPORTS = ['IKUSAカー', 'IKUSAカー2台', 'IKUSAカー3台', '電車', 'レンタカー',
                      'IKUSAカー+レンタカー', '電車+IKUSAカー', '電車+レンタカー', '飛行機', '飛行機+レンタカー'];
  const SOUND = ['会場音響', 'クラシックプロ大', 'クラシックプロ中', 'クラシックプロ小', 'CUBE', 'SANWA', 'TOA', '不要'];
  // ケータリングの選択肢（案件登録フォームと同じ並び）。詳細で選べる。
  const CATERING_OPTS = ['無', 'ケータリング', 'オードブル', 'お弁当', 'キッチンカー', 'BBQ', 'LH発注あり（格付け）', 'LH発注あり（ゴチ）', 'その他'];
  // ケータリングが「あり」（＝無・空・なし系ではない）か
  function isCateringOn(v) { return !!v && !['', '-', '−', '無', '無し', 'なし'].includes(String(v).trim()); }

  // ===== 案件一覧（共通リスト data/cases.js から作る）=====
  // 案件一覧は全案件を表示（過去・下書きも含む）。月グループ／フォルダで月またぎを見せる。
  const projects = ECS_CASES.map(c => {
    const isParent = ECS_CASES.some(x => x.parentId === c.id);   // 予備日/リハの親（＝複数日案件）か
    return {
      content:c.content, client:c.client, place:c.placeShort || c.place, dayType:c.dayType,
      category:c.category, yomi:c.yomi, format:c.format, sales:c.sales, director:c.dir,
      meet:c.meet, leave:c.leave, enter:c.enter, evStart:c.evStart, evEnd:c.evEnd,
      guests:c.guests, teams:c.teams, goods:c.goods, transport:c.transport, sound:c.sound,
      lodging:c.lodging, recruit:c.recruit, status:c.status,
      lineSent:c.lineSent, handover:c.handover, script:c.script, opSheet:c.opSheet,
      offset:c.off, multi:(c.parentId != null) || isParent, tentative:!!c.tentative,
      area:c.area, catering:c.catering, agency:c.agency,
      logo:c.logo, camera:c.camera, article:c.article, video:c.video,
      note:c.note || undefined, draft:!!c.draft, archived:!!c.archived, scale:c.scale, sd:c.sd, id:c.id,
      toc:!!c.toc, cateringNote:c.cateringNote,
      // 詳細プルダウンの現在値（社員ID）。担当なしは null。音響(sound)は上で設定済み。
      directorId:c.director_id, sdId:c.sd_id, goodsId:c.goods_owner_id
    };
  });
  projects.forEach((p, i) => { p._i = i; });   // 編集・展開用に番号を保持
  // 規模(scale)・SD(sd) は共通データ（data/cases.js）側で持っているのでここでは設定しない。

  const kbnClass    = { '予備日':'yobi', 'リハ':'reha' };
  const yomiMark    = { 'Aヨミ':{ t:'A', c:'a' }, 'Bヨミ':{ t:'B', c:'b' }, 'Cヨミ':{ t:'C', c:'c' } };
  const statusBadge = { '未着手':'amber', '調整中':'blue', '確定':'green' };
  const rsClass     = { '募集中':'open', '締切':'closed', '募集前':'pre', '下書き':'draft' };
  const DOW = ['日','月','火','水','木','金','土'];

  // 募集状態（モックの簡易ルール）：下書き＝準備中／募集しない案件＝なし／充足（確定）＝締切／予備日＝募集前／それ以外＝募集中
  function recruitStateOf(p) {
    if (p.draft)               return '下書き';
    if (!p.recruit)            return 'なし';
    if (p.status === '確定')   return '締切';
    if (p.dayType === '予備日') return '募集前';
    return '募集中';
  }

  // ===== 日付ユーティリティ =====
  function atMidnight(d) { const x = new Date(d); x.setHours(0,0,0,0); return x; }
  function addDays(d, n) { const x = new Date(d); x.setDate(x.getDate() + n); return x; }
  function fmtMD(d) { return (d.getMonth()+1) + '/' + d.getDate(); }

  const today = atMidnight(new Date());
  const todayY = today.getFullYear();
  const todayM = today.getMonth() + 1;   // 1〜12

  // 各案件に実際の日付と「年月グループ」を割り当て（キー＝"2026-7" のような年月）
  projects.forEach(p => {
    p.date = addDays(today, p.offset);
    p.gy = p.date.getFullYear();
    p.gm = p.date.getMonth() + 1;
    p.group = p.gy + '-' + p.gm;
    // アーカイブ状態はサーバ（実効アーカイブ判定）から受け取った c.archived をそのまま使う。
    // ＝ is_archived が未設定なら「開催日<今日」で自動、手動で隠す/戻すをしていればそれを優先。
  });

  // 案件のある年月を、日付の早い順に並べてグループ一覧をつくる
  const GROUPS = [];
  {
    const seen = {};
    projects.slice().sort((a, b) => a.date - b.date).forEach(p => {
      if (seen[p.group]) return;
      seen[p.group] = true;
      const past = (p.gy < todayY) || (p.gy === todayY && p.gm < todayM);
      GROUPS.push({ key: p.group, label: p.gy + '年 ' + p.gm + '月', year: p.gy, month: p.gm, past });
    });
  }

  function formatMatches(fmt, sel) {
    if (!sel) return true;
    if (sel === 'リアル')    return fmt.indexOf('リアル') !== -1;
    if (sel === 'オンライン') return fmt.indexOf('オンライン') !== -1;
    if (sel === 'ARENA')     return fmt.indexOf('ARENA') !== -1;
    if (sel === '他拠点')    return fmt.indexOf('他拠点') !== -1;
    return true;
  }
  function kbnKey(dayType) { return dayType === '予備日' ? '予備日' : (dayType.indexOf('リハ') !== -1 ? 'リハ' : '本番'); }

  // 実施形態 → 色分けクラス
  function formatClass(fmt) {
    if (fmt.indexOf('ARENA') !== -1)     return 'fmt-arena';
    if (fmt.indexOf('オンライン') !== -1) return 'fmt-online';
    if (fmt.indexOf('他拠点') !== -1)     return 'fmt-other';
    if (fmt.indexOf('リアルロング') !== -1) return 'fmt-long';
    if (fmt.indexOf('リアル') !== -1)     return 'fmt-real';
    return 'fmt-etc';
  }

  // 社員ID → 名前（EMPLOYEES から引く）。無ければ空文字。
  function empName(id) {
    const e = EMPLOYEES.find(x => x.id === id);
    return e ? e.name : '';
  }

  // 担当（D／SD／物品）プルダウンHTML。先頭に「担当なし」（空）、続けて社員一覧。現在値を選択状態に。
  function employeeSelectHtml(idx, id, field, currentId, emptyLabel) {
    let opts = `<option value=""${!currentId ? ' selected' : ''}>${emptyLabel}</option>`;
    EMPLOYEES.forEach(e => {
      opts += `<option value="${e.id}"${e.id === currentId ? ' selected' : ''}>${e.name}</option>`;
    });
    return `<select class="cell-edit" onchange="onCellSaveEmployee(${idx}, '${id}', '${field}', this)">${opts}</select>`;
  }

  // 移動・音響プルダウンHTML（自由記述の候補一覧）。現在値が一覧に無ければ先頭に足して選択。空＝未設定。
  function textSelectHtml(idx, id, field, options, currentVal, noneLabel) {
    const cur = (currentVal && currentVal !== 'ー') ? String(currentVal) : '';
    let list = options.slice();
    if (cur && list.indexOf(cur) === -1) list = [cur].concat(list);
    let opts = `<option value=""${cur === '' ? ' selected' : ''}>${noneLabel}</option>`;
    opts += list.map(o => `<option${o === cur ? ' selected' : ''}>${o}</option>`).join('');
    return `<select class="cell-edit" onchange="onCellSaveText(${idx}, '${id}', '${field}', this)">${opts}</select>`;
  }

  // 詳細セルをDBに保存（POST /projects/cells）。変えたキーだけ送る（他項目は消さない）。
  function saveCell(id, field, value) {
    const payload = { id: id };
    payload[field] = value;
    fetch('/projects/cells', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF },
      body: JSON.stringify(payload)
    })
    .then(r => { if (!r.ok) throw new Error('save failed'); })
    .catch(() => alert('保存に失敗しました。もう一度お試しください。'));
  }

  // 担当（D／SD／物品）を選んだとき：DB保存＋画面表示・集計を更新。
  function onCellSaveEmployee(idx, id, field, sel) {
    const val = sel.value;                       // 社員ID or ''（担当なし）
    saveCell(id, field, val);
    const name = val ? (empName(val) || '未定') : (field === 'sd_id' ? '未設定' : '未定');
    if (field === 'director_id') {
      projects[idx].director = name;
      const el = document.getElementById('dir-' + idx);
      if (el) el.textContent = name;             // 一覧行のD表示も更新
      pushAgg();
    } else if (field === 'sd_id') {
      projects[idx].sd = name;
      pushAgg();
    } else if (field === 'goods_owner_id') {
      projects[idx].goods = name;
    }
  }

  // 移動・音響を選んだとき：DB保存＋手元データを更新。
  function onCellSaveText(idx, id, field, sel) {
    const val = sel.value;
    saveCell(id, field, val);
    if (field === 'transport')       projects[idx].transport = val || 'ー';
    if (field === 'audio_equipment') projects[idx].sound = val;
  }

  // ===== ケータリング（選択＋メモ）をDBに保存 =====
  // 「無」以外を選ぶと横にメモ欄を出す。プルダウン・メモを変えるたびに保存する。
  function onCateringPick(id, sel) {
    const wrap = sel.closest('.cat-ctl');
    const note = wrap ? wrap.querySelector('.cat-note') : null;
    if (note) note.style.display = isCateringOn(sel.value) ? '' : 'none';
    saveCatering({ id: id, catering: sel.value });
  }
  function onCateringNote(id, inp) {
    saveCatering({ id: id, catering_note: inp.value });
  }
  function saveCatering(payload) {
    fetch('/projects/catering', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF },
      body: JSON.stringify(payload)
    })
    .then(r => { if (!r.ok) throw new Error('save failed'); })
    .catch(() => alert('ケータリングの保存に失敗しました。もう一度お試しください。'));
  }

  // 運営シート（スプシ）の作成（モック）：押すと「作成済」になり「シートを開く」に切り替わる
  // ★連携ポイント：本番では、ここで「コンテンツごとの雛型ドライブを作成する既存スクリプト」を呼び出して
  //   作られたスプレッドシートのURLを opSheet に入れる（いまbabaさんが動かしているスクリプトと後でがっちゃんこする）。
  function createSheet(idx, el) {
    const content = projects[idx].content;   // コンテンツ名（雛型の出し分けに使う）
    // TODO（連携）: createTemplateDrive(content) → 返ってきたURLを projects[idx].opSheet にセット
    projects[idx].opSheet = 'created';        // モックでは作成済み扱いにするだけ
    window.open('https://docs.google.com/spreadsheets/create', '_blank');
    el.className = 'sheet-link';
    el.textContent = '📄 シートを開く';
    el.setAttribute('href', 'https://docs.google.com/spreadsheets/');
    el.setAttribute('target', '_blank');
    el.onclick = function (e) { e.stopPropagation(); };
  }

  // ===== テーブル描画 =====
  const COLSPAN = 7;
  const tbody = document.getElementById('projBody');

  GROUPS.forEach(g => {
    const items = projects.filter(p => p.group === g.key).sort((a,b) => a.date - b.date);
    if (items.length === 0) return;

    // グループ見出し行
    const gr = document.createElement('tr');
    gr.className = 'group-row' + (g.past ? ' past' : '');
    gr.dataset.group = g.key;
    gr.id = 'group-' + g.key;
    gr.setAttribute('onclick', `toggleGroup('${g.key}')`);
    gr.innerHTML = `<td colspan="${COLSPAN}"><span class="gcaret">▼</span>${g.label}${g.past ? '（終了）' : ''}<span class="g-count">${items.length}件</span></td>`;
    tbody.appendChild(gr);

    items.forEach(p => {
      const dow = DOW[p.date.getDay()];
      const dowCls = p.date.getDay() === 0 ? 'sun' : (p.date.getDay() === 6 ? 'sat' : '');
      const kk = kbnKey(p.dayType);

      // 確度マーク（確定は出さない）
      const ym = yomiMark[p.yomi];
      const ymHtml = ym ? `<span class="ymk ${ym.c}">${ym.t}</span>` : '';

      // 日程の下のタグ（追加案件・前泊）
      let dateTags = '';
      if (p.category === '追加案件')       dateTags += '<span class="tag-mini add">追加</span>';
      if (p.lodging && p.lodging !== '無') dateTags += `<span class="tag-mini stay">${p.lodging}</span>`;
      const dateTagsHtml = dateTags ? `<div class="date-tags">${dateTags}</div>` : '';

      // 案件名の横タグ（下書き・予備日/リハ・複数案件）
      let tags = '';
      if (p.draft)       tags += '<span class="tag-mini draft">下書き</span>';
      if (kk !== '本番') tags += `<span class="tag-mini ${kbnClass[p.dayType] || 'reha'}">${kk}</span>`;
      if (p.multi)       tags += '<span class="tag-mini multi">複数</span>';

      // 備考があれば📝マーク（一覧でひと目で気づけるように）
      const hasNote = p.note && p.note.trim();
      const noteFlag = hasNote ? '<span class="note-flag" title="備考あり（クリックで開く）">📝</span>' : '';

      // 集合・解散の下の入場/開始/終了（無いものは出さない）
      const evHtml = (p.enter && p.enter !== '—')
        ? `<div class="ev">${p.enter}/${p.evStart}/${p.evEnd}</div>` : '';

      // 募集状態
      const rs = recruitStateOf(p);
      const recruitHtml = rs === 'なし'
        ? '<span class="na">—</span>'
        : `<span class="recruit-badge ${rsClass[rs]}">${rs}</span>`;

      // 状況（下書き・スタッフ募集しない案件は「—」）
      const statusHtml = (p.recruit && !p.draft)
        ? `<span class="badge ${statusBadge[p.status]}">${p.status}</span>`
        : '<span class="na">—</span>';

      // ----- 案件行（クリックで詳細展開） -----
      const tr = document.createElement('tr');
      tr.className = 'main-row' + (p.draft ? ' draft' : '');
      tr.dataset.group  = g.key;
      tr.dataset.idx    = p._i;
      tr.dataset.name   = p.content + ' ' + p.place + ' ' + p.client;
      tr.dataset.status = p.status;
      tr.dataset.yomi   = p.yomi;
      tr.dataset.format = p.format;
      tr.dataset.kbn    = kk;
      tr.dataset.recruit = rs;
      tr.dataset.toc     = p.toc ? '1' : '0';
      tr.dataset.catering = isCateringOn(p.catering) ? 'あり' : 'なし';
      tr.dataset.draft  = p.draft ? '1' : '0';
      tr.dataset.archived = p.archived ? '1' : '0';
      tr.setAttribute('onclick', `toggleDetail(${p._i})`);

      // リピート（常連）クライアントなら、クライアント名を履歴（/assign-history）へのリンクにし「リピート」バッジを付ける。
      const clientTrim = (p.client || '').trim();
      const isRepeatClient = !!(window.ECS_REPEAT_CLIENTS && clientTrim && window.ECS_REPEAT_CLIENTS[clientTrim]);
      const clientNameHtml = isRepeatClient
        ? `<a href="/assign-history?client=${encodeURIComponent(clientTrim)}" onclick="event.stopPropagation()">${p.client}</a>`
        : (p.client || '');
      const repeatBadge = isRepeatClient ? '<span class="tag-mini repeat">リピート</span>' : '';
      const clientLine = (p.agency ? `${clientNameHtml}（${p.agency}）` : clientNameHtml) + repeatBadge;

      tr.innerHTML = `
        <td class="caret-cell"><span class="caret" id="caret-${p._i}">▸</span></td>
        <td class="date-cell">${fmtMD(p.date)}<span class="dow ${dowCls}">(${dow})</span>${ymHtml}<span class="big-mark" id="big-${p._i}"${p.scale === '大型' ? '' : ' style="display:none;"'}>大型</span>${dateTagsHtml}</td>
        <td class="proj-cell">
          <strong>${p.content}</strong>${tags}${noteFlag}
          <div class="sub-info"><span class="fbadge ${formatClass(p.format)}">${p.format}</span></div>
          <div class="sub-info">${clientLine}</div>
        </td>
        <td class="person">
          <div>${p.sales}</div>
          <div class="dir-line">D：<span id="dir-${p._i}">${p.director}</span></div>
        </td>
        <td class="time-cell">${p.meet}〜${p.leave}${evHtml}</td>
        <td class="status-cell">${recruitHtml}<div class="st-asgn">${statusHtml}</div></td>
        <td class="ops" onclick="event.stopPropagation()">
          <a href="/project-assign?project=${encodeURIComponent(p.id)}">アサイン</a>
          <a href="/project-form?project=${encodeURIComponent(p.id)}">編集</a>
          <a href="#" id="arc-${p._i}" onclick="event.preventDefault(); toggleArchive(${p._i});">${p.archived ? '↩ 戻す' : '🗄 アーカイブ'}</a>
          <a href="#" class="del-link" onclick="event.preventDefault(); deleteProject('${p.id}');">削除</a>
        </td>`;
      tbody.appendChild(tr);

      // ----- 詳細（折りたたみ）行 -----
      const dr = document.createElement('tr');
      dr.className = 'detail-row';
      dr.id = 'detail-' + p._i;
      dr.dataset.group = g.key;
      dr.style.display = 'none';
      // 第1弾：詳細に条件表示する項目を組み立てる
      // 制作・記録（ロゴ/カメラ/事例記事/動画）を横並びで出す。
      const PUB = [['ロゴ', p.logo], ['カメラ', p.camera], ['事例記事', p.article], ['動画', p.video]];
      const inlineItems = PUB
        .filter(([k, v]) => v && !['', '-', '−'].includes(v))
        .map(([k, v]) => `<span class="pub-item"><span class="pub-k">${k}</span><b>${v}</b></span>`);
      const pubInline = inlineItems.length
        ? inlineItems.join('')
        : '<span style="color:var(--muted);font-size:12px;">（指定なし）</span>';
      // ケータリングは動画の横に「選べるプルダウン」で出す。「無」以外を選ぶとメモ欄が現れる。変更はDB保存。
      const catCur = (p.catering && p.catering !== '−' && p.catering !== '-') ? p.catering : '無';
      const catOpts = CATERING_OPTS.map(o => `<option${o === catCur ? ' selected' : ''}>${o}</option>`).join('');
      const catNoteVal = (p.cateringNote || '').replace(/"/g, '&quot;');
      const cateringCtl = `<span class="pub-item cat-ctl">
        <span class="pub-k">ケータリング</span>
        <select class="cat-select" onchange="onCateringPick('${p.id}', this)">${catOpts}</select>
        <input type="text" class="cat-note" placeholder="メモ（内容・時間・食数など）" value="${catNoteVal}" onchange="onCateringNote('${p.id}', this)"${isCateringOn(catCur) ? '' : ' style="display:none;"'}>
      </span>`;
      dr.innerHTML = `
        <td colspan="${COLSPAN}" onclick="event.stopPropagation()">
          <div class="detail-panel">
            <div class="d-item">
              <span class="d-label">ディレクター</span>
              ${employeeSelectHtml(p._i, p.id, 'director_id', p.directorId, '未定')}
            </div>
            <div class="d-item">
              <span class="d-label">規模・SD担当</span>
              <div class="mini-field"><span class="mini-label">規模</span><span style="font-weight:600;">${p.scale}</span><span style="color:var(--muted);font-size:11px;margin-left:6px;">（案件登録で設定）</span></div>
              <div class="mini-field"><span class="mini-label">SD</span>${employeeSelectHtml(p._i, p.id, 'sd_id', p.sdId, '未設定')}</div>
            </div>
            <div class="d-item">
              <span class="d-label">物品担当</span>
              ${employeeSelectHtml(p._i, p.id, 'goods_owner_id', p.goodsId, '未定')}
            </div>
            <div class="d-item">
              <span class="d-label">運営場所</span>
              <span style="font-weight:600;">${p.area ? p.area : '<span style=\"color:var(--muted);font-weight:400;\">（未設定）</span>'}</span>
            </div>
            <div class="d-item">
              <span class="d-label">お客様人数・チーム数</span>
              <span style="font-weight:600;">${p.guests}名${p.tentative ? '（仮）' : ''}<span style="color:var(--muted);margin:0 5px;">/</span>${p.teams}チーム</span>
            </div>
            <div class="d-item">
              <span class="d-label">移動・音響</span>
              <div class="mini-field"><span class="mini-label">移動</span>${textSelectHtml(p._i, p.id, 'transport', TRANSPORTS, p.transport, 'ー（未設定）')}</div>
              <div class="mini-field"><span class="mini-label">音響</span>${textSelectHtml(p._i, p.id, 'audio_equipment', SOUND, p.sound, '（未設定）')}</div>
            </div>
            <div class="d-item" style="flex-basis:100%;">
              <span class="d-label">準備チェック・制作記録</span>
              <div class="prep-pub">
                <div class="checks">
                  <span>${p.lineSent ? '✅' : '⬜'} LINE概要送付</span>
                  <span>${p.handover ? '✅' : '⬜'} 引き継ぎ</span>
                  <span>${p.script ? '✅' : '⬜'} 台本</span>
                </div>
                <div class="pub-inline"><span class="pub-cap">制作・記録</span>${pubInline}${cateringCtl}</div>
              </div>
            </div>
            <div class="d-item" style="flex-basis:100%;">
              <span class="d-label">備考</span>
              <div class="note-text">${hasNote ? p.note : '<span style="color:var(--muted);">（なし）</span>'}</div>
            </div>
            <div class="d-item">
              <span class="d-label">運営シート</span>
              ${p.opSheet
                ? `<a class="sheet-link" href="${p.opSheet}" target="_blank" rel="noopener" onclick="event.stopPropagation()">📄 シートを開く</a>`
                : `<a class="sheet-link" href="/project-form?project=${encodeURIComponent(p.id)}" onclick="event.stopPropagation()" style="color:var(--muted);">＋ 編集画面でURLを登録</a>`}
            </div>
            <div class="d-item" style="flex-basis:100%;">
              <span style="font-size:11.5px;color:var(--muted);">※ <b>ディレクター・SD・物品担当・移動・音響・ケータリング</b>は、この詳細で選ぶ／入力するとその場で保存されます（自動保存）。準備チェックの保存は次の工程で対応します。案件の他の内容を直すときは上の「編集」からどうぞ。</span>
            </div>
          </div>
        </td>`;
      tbody.appendChild(dr);
    });
  });

  // 該当なし行
  const emptyRow = document.createElement('tr');
  emptyRow.className = 'empty-row';
  emptyRow.style.display = 'none';
  emptyRow.innerHTML = `<td colspan="${COLSPAN}">条件に合う案件がありません。</td>`;
  tbody.appendChild(emptyRow);

  // ===== 詳細行の開閉 =====
  function toggleDetail(idx) {
    const d = document.getElementById('detail-' + idx);
    const c = document.getElementById('caret-' + idx);
    if (!d) return;
    const isOpen = d.style.display !== 'none';
    d.style.display = isOpen ? 'none' : 'table-row';
    if (c) c.textContent = isOpen ? '▸' : '▾';
  }

  // ===== 一覧 / 下書き / アーカイブ タブの切替 =====
  let currentTab = 'list';   // 'list'＝通常／'draft'＝下書きだけ／'archived'＝アーカイブだけ
  function switchTab(tab) {
    currentTab = tab;
    ['list', 'draft', 'archived'].forEach(t =>
      document.getElementById('tab-' + t).classList.toggle('active', tab === t));
    applyFilter();
  }

  // ===== アーカイブ（手動の隠す／戻す） =====
  // DB（POST /projects/archive）に保存してから、ボタン表記とタブ表示を切り替える。
  // 保存できたときだけ画面を反映する（失敗時は元のまま知らせる）。
  function toggleArchive(idx) {
    const p = projects[idx];
    const next = !p.archived;
    fetch('/projects/archive', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF },
      body: JSON.stringify({ id: p.id, archived: next })
    })
    .then(r => { if (!r.ok) throw new Error('save failed'); return r.json(); })
    .then(() => {
      p.archived = next;
      const tr = document.querySelector('tr.main-row[data-idx="' + idx + '"]');
      if (tr) tr.dataset.archived = p.archived ? '1' : '0';
      const a = document.getElementById('arc-' + idx);
      if (a) a.textContent = p.archived ? '↩ 戻す' : '🗄 アーカイブ';
      applyFilter();   // 表示中タブから消える／現タブに現れるのを反映
    })
    .catch(() => alert('アーカイブの保存に失敗しました。もう一度お試しください。'));
  }

  // ===== 案件の削除（キャンセルになった案件を消す） =====
  // 確認ダイアログでOKのときだけ POST /projects/{id}/delete を送る（元に戻せないため一度確認する）。
  // CSRFトークンは画面上部で用意した window.ECS_CSRF を使う。
  function deleteProject(id) {
    if (!confirm('この案件を削除します。よろしいですか？（元に戻せません）')) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/projects/' + encodeURIComponent(id) + '/delete';
    const token = document.createElement('input');
    token.type = 'hidden';
    token.name = '_token';
    token.value = window.ECS_CSRF;
    form.appendChild(token);
    document.body.appendChild(form);
    form.submit();
  }

  // ===== 月見出しの開閉（アコーディオン） =====
  // 畳んだ月のキーをためておく。見出しクリックでその月だけ開閉する。
  const collapsedMonths = new Set();
  function toggleGroup(key) {
    if (collapsedMonths.has(key)) collapsedMonths.delete(key);
    else collapsedMonths.add(key);
    applyFilter();   // 表示を反映（畳んだ月の行は隠す）
  }

  // ===== 絞り込み =====
  function applyFilter() {
    const kw      = document.getElementById('kw').value.trim();
    const yomi    = document.getElementById('yomi').value;
    const format  = document.getElementById('format').value;
    const toc      = document.getElementById('toc').value;         // ''=すべて / 'toc' / 'tob'
    const catering = document.getElementById('catering').value;    // ''=すべて / 'あり' / 'なし'
    const kbn     = document.getElementById('kbn').value;

    let shown = 0, total = 0, draftTotal = 0, archivedTotal = 0;
    const groupShown = {};

    document.querySelectorAll('#projBody tr.main-row').forEach(tr => {
      const isDraft = tr.dataset.draft === '1';
      const isArch  = tr.dataset.archived === '1';
      if (isDraft) draftTotal++;
      if (isArch && !isDraft) archivedTotal++;
      // タブで表示を切り替える（下書き＞アーカイブ＞通常の優先で振り分け／件数は表示中タブのぶんだけ）
      let okTab;
      if (currentTab === 'draft')         okTab = isDraft;
      else if (currentTab === 'archived') okTab = isArch && !isDraft;
      else                                okTab = !isDraft && !isArch;
      if (okTab) total++;
      const okKw  = !kw     || tr.dataset.name.includes(kw);
      const okYo  = !yomi   || tr.dataset.yomi === yomi;
      const okFmt = formatMatches(tr.dataset.format, format);
      const okToc = !toc    || (toc === 'toc' ? tr.dataset.toc === '1' : tr.dataset.toc === '0');
      const okCat = !catering || tr.dataset.catering === catering;
      const okKbn = !kbn     || tr.dataset.kbn === kbn;
      // 絞り込みに一致するか（matched）と、その月が畳まれているか（collapsed）は別。
      const matched = okTab && okKw && okYo && okFmt && okToc && okCat && okKbn;
      const collapsed = collapsedMonths.has(tr.dataset.group);
      tr.style.display = (matched && !collapsed) ? '' : 'none';
      // 詳細はいったん閉じる
      const d = document.getElementById('detail-' + tr.dataset.idx);
      const c = document.getElementById('caret-' + tr.dataset.idx);
      if (d) d.style.display = 'none';
      if (c) c.textContent = '▸';
      if (matched) {
        groupShown[tr.dataset.group] = (groupShown[tr.dataset.group] || 0) + 1;
        if (!collapsed) shown++;   // 表示中の件数は、畳んでいない月のぶんだけ
      }
    });

    // グループ見出しは、その月に一致行が1件もなければ隠す。件数・開閉マークを更新。
    document.querySelectorAll('#projBody tr.group-row').forEach(gr => {
      const n = groupShown[gr.dataset.group] || 0;
      gr.style.display = n === 0 ? 'none' : '';
      const c = gr.querySelector('.g-count');
      if (c) c.textContent = n + '件';
      const car = gr.querySelector('.gcaret');
      if (car) car.textContent = collapsedMonths.has(gr.dataset.group) ? '▶' : '▼';
    });

    document.getElementById('totalCount').textContent = total;
    document.getElementById('shownCount').textContent = shown;
    document.getElementById('draftCount').textContent = draftTotal;
    document.getElementById('archivedCount').textContent = archivedTotal;
    emptyRow.style.display = shown === 0 ? '' : 'none';
  }

  // ===== サイドバーの年月フォルダ =====
  // GROUPS（案件のある年月）を「年→月」のツリーにして左メニューに出す。
  // 月をクリックすると、一覧はそのまま全部表示で、その月の見出しまでスクロールする。
  function buildYmTree() {
    const tree = document.getElementById('ymTree');
    if (!tree) return;

    const byYear = {};
    const yearOrder = [];
    GROUPS.forEach(g => {
      if (!byYear[g.year]) { byYear[g.year] = []; yearOrder.push(g.year); }
      const count = projects.filter(p => p.group === g.key && !p.draft && !p.archived).length;  // 件数は下書き・アーカイブ以外
      byYear[g.year].push({ key: g.key, month: g.month, count });
    });

    let html = '';
    yearOrder.forEach(y => {
      const months = byYear[y];
      const total = months.reduce((s, m) => s + m.count, 0);
      const open = (y === todayY);   // 今年だけ最初から開いておく
      html += '<div class="ym-year">'
        + '<button class="ym-year-btn" onclick="toggleYear(' + y + ')">'
        +   '<span class="ym-caret" id="ymcaret-' + y + '">' + (open ? '▾' : '▸') + '</span>'
        +   y + '年<span class="ym-ycount">' + total + '</span></button>'
        + '<div class="ym-months' + (open ? '' : ' collapsed') + '" id="ymmonths-' + y + '">';
      months.forEach(m => {
        html += '<button class="ym-month-btn" id="ymbtn-' + m.key + '" onclick="jumpToMonth(\'' + m.key + '\')">'
          + m.month + '月<span class="ym-mcount">' + m.count + '</span></button>';
      });
      html += '</div></div>';
    });
    tree.innerHTML = html;
  }

  // 年フォルダの開閉
  function toggleYear(y) {
    const box = document.getElementById('ymmonths-' + y);
    const car = document.getElementById('ymcaret-' + y);
    if (!box) return;
    const collapsed = box.classList.toggle('collapsed');
    if (car) car.textContent = collapsed ? '▸' : '▾';
  }

  // 月をクリック → その月の見出しへスクロール＋点滅（全部表示のまま移動）
  let flashTimer = null;
  function jumpToMonth(key) {
    document.querySelectorAll('.ym-month-btn').forEach(b => b.classList.remove('active'));
    const btn = document.getElementById('ymbtn-' + key);
    if (btn) btn.classList.add('active');

    const gr = document.getElementById('group-' + key);
    if (!gr) return;
    gr.scrollIntoView({ behavior: 'smooth', block: 'start' });
    gr.classList.remove('flash');
    void gr.offsetWidth;            // 同じ月を続けて押しても点滅をやり直すための再描画
    gr.classList.add('flash');
    if (flashTimer) clearTimeout(flashTimer);
    flashTimer = setTimeout(() => gr.classList.remove('flash'), 1500);
  }

  buildYmTree();
  applyFilter();

  // 他画面（スタッフ公開ボードの案件名など）から ?focus=2026-7 で来たら、その月へ移動して点滅
  (function(){
    const m = location.search.match(/[?&]focus=([^&]+)/);
    if (!m) return;
    const key = decodeURIComponent(m[1]);
    setTimeout(() => jumpToMonth(key), 120);
  })();
</script>

<!-- ===== 社員・ディレクター集計：別ウィンドウへライブ送信 ===== -->
<script>
  // 案件データから、ディレクター/SDの担当回数を実施形態・規模別に集計する
  function computeAgg() {
    const map = {};
    function ensure(name) {
      if (!map[name]) map[name] = { name, d:0, realD:0, bigD:0, bigSD:0, onlineD:0 };
      return map[name];
    }
    projects.forEach(p => {
      if (p.draft) return;                                   // 下書きは数えない
      const isReal   = p.format.indexOf('リアル') !== -1;
      const isOnline = p.format.indexOf('オンライン') !== -1;
      const isBig    = p.scale === '大型';
      if (p.director && p.director !== '未定') {
        const r = ensure(p.director);
        r.d++;
        if (isReal)          r.realD++;
        if (isOnline)        r.onlineD++;
        if (isReal && isBig) r.bigD++;
      }
      if (p.sd && p.sd !== 'なし' && p.sd !== '未定' && p.sd !== '未設定') {
        if (isReal && isBig) ensure(p.sd).bigSD++;
      }
    });
    return Object.values(map).sort((a, b) => (b.d - a.d) || (b.bigD - a.bigD));
  }

  // 別ウィンドウ（集計画面）へ最新の集計を送る
  let aggWin = null;
  function pushAgg() {
    if (aggWin && !aggWin.closed) {
      aggWin.postMessage({ type:'agg-data', month:'2026年 7月', rows: computeAgg() }, '*');
    }
  }
  // ボタン：集計を別ウィンドウで開く（開いたままにできる）
  function openAggWindow() {
    aggWin = window.open('/projects-agg', 'ecs_agg', 'width=780,height=620');
    if (!aggWin) { alert('ポップアップがブロックされたようです。ブラウザのポップアップ許可を確認してください。'); return; }
    aggWin.focus();
    setTimeout(pushAgg, 400);   // 集計画面の読み込み完了後にも届くよう、少し遅れて1回送る
  }
  // 集計画面からの「データちょうだい」要求に応える
  window.addEventListener('message', function (e) {
    if (e.data && e.data.type === 'agg-request') pushAgg();
  });
</script>

<!-- ===== アサイン表へ書き出し（GAS取込用の表をつくる） ===== -->
<script>
  // 書き出す項目と並び（1行目の見出し）。GAS側はこの見出し名で読むので、並びを変えても動く。
  const EXPORT_COLS = ['日付','コンテンツ','日程種別','種別','規模','営業','顧客名','エリア','会場住所','集合形式','集合','解散','入場','開始','終了','人数','チーム','運営人数','宿泊','音響','物品','移動','備考','D'];

  // セル値を整える（タブ・改行はスペースに、「—」など実体のない記号は空欄に）
  function expCell(v) {
    if (v === null || v === undefined) return '';
    let s = String(v).trim();
    if (s === '—' || s === 'ー' || s === '-' || s === '（未定）') return '';
    return s.replace(/[\t\r\n]+/g, ' ');
  }

  // 対象月（key="2026-7"）の案件をタブ区切りの表にする。下書き・アーカイブ（終了）は除く。
  function buildExportTSV(key) {
    const rows = [EXPORT_COLS.join('\t')];
    const list = ECS_CASES
      .filter(c => !c.draft && !c.archived)
      .map(c => ({ c, date: addDays(today, c.off) }))
      .filter(o => (o.date.getFullYear() + '-' + (o.date.getMonth() + 1)) === key)
      .sort((a, b) => a.date - b.date);
    list.forEach(o => {
      const c = o.c, d = o.date;
      const ymd = d.getFullYear() + '/' + (d.getMonth() + 1) + '/' + d.getDate();
      rows.push([
        ymd, expCell(c.content), expCell(c.dayType), expCell(c.format), expCell(c.scale),
        expCell(c.sales), expCell(c.client), expCell(c.area), expCell(c.place), expCell(c.meetPlace),
        expCell(c.meet), expCell(c.leave), expCell(c.enter), expCell(c.evStart), expCell(c.evEnd),
        expCell(c.guests), expCell(c.teams), expCell(c.need), expCell(c.lodging), expCell(c.sound),
        expCell(c.goods), expCell(c.transport), expCell(c.note), expCell(c.dir)
      ].join('\t'));
    });
    return { tsv: rows.join('\n'), n: list.length };
  }

  // モーダルを開く（初回に月の選択肢をつくる）
  function openExport() {
    const sel = document.getElementById('expMonth');
    if (!sel.options.length) {
      GROUPS.forEach(g => {
        const o = document.createElement('option');
        o.value = g.key;
        o.textContent = g.label + (g.past ? '（終了）' : '');
        sel.appendChild(o);
      });
      const upcoming = GROUPS.find(g => !g.past);   // 既定＝これから来る最初の月
      sel.value = upcoming ? upcoming.key : (GROUPS[0] ? GROUPS[0].key : '');
    }
    renderExport();
    document.getElementById('expBg').classList.add('show');
  }
  function closeExport() { document.getElementById('expBg').classList.remove('show'); }

  // 選んだ月の表を作って画面に出す
  function renderExport() {
    const key = document.getElementById('expMonth').value;
    const { tsv, n } = buildExportTSV(key);
    document.getElementById('expTa').value = tsv;
    document.getElementById('expCount').textContent = n;
    document.getElementById('expCopied').classList.remove('show');
  }

  // 表を全選択してコピー
  function copyExport() {
    const ta = document.getElementById('expTa');
    ta.focus();
    ta.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (e) {}
    if (navigator.clipboard) { navigator.clipboard.writeText(ta.value).then(()=>{}, ()=>{}); ok = true; }
    if (ok) {
      const m = document.getElementById('expCopied');
      m.classList.add('show');
      setTimeout(() => m.classList.remove('show'), 2500);
    }
  }
</script>
@endverbatim
@endpush
