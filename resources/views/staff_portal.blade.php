<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ECS スタッフ画面（エントリー・希望・アサイン／スマホ・PC両対応）</title>
  <link rel="stylesheet" href="/ecs/style.css?v={{ \App\Support\Asset::ver('ecs/style.css') }}">
  @verbatim
  <style>
    /* ===== スタッフ用ポータル（スマホ・PC両対応＝レスポンシブ） ===== */
    /* 画面の幅にあわせて自動で並びが変わります。
       狭い（スマホ）→ 1列・縦並び ／ 広い（PC）→ 案件の行が横に2列。 */

    body { background: var(--bg); }

    .staff-shell {
      max-width: 1100px; margin: 0 auto; min-height: 100vh;
      background: var(--bg); display: flex; flex-direction: column;
    }

    /* 上部ヘッダー（誰でログインしているか） */
    .s-header {
      background: var(--brand); color: #fff;
      padding: 14px 18px 12px; position: sticky; top: 0; z-index: 10;
    }
    .s-header .app-name { font-size: 12px; opacity: .85; letter-spacing: 1px; }
    .s-header .who { font-size: 19px; font-weight: 700; margin-top: 2px; display: flex; align-items: center; gap: 8px; }
    .s-header .who .role {
      font-size: 11px; font-weight: 600; background: rgba(255,255,255,.22);
      padding: 2px 9px; border-radius: 999px;
    }

    /* タブ（上に固定）
       スマホでは4つが1行に収まらず、以前は横スクロールにしていたため
       「募集中の案件」の件数バッジが画面の外に出て見切れていた。
       折り返して2段（2×2）にすることで、狭い画面でも数字まで必ず見えるようにする。 */
    .s-tabs {
      display: flex; gap: 6px; flex-wrap: wrap; background: var(--panel);
      padding: 8px 12px; border-bottom: 1px solid var(--line);
      position: sticky; top: 56px; z-index: 9;
    }
    .s-tabs button {
      /* タブ5つを狭い画面では3＋2の2段に折り返す（1段に5つは入らないため）。 */
      flex: 1 1 calc(33.333% - 4px); min-width: 0; border: 1px solid var(--line); background: #fff;
      color: var(--muted); border-radius: 999px; padding: 9px 5px;
      font-size: 12.5px; font-weight: 700; cursor: pointer; font-family: inherit;
      display: flex; align-items: center; justify-content: center; gap: 4px; white-space: nowrap;
    }
    /* タブ名の長短の出し分け。既定（スマホ）は短いほうを見せる。 */
    .s-tabs button .lb-l { display: none; }
    .s-tabs button .lb-s { display: inline; }
    .s-tabs button .ti { font-size: 15px; flex: 0 0 auto; }
    .s-tabs button.active { background: var(--brand); border-color: var(--brand); color: #fff; }
    .s-tabs button .pill {
      font-size: 11px; background: var(--danger); color: #fff;
      border-radius: 999px; padding: 0 6px; font-weight: 700;
      flex: 0 0 auto;   /* 件数バッジは絶対に縮ませない（見切れの原因だったため） */
    }
    .s-tabs button.active .pill { background: #fff; color: var(--brand-dark); }

    .s-body { flex: 1; padding: 16px 14px 32px; }
    .tab-panel { display: none; }
    .tab-panel.active { display: block; }

    /* お知らせバナー */
    /* 稼働希望の月切り替え */
    .pref-month-nav { display:flex; align-items:center; justify-content:center; gap:10px; margin:10px 0 6px; }
    .pref-month-nav .pm-btn {
      display:inline-block; padding:6px 14px; border-radius:999px; text-decoration:none;
      font-size:13px; font-weight:700; color:#7a4a00; background:#fff; border:1px solid #e6d8c8;
    }
    .pref-month-nav .pm-btn:hover { background:#fdf3e2; }
    .pref-month-nav .pm-btn.off { color:#bbb; background:#f6f4f1; border-color:#eee; }
    .pref-month-nav .pm-now { font-size:15px; font-weight:800; }
    .pref-month-nav .pm-sel {
      font-size:15px; font-weight:800; padding:5px 10px; border-radius:8px;
      border:1px solid #e6d8c8; background:#fff; font-family:inherit; color:#2c2018;
    }

    .notice {
      background: var(--warn-soft); color: #92400e; border: 1px solid #f6d9a7;
      border-radius: 10px; padding: 10px 14px; font-size: 13px; margin-bottom: 14px;
      line-height: 1.6;
    }
    .notice b { color: #7a4a00; }
    /* 追加募集のお知らせ（赤系で強調） */
    .extra-notice {
      background: var(--danger-soft); border-color: #f4c2c2; color: #b91c1c;
    }
    .extra-notice b { color: #991b1b; }

    .sec-title { font-size: 14px; font-weight: 700; margin: 4px 2px 8px; }

    /* かんたん絞り込み */
    .job-filter { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
    .job-filter input, .job-filter select {
      padding: 8px 11px; border: 1px solid var(--line); border-radius: 10px;
      font-size: 14px; font-family: inherit; background: #fff;
    }
    .job-filter input { flex: 1; min-width: 150px; }
    .job-filter input[type="date"] { flex: 0 0 auto; min-width: 0; }
    .job-filter .jf-today {
      padding: 8px 12px; border: 1px solid var(--line); border-radius: 10px;
      font-size: 14px; font-family: inherit; background: #fff; color: var(--ink);
      cursor: pointer; white-space: nowrap;
    }
    .job-filter .jf-today:active { background: var(--brand-soft); }
    /* 絞り込みボタンの並び。スマホでも押しやすいよう、プルダウンではなくボタンにしている。
       押すと色が変わるので「いま何で絞っているか」が一目で分かる。 */
    .job-toggles { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
    .job-toggles .jf-tg {
      flex: 1 1 auto; border: 1px solid var(--line); background: #fff;
      color: var(--muted); border-radius: 999px; padding: 9px 14px;
      font-size: 13px; font-weight: 700; cursor: pointer; font-family: inherit;
      white-space: nowrap;
    }
    .job-toggles .jf-tg.on { background: var(--brand); border-color: var(--brand); color: #fff; }
    /* 追加案件は「急ぎ」なので赤で区別する（案件カードの赤枠と揃える）。 */
    .job-toggles .jf-tg.extra { border-color: var(--danger); color: var(--danger); }
    .job-toggles .jf-tg.extra.on { background: var(--danger); border-color: var(--danger); color: #fff; }

    /* 募集案件の並び（広い画面では自動で2列に／狭い画面では幅に合わせて1列に縮む） */
    .job-grid {
      display: grid; gap: 8px;
      grid-template-columns: repeat(auto-fill, minmax(min(460px, 100%), 1fr));
    }

    /* 1件＝コンパクトな行 */
    .job-row {
      background: #fff; border: 1px solid var(--line); border-radius: 10px;
      padding: 9px 12px 10px; display: flex; flex-direction: column; gap: 5px;
    }
    .job-row.applied { border-color: #bbe3c6; background: #f6fbf7; }
    .job-row.closed  { opacity: .55; }
    /* 追加案件は左に赤いライン＋赤枠で強調 */
    .job-row.extra { box-shadow: inset 3px 0 0 var(--danger); border-color: #f0b9b9; }

    /* 1段目：日付・案件名・状態 */
    .jr-head { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
    .jr-date { font-weight: 700; font-size: 14px; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .jr-date .sun { color: var(--danger); }
    .jr-date .sat { color: var(--brand); }
    .jr-title { font-weight: 700; font-size: 14px; }
    .jr-client { font-weight: 400; font-size: 12px; color: var(--muted); margin-left: 4px; }
    .jr-head .j-badge { margin-left: auto; }

    /* 募集の状態の色は、ここ1か所で決める（2026-09-01）。
       ⚠ リストのバッジとカレンダーのチップで**必ず同じ色**にする。
         別々に書いていたため、カレンダーの「募集中」がリストの「エントリー中」と
         同じ薄い緑になっていて、同じ画面で色の意味が食い違っていた（baba指摘）。 */
    :root {
      --job-open-bg: #16a34a;  --job-open-fg: #ffffff;   /* 募集中＝濃い緑に白文字 */
      --job-applied-bg: var(--ok-soft); --job-applied-fg: #15803d; --job-applied-bd: #bbe3c6;  /* エントリー中＝薄い緑 */
      --job-closed-bg: #ece3d4; --job-closed-fg: #7a6a58; /* 締切・満員＝薄い茶 */
    }

    /* 募集状態バッジ */
    .j-badge { font-size: 11px; font-weight: 700; padding: 2px 9px; border-radius: 999px; white-space: nowrap; }
    .j-badge.open    { background: var(--job-open-bg); color: var(--job-open-fg); }
    .j-badge.applied { background: var(--job-applied-bg); color: var(--job-applied-fg); border: 1px solid var(--job-applied-bd); }
    .j-badge.closed  { background: var(--job-closed-bg); color: var(--job-closed-fg); }

    /* 2段目：実施形態・大型・リピートのタグ */
    .jr-tags { display: flex; flex-wrap: wrap; gap: 5px; }
    .fbadge { display: inline-block; font-size: 10.5px; font-weight: 700; padding: 1px 7px; border-radius: 6px; }
    /* 実施形態バッジ＝マイページと同じやわらかい配色（リアル＝緑／オンライン＝青）。リアルロングは独自の橙で区別。 */
    .fbadge.fmt-real   { background: #eef6f0; color: #4f8a63; }
    .fbadge.fmt-long   { background: #fdecd9; color: #b4530a; }
    .fbadge.fmt-online { background: #eef3fb; color: #4f74ad; }
    /* ⚠ 実施形態が増えても崩れないように、残りの種類にも色を用意しておく。
       色が無いと文字だけになって「バッジが消えた」ように見えるため。 */
    .fbadge.fmt-arena  { background: #f3eefb; color: #6b4fad; }
    .fbadge.fmt-other  { background: #eef7f9; color: #3f7f8a; }
    .fbadge.fmt-etc    { background: #f1efec; color: #7a6a56; }
    .tag-mini { font-size: 10px; padding: 1px 7px; border-radius: 999px; font-weight: 700; }
    /* 強調（注目してほしい）：追加・宿泊 */
    .tag-mini.add  { background: #b91c1c;            color: #fff; }
    .tag-mini.stay { background: #e8833a;            color: #fff; }
    /* 中間：予備日・リハ */
    .tag-mini.yobi { background: var(--warn-soft);   color: #b45309; }
    .tag-mini.reha { background: #ece3d4;            color: #7a6a58; }
    /* 控えめ（参考情報）：大型・リピート */
    .tag-mini.size { background: #ece3d4;            color: #7a6a58; }
    .tag-mini.rep  { background: #f1ece2;            color: #8a7a66; }

    /* 3段目：会場（住所）／集合／時間 */
    .jr-meta { display: flex; flex-wrap: wrap; gap: 2px 14px; font-size: 12px; color: var(--ink); line-height: 1.5; overflow-wrap: anywhere; }
    .jr-meta .ic { color: var(--muted); margin-right: 2px; }
    .jr-meta .ev { color: var(--muted); }

    /* 担当からの伝達（募集カードの備考）。応募の判断に要る情報なので目に留まる色にする。 */
    .jr-note { display: flex; gap: 6px; align-items: flex-start; margin-top: 6px; padding: 7px 10px;
               background: var(--warn-soft, #fdf3e2); border: 1px solid #ecd9b6; border-radius: 8px;
               font-size: 12px; line-height: 1.6; color: #6b4e17; overflow-wrap: anywhere; }
    .jr-note .ic { flex: none; }

    /* 締切・残り人数のチップ */
    .jr-sub { display: flex; flex-wrap: wrap; gap: 6px; }
    /* エントリーが保存できなかったときの表示。⚠ 消えるお知らせだけだと
       「エントリーできたつもり」になるので、カードに残す（2026-08-28 baba報告）。 */
    .jr-error {
      margin-top: 6px; padding: 6px 9px; border-radius: 8px; font-size: 12px; line-height: 1.6;
      background: #fdeaea; border: 1px solid #f0b9b9; color: #b91c1c; font-weight: 700;
    }
    .chip { font-size: 11px; font-weight: 700; padding: 2px 9px; border-radius: 999px; white-space: nowrap; }
    .chip.deadline { background: var(--warn-soft); color: #b45309; }
    .chip.slots    { background: var(--ok-soft);   color: #15803d; }
    .chip.slots.few{ background: var(--danger-soft); color: #b91c1c; } /* 残りわずか */
    .chip.full     { background: #ece3d4;          color: #7a6a58; }

    /* 4段目：コメント（ふだん隠す）＋エントリーボタン */
    .jr-foot { display: flex; gap: 8px; align-items: center; margin-top: 1px; }
    .cmt-toggle {
      border: 1px solid var(--line); background: #fff; color: var(--muted);
      border-radius: 8px; padding: 6px 11px; font-size: 12.5px; cursor: pointer;
      font-family: inherit; white-space: nowrap;
    }
    .cmt-toggle:hover { background: #f3ece0; }
    .cmt-toggle.on { color: var(--brand-dark); border-color: #e6cdb8; background: var(--brand-soft); }
    .jr-foot .apply-btn-sm { margin-left: auto; }
    .jr-comment-wrap { margin-top: 1px; }
    .jr-comment-row { display: flex; gap: 6px; align-items: center; }
    .jr-cmt-save {
      flex: none; border: none; border-radius: 8px; padding: 8px 14px; cursor: pointer;
      font-family: inherit; font-size: 13px; font-weight: 700; background: #a15c2e; color: #fff;
    }
    .jr-cmt-save:hover { background: #8a4d24; }
    .jr-cmt-ok { flex: none; font-size: 12px; font-weight: 700; color: #15803d; opacity: 0; transition: opacity .2s; }
    .jr-cmt-ok.show { opacity: 1; }
    .jr-cmt-hint { font-size: 11px; color: #8a7a6b; margin-top: 4px; }
    .jr-comment {
      width: 100%; padding: 7px 10px; border: 1px solid var(--line);
      border-radius: 8px; font-size: 12.5px; font-family: inherit; background: #fff;
    }
    .apply-btn-sm {
      border: none; border-radius: 8px; padding: 7px 16px; font-size: 13px; font-weight: 700;
      cursor: pointer; font-family: inherit; background: var(--brand); color: #fff; white-space: nowrap;
    }
    .apply-btn-sm:active { background: var(--brand-dark); }
    .apply-btn-sm.cancel { background: #fff; color: var(--brand-dark); border: 1px solid #bbe3c6; }
    .apply-btn-sm.disabled { background: #ece3d4; color: #8a7a66; cursor: default; }

    /* ===== 稼働希望カレンダー（既存スマホ画面から移植） ===== */
    .pref-wrap { max-width: 460px; margin: 0 auto; }
    .m-card { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 16px; margin-bottom: 14px; }
    .m-card h3 { margin: 0 0 4px; font-size: 15px; }
    .m-card .sub { font-size: 12px; color: var(--muted); margin: 0 0 12px; }
    /* 募集中の案件：リスト／カレンダーの切替（2026-09-01 baba要望）。
       稼働希望のカレンダーと同じ見た目の土台（.cal-head / .cal-grid / .dow）を使い、
       マスの中に案件を並べるぶんだけ専用の指定を足す。 */
    .job-views { display: flex; gap: 8px; margin: 4px 0 10px; }
    .jv-tab {
      padding: 7px 16px; border: 1px solid var(--line); border-radius: 999px;
      background: #fff; cursor: pointer; font-size: 13px; font-weight: 700; color: #6b5544;
      font-family: inherit;
    }
    .jv-tab.active { background: var(--brand); border-color: var(--brand-dark); color: #fff; }
    .jc-nav {
      border: 1px solid var(--line); background: #fff; border-radius: 8px;
      width: 34px; height: 34px; font-size: 18px; cursor: pointer; font-family: inherit; color: var(--brand-dark);
    }
    .jc-nav:hover { background: #f3ece0; }
    /* ⚠ マスは正方形にしない（.cell と違う）。案件のチップが入るので高さが要る。 */
    .jc-cell {
      min-height: 66px; border-radius: 9px; border: 1px solid var(--line); background: #fff;
      padding: 3px; display: flex; flex-direction: column; gap: 2px; overflow: hidden;
    }
    .jc-cell.empty { border: none; background: none; }
    .jc-cell .dnum { font-size: 11px; color: var(--muted); font-weight: 700; }
    .jc-cell.sun .dnum { color: var(--danger); }
    .jc-cell.sat .dnum { color: var(--brand); }
    .jc-cell.today { border-color: var(--brand); box-shadow: inset 0 0 0 1px var(--brand); }
    .jc-job {
      border: 1px solid var(--line); border-radius: 6px; background: #fff;
      padding: 2px 4px; font-size: 10px; line-height: 1.3; cursor: pointer;
      text-align: left; font-family: inherit; color: var(--ink); width: 100%;
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    /* ⚠ 色はリストのバッジ（.j-badge）とまったく同じにする。上の :root で1か所に決めてある。
       別々に書いていたため、カレンダーの「募集中」がリストの「エントリー中」と同じ薄い緑になり、
       同じ画面で色の意味が食い違っていた（2026-09-01 baba指摘）。 */
    .jc-job.open    { background: var(--job-open-bg);    color: var(--job-open-fg); border-color: var(--job-open-bg); font-weight: 700; }
    .jc-job.applied { background: var(--job-applied-bg); color: var(--job-applied-fg); border-color: var(--job-applied-bd); font-weight: 700; }
    .jc-job.closed  { background: var(--job-closed-bg);  color: var(--job-closed-fg); border-color: var(--job-closed-bg); }
    .jc-job.extra   { box-shadow: inset 3px 0 0 var(--danger); }
    .jc-hint { font-size: 11.5px; color: var(--muted); margin: 8px 2px 0; }
    /* 色の凡例。見本のチップは本物と同じ class なので、色を変えればここも一緒に変わる。 */
    .jc-legend { display: flex; flex-wrap: wrap; gap: 6px; margin: 10px 2px 0; }
    .jc-legend .jc-job { width: auto; cursor: default; padding: 2px 8px; }
    /* タップした案件を、カレンダーのすぐ下に開く（2026-09-01 baba指摘）。
       ⚠ リストへ飛ばすと、カレンダーに戻るのに上までスクロールし直しになって面倒だった。 */
    .jc-detail { margin-top: 12px; border-top: 2px solid var(--line); padding-top: 10px; }
    .jc-detail-head {
      display: flex; align-items: center; justify-content: space-between;
      font-size: 12px; font-weight: 700; color: var(--muted); margin-bottom: 6px;
    }
    .jc-detail-close {
      border: 1px solid var(--line); background: #fff; border-radius: 999px;
      padding: 3px 12px; font-size: 12px; cursor: pointer; font-family: inherit; color: #6b5544;
    }
    .jc-detail-close:hover { background: #f3ece0; }
    /* いま開いている案件が、カレンダーのどれか分かるようにする */
    .jc-job.picked { outline: 2px solid var(--brand); outline-offset: -2px; }

    .cal-head { display: flex; align-items: center; justify-content: center; gap: 14px; margin-bottom: 10px; }
    .cal-head .mon { font-size: 16px; font-weight: 700; }
    .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; }
    .cal-grid .dow { text-align: center; font-size: 11px; color: var(--muted); padding-bottom: 2px; }
    .cal-grid .dow.sun { color: var(--danger); }
    .cal-grid .dow.sat { color: var(--brand); }
    .cell {
      aspect-ratio: 1/1; border-radius: 9px; border: 1px solid var(--line);
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      font-size: 13px; cursor: pointer; user-select: none; background: #fff;
    }
    .cell.empty { border: none; background: none; cursor: default; }
    .cell .st { font-size: 9px; margin-top: 1px; }
    .cell.s-ok   { background: var(--ok-soft);    border-color: #bbe3c6; color: #15803d; }
    .cell.s-ng   { background: var(--danger-soft); border-color: #f4c2c2; color: #b91c1c; text-decoration: line-through; }
    .cell.s-event { background: var(--brand); border-color: var(--brand-dark); color: #fff; font-weight: 700; cursor: default; }
    .cell.s-event .st { font-size: 8px; }
    /* エントリー中の案件がある日（★） */
    .cell.s-entry { background: var(--brand-soft); border-color: var(--brand); color: var(--brand-dark); font-weight: 700; cursor: default; }
    .cell.s-entry .st { font-size: 8px; }
    .legend { display: flex; gap: 14px; justify-content: center; margin: 12px 0 4px; font-size: 11.5px; flex-wrap: wrap; }
    .legend span { display: inline-flex; align-items: center; gap: 5px; }
    .dot { width: 12px; height: 12px; border-radius: 4px; display: inline-block; border: 1px solid var(--line); }
    .dot.ok { background: var(--ok-soft); border-color: #bbe3c6; }
    .dot.ng { background: var(--danger-soft); border-color: #f4c2c2; }
    .dot.event { background: var(--brand); border-color: var(--brand-dark); }
    .dot.entry { background: var(--brand-soft); border-color: var(--brand); }
    .field { margin-bottom: 14px; }
    .field label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 5px; }
    .field input, .field textarea {
      width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: 10px;
      font-size: 15px; font-family: inherit; background: #fff;
    }
    .submit-btn {
      width: 100%; background: var(--brand); color: #fff; border: none; border-radius: 12px;
      padding: 14px; font-size: 15px; font-weight: 700; cursor: pointer; font-family: inherit;
    }
    .submit-btn:active { background: var(--brand-dark); }
    .saved-msg { display: none; text-align: center; color: #15803d; font-size: 13px; margin-top: 10px; }

    /* ===== 確定アサイン（既存スマホ画面から移植） ===== */
    .assign-wrap { max-width: 620px; margin: 0 auto; }
    .assign-item { display: flex; gap: 12px; align-items: center; padding: 12px 4px; border-bottom: 1px solid var(--line); color: var(--ink); cursor: pointer; }
    .assign-item:last-child { border-bottom: none; }
    .assign-item:hover { background: #faf6ee; text-decoration: none; border-radius: 8px; }
    .assign-date { background: var(--brand); color: #fff; border-radius: 10px; text-align: center; padding: 6px 0 5px; width: 54px; flex-shrink: 0; line-height: 1.15; }
    .assign-date .d { font-size: 15px; font-weight: 700; }
    .assign-date .dow { font-size: 11px; }
    .assign-info { flex: 1; min-width: 0; }
    .assign-info .t { font-weight: 700; font-size: 14px; }
    .assign-info .meta { font-size: 12px; color: var(--muted); margin-top: 2px; }
    .assign-arrow { color: var(--muted); font-size: 20px; flex-shrink: 0; }
    /* 確定アサインの詳細（タップで開く）。当日必要な情報＝持ち物・服装・注意事項・集合場所の詳細 */
    .assign-detail { padding: 0 4px 12px; }
    .ad-box { background: #faf6ee; border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px; }
    .ad-row { display: flex; gap: 10px; padding: 5px 0; border-bottom: 1px dashed var(--line); font-size: 13px; }
    .ad-row:last-of-type { border-bottom: none; }
    .ad-l { flex: 0 0 108px; color: var(--muted); font-weight: 700; }
    .ad-v { flex: 1; min-width: 0; word-break: break-word; }
    .ad-note { margin-top: 8px; font-size: 11.5px; color: var(--muted); line-height: 1.6; }
    @media (max-width: 720px) {
      .ad-row { flex-direction: column; gap: 2px; }
      .ad-l { flex: none; }
    }

    .empty-note { text-align: center; color: var(--muted); font-size: 13px; padding: 24px 0; }

    /* ===== 設定タブ ===== */
    .settings-wrap { max-width: 560px; margin: 0 auto; }
    /* 便利リンク集（Notion・アンケートフォーム等）。1行まるごとタップできる大きさにする。 */
    .lk-items { display: flex; flex-direction: column; gap: 8px; }
    .lk-item {
      display: flex; align-items: center; gap: 12px; text-decoration: none;
      border: 1px solid var(--line); border-radius: 12px; padding: 13px 14px; background: #fff;
    }
    .lk-item:active { background: var(--brand-soft); }
    .lk-item .lk-txt { flex: 1; min-width: 0; }
    .lk-item .lk-name { font-size: 14.5px; font-weight: 700; color: var(--ink); overflow-wrap: anywhere; }
    .lk-item .lk-memo { font-size: 12px; color: var(--muted); margin-top: 2px; overflow-wrap: anywhere; }
    .lk-item .lk-go { flex: 0 0 auto; font-size: 13px; color: var(--muted); }
    .lk-none { font-size: 13px; color: var(--muted); margin: 0; line-height: 1.7; }
    /* プロフィール：身長・靴・服サイズを横3列に */
    .field-row3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
    .field-row2 { display: grid; grid-template-columns: 1fr 1.4fr; gap: 10px; }
    .field select {
      width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: 10px;
      font-size: 15px; font-family: inherit; background: #fff;
    }
    /* ポジションのスイッチ一覧 */
    .pos-list { display: flex; flex-direction: column; gap: 2px; margin-bottom: 14px; }
    .pos-item {
      display: flex; align-items: center; gap: 12px; padding: 11px 4px;
      border-bottom: 1px solid var(--line); cursor: pointer; user-select: none;
    }
    .pos-item:last-child { border-bottom: none; }
    .pos-name { flex: 1; font-size: 14px; font-weight: 600; color: var(--ink); }
    .pos-name .note { display: block; font-size: 11.5px; font-weight: 400; color: var(--muted); margin-top: 1px; }
    /* ON/OFFスイッチ（見た目だけのトグル） */
    .switch { position: relative; width: 46px; height: 26px; flex-shrink: 0; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .switch .track {
      position: absolute; inset: 0; background: #d8cfc0; border-radius: 999px;
      transition: background .15s;
    }
    .switch .track::before {
      content: ''; position: absolute; left: 3px; top: 3px; width: 20px; height: 20px;
      background: #fff; border-radius: 50%; transition: transform .15s; box-shadow: 0 1px 2px rgba(0,0,0,.2);
    }
    .switch input:checked + .track { background: var(--brand); }
    .switch input:checked + .track::before { transform: translateX(20px); }

    /* アカウント欄 */
    .acct-row {
      display: flex; justify-content: space-between; align-items: baseline; gap: 10px;
      padding: 10px 2px; border-bottom: 1px solid var(--line); margin-bottom: 14px; flex-wrap: wrap;
    }
    .acct-label { font-size: 13px; color: var(--muted); font-weight: 600; }
    .acct-value { font-size: 14px; color: var(--ink); font-weight: 600; overflow-wrap: anywhere; }
    .line-btn {
      width: 100%; background: #fff; color: var(--brand-dark); border: 1px solid var(--line);
      border-radius: 12px; padding: 13px; font-size: 14px; font-weight: 700; cursor: pointer;
      font-family: inherit; margin-bottom: 10px;
    }
    .line-btn:active { background: #f3ece0; }
    .line-btn.danger { color: var(--danger); border-color: #f0b9b9; }
    .line-btn.danger:active { background: var(--danger-soft); }

    /* ===== 広い画面（PC）向けの微調整 ===== */
    @media (min-width: 720px) {
      .s-header { padding: 16px 26px 14px; }
      .s-tabs { padding: 10px 22px; top: 60px; }
      /* PCは5つとも1段に収まるので、長いタブ名に戻す。 */
      .s-tabs button { flex: 1 1 auto; min-width: 110px; font-size: 13px; padding: 9px 12px; gap: 5px; }
      .s-tabs button .lb-l { display: inline; }
      .s-tabs button .lb-s { display: none; }
      .s-body { padding: 22px 24px 40px; }
    }
  </style>
  @endverbatim
</head>
<body>
  <div class="staff-shell">

    <!-- ヘッダー -->
    <div class="s-header">
      <div class="app-name">ECS スタッフ画面</div>
      <div class="who">{{ Auth::user()->name ?? 'スタッフ' }} さん <span class="role">スタッフ</span></div>
    </div>

    <!-- タブ -->
    <div class="s-tabs">
      {{-- タブ名は画面幅で長短を出し分ける（lb-l＝PC用の長い名前／lb-s＝スマホ用の短い名前）。
           スマホは5つを2段（3＋2）に並べるため、長いままだと1マスに収まらない。 --}}
      <button class="active" data-tab="tab-jobs" onclick="switchTab(this)">
        <span class="ti">📋</span><span class="lb-l">募集中の案件</span><span class="lb-s">募集中</span> <span class="pill" id="openCount">0</span>
      </button>
      <button data-tab="tab-pref" onclick="switchTab(this)"><span class="ti">📅</span><span class="lb-l">稼働希望</span><span class="lb-s">稼働希望</span></button>
      <button data-tab="tab-assign" onclick="switchTab(this)"><span class="ti">✓</span><span class="lb-l">確定アサイン</span><span class="lb-s">アサイン</span></button>
      <button data-tab="tab-links" onclick="switchTab(this)"><span class="ti">🔗</span><span class="lb-l">リンク集</span><span class="lb-s">リンク</span></button>
      <button data-tab="tab-settings" onclick="switchTab(this)"><span class="ti">⚙️</span><span class="lb-l">設定</span><span class="lb-s">設定</span></button>
    </div>

    <div class="s-body">

      <!-- ===== タブ1：募集中の案件（エントリーできる） ===== -->
      <div class="tab-panel active" id="tab-jobs">
        {{-- 体験用アカウントのときの注意。応募・希望が保存されないので、押す前に分かるようにする。 --}}
        @if (!empty($mockOnly))
          <div class="notice" style="background:#fdeaea;border-color:#f0b9b9;">
            ⚠ <b>これは体験用のアカウントです。</b>エントリー（応募）や稼働希望を押しても<b>保存されません</b>（見本）。
            実際に試すときは、発行されたスタッフのアカウントでログインしてください。
          </div>
        @endif
        {{-- お知らせ文は DB（settings.staff_notice）から。担当が公開ボードで保存すると全スタッフに反映される。
             空のときの既定文は、募集が0件のときに「募集が出ています」と出ないように出し分ける
             （2026-08-24＝案件を登録する前に開くと嘘になるため）。 --}}
        <div class="notice" id="staffNotice">
          📣 @if(!empty($notice)){{ $notice }}
          @elseif (!empty($recruitJobs) && count($recruitJobs))募集が出ています。気になる案件は「エントリーする」を押してください。担当が確認して、確定したら「確定アサイン」タブに入ります。（エントリー締切は案件ごとに表示しています）
          @else いまは募集中の案件はありません。募集が始まると、ここに案件が並びます。@endif
        </div>

        <div class="notice extra-notice" id="extraNotice" style="display:none;"></div>

        <div class="job-filter">
          <input type="text" id="jobKw" placeholder="🔍 案件名・会場で探す" oninput="renderJobs()">
          <select id="jobArea" onchange="renderJobs()">
            <option value="">エリアすべて</option>
            <option value="千葉">千葉</option>
            <option value="東京">東京</option>
            <option value="オンライン">オンライン</option>
          </select>
          <input type="date" id="jobFrom" onchange="renderJobs()" title="この日以降の案件を表示します">
          <button type="button" class="jf-today" onclick="setJobToday()">今日から</button>
        </div>

        {{-- 絞り込みボタン。以前「状態」はプルダウンだったが、スマホでは押しにくく
             選んでいることも分かりにくかったので、押すと色が変わるボタンにした。
             「募集中」「エントリー中」はどちらか片方だけ（もう一度押すと解除）。
             「追加案件」はそれとは別に、重ねて絞り込める。 --}}
        <div class="job-toggles">
          <button type="button" class="jf-tg" id="jfOpen" onclick="setJobState('open')">📋 募集中のみ</button>
          <button type="button" class="jf-tg" id="jfApplied" onclick="setJobState('applied')">★ エントリー中のみ</button>
          <button type="button" class="jf-tg extra" id="jfExtra" onclick="toggleExtraOnly()">🔥 追加案件のみ</button>
        </div>

        {{-- 表示の切替（リスト／カレンダー）。2026-09-01 baba要望。
             上の絞り込みは、どちらの表示にも同じように効く。 --}}
        <div class="job-views">
          <button type="button" class="jv-tab active" id="jvList" onclick="switchJobView('list')">📋 リスト</button>
          <button type="button" class="jv-tab" id="jvCal" onclick="switchJobView('cal')">📅 カレンダー</button>
        </div>

        <div class="sec-title" id="jobSecTitle">募集中の案件</div>
        <div class="job-grid" id="jobGrid"></div>

        {{-- カレンダー表示。日付のマスに、その日の募集案件を並べる。 --}}
        <div id="jobCal" style="display:none;">
          <div class="cal-head">
            <button type="button" class="jc-nav" onclick="shiftJobMonth(-1)" title="前の月へ">‹</button>
            <div class="mon" id="jobCalMon">—</div>
            <button type="button" class="jc-nav" onclick="shiftJobMonth(1)" title="次の月へ">›</button>
          </div>
          <div class="cal-grid" id="jobCalGrid">
            <div class="dow sun">日</div><div class="dow">月</div><div class="dow">火</div><div class="dow">水</div><div class="dow">木</div><div class="dow">金</div><div class="dow sat">土</div>
          </div>
          {{-- 色の意味は画面にも出す。文章だけで説明すると、色を変えたときに食い違うため。
               ⚠ 見本のチップは本物と同じ class を使う＝色が変わればここも自動でそろう。 --}}
          <div class="jc-legend">
            <span class="jc-job open">募集中</span>
            <span class="jc-job applied">エントリー中</span>
            <span class="jc-job closed">締切・満員</span>
            <span class="jc-job open extra">追加案件</span>
          </div>
          <p class="jc-hint">案件をタップすると、<b>すぐ下に中身が開きます</b>。エントリーもそのままできます（カレンダーは開いたままです）。</p>
          <div class="empty-note" id="jobCalEmpty" style="display:none;">この月には、条件に合う案件がありません。「‹ ›」で前後の月を見てください。</div>
          {{-- タップした案件の中身。リストとまったく同じカードをここに出す（作り方は buildJobRow の1つだけ）。 --}}
          <div id="jobCalDetail" class="jc-detail" style="display:none;">
            <div class="jc-detail-head">
              <span>タップした案件</span>
              <button type="button" class="jc-detail-close" onclick="closeJobDetail()">✕ 閉じる</button>
            </div>
            <div class="job-grid" id="jobCalDetailBody"></div>
          </div>
        </div>

        <div class="empty-note" id="jobEmpty" style="display:none;">条件に合う案件がありません。</div>
      </div>

      <!-- ===== タブ2：稼働希望 ===== -->
      <div class="tab-panel" id="tab-pref">
        <div class="pref-wrap">
          {{-- 見出しは対象月から計算（当月）。以前は2026年7月が直書きで、保存対象月とズレていた。
               ⚠ 締切の表示はやめた（2026-08-25 baba）。「前月25日」を自動計算して出していたが、
                 実際の運用の締切と合っておらず、過ぎた日付が出て混乱のもとになるため。
                 締切の連絡はLINEで行う（ECS＝見せる・記録する／LINE＝連絡する、の分担どおり）。 --}}
          <div class="notice"><b>{{ $prefMeta['label'] }}分</b>の希望を入力中</div>

          {{-- 月の切り替え（2026-08-21 baba要望）。当月〜3か月先を行き来できる。 --}}
          <div class="pref-month-nav">
            @if ($prefMeta['prev'])
              <a class="pm-btn" href="/staff-portal?period={{ $prefMeta['prev'] }}#tab-pref">‹ {{ $prefMeta['prevLabel'] }}</a>
            @else
              <span class="pm-btn off">‹ 前の月</span>
            @endif
            <select class="pm-sel" onchange="if(this.value)location.href='/staff-portal?period='+this.value+'#tab-pref';"
                    aria-label="表示する月">
              @foreach ($prefMeta['months'] as $m)
                <option value="{{ $m['value'] }}" @selected($m['value'] === $prefMeta['value'])>{{ $m['label'] }}</option>
              @endforeach
            </select>
            @if ($prefMeta['next'])
              <a class="pm-btn" href="/staff-portal?period={{ $prefMeta['next'] }}#tab-pref">{{ $prefMeta['nextLabel'] }} ›</a>
            @else
              <span class="pm-btn off">次の月 ›</span>
            @endif
          </div>
          @if (!empty($prefMeta['isPast']))
            <div class="notice" style="background:#fdeaea;border-color:#f0b9b9;">
              ⚠ これは<b>過ぎた月</b>です。入力しても募集には反映されません（見るだけにしてください）。
            </div>
          @endif

          <div class="m-card">
            <h3>稼働できる日を選んでください</h3>
            <p class="sub">日付をタップ（クリック）するたびに「終日〇 → NG → 未定」と切り替わります。「終日〇」はその日は一日じゅう稼働できる（どの案件にも入れる）という意味です。「★エントリー中」はエントリーした案件がある日、「イベント」は確定アサインが入っている日です（どちらもタップでは変えられません）。</p>
            <div class="cal-head"><div class="mon">{{ $prefMeta['year'] }}年 {{ $prefMeta['month'] }}月</div></div>
            <div class="cal-grid" id="calGrid">
              <div class="dow sun">日</div><div class="dow">月</div><div class="dow">火</div><div class="dow">水</div><div class="dow">木</div><div class="dow">金</div><div class="dow sat">土</div>
              {{-- 1日までの空きマスは対象月の曜日から作る（以前は「7/1は火曜」で2つ固定だった） --}}
            </div>
            <div class="legend">
              <span><i class="dot ok"></i>終日〇</span>
              <span><i class="dot ng"></i>NG</span>
              <span><i class="dot"></i>未定</span>
              <span><i class="dot entry"></i>★エントリー中</span>
              <span><i class="dot event"></i>イベント（確定）</span>
            </div>
          </div>

          <div class="m-card">
            <div class="field">
              <label>コメント（任意）</label>
              <textarea id="prefMemo" rows="2" placeholder="例）後半は予定が入りやすいです／土日を希望します"></textarea>
            </div>
            <button class="submit-btn" onclick="submitPref()">この内容で希望を提出する</button>
            <div class="saved-msg" id="savedMsg">✓ 希望を保存しました</div>
          </div>
        </div>
      </div>

      <!-- ===== タブ3：確定アサイン ===== -->
      <div class="tab-panel" id="tab-assign">
        <div class="assign-wrap">
          <div class="m-card">
            <h3>確定したアサイン</h3>
            <p class="sub">担当が「<b>スタッフに公開</b>」して、あなたのアサインを<b>確定</b>にした案件だけが表示されます（エントリーしただけ・調整中のものは出ません）。公開後にメンバーや内容が変わったときも、最新の内容が表示されます。<b>タップすると持ち物・服装・注意事項が見られます。</b></p>

            <!-- 公開スイッチがONの案件だけ、共通データから自動表示（集合時間は公開ボードの編集を反映） -->
            <div id="confirmList"></div>
          </div>
          <p class="empty-note">これ以降のアサインはまだ確定していません。</p>
        </div>
      </div>

      {{-- ===== タブ4：リンク集 =====
           中身は共通設定（/settings の「スタッフ画面のリンク集」）で社員が登録する（settings.staff_links）。
           最初は設定タブの中に置いていたが「どこにあるか分からない」との声があり、専用タブに独立させた。 --}}
      <div class="tab-panel" id="tab-links">
        <div class="settings-wrap">
          <div class="m-card">
            <h3>🔗 リンク集</h3>
            <p class="sub">よく使うページをまとめています。タップすると別のタブで開きます。</p>
            @if(!empty($staffLinks))
            <div class="lk-items">
              @foreach($staffLinks as $link)
              <a class="lk-item" href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer">
                <span class="lk-txt">
                  <span class="lk-name">{{ $link['title'] }}</span>
                  @if(!empty($link['memo']))<span class="lk-memo">{{ $link['memo'] }}</span>@endif
                </span>
                <span class="lk-go">開く ↗</span>
              </a>
              @endforeach
            </div>
            @else
            {{-- 空っぽのまま黙って出すと「壊れている」と思われるので、理由を書いておく。 --}}
            <p class="lk-none">まだリンクが登録されていません。担当者が登録すると、ここに並びます。</p>
            @endif
          </div>
        </div>
      </div>

      <!-- ===== タブ5：設定 ===== -->
      <div class="tab-panel" id="tab-settings">
        <div class="settings-wrap">

          <!-- ① プロフィール（自分の情報） -->
          <div class="m-card">
            <h3>プロフィール</h3>
            <p class="sub">あなたの基本情報です。担当が当日の準備やメンバー決めの参考にします。</p>
            <div class="field-row3">
              <div class="field"><label>身長</label><input id="pfHeight" type="number" inputmode="numeric" placeholder="cm"></div>
              <div class="field"><label>靴（足袋）のサイズ</label><input id="pfShoe" type="number" inputmode="decimal" placeholder="cm"></div>
              <div class="field"><label>服のサイズ</label>
                <select id="pfWear">
                  <option value="">選択</option>
                  <option>SS</option><option>S</option><option>M</option><option>L</option><option>LL</option><option>3L</option>
                </select>
              </div>
            </div>
            <div class="field-row2">
              <div class="field"><label>都道府県</label>
                <select id="pfPref">
                  <option value="">選択</option>
                  <option>北海道</option><option>青森県</option><option>岩手県</option><option>宮城県</option><option>秋田県</option><option>山形県</option><option>福島県</option>
                  <option>茨城県</option><option>栃木県</option><option>群馬県</option><option>埼玉県</option><option>千葉県</option><option>東京都</option><option>神奈川県</option>
                  <option>新潟県</option><option>富山県</option><option>石川県</option><option>福井県</option><option>山梨県</option><option>長野県</option>
                  <option>岐阜県</option><option>静岡県</option><option>愛知県</option><option>三重県</option>
                  <option>滋賀県</option><option>京都府</option><option>大阪府</option><option>兵庫県</option><option>奈良県</option><option>和歌山県</option>
                  <option>鳥取県</option><option>島根県</option><option>岡山県</option><option>広島県</option><option>山口県</option>
                  <option>徳島県</option><option>香川県</option><option>愛媛県</option><option>高知県</option>
                  <option>福岡県</option><option>佐賀県</option><option>長崎県</option><option>熊本県</option><option>大分県</option><option>宮崎県</option><option>鹿児島県</option><option>沖縄県</option>
                </select>
              </div>
              <div class="field"><label>最寄り駅</label><input id="pfStation" type="text" placeholder="例）JR千葉駅"></div>
            </div>
            <div class="field"><label>一言アピール</label><textarea id="pfAppeal" rows="2" placeholder="例）元気な進行が得意です！"></textarea></div>
            <div class="field"><label>好きなコンテンツ</label><input id="pfLike" type="text" placeholder="例）運動会・水合戦"></div>
            <div class="field"><label>苦手なコンテンツ</label><input id="pfDislike" type="text" placeholder="例）オンライン配信"></div>
            <div class="field"><label>得意なポジション</label><textarea id="pfStrongPosFree" rows="2" placeholder="例）大人数の前での進行が得意。盛り上げ役が好きです。／裏方作業やサポートをするのが得意です。"></textarea></div>
            <div class="field"><label>苦手なポジション</label><textarea id="pfWeakPosFree" rows="2" placeholder="例）細かい受付業務はやや苦手です。／オンラインなどPC操作が発生する業務が苦手です。"></textarea></div>

            {{-- できること・やってみたいこと（2026-08-31 baba要望）。社員の「マイプロフィール」と同じ項目。
                 選択肢はサーバー側（App\Support\ProfileOptions）から出す＝画面に直書きしない。
                 ⚠ 運転・英語はこの下の「できるポジション・スキル」にすでにあるので、ここには置かない（二重入力になる）。 --}}
            <div class="field">
              <label>その他話せる言語</label>
              <input id="pfOtherLang" type="text" placeholder="例）中国語（日常会話）・韓国語（片言）">
            </div>

            <div class="field">
              <label>チャレンジしたいポジション</label>
              <div id="pfChallengeList" style="display:flex;flex-wrap:wrap;gap:8px 16px;padding:4px 2px;">
                @foreach (\App\Support\ProfileOptions::CHALLENGE_POSITIONS as $opt)
                  <label style="display:inline-flex;align-items:center;gap:6px;font-size:13.5px;font-weight:400;">
                    <input type="checkbox" data-val="{{ $opt }}" style="width:auto;">{{ $opt }}
                  </label>
                @endforeach
              </div>
              <span style="font-size:12px;color:var(--muted);">今できるかどうかは気にせず、やってみたいものを選んでください。</span>
            </div>

            <div class="field">
              <label>日常で使っているオンラインツール</label>
              <div id="pfToolList" style="display:flex;flex-wrap:wrap;gap:8px 16px;padding:4px 2px;">
                @foreach (\App\Support\ProfileOptions::ONLINE_TOOLS as $opt)
                  <label style="display:inline-flex;align-items:center;gap:6px;font-size:13.5px;font-weight:400;">
                    <input type="checkbox" data-val="{{ $opt }}" style="width:auto;">{{ $opt }}
                  </label>
                @endforeach
              </div>
              <span style="font-size:12px;color:var(--muted);">ひととおり使えるものを選んでください。</span>
            </div>

            <div class="field">
              <label>その他のオンラインツール</label>
              <input id="pfToolOther" type="text" placeholder="例）Miro・Figma">
            </div>

            <div class="field">
              <label>その他備考</label>
              <textarea id="pfNote" rows="2" placeholder="例）運転練習中です。簡単な動画編集ができます。"></textarea>
            </div>

            <div style="font-size:12.5px;line-height:1.7;color:var(--muted);background:#f8f3ea;border:1px dashed var(--line);border-radius:8px;padding:9px 12px;margin:4px 0 10px;">
              💡 ここに入力した内容は、メンバーを決めるときの<b>参考</b>にさせてもらうものです。できるだけ希望や得意を活かしたいと思っていますが、現場の状況やチームのバランスもあるため、<b>必ずしも好きなコンテンツや得意なポジションばかりにアサインされるわけではありません</b>。あらかじめご了承ください。
            </div>
            <button class="submit-btn" onclick="saveProfile()">この内容で保存する</button>
            <div class="saved-msg" id="pfSavedMsg">✓ 保存しました</div>
          </div>

          <!-- ② できるポジション・スキル -->
          <div class="m-card">
            <h3>できるポジション・スキル</h3>
            <p class="sub">あなたが対応できる役割をONにしてください。ここで選んだ内容は、担当が案件のメンバーを決めるときの参考になります。</p>
            <div class="pos-list" id="posList"></div>
            <button class="submit-btn" style="margin-top:6px;" onclick="savePositions()">この内容で保存する</button>
            <div class="saved-msg" id="posSavedMsg">✓ 保存しました</div>
          </div>

          <!-- ③ アカウント（ログイン関連） -->
          <div class="m-card">
            <h3>アカウント</h3>
            <p class="sub">ログイン情報の確認・変更ができます。</p>
            <div class="acct-row">
              <span class="acct-label">ログイン中のメール</span>
              <span class="acct-value">{{ Auth::user()->email ?? '—' }}</span>
            </div>
            <button class="line-btn" onclick="location.href='/profile'">マイプロフィールを編集する</button>
            <button class="line-btn" onclick="location.href='/password'">パスワードを変更する</button>
            <button class="line-btn" onclick="window.open('/guide-staff','_blank','noopener')">📋 使い方ガイドを見る</button>
            <button class="line-btn danger" onclick="doLogout()">ログアウト</button>
          </div>

        </div>
      </div>

    </div>
  </div>

  {{-- 凍結モック /ecs/data/cases.js の読み込みはやめた（2026-08-24）。
       案件が0件のとき、架空の案件19件がスタッフ全員に見えてしまっていた。
       スタッフに配るアカウントを増やすので、見本に戻る道をなくす（baba要望）。
       この画面が使っていたのは日付計算の関数だけなので、下で定義する。 --}}
  <script>
    // 今日から off 日後の日付。凍結モックにあった ECS_caseDate と同じ動き。
    window.ECS_caseDate = function (off) {
      var x = new Date();
      x.setHours(0, 0, 0, 0);
      x.setDate(x.getDate() + (off || 0));
      return x;
    };
  </script>
  <!-- DBから渡された「公開ON（staff_published=true）」の案件。確定アサイン表示の元データ。 -->
  <script>window.ECS_PUBLISHED = @json($published);</script>
  <!-- 募集中タブの案件リスト（DB）。 -->
  <script>window.ECS_RECRUIT_JOBS = @json($recruitJobs ?? []);</script>
  <!-- 設定タブの初期値（本人のDB値）＋保存用のCSRFトークン。test/未ログインは null。 -->
  <script>
    window.ECS_MY_PROFILE = @json($myProfile ?? null);
    window.ECS_CSRF = '{{ csrf_token() }}';
    {{-- 体験用（見本）アカウントか。⚠ true のときはエントリー・稼働希望が保存されない。
         押したあとに知らせるだけだと「エントリーしたのに担当の一覧に無い」に見えるので、
         ボタンの文字でも先に知らせる（2026-08-28 baba報告）。 --}}
    window.ECS_MOCK_ONLY = @json(! empty($mockOnly));
    window.ECS_MY_PREFS = @json($myPrefs ?? []);
    window.ECS_PREF_PERIOD = @json($prefPeriod ?? '');
    window.ECS_MY_PREF_MEMO = @json($myPrefMemo ?? '');
    window.ECS_PREF_META = @json($prefMeta ?? null);
    {{-- プロフィールの選択肢（運転・英語）。正本＝App\Support\ProfileOptions。
         ⚠ 必ずここ（下の「そのまま出す」区間の外）で渡すこと。
           下の script はその区間の中なので、Blade の書き方をしても処理されず、
           書いた文字がそのまま JavaScript に出て文法エラーになる
           ＝ スタッフ画面が丸ごと動かなくなる（2026-08-31 に本番で起こした。タブも押せない）。 --}}
    window.ECS_PROFILE_OPTIONS = @json([
        'driving' => \App\Support\ProfileOptions::drivingChoices(),
        'english' => \App\Support\ProfileOptions::englishChoices(),
    ]);
  </script>
  @verbatim
  <script>
    // ===== 募集中の案件 =====
    // 中身はDB（ECS_RECRUIT_JOBS＝StaffPortalController の recruitJobs）がすべて。
    // スタッフ画面には「募集する・過去でない・下書きでない」案件だけ出す。
    // 0件なら0件のまま出す。
    // ⚠ 以前は「案件が1件も無ければ見本 /ecs/data/cases.js を出す」作りで、案件を登録する前は
    //   架空の案件19件がスタッフ全員に見えていた（2026-08-24 baba指摘で撤去）。
    //   スタッフにアカウントを配るので、見本に戻る道は残さない。
    //   以前ここにあった見本の配列（_jobsSample）と列の説明も一緒に消した。
    // ※応募（エントリー）は本物保存です（DB=applicationsへ）。「エントリーする／取り消す」を押すと保存されます。
    const _jobSrc = window.ECS_RECRUIT_JOBS || [];
    const jobs = _jobSrc.filter(c => c.recruit && !c.archived && !c.draft).map(c => {
      const _dl = new Date(); _dl.setHours(0,0,0,0); _dl.setDate(_dl.getDate() + c.off - 4);
      return {
        id:c.id, content:c.content, client:c.client, place:c.place, meetPlace:c.meetPlace,
        area:c.area, format:c.fmt, fmtText:c.fmtText, size:(c.scale === '大型' ? '大型' : ''), repeat:!!c.repeat,
        lodging:c.lodging, dayType:c.dayType, parentId:c.parentId,
        // 締切はサーバー計算（通常=一斉締切日／追加=公開日+3日・土日は月曜）。
        // 万一サーバーから来なかったときだけ「開催日の4日前」で出す（空欄にしないための保険）。
        deadline:(c.deadline || ((_dl.getMonth()+1) + '/' + _dl.getDate())),
        need:c.need, filled:c.filled, meet:c.meet, leave:c.leave,
        enter:c.enter, evStart:c.evStart, evEnd:c.evEnd, evTbd:!!c.evTbd, offset:c.off,
        staffNotes:(c.staffNotes || ''),
        // 自分が応募済みなら「エントリー中」を最優先で表示（そうすれば取り消しもできる）。
        // 未応募なら満員→締切／調整中→エントリー中／それ以外→募集中。
        state:(c.applied ? 'applied' : (c.filled >= c.need ? 'closed' : (c.state === 'adj' ? 'applied' : 'open'))),
        applied:!!c.applied, myNote:(c.myNote || ''), myIntent:(c.myIntent || '希望'),
        extra:(c.category === '追加案件')
      };
    });

    // ===== 日付ユーティリティ =====
    const DOW_CIRCLE = ['㈰','㈪','㈫','㈬','㈭','㈮','㈯'];
    function atMidnight(d){ const x = new Date(d); x.setHours(0,0,0,0); return x; }
    function addDays(d,n){ const x = new Date(d); x.setDate(x.getDate()+n); return x; }
    const today = atMidnight(new Date());
    jobs.forEach((j,i) => { j._i = i; j.date = addDays(today, j.offset); });
    const jobById = {};
    jobs.forEach(j => { jobById[j.id] = j; });
    // 予備日・リハは親の本番に合わせて並べるためのアンカー（本番＝自分、予備日/リハ＝親の本番）
    function anchorJob(j) {
      if ((j.dayType === '予備日' || j.dayType === 'リハ') && j.parentId && jobById[j.parentId]) return jobById[j.parentId];
      return j;
    }

    const stateBadge = { open:{c:'open', t:'募集中'}, applied:{c:'applied', t:'エントリー中'}, closed:{c:'closed', t:'締切・満員'} };
    // 実施形態のバッジ。⚠ ここに実施形態の一覧を書かない。
    //   前は { real:…, long:…, online:… } の対応表を引いていたため、
    //   ARENA場所貸し・体験会・未入力（＝表に無い値）で undefined になり、
    //   **そこで募集一覧の描画が丸ごと止まっていた**（お知らせだけ出て一覧が空になる）。
    //   いまは色の手がかり（fmt）と名前（fmtText）をサーバーから受け取るだけなので、
    //   実施形態が増えても画面を直す必要が無い。
    function fmtBadge(j){
      return { c: j.format || 'fmt-etc', t: (j.fmtText || '').trim() || 'その他' };
    }

    // 絞り込みボタンの状態。
    // jobStateFilter … ''＝すべて / 'open'＝募集中のみ / 'applied'＝エントリー中のみ（どちらか片方）
    // extraOnly      … 追加案件だけに絞るか（上とは別に重ねられる）
    let jobStateFilter = '';
    let extraOnly = false;

    function syncToggleButtons() {
      document.getElementById('jfOpen').classList.toggle('on', jobStateFilter === 'open');
      document.getElementById('jfApplied').classList.toggle('on', jobStateFilter === 'applied');
      document.getElementById('jfExtra').classList.toggle('on', extraOnly);
    }
    // 同じボタンをもう一度押したら解除（＝すべて表示に戻る）。
    function setJobState(v) {
      jobStateFilter = (jobStateFilter === v) ? '' : v;
      syncToggleButtons();
      renderJobs();
    }
    function toggleExtraOnly() {
      extraOnly = !extraOnly;
      syncToggleButtons();
      renderJobs();
    }

    // ===== 募集案件の行を描画 =====
    // カードを1枚つくる（2026-09-01 に切り出し）。
    // ⚠ リストとカレンダーの両方で**この1つ**を使う。
    //   カレンダー側に別のカードを作ると、エントリーボタンやコメント欄が2つになり、
    //   片方だけ直して食い違う。描けなかったときは null を返す（黙って消さない）。
    function buildJobRow(j) {
      try {
        const dy   = j.date.getDay();
        const dowC = dy === 0 ? 'sun' : (dy === 6 ? 'sat' : '');
        // 状態のバッジも、知らない状態が来たら「募集中」で描き続ける。
        const sb   = stateBadge[j.state] || stateBadge.open;
        const fb   = fmtBadge(j);

        // 日付（7/5㈰）
        const dateStr = `${j.date.getMonth()+1}/${j.date.getDate()}<span class="${dowC}">${DOW_CIRCLE[dy]}</span>`;

        // タグ（追加・予備日/リハ・実施形態・大型・リピート・宿泊）
        let tags = '';
        if (j.extra)                tags += '<span class="tag-mini add">追加</span>';
        if (j.dayType === '予備日')  tags += '<span class="tag-mini yobi">予備日</span>';
        else if (j.dayType === 'リハ') tags += '<span class="tag-mini reha">リハ</span>';
        tags += `<span class="fbadge ${fb.c}">${fb.t}</span>`;
        if (j.size === '大型')      tags += '<span class="tag-mini size">大型</span>';
        if (j.repeat)               tags += '<span class="tag-mini rep">リピート</span>';
        if (j.lodging && j.lodging !== '無') tags += `<span class="tag-mini stay">${j.lodging}</span>`;

        // 締切・残り人数のチップ
        const remain = (typeof j.need === 'number') ? Math.max(0, j.need - (j.filled || 0)) : null;
        let slotChip = '';
        if (j.state === 'closed' || remain === 0) slotChip = '<span class="chip full">満員</span>';
        else if (remain !== null)                 slotChip = `<span class="chip slots${remain <= 2 ? ' few' : ''}">あと${remain}名</span>`;
        const deadlineChip = j.deadline ? `<span class="chip deadline" title="締切を過ぎても応募は受け付けます（目安の日付です）">📅 締切 ${j.deadline}</span>` : '';

        // コメントの保存内容（再描画でも消えないように保持）
        const cmt = commentState[j.id] || { text: '', open: false };

        // エントリーボタン（状態で見た目が変わる）
        let btn;
        if (j.state === 'closed') {
          btn = `<button class="apply-btn-sm disabled" disabled>締切・満員</button>`;
        } else if (j.state === 'applied') {
          btn = `<button class="apply-btn-sm cancel" onclick="toggleApply(${j._i})">エントリーを取り消す</button>`;
        } else if (window.ECS_MOCK_ONLY) {
          // ⚠ 体験用（見本）アカウントはエントリーが保存されない。押したあとに注意を出すだけだと
          //   「エントリーしたのに担当の一覧に無い」に見えるので、押す前にボタンで分かるようにする
          //   （2026-08-28 baba報告）。上の赤い注意と合わせて二重に知らせる。
          btn = `<button class="apply-btn-sm" onclick="toggleApply(${j._i})" title="体験用アカウントのため保存されません。実際に応募するときは、発行されたスタッフのアカウントでログインしてください。">エントリーする（体験用・保存されません）</button>`;
        } else {
          btn = `<button class="apply-btn-sm" onclick="toggleApply(${j._i})">エントリーする</button>`;
        }

        const row = document.createElement('div');
        // どの案件のカードか分かるように印を付けておく。
        // ⚠ 同じ案件を2か所に出さない決まりなので id が重ならない
        //   （カレンダー表示のときはリストを作らない）。
        row.id = 'job-' + j.id;
        row.className = 'job-row' + (j.extra ? ' extra' : '') + (j.state === 'applied' ? ' applied' : '') + (j.state === 'closed' ? ' closed' : '');
        row.innerHTML = `
          <div class="jr-head">
            <span class="jr-date">${dateStr}</span>
            <span class="jr-title">${(j.dayType === '予備日' || j.dayType === 'リハ') ? '<span style="color:var(--muted);">↳ </span>' : ''}${j.content}<span class="jr-client">${j.client}</span></span>
            <span class="j-badge ${sb.c}">${sb.t}</span>
          </div>
          <div class="jr-tags">${tags}</div>
          <div class="jr-meta">
            <span><span class="ic">📍</span>${j.place}</span>
            <span><span class="ic">🚩</span>集合 ${j.meetPlace}</span>
            <span><span class="ic">⏰</span>${staffMeetOf(j.id, j.meet)}〜${j.leave}<span class="ev">${j.evTbd ? '（本番時間未定）' : `（入場${j.enter}/開始${j.evStart}/終了${j.evEnd}）`}</span></span>
          </div>
          ${String(j.staffNotes || '').trim() !== ''
            ? `<div class="jr-note"><span class="ic">📣</span><span>${escLines(j.staffNotes)}</span></div>` : ''}
          <div class="jr-sub">${deadlineChip}${slotChip}</div>
          ${j.saveError ? `<div class="jr-error">⚠ ${escAttr(j.saveError)}</div>` : ''}
          <div class="jr-foot">
            <button class="cmt-toggle${cmt.open ? ' on' : ''}" onclick="toggleComment(this, '${j.id}')">💬 コメント</button>
            ${btn}
          </div>
          <div class="jr-comment-wrap" style="display:${cmt.open ? 'block' : 'none'};">
            <div class="jr-comment-row">
              <input class="jr-comment" type="text" placeholder="担当へ伝えたいこと（任意）" value="${escAttr(cmt.text)}"
                     oninput="saveComment('${j.id}', this.value)"
                     onkeydown="if(event.key==='Enter'){event.preventDefault();sendComment('${j.id}');}">
              <button type="button" class="jr-cmt-save" onclick="sendComment('${j.id}')">保存</button>
              <span class="jr-cmt-ok" id="cmtok-${j.id}">✓ 保存しました</span>
            </div>
            <div class="jr-cmt-hint">担当（アサインする人）の画面に表示されます。エントリーしたあとでも直せます。</div>
          </div>`;
        return row;
      } catch (e) {
        console.error('募集案件を表示できませんでした', j && j.id, e);
        return null;
      }
    }

    function renderJobs() {
      const kw    = document.getElementById('jobKw').value.trim();
      const area  = document.getElementById('jobArea').value;
      const state = jobStateFilter;
      const fromStr = document.getElementById('jobFrom').value;
      const fromDate = fromStr ? (function(){ const p = fromStr.split('-'); return new Date(+p[0], (+p[1]) - 1, +p[2]); })() : null;
      const grid  = document.getElementById('jobGrid');
      grid.innerHTML = '';

      const list = jobs
        .filter(j => !kw    || (j.content + j.client + j.place).includes(kw))
        .filter(j => !area  || j.area === area)
        .filter(j => !state || j.state === state)
        .filter(j => !fromDate || j.date >= fromDate)   // 「この日から」以降の案件だけ表示
        // 「🔥 追加案件のみ」。予備日・リハは本番（親）が追加案件なら一緒に残す。
        .filter(j => !extraOnly || j.extra || anchorJob(j).extra)
        .sort((a,b) => {
          const pa = anchorJob(a), pb = anchorJob(b);
          // 追加案件を先頭に
          const ax = pa.extra ? 1 : 0, bx = pb.extra ? 1 : 0;
          if (ax !== bx) return bx - ax;
          // 本番の日付の近い順（予備日・リハは親の本番に合わせて並ぶ）
          if (pa.date - pb.date !== 0) return pa.date - pb.date;
          // 同じ本番グループ内：本番 → 予備日・リハ の順
          const sa = (a.dayType === '予備日' || a.dayType === 'リハ') ? 1 : 0;
          const sb = (b.dayType === '予備日' || b.dayType === 'リハ') ? 1 : 0;
          return sa - sb;
        });

      // 追加募集のお知らせバナー（表示中の追加案件の件数）
      const extraCount = list.filter(j => j.extra).length;
      const en = document.getElementById('extraNotice');
      if (extraCount > 0) {
        en.style.display = '';
        en.innerHTML = `📣 <b>追加募集が${extraCount}件</b>出ています。急ぎの募集が多いので、参加できる方はお早めにエントリーをお願いします。`;
      } else {
        en.style.display = 'none';
      }

      // ⚠ 1件でも描けない案件があっても、そこで止めない。
      //   前は途中で止まると**残り全部が消える**のに画面は真っ白にならなかったので、
      //   「公開したのに出てこない」に気づけなかった（2026-08-28 baba報告）。
      // ⚠ カレンダー表示のときはリストを作らない。
      //   作ってしまうと、同じ案件のカードが2つになり（リストとカレンダーの下）、
      //   同じ id が2つできて「コメント保存しました」の表示などが片方にしか出なくなる。
      let skipped = 0;
      if (jobView === 'list') {
        list.forEach(j => {
          const row = buildJobRow(j);
          if (row) grid.appendChild(row); else skipped++;
        });
      }

      if (skipped > 0) {
        const warn = document.createElement('div');
        warn.className = 'extra-notice';
        warn.innerHTML = `⚠ ${skipped}件は表示できませんでした。お手数ですが担当へお知らせください。`;
        grid.appendChild(warn);
      }

      document.getElementById('jobEmpty').style.display = list.length === 0 ? '' : 'none';
      document.getElementById('jobSecTitle').textContent = `募集中の案件（${list.length}件）`;
      updateOpenCount();

      // カレンダー表示のときは、同じ「絞り込みを通った案件」でカレンダーも描き直す。
      // ⚠ 絞り込みは renderJobs の1か所だけで行う＝リストとカレンダーで結果が食い違わない。
      if (jobView === 'cal') renderJobCal(list);
    }

    // ===== 募集中の案件：カレンダー表示（2026-09-01 baba要望）=====
    // ⚠ 絞り込みはここでは行わない。renderJobs が絞り込んだ結果をそのまま受け取る。
    let jobView = 'list';                                             // 'list' か 'cal'
    const jobCalCursor = new Date(today.getFullYear(), today.getMonth(), 1);   // 見ている月の1日

    function switchJobView(view) {
      jobView = view;
      document.getElementById('jvList').classList.toggle('active', view === 'list');
      document.getElementById('jvCal').classList.toggle('active', view === 'cal');
      document.getElementById('jobGrid').style.display = (view === 'list') ? '' : 'none';
      document.getElementById('jobCal').style.display  = (view === 'cal')  ? '' : 'none';
      // 「条件に合う案件がありません」はリストのときだけ（カレンダーは月ごとに別の案内を出す）。
      if (view === 'cal') document.getElementById('jobEmpty').style.display = 'none';
      // リストに戻るときは、カレンダーの下に開いていた案件を閉じる（同じカードが2つ出ないように）。
      if (view === 'list') { openJobId = null; renderJobDetail(); }
      renderJobs();
    }

    function shiftJobMonth(n) {
      jobCalCursor.setMonth(jobCalCursor.getMonth() + n);
      renderJobs();
    }

    function renderJobCal(list) {
      const y = jobCalCursor.getFullYear();
      const m = jobCalCursor.getMonth();          // 0始まり
      document.getElementById('jobCalMon').textContent = y + '年 ' + (m + 1) + '月';

      const grid = document.getElementById('jobCalGrid');
      // 曜日の見出し（先頭7つ）は残して、日付のマスだけ作り直す。
      while (grid.children.length > 7) grid.removeChild(grid.lastChild);

      // その月の案件を日ごとにまとめる。
      const byDay = {};
      let monthCount = 0;
      list.forEach(j => {
        if (j.date.getFullYear() !== y || j.date.getMonth() !== m) return;
        (byDay[j.date.getDate()] = byDay[j.date.getDate()] || []).push(j);
        monthCount++;
      });

      const first = new Date(y, m, 1);
      const days = new Date(y, m + 1, 0).getDate();
      // 1日までの空きマス（日曜始まり＝稼働希望のカレンダーと同じ並び）。
      for (let i = 0; i < first.getDay(); i++) {
        const e = document.createElement('div');
        e.className = 'jc-cell empty';
        grid.appendChild(e);
      }

      for (let d = 1; d <= days; d++) {
        const cell = document.createElement('div');
        const dow = new Date(y, m, d).getDay();
        cell.className = 'jc-cell' + (dow === 0 ? ' sun' : (dow === 6 ? ' sat' : ''))
          + ((y === today.getFullYear() && m === today.getMonth() && d === today.getDate()) ? ' today' : '');
        const num = document.createElement('div');
        num.className = 'dnum';
        num.textContent = d;
        cell.appendChild(num);

        (byDay[d] || []).forEach(j => {
          const b = document.createElement('button');
          b.type = 'button';
          b.className = 'jc-job ' + j.state + (j.extra ? ' extra' : '') + (openJobId === j.id ? ' picked' : '');
          b.textContent = j.content;
          b.title = j.content + '／' + j.client + '（' + (stateBadge[j.state] || stateBadge.open).t + '）';
          b.onclick = function () { openJobDetail(j.id); };
          cell.appendChild(b);
        });

        grid.appendChild(cell);
      }

      document.getElementById('jobCalEmpty').style.display = monthCount === 0 ? '' : 'none';

      // 開いている案件が、いま見ている月・絞り込みから外れたら閉じる（中身だけ残らないように）。
      if (openJobId && !list.some(j => j.id === openJobId)) openJobId = null;
      renderJobDetail();
    }

    // カレンダーの案件をタップ → **カレンダーはそのまま**、すぐ下に中身を開く（2026-09-01 baba指摘）。
    // ⚠ 前はリスト表示へ飛ばしていたが、カレンダーに戻るのに上までスクロールし直しで面倒だった。
    // ⚠ 出すカードは buildJobRow で作る＝リストとまったく同じもの。
    //   カレンダー用に別のカードを作ると、エントリーボタンやコメント欄が2種類になって食い違う。
    let openJobId = null;

    function openJobDetail(id) {
      openJobId = id;
      renderJobDetail();
    }

    function closeJobDetail() {
      openJobId = null;
      renderJobDetail();
    }

    function renderJobDetail() {
      const wrap = document.getElementById('jobCalDetail');
      const body = document.getElementById('jobCalDetailBody');
      if (!wrap || !body) return;
      body.innerHTML = '';

      const j = openJobId ? jobById[openJobId] : null;
      if (!j) { wrap.style.display = 'none'; return; }

      const row = buildJobRow(j);
      if (!row) { wrap.style.display = 'none'; return; }
      body.appendChild(row);
      wrap.style.display = '';
    }

    // コメントの保存（案件IDごとに本文と開閉状態を覚えておく＝再描画で消えない）
    const commentState = {};
    // 応募済み案件は、DBに保存済みの一言コメント（myNote）を初期表示に復元する。
    jobs.forEach(j => { if (j.myNote) commentState[j.id] = { text: j.myNote, open: false }; });
    // 画面に文字を差し込むときの共通エスケープ。案件名・会場名などはDBの自由入力なので
    // < > も必ず落とす（以前は & と " だけで、案件名にタグを入れると本人画面でスクリプトが動いた）。
    function escAttr(s) {
      return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    // 改行を <br> にして差し込む（持ち物・注意事項のように複数行の項目用）。
    function escLines(s) { return escAttr(s).split(/[\r\n]+/).join('<br>'); }
    function saveComment(id, val) {
      commentState[id] = commentState[id] || { text: '', open: true };
      commentState[id].text = val;
    }

    // コメントを保存する（2026-08-21 baba要望）。
    // ⚠ これまではコメントを「エントリーする」を押したときにだけ送っていたため、
    //   エントリーしたあとに書いたコメントは**どこにも保存されていなかった**。
    //   保存ボタンで、その場でDB（applications.note）へ入れる。
    function sendComment(id) {
      const j = jobs.find(x => x.id === id);
      if (!j) return;
      if (j.state !== 'applied') {
        alert('先に「エントリーする」を押してください。エントリーした案件にだけコメントを残せます。');
        return;
      }
      const note = (commentState[id] && commentState[id].text) || '';
      const body = new URLSearchParams();
      body.append('project_id', id);
      body.append('action', 'apply');     // 応募のまま、コメントだけ書き換える
      body.append('intent', '希望');
      body.append('note', note);

      fetch('/staff-portal/entry', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': window.ECS_CSRF || '', 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      })
      .then(readJson)
      .then(res => {
        if (!res || !res.ok) { alert((res && res.message) || '保存できませんでした。もう一度お試しください。'); return; }
        if (res.saved === false) {
          alert(res.message || 'このアカウントは体験用のため、コメントは保存されません（見本）。');
          return;
        }
        const ok = document.getElementById('cmtok-' + id);
        if (ok) { ok.classList.add('show'); setTimeout(() => ok.classList.remove('show'), 2000); }
      })
      .catch(err => alert(saveErrorMessage(err)));
    }

    // コメント欄の開け閉め（ふだんは隠し、押したら開いて入力できる）
    function toggleComment(btn, id) {
      const wrap = btn.closest('.job-row').querySelector('.jr-comment-wrap');
      const open = wrap.style.display === 'none';
      wrap.style.display = open ? 'block' : 'none';
      btn.classList.toggle('on', open);
      commentState[id] = commentState[id] || { text: '', open: false };
      commentState[id].open = open;
      if (open) wrap.querySelector('.jr-comment').focus();
    }

    // エントリーする／取り消す → DB(applications)へ本物保存する。
    //  applied 以外 → apply（応募・コメントも note として送る）／ applied → cancel（取り消し）。
    // 画面は先に切り替えて体感を軽くし、保存に失敗したら元に戻して知らせる。
    function toggleApply(i) {
      const j = jobs[i];
      const willApply = (j.state !== 'applied');
      const before = j.state;
      const note = (commentState[j.id] && commentState[j.id].text) || '';

      const body = new URLSearchParams();
      body.append('project_id', j.id);
      body.append('action', willApply ? 'apply' : 'cancel');
      if (willApply) { body.append('intent', '希望'); body.append('note', note); }

      // 先に画面を反映。エントリーした瞬間にメモ（コメント）欄を開いて、その場で一言添えられるようにする。
      j.state = willApply ? 'applied' : 'open';
      if (willApply) {
        commentState[j.id] = commentState[j.id] || { text: '', open: false };
        commentState[j.id].open = true;
      }
      renderJobs();
      // 稼働希望カレンダーの「★エントリー中」も、リロードを待たずその場で反映する。
      if (typeof refreshEntryDay === 'function') refreshEntryDay(j, willApply);

      fetch('/staff-portal/entry', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': window.ECS_CSRF || '', 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      })
      // ⚠ 中身が JSON でないときは「ログインの画面（HTML）に飛ばされた」＝有効期限切れ。
      //   そのまま読もうとすると SyntaxError になり、意味の分からないお知らせになる。
      .then(readJson)
      .then(res => {
        if (!res || !res.ok) { entryFailed(j, before, (res && res.message) || '保存できませんでした。'); return; }
        j.saveError = '';   // 前に失敗していたら消す
        // ⚠ 体験用（見本）アカウントは保存されない。これまでは画面だけ「エントリー済み」に
        //   変わってしまい、担当の画面には出ないので「エントリーしたのに出ない」と見えた
        //   （2026-08-21 baba指摘）。保存されていないことをはっきり知らせて元に戻す。
        if (res.saved === false) {
          j.state = before;
          renderJobs();
          if (typeof refreshEntryDay === 'function') refreshEntryDay(j, false);
          alert(res.message || 'このアカウントは体験用のため、エントリーは保存されません（見本）。実際に試すときは、発行されたスタッフのアカウントでログインしてください。');
        }
      })
      .catch(err => entryFailed(j, before, saveErrorMessage(err)));
    }

    /**
     * サーバーの返事を JSON として読む。
     * ⚠ ログインの有効期限が切れていると、保存の宛先ではなく**ログインの画面（HTML）**が返る。
     *   そのまま JSON として読むと「SyntaxError: Unexpected token '<' …」という
     *   意味の分からないお知らせになる（2026-08-28 baba報告）。ここで見分けて言い換える。
     */
    function readJson(r) {
      const type = (r.headers.get('content-type') || '');
      if (type.indexOf('application/json') < 0) {
        return Promise.reject(r.status === 200 ? 'session' : r.status);
      }
      return r.json().then(j => {
        // サーバーが「ログインし直して」と言っている場合（401/409）。
        if (!r.ok && j && j.reauth) return { ok: false, message: j.message };
        if (!r.ok) return Promise.reject(r.status);
        return j;
      });
    }

    /** 失敗の中身を、何をすればよいかが分かる日本語にする。 */
    function saveErrorMessage(err) {
      const code = String(err);
      if (code === 'session' || code === '401' || code === '419' || code === '409') {
        return 'ログインの有効期限が切れています。画面を読み込み直して、ログインし直してから、もう一度押してください。';
      }
      return '保存に失敗しました（' + code + '）。通信を確認して、もう一度押してください。';
    }

    // エントリーが保存できなかったとき。
    // ⚠ これまでは消えるお知らせ（alert）だけだったので、閉じてしまうと
    //   「エントリーできたつもり」になっていた（2026-08-28 baba報告）。
    //   案件のカードに赤い文字で残して、押し直すまで消えないようにする。
    function entryFailed(j, before, message) {
      j.state = before;
      j.saveError = message;
      renderJobs();
      alert(message);
    }

    // タブの「募集中」の数字バッジ（まだエントリーしていない＝募集中の件数）
    function updateOpenCount() {
      const n = jobs.filter(j => j.state === 'open').length;
      const el = document.getElementById('openCount');
      el.textContent = n;
      el.style.display = n === 0 ? 'none' : '';
    }

    // ===== 確定アサインの「公開」連動（公開ボード／assign.html の「スタッフに公開」と同じキー）=====
    // 同じブラウザで公開をON/OFFすると、この画面に反映されます（案件ごとに連動）。
    function pubKey(id){ return id === 'mizu' ? 'ecs_publish_mizu0720' : 'ecs_publish_' + id; }
    function isPublished(id){ try { return localStorage.getItem(pubKey(id)) === '1'; } catch(e){ return false; } }
    // 公開ボードで編集した「スタッフ集合時間」があればそれを優先（無ければ既定の集合時間）
    function staffMeetOf(id, fallback){ try { return localStorage.getItem('ecs_staff_meet_' + id) || fallback; } catch(e){ return fallback; } }

    // 確定アサイン＝DBから渡された「公開ON」の案件（window.ECS_PUBLISHED）を表示する。
    // これまでは同じブラウザの localStorage を見ていたが、担当が公開ボードで保存した
    // 状態を DB（staff_published）から読むので、他PC・他ブラウザでも同じ内容が出る。
    function renderConfirmed(){
      const wrap = document.getElementById('confirmList');
      if (!wrap) return;
      const pub = (window.ECS_PUBLISHED || []).slice().sort((a, b) => a.off - b.off);
      if (!pub.length){
        wrap.innerHTML = '<p class="empty-note" style="margin:6px 0 0;">あなたが確定アサインされた公開案件はまだありません。（担当があなたをアサインして「スタッフに公開」すると、ここにあなたの担当が出ます）</p>';
        return;
      }
      // ⚠ 案件名・会場・持ち物などはDBの自由入力なので、必ず escAttr / escLines を通してから差し込む。
      wrap.innerHTML = pub.map((j, i) => {
        const d = addDays(today, j.off);
        const key = 'ad-' + i;
        const roleText = j.myRole ? escAttr(j.myRole) + (j.myRole2 ? '（兼任：' + escAttr(j.myRole2) + '）' : '') : '';
        return `<div class="assign-item" onclick="toggleAssignDetail('${key}')">
          <div class="assign-date"><div class="d">${(d.getMonth()+1)}/${d.getDate()}</div><div class="dow">${DOW_CIRCLE[d.getDay()]}</div></div>
          <div class="assign-info">
            <div class="t">${escAttr(j.content)} ${escAttr(j.client)} <span style="font-size:11px;color:#15803d;font-weight:700;">★公開されました</span>${roleText ? ' ／ <span style="color:#b45309;font-weight:700;">あなたの担当：'+roleText+'</span>' : ''}</div>
            <div class="meta">集合 ${escAttr(j.meet)}〜${escAttr(j.leave)}　／　${escAttr(j.meetPlace)}　／　${escAttr(j.place)}</div>
            <div class="meta" style="color:#2563eb;font-weight:700;">タップすると持ち物・注意事項が見られます</div>
          </div>
          <div class="assign-arrow">›</div>
        </div>
        <div class="assign-detail" id="${key}" style="display:none;">
          ${assignDetailHtml(j, d)}
        </div>`;
      }).join('');
    }

    // 確定アサインの詳細（開閉）。当日必要な情報をここにまとめて出す。
    function toggleAssignDetail(key){
      const el = document.getElementById(key);
      if (el) el.style.display = (el.style.display === 'none' || !el.style.display) ? '' : 'none';
    }

    // 詳細の中身。空の項目は行ごと出さない（「—」ばかりにならないように）。
    function assignDetailHtml(j, d){
      const rows = [];
      const add = (label, val, multiline) => {
        const v = (val === null || val === undefined) ? '' : String(val).trim();
        if (v === '' || v === '—') return;
        rows.push('<div class="ad-row"><span class="ad-l">' + label + '</span><span class="ad-v">'
          + (multiline ? escLines(v) : escAttr(v)) + '</span></div>');
      };
      const ymd = d.getFullYear() + '年' + (d.getMonth()+1) + '月' + d.getDate() + '日'
        + '（' + '日月火水木金土'[d.getDay()] + '）';
      add('日付', ymd);
      add('あなたの担当', j.myRole ? (j.myRole + (j.myRole2 ? '（兼任：' + j.myRole2 + '）' : '')) : '');
      add('集合〜解散', (j.meet || '—') + ' 〜 ' + (j.leave || '—'));
      add('イベント', j.evTbd
        ? '本番時間未定（決まりしだいお知らせします）'
        : [j.enter && ('入場 ' + j.enter), j.evStart && ('開始 ' + j.evStart), j.evEnd && ('終了 ' + j.evEnd)].filter(Boolean).join('　'));
      add('集合場所', j.meetPlace);
      add('会場', j.place);
      add('屋内 / 屋外', j.outdoor ? '屋外' : '');
      add('宿泊', (j.lodging && j.lodging !== '無') ? j.lodging : '');
      // スタッフに伝えること＝集合場所の詳細・服装・持ち物・注意事項をまとめた1欄
      // （2026-08-21 baba。書く側が備考のように自由に書けるようにしたため）。
      // 空欄でも「特になし」と出す＝聞き忘れに見えないようにする。
      rows.push('<div class="ad-row"><span class="ad-l">スタッフに伝えること</span><span class="ad-v">'
        + (String(j.staffNotes || '').trim() !== '' ? escLines(j.staffNotes) : '特になし') + '</span></div>');
      add('担当からの連絡', j.myNote, true);
      // ⚠ 上の「担当からの連絡」（運営→あなた）とは別物。こちらは**あなたが応募のときに書いた一言**。
      //   確定になると見返せなくなっていたので出すようにした（2026-08-28 baba要望）。
      add('応募のときに書いた一言', j.entryNote, true);
      return '<div class="ad-box">' + rows.join('')
        + '<div class="ad-note">当日の連絡・集合の合図は、これまでどおり LINE・チャットワークで行います。'
        + '内容に変更があると、この画面の表示も自動で新しくなります。</div></div>';
    }
    renderConfirmed();
    // 公開や集合時間を切り替えたら、この画面に戻ったとき／別タブ更新時に反映
    window.addEventListener('focus', renderConfirmed);
    window.addEventListener('storage', renderConfirmed);

    // ===== 稼働希望カレンダー（既存スマホ画面から移植） =====
    const grid = document.getElementById('calGrid');
    // 確定アサイン（公開済み）の日＝「イベント」、エントリー中の日＝「★」。すべて共通データから作る。
    // 対象月（DB保存・読込にも使う）。サーバーが渡す当月（ECS_PREF_META）を正とする。
    // 以前は画面が「2026年7月」固定だったため、月が変わると表示と保存対象月がズレていた。
    const _meta = window.ECS_PREF_META || null;
    const _pp = (window.ECS_PREF_PERIOD || '').split('-');
    const PREF_Y = _meta ? _meta.year  : (parseInt(_pp[0], 10) || today.getFullYear());
    const PREF_M = _meta ? _meta.month : (parseInt(_pp[1], 10) || (today.getMonth() + 1));
    const PREF_DAYS = _meta ? _meta.days : new Date(PREF_Y, PREF_M, 0).getDate();
    const PREF_FIRST_DOW = _meta ? _meta.firstDow : new Date(PREF_Y, PREF_M - 1, 1).getDay();

    const eventDays = {};
    const entryDays = {};
    // 確定（イベント）＝DBの公開済み案件。エントリー中＝募集タブの応募中案件。
    // ⚠ 表示中の月の日だけ拾う（inPrefMonth）。以前は日番号だけで入れていたため、
    //    別の月の確定アサインが同じ日番号のマスに「イベント」として出ていた。
    (window.ECS_PUBLISHED || []).forEach(j => {
      const d = addDays(today, j.off);
      if (inPrefMonth(d)) eventDays[d.getDate()] = j.content + ' ' + j.client;
    });
    jobs.filter(j => j.state === 'applied').forEach(j => {
      const d = ECS_caseDate(j.offset);
      if (inPrefMonth(d) && !eventDays[d.getDate()]) entryDays[d.getDate()] = j.content + ' ' + j.client;
    });
    // 本人がDBに保存済みの希望（date "Y-M-D" => ok/ng/maybe）。無ければ空＝全部「未定」で開く。
    const savedPrefs = window.ECS_MY_PREFS || {};
    // 1つの日セルを「編集可（終日〇→NG→未定を切替）」に描く。保存済み希望を初期状態に。
    function paintEditable(cell, d) {
      cell.className = 'cell';
      let s = 0; // 0=未定,1=稼働可,2=NG
      const pv = savedPrefs[PREF_Y + '-' + PREF_M + '-' + d];
      if (pv === 'ok') s = 1; else if (pv === 'ng') s = 2;
      cell.dataset.state = s;
      cell.innerHTML = '<div>' + d + '</div><div class="st"></div>';
      applyCellState(cell);
      cell.onclick = () => {
        cell.dataset.state = (parseInt(cell.dataset.state) + 1) % 3;
        applyCellState(cell);
      };
    }
    // 確定アサインのある日＝イベント（押して変更できない）。
    function paintEvent(cell, d, title) {
      cell.className = 'cell s-event';
      delete cell.dataset.state; cell.onclick = null;
      cell.title = '確定アサイン：' + title;
      cell.innerHTML = '<div>' + d + '</div><div class="st">イベント</div>';
    }
    // エントリー中の案件がある日＝★（押して変更できない）。
    function paintEntry(cell, d, title) {
      cell.className = 'cell s-entry';
      delete cell.dataset.state; cell.onclick = null;
      cell.title = 'エントリー中：' + title;
      cell.innerHTML = '<div>' + d + ' ★</div><div class="st">エントリー</div>';
    }
    // 1日の前の空きマス（曜日合わせ）を対象月から作る。
    for (let i = 0; i < PREF_FIRST_DOW; i++) {
      const pad = document.createElement('div');
      pad.className = 'cell empty';
      grid.appendChild(pad);
    }
    for (let d = 1; d <= PREF_DAYS; d++) {
      const cell = document.createElement('div');
      if (eventDays[d]) paintEvent(cell, d, eventDays[d]);
      else if (entryDays[d]) paintEntry(cell, d, entryDays[d]);
      else paintEditable(cell, d);
      grid.appendChild(cell);
    }
    // 保存済みのコメントを反映
    const _memoEl = document.getElementById('prefMemo');
    if (_memoEl && window.ECS_MY_PREF_MEMO) _memoEl.value = window.ECS_MY_PREF_MEMO;
    function applyCellState(cell) {
      const s = parseInt(cell.dataset.state);
      cell.classList.remove('s-ok','s-ng');
      const label = cell.querySelector('.st');
      if (s === 1) { cell.classList.add('s-ok'); label.textContent = '終日〇'; }
      else if (s === 2) { cell.classList.add('s-ng'); label.textContent = 'NG'; }
      else { label.textContent = ''; }
    }
    // 日番号から該当セルを探す（先頭の空セルは除く）。
    function dayCellByNum(n) {
      let found = null;
      document.querySelectorAll('#calGrid .cell').forEach(cell => {
        if (cell.classList.contains('empty')) return;
        const dv = cell.querySelector('div');
        if (dv && parseInt(dv.textContent, 10) === n) found = cell;
      });
      return found;
    }
    // エントリーのオン/オフを、リロードを待たずカレンダーへ即反映する（★エントリー中）。
    function inPrefMonth(dt) { return dt.getFullYear() === PREF_Y && (dt.getMonth() + 1) === PREF_M; }
    function refreshEntryDay(j, applied) {
      const dt = ECS_caseDate(j.offset);
      if (!inPrefMonth(dt)) return;                 // 表示中の月の日だけ
      const d = dt.getDate();
      const cell = dayCellByNum(d);
      if (!cell || cell.classList.contains('s-event')) return;   // 確定アサイン日は触らない
      if (applied) {
        entryDays[d] = j.content + ' ' + j.client;
        paintEntry(cell, d, entryDays[d]);
      } else {
        // 同じ日にまだ別のエントリーが残っていれば★は消さない
        const other = jobs.find(x => x !== j && x.state === 'applied'
          && (() => { const od = ECS_caseDate(x.offset); return inPrefMonth(od) && od.getDate() === d; })());
        if (other) { entryDays[d] = other.content + ' ' + other.client; paintEntry(cell, d, entryDays[d]); }
        else { delete entryDays[d]; paintEditable(cell, d); }
      }
    }

    // ※ 以前ここにあった openAssign()（「モックのためダミーです」のアラート）は撤去した。
    //   確定アサインをタップすると、その場で詳細（持ち物・注意事項など）が開くようになったため
    //   ＝ toggleAssignDetail / assignDetailHtml（この上の確定アサインの描画部分）。

    // 「この内容で希望を提出する」→ その月の希望をDB(shift_preferences)へ保存。
    // タップで変えられるセル（dataset.state を持つ日）だけを集める。イベント/エントリー日は対象外。
    function submitPref() {
      const state = {};
      document.querySelectorAll('#calGrid .cell').forEach(cell => {
        if (cell.dataset.state === undefined) return;
        const day = parseInt((cell.querySelector('div') || {}).textContent, 10);
        if (!day) return;
        const s = parseInt(cell.dataset.state, 10);
        state[PREF_Y + '-' + PREF_M + '-' + day] = (s === 1 ? 'ok' : (s === 2 ? 'ng' : 'maybe'));
      });
      const body = new URLSearchParams();
      body.append('period', window.ECS_PREF_PERIOD || '');
      Object.keys(state).forEach(k => body.append('state[' + k + ']', state[k]));
      const memoEl = document.getElementById('prefMemo');
      if (memoEl) body.append('memo', memoEl.value);
      const msg = document.getElementById('savedMsg');
      fetch('/staff-portal/availability', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': window.ECS_CSRF || '', 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      })
      .then(readJson)
      .then(res => {
        if (!msg) return;
        msg.textContent = (res && res.ok) ? '✓ 希望を保存しました' : ('⚠ ' + ((res && res.message) || '保存できませんでした'));
        msg.style.display = 'block';
      })
      .catch(err => { if (msg) { msg.textContent = '⚠ ' + saveErrorMessage(err); msg.style.display = 'block'; } });
    }

    // ===== タブ切り替え =====
    function switchTab(btn) {
      document.querySelectorAll('.s-tabs button').forEach(b => b.classList.remove('active'));
      document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
      btn.classList.add('active');
      document.getElementById(btn.dataset.tab).classList.add('active');
      // URLの末尾にタブ名を残す＝月を切り替えて開き直しても同じタブに戻れる（2026-08-21）。
      try { history.replaceState(null, '', '#' + btn.dataset.tab); } catch (e) {}
    }
    // 開いたとき、URLの末尾（#tab-pref など）で指定されたタブを開く。
    (function openTabFromHash(){
      const h = (location.hash || '').replace('#', '');
      if (!h) return;
      const btn = document.querySelector('.s-tabs button[data-tab="' + h + '"]');
      if (btn) switchTab(btn);
    })();

    // ===== 設定タブ：DB保存の共通処理 =====
    // 入力内容を本物のDB（people）へ保存する。localStorage はやめてサーバに送る。
    function postSettings(url, body, msgId) {
      const msg = document.getElementById(msgId);
      fetch(url, {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': window.ECS_CSRF || '',
          'Accept': 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: body.toString()
      })
      .then(r => r.ok ? r.json() : Promise.reject(r.status))
      .then(res => {
        if (!msg) return;
        msg.textContent = (res && res.ok ? '✓ ' : '⚠ ') + ((res && res.message) || '保存しました');
        msg.style.display = 'block';
        setTimeout(() => { msg.style.display = 'none'; }, 3000);
      })
      .catch(err => {
        if (msg) { msg.textContent = '保存に失敗しました（' + err + '）'; msg.style.display = 'block'; }
      });
    }

    // ===== 設定タブ：プロフィール（自分の情報） =====
    // 入力欄のID ↔ people 列名の対応
    const PF_MAP = {
      pfHeight:'height', pfShoe:'shoe_size', pfWear:'shirt_size', pfPref:'prefecture', pfStation:'nearest_station',
      pfAppeal:'appeal', pfLike:'liked_contents', pfDislike:'disliked_contents',
      pfStrongPosFree:'strong_positions', pfWeakPosFree:'weak_positions',
      pfOtherLang:'other_languages', pfToolOther:'online_tools_other', pfNote:'profile_note'
    };

    // 複数チェックの欄 ↔ people 列名の対応（選択肢そのものは Blade がサーバーから出している）
    const PF_CHECKS = { pfChallengeList:'challenge_positions', pfToolList:'online_tools' };

    // DBに保存済みの本人プロフィールを各欄に入れる
    function loadProfile() {
      const d = window.ECS_MY_PROFILE || {};
      Object.keys(PF_MAP).forEach(id => {
        const el = document.getElementById(id);
        if (el && d[PF_MAP[id]] != null) el.value = d[PF_MAP[id]];
      });
      // チェックボックス（保存済みの値と同じものにチェックを入れる）
      Object.keys(PF_CHECKS).forEach(id => {
        const box = document.getElementById(id);
        if (!box) return;
        const saved = d[PF_CHECKS[id]] || [];
        box.querySelectorAll('input[type="checkbox"]').forEach(cb => {
          cb.checked = saved.indexOf(cb.dataset.val) >= 0;
        });
      });
    }

    // 「この内容で保存する」→ DB（people）へ保存
    function saveProfile() {
      const body = new URLSearchParams();
      Object.keys(PF_MAP).forEach(id => {
        const el = document.getElementById(id);
        if (el) body.append(PF_MAP[id], el.value);
      });
      // チェックが入っているものだけ送る。1つも無いときは「空で送った」と伝えるため印を付ける
      // （何も送らないと「欄ごと無かった」ことになり、前の内容が消せなくなる）。
      Object.keys(PF_CHECKS).forEach(id => {
        const box = document.getElementById(id);
        if (!box) return;
        const name = PF_CHECKS[id];
        body.append(name + '_sent', '1');
        box.querySelectorAll('input[type="checkbox"]').forEach(cb => {
          if (cb.checked) body.append(name + '[]', cb.dataset.val);
        });
      });
      postSettings('/staff-portal/profile', body, 'pfSavedMsg');
    }

    // ===== 設定タブ：できるポジション・スキル =====
    // 選べるポジションの一覧（label=表示名／note=補足／key=保存用のID）
    // ON/OFFで答えるもの（MCと同じトグル）
    const POSITIONS = [
      { key:'mc',      label:'MC（司会）',       note:'IKUSA MCオーディションに合格した' },
      { key:'op',      label:'OP（音響）',        note:'音響・PA機材の操作ができる' },
      { key:'gunshi',  label:'軍師・サポーター',   note:'チームを束ねて現場を回せる' },
      { key:'kigurumi',label:'着ぐるみOK',       note:'着ぐるみの中に入る役ができる' },
      { key:'stay',    label:'前泊・後泊OK',      note:'宿泊を伴う遠方の現場に対応できる' },
    ];
    // レベルを選ぶもの（プルダウン）。options[0]＝未選択（なし）
    // ⚠ options は画面に直書きしない＝正本は App\Support\ProfileOptions（社員のマイプロフィールと共通）。
    //   ここに書き写すと、片方だけ直して食い違う。
    // ⚠⚠ ここは @verbatim の中なので **Blade の書き方（@json など）は使えない**。
    //   書いてもそのままの文字が出て、JavaScript の文法エラーになり
    //   **この画面が丸ごと動かなくなる**（2026-08-31 に本番でやった）。
    //   サーバーの値は上の window.ECS_… で受け取る。
    const _po = window.ECS_PROFILE_OPTIONS || { driving: [], english: [] };
    const POS_SELECTS = [
      { key:'drive',   label:'車（運転）', note:'運転できる車のサイズ',
        options:_po.driving },
      { key:'english', label:'英語力',     note:'英語での対応レベル',
        options:_po.english },
    ];
    // DBに保存済みの「できるポジション・スキル」を読む（無ければ既定OFF）
    function loadPositions() {
      const d = window.ECS_MY_PROFILE || {};
      return {
        mc: !!d.mcPass, op: !!d.op, gunshi: !!d.gunshi, kigurumi: !!d.kigurumi, stay: !!d.stay,
        drive: d.drive || '（なし）', english: d.english || '（なし）'
      };
    }

    // ポジションのスイッチ一覧を描画
    function renderPositions() {
      const sel = loadPositions();
      const list = document.getElementById('posList');
      list.innerHTML = '';
      POSITIONS.forEach(p => {
        const on = !!sel[p.key];
        const item = document.createElement('label');
        item.className = 'pos-item';
        item.innerHTML = `
          <span class="pos-name">${p.label}<span class="note">${p.note}</span></span>
          <span class="switch">
            <input type="checkbox" data-key="${p.key}" ${on ? 'checked' : ''}>
            <span class="track"></span>
          </span>`;
        list.appendChild(item);
      });
      // レベルを選ぶもの（プルダウン）
      POS_SELECTS.forEach(s => {
        const cur = sel[s.key] != null ? sel[s.key] : s.options[0];
        const item = document.createElement('div');
        item.className = 'pos-item';
        const opts = s.options.map(o => `<option ${o === cur ? 'selected' : ''}>${o}</option>`).join('');
        item.innerHTML = `
          <span class="pos-name">${s.label}<span class="note">${s.note}</span></span>
          <select data-key="${s.key}" style="padding:7px 10px;border:1px solid var(--line);border-radius:8px;font-family:inherit;font-size:13px;background:#fff;flex-shrink:0;max-width:62%;">${opts}</select>`;
        list.appendChild(item);
      });
    }

    // 「この内容で保存する」→ DBへ保存（ON項目だけ送る＝未チェックはOFF扱い）
    function savePositions() {
      const body = new URLSearchParams();
      document.querySelectorAll('#posList input[type="checkbox"]').forEach(cb => {
        if (cb.checked) body.append(cb.dataset.key, '1');
      });
      document.querySelectorAll('#posList select[data-key]').forEach(s => {
        body.append(s.dataset.key, s.value);
      });
      postSettings('/staff-portal/skills', body, 'posSavedMsg');
    }
  </script>
  @endverbatim
  <script>
    // ログアウト → 本物のログアウト（POST /logout）。CSRFトークン付きでフォーム送信する。
    function doLogout() {
      if (confirm('ログアウトします。よろしいですか？')) {
        var f = document.createElement('form');
        f.method = 'POST';
        f.action = '/logout';
        var t = document.createElement('input');
        t.type = 'hidden'; t.name = '_token'; t.value = '{{ csrf_token() }}';
        f.appendChild(t);
        document.body.appendChild(f);
        f.submit();
      }
    }

    loadProfile();
    renderPositions();

    // 募集の「この日から」＝既定は今日。ボタンで今日に戻せる。
    function ymd(d){ return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
    function setJobToday(){ document.getElementById('jobFrom').value = ymd(today); renderJobs(); }
    document.getElementById('jobFrom').value = ymd(today);

    // 初期描画
    renderJobs();
  </script>
</body>
</html>
