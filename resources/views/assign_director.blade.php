@extends('layouts.app')
@section('title', 'D決め（ディレクター・カレンダー）')
@section('h1', 'D決め（ディレクター）')
@php($active = 'assign_director')

@push('head')
@verbatim
<style>
    /* ===== D決め（ディレクター・カレンダー）専用スタイル ===== */

    /* 上部の操作バー（月切替） */
    /* ノートPCでも1画面に収めるため、この画面だけ本文の余白を詰める */
    .content { padding: 12px 16px; }
    /* 使い方バナー＝折りたたみ（初期は閉じて縦を節約） */
    .help-note {
      background: var(--warn-soft); border: 1px solid #f6d9a7; color: #8a5a00;
      border-radius: 8px; padding: 6px 12px; margin-bottom: 10px; font-size: 12px; line-height: 1.6;
    }
    .help-note > summary { cursor: pointer; font-weight: 700; list-style: none; }
    .help-note > summary::-webkit-details-marker { display: none; }
    .help-note .help-body { margin-top: 6px; }

    .dir-controls {
      display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
      background: var(--panel); border: 1px solid var(--line); border-radius: 12px;
      padding: 8px 12px; margin-bottom: 10px;
    }
    .month-nav { display: flex; align-items: center; gap: 10px; }
    .month-nav button {
      border: 1px solid var(--line); background: #fff; color: var(--ink);
      border-radius: 8px; width: 32px; height: 32px; font-size: 16px; cursor: pointer; font-family: inherit;
    }
    .month-nav .mon { font-size: 16px; font-weight: 700; min-width: 110px; text-align: center; }
    .dir-controls .spacer { flex: 1; }
    .dir-controls label.chk { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--ink); cursor: pointer; }
    .btn-save-dir {
      border: 1px solid var(--brand); background: var(--brand); color: #fff;
      border-radius: 8px; padding: 8px 16px; font-size: 13.5px; font-weight: 700;
      cursor: pointer; font-family: inherit;
    }
    .btn-save-dir:hover { filter: brightness(1.05); }

    /* 全体：カレンダー（左・広め）＋ 担当バランス集計（右・固定幅） */
    .dir-layout { display: grid; grid-template-columns: 1fr 300px; gap: 16px; align-items: start; }
    @media (max-width: 1080px) { .dir-layout { grid-template-columns: 1fr; } }

    /* ===== カレンダー本体 ===== */
    .cal { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 6px; }
    .cal-dow { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; margin-bottom: 4px; }
    .cal-dow > div { text-align: center; font-size: 12px; font-weight: 700; color: var(--muted); padding: 2px 0; }
    .cal-dow > div.sun { color: var(--danger); }
    .cal-dow > div.sat { color: var(--brand); }
    .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }

    .cal-cell {
      background: #fff; border: 1px solid var(--line); border-radius: 10px;
      min-height: 76px; padding: 4px; display: flex; flex-direction: column; gap: 4px;
    }
    .cal-cell.other { background: #f6f1e8; }                 /* 当月外の日は薄く */
    .cal-cell.today { border-color: var(--brand); box-shadow: 0 0 0 2px var(--brand-soft) inset; }
    .cal-cell .c-date { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; font-variant-numeric: tabular-nums; color: var(--ink); }
    .cal-cell.other .c-date { color: var(--muted); }
    .cal-cell .c-date .sun { color: var(--danger); }
    .cal-cell .c-date .sat { color: var(--brand); }
    /* その日の希望休（ディレクター） */
    .c-dayoff { font-size: 9.5px; font-weight: 700; color: #b4530a; background: #fdecd9; border-radius: 5px; padding: 0 5px; white-space: nowrap; }

    /* 1日の中の案件カード（小） */
    .dcase {
      border: 1px solid var(--line); border-radius: 8px; padding: 4px 5px;
      background: #fff; display: flex; flex-direction: column; gap: 3px; position: relative;
    }
    .dcase.undecided { border-left: 4px solid var(--warn); background: #fff8ee; }  /* D未定＝決めるべき */
    .dcase.decided   { border-left: 4px solid #16a34a; }                           /* D決定済み */
    /* 大型案件＝金の太枠＋うすい背景で目立たせる */
    .dcase.big { border: 2px solid #e0a800; background: #fffdf2; box-shadow: 0 1px 4px rgba(224,168,0,.18); }
    .dcase.big.undecided { border-left: 5px solid var(--warn); }
    .dcase.big.decided   { border-left: 5px solid #16a34a; }

    .dc-name { font-size: 12px; font-weight: 700; line-height: 1.3; }
    .dcase.big .dc-name { font-size: 13px; }
    .dc-star { color: #e0a800; }
    .dc-content { font-size: 10.5px; font-weight: 700; color: var(--brand-dark); }
    .dc-badges { display: flex; gap: 3px; flex-wrap: wrap; }
    .mini-badge { font-size: 9.5px; font-weight: 700; padding: 1px 5px; border-radius: 5px; white-space: nowrap; }
    .mini-badge.big   { background: #fde68a; color: #92600a; }              /* 大型 */
    .mini-badge.real  { background: #e3edf7; color: #2c6ca0; }
    .mini-badge.long  { background: #fdecd9; color: #b4530a; }
    .mini-badge.online{ background: #efe6f6; color: #6d28d9; }
    .mini-badge.daytype { background: #ece3d4; color: #7a6a58; }            /* 予備日/リハ/前日設営 */
    .mini-badge.repeat { background: var(--ok-soft); color: #15803d; }      /* リピート案件 */

    /* 案件の数値情報（参加者・チーム・時間など） */
    .dc-info { font-size: 10px; color: var(--ink); display: flex; flex-wrap: wrap; gap: 2px 8px; }
    .dc-info .need { color: var(--muted); }

    /* メンバー充足のポジションランプ（FC・OPなどのアサイン状況） */
    .dc-pos { display: flex; flex-wrap: wrap; gap: 3px; }
    .plamp { font-size: 9.5px; font-weight: 700; padding: 0 5px; border-radius: 5px; line-height: 1.7; }
    .plamp.ok    { background: var(--ok-soft);     color: #15803d; }
    .plamp.short { background: var(--danger-soft); color: #b91c1c; }
    .plamp.none  { background: #ece3d4;            color: #7a6a58; }

    /* D・SD のプルダウン（Dは大きく・色つき／SDは控えめ） */
    .dc-pick { display: flex; flex-direction: column; gap: 3px; margin-top: 1px; }
    .dc-pick .pk { display: flex; align-items: center; gap: 4px; }
    .dc-pick .pk .lbl { font-weight: 700; text-align: center; border-radius: 5px; }
    /* D行＝主役（大きめ・ブランド色） */
    .dc-pick .pk.d-row .lbl { font-size: 11px; width: 26px; color: #fff; background: var(--brand); padding: 2px 0; }
    .dc-pick .pk.d-row select { flex: 1; padding: 4px 6px; border: 1.5px solid var(--brand); border-radius: 6px; font-size: 12px; font-weight: 700; color: var(--brand-dark); font-family: inherit; background: #fff; min-width: 0; }
    .dc-pick .pk.d-row select.undef { border-color: var(--warn); background: #fffbf3; color: #b45309; }
    /* SD行＝控えめ（小さめ・グレー） */
    .dc-pick .pk.sd-row .lbl { font-size: 9.5px; width: 26px; color: var(--muted); background: #ece3d4; padding: 1px 0; }
    .dc-pick .pk.sd-row select { flex: 1; padding: 2px 5px; border: 1px solid var(--line); border-radius: 6px; font-size: 10.5px; color: var(--muted); font-family: inherit; background: #fbf8f2; min-width: 0; }

    .dc-offwarn { font-size: 9.5px; font-weight: 700; color: #b91c1c; background: var(--danger-soft); border-radius: 5px; padding: 1px 5px; }

    /* ===== 確定案件＝休みタグのように名前だけのチップ。クリックで詳細が開く ===== */
    .dcase.locked-chip {
      display: inline-flex; align-items: center; gap: 4px; width: fit-content; max-width: 100%;
      background: var(--ok-soft); color: #15803d; border: 1px solid #cdeccf; border-radius: 999px;
      padding: 2px 9px; cursor: pointer; position: relative;
    }
    .dcase.locked-chip.big { background: #fbf3d6; color: #92600a; border: 1px solid #ecd9a0; box-shadow: none; }
    .dcase.locked-chip .chip-name { font-size: 11px; font-weight: 700; line-height: 1.3; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .dcase.locked-chip .chip-arrow { font-size: 9px; transition: transform .12s; }
    .dcase.locked-chip.open .chip-arrow { transform: rotate(90deg); }
    /* クリックで開く詳細ふきだし */
    .dc-tip {
      display: none; position: absolute; top: 100%; left: 0; z-index: 60;
      width: 230px; margin-top: 4px; background: #fff; border: 1px solid var(--line);
      border-radius: 10px; box-shadow: 0 8px 24px rgba(60,40,20,.18); padding: 10px 12px;
      font-size: 11.5px; line-height: 1.6; color: var(--ink); cursor: default; text-align: left;
    }
    .dcase.locked-chip.open .dc-tip { display: block; }
    .dc-tip .t-name { font-weight: 700; font-size: 12.5px; margin-bottom: 4px; }
    .dc-tip .t-row { display: flex; gap: 6px; }
    .dc-tip .t-row .k { color: var(--muted); width: 56px; flex-shrink: 0; }
    .dc-tip .t-row .v { font-weight: 600; }
    .dc-tip .t-d  { color: var(--brand-dark); font-weight: 700; }
    .dc-tip .t-sd { color: var(--muted); }
    .dc-tip .t-pos { margin-top: 5px; }

    .cell-empty { font-size: 10px; color: #cbbfae; text-align: center; padding-top: 6px; }

    /* ===== 担当バランス集計（右パネル・ライブ） ===== */
    .agg-panel { position: sticky; top: 14px; background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 12px 14px; }
    .agg-panel h3 { margin: 0 0 4px; font-size: 14px; }
    .agg-panel .live { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: var(--ok-soft); color: #15803d; margin-bottom: 8px; }
    .agg-panel .live .dot { width: 8px; height: 8px; border-radius: 999px; background: currentColor; }
    table.agg-tbl { width: 100%; border-collapse: collapse; font-size: 12px; }
    table.agg-tbl th, table.agg-tbl td { padding: 5px 4px; border-bottom: 1px solid var(--line); }
    table.agg-tbl th { font-size: 10.5px; color: var(--muted); font-weight: 700; text-align: right; white-space: nowrap; }
    table.agg-tbl th.nm, table.agg-tbl td.nm { text-align: left; font-weight: 600; }
    table.agg-tbl td.num { text-align: right; font-variant-numeric: tabular-nums; }
    table.agg-tbl td.dcnt { font-weight: 700; }
    table.agg-tbl tr.most td.dcnt { color: #b91c1c; }   /* 一番多い人＝偏りに注意 */
    .agg-panel .agg-note { font-size: 11px; color: var(--muted); line-height: 1.6; margin-top: 8px; }
    .agg-bar { height: 5px; background: #ece3d4; border-radius: 999px; overflow: hidden; margin-top: 2px; }
    .agg-bar > i { display: block; height: 100%; background: var(--brand); }

    /* ===== 社員主役レイアウト（件数バッジ＋社員チップ＋担当ピッカー）===== */
    .cal-cell .c-date { position: relative; justify-content: space-between; }
    /* 日付の横の件数バッジ（カーソルでその日の案件一覧） */
    .c-count { font-size: 10px; font-weight: 700; color: #fff; background: var(--brand); border-radius: 999px; padding: 0 7px; cursor: default; }
    .c-count.has-undecided { background: var(--warn); }   /* D未定の案件がある日＝橙で注意 */
    /* 件数ふきだし */
    .day-tip {
      /* 件数バッジとの間に余白を入れない＝ふきだしの中の案件名リンクまでカーソルを動かせるようにする
         （4pxでも空くと途中で hover が切れて、ふきだしが消えてしまう・2026-08-21）。 */
      display: none; position: absolute; top: 100%; right: 0; z-index: 70; width: 244px; margin-top: 0;
      background: #fff; border: 1px solid var(--line); border-radius: 10px; box-shadow: 0 8px 24px rgba(60,40,20,.18);
      padding: 8px 10px; font-size: 11px; line-height: 1.5; color: var(--ink); text-align: left;
    }
    .c-count:hover + .day-tip, .day-tip:hover { display: block; }
    .day-tip .dt-row { padding: 4px 0; border-bottom: 1px dashed var(--line); }
    .day-tip .dt-row:last-child { border-bottom: none; }
    .day-tip .dt-name { font-weight: 700; }
    .day-tip .dt-meta { color: var(--muted); font-size: 10px; }
    .day-tip .dt-dir { color: var(--brand-dark); font-weight: 700; font-size: 10px; }

    /* 社員チップ */
    .emp-list { display: flex; flex-direction: column; gap: 3px; }
    .emp-chip {
      display: flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700;
      padding: 2px 7px; border-radius: 7px; border: 1px solid var(--line); background: #fff; cursor: pointer;
    }
    .emp-chip:hover { background: var(--brand-soft); }
    .emp-chip.assigned { color: #15803d; border-color: #cdeccf; background: var(--ok-soft); }  /* D/SD担当＝緑 */
    .emp-chip.busy { color: #2c6ca0; border-color: #cfe0f0; background: #eef4fb; }             /* 他ロール(FC等)でアサイン済＝青 */
    .emp-chip.free { color: #9c8f80; }                                                         /* 未アサイン＝グレー */
    .emp-chip .e-dot { width: 7px; height: 7px; border-radius: 999px; background: currentColor; flex-shrink: 0; }
    .emp-chip .e-nm { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .emp-chip .e-star { font-size: 10px; flex-shrink: 0; }                                      /* 大型のD/SD＝⭐だけ */
    .emp-chip .e-role { font-size: 9px; font-weight: 700; padding: 0 4px; border-radius: 5px; background: #e3edf7; color: #2c6ca0; flex-shrink: 0; }
    .emp-chip .e-multi { font-size: 9px; font-weight: 700; padding: 0 4px; border-radius: 5px; background: #ece3d4; color: #7a6a58; flex-shrink: 0; }
    .emp-chip .e-newbie { font-size: 9px; font-weight: 700; padding: 0 4px; border-radius: 5px; background: #efe6f6; color: #6d28d9; flex-shrink: 0; }
    /* 名前の文字色＝部署（背景＝担当状況、文字色＝部署、で役割を分ける） */
    .emp-chip.dep-plan     .e-nm { color: #c2410c; }   /* イベプラ＝オレンジ */
    .emp-chip.dep-sales    .e-nm { color: #4338ca; }   /* セールス＝藍 */
    .emp-chip.dep-creative .e-nm { color: #16a34a; }   /* クリエイティブ＝緑 */
    .emp-chip.dep-other    .e-nm { color: #6e5b49; }   /* その他＝茶（3つ以外をまとめた色） */
    .emp-chip.dep-none     .e-nm { color: #a3968a; }   /* 所属が未設定 */

    /* 凡例バー（色とマークの意味を常時表示） */
    .dir-legend {
      display: flex; flex-wrap: wrap; gap: 5px 14px; align-items: center;
      background: var(--panel); border: 1px solid var(--line); border-radius: 10px;
      padding: 7px 12px; margin-bottom: 10px; font-size: 11px; color: var(--ink);
    }
    .dir-legend b { color: var(--muted); font-size: 10.5px; }
    .dir-legend > span { display: inline-flex; align-items: center; gap: 4px; }
    .dir-legend .lg-dot { width: 9px; height: 9px; border-radius: 999px; display: inline-block; }
    .dir-legend .lg-dot.green { background: #15803d; }
    .dir-legend .lg-dot.blue  { background: #2c6ca0; }
    .dir-legend .lg-dot.gray  { background: #9c8f80; }
    .dir-legend .lg-tag { font-size: 9px; font-weight: 700; padding: 0 4px; border-radius: 5px; }
    .dir-legend .lg-tag.newb  { background: #efe6f6; color: #6d28d9; }
    .dir-legend .lg-tag.multi { background: #ece3d4; color: #7a6a58; }
    .dir-legend .lg-tag.role  { background: #e3edf7; color: #2c6ca0; }
    .dir-legend .lg-cnt { font-size: 10px; font-weight: 700; color: #fff; background: var(--brand); border-radius: 999px; padding: 0 6px; }

    /* 担当ピッカー（社員名クリックで開く小窓） */
    .dpick-pop {
      position: fixed; z-index: 200; width: 260px; background: #fff; border: 1px solid var(--line);
      border-radius: 12px; box-shadow: 0 12px 34px rgba(60,40,20,.24); padding: 11px 12px; font-size: 12px;
    }
    .dpick-pop h4 { margin: 0 0 8px; font-size: 13px; }
    .dpick-pop .dp-case { display: flex; align-items: center; gap: 7px; padding: 6px 0; border-bottom: 1px solid var(--line); }
    .dpick-pop .dp-case:last-of-type { border-bottom: none; }
    .dpick-pop .dp-info { flex: 1; min-width: 0; }
    .dpick-pop .dp-nm { font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .dpick-pop .dp-ct { font-size: 10px; color: var(--brand-dark); }
    .dpick-pop .dp-btns { display: flex; gap: 5px; flex-shrink: 0; }
    .dpick-pop .dp-btn { border: 1px solid var(--line); background: #fbf8f2; color: var(--ink); border-radius: 7px; padding: 4px 9px; font-size: 11px; font-weight: 700; cursor: pointer; font-family: inherit; }
    .dpick-pop .dp-btn.d.on { background: var(--brand); color: #fff; border-color: var(--brand); }
    .dpick-pop .dp-btn.sd.on { background: #7a6a58; color: #fff; border-color: #7a6a58; }
    .dpick-pop .dp-btn.fc.on { background: #2c6ca0; color: #fff; border-color: #2c6ca0; }
    .dpick-pop .dp-btn .who { font-size: 8.5px; font-weight: 600; opacity: .85; }
    .dpick-pop .dp-empty { color: var(--muted); padding: 6px 0; }
    .dpick-pop .dp-warn { font-size: 10.5px; color: #b91c1c; font-weight: 700; margin-top: 7px; }
    .dpick-pop .dp-close { margin-top: 9px; text-align: right; }
    .dpick-pop .dp-close button { border: none; background: none; color: var(--brand); cursor: pointer; font-size: 12px; font-family: inherit; }
</style>
@endverbatim
@endpush

@section('content')
      @if (session('status'))
        <div class="mock-note" style="background:#e7f6e9; border-color:#bfe4c4; color:#15803d;">{{ session('status') }}</div>
      @endif

      {{-- 拠点の切替（管理者以上だけ表示。一般社員は自拠点固定＝スイッチは出ない） --}}
      @include('partials.office_switch')
      @if ($officeScope)
        <p class="mock-note" style="background:#fbf6ef; border-color:#e6d8c8; color:#7a6a58;">
          <b>{{ $officeScope }}</b>の案件と社員だけを表示しています（{{ $officeScope }}に共有された他拠点の案件も含みます）。
        </p>
      @endif
@verbatim
      <details class="help-note">
        <summary>📖 この画面の使い方（クリックで開く）</summary>
        <div class="help-body">
          <b>各日に「イベプラ社員＋新人」を並べ、名前をクリックして担当（D／SD）を決める画面です。</b>
          日付の横の<b>●件数</b>にカーソルを当てると、その日の案件一覧が出ます。
          社員名をクリック → その日の案件を選び → <b>D</b>／<b>SD</b>／<b>FC</b>を押すと割当（もう一度押すと外せます）。同じ人を同日に複数案件へ兼任もできます。<br>
          <span style="color:#15803d; font-weight:700;">緑＝D/SD担当</span>／<span style="color:#2c6ca0; font-weight:700;">青＝FC等で稼働</span>／<span style="color:#9c8f80; font-weight:700;">グレー＝未アサイン</span>／<span style="color:#92600a; font-weight:700;">⭐＝大型のD/SD</span>／<span style="color:#7a6a58; font-weight:700;">掛N＝同日N件の掛け持ち</span>／<span style="color:#6d28d9; font-weight:700;">新＝新人</span>。
          名前の<b>文字色は部署</b>（<span style="color:#c2410c;font-weight:700;">オレンジ＝イベプラ</span>・<span style="color:#4338ca;font-weight:700;">藍＝セールス</span>・<span style="color:#16a34a;font-weight:700;">緑＝クリエイティブ</span>・<span style="color:#6e5b49;font-weight:700;">茶＝その他</span>）。
          右上の<b>「＋全社員を表示」</b>でセールス等も選べます。最後に<b>「D／SDを保存」</b>で確定（保存先＝アサイン台帳）。
        </div>
      </details>

      <!-- 操作バー -->
      <div class="dir-controls">
        <div class="month-nav">
          <button type="button" onclick="shiftMonth(-1)" title="前の月へ">◀</button>
          <span class="mon" id="monLabel">2026年 7月</span>
          <button type="button" onclick="shiftMonth(1)" title="次の月へ">▶</button>
        </div>
        <div class="spacer"></div>
        <label class="chk"><input type="checkbox" id="showAllEmp" onchange="render()"> ＋全社員を表示（セールス・クリエイティブも）</label>
        <button type="button" id="saveDirBtn" class="btn-save-dir">D／SDを保存</button>
      </div>

      <!-- 凡例（色とマークの意味） -->
      <div class="dir-legend">
        <b>凡例</b>
        <span><span class="lg-dot green"></span>緑＝D/SD担当</span>
        <span><span class="lg-dot blue"></span>青＝他ロール(FC等)でアサイン済</span>
        <span><span class="lg-dot gray"></span>灰＝空き</span>
        <span>⭐＝大型のD/SD</span>
        <span><span class="lg-tag role">FC</span>＝その日の別ロール</span>
        <span><span class="lg-tag newb">新</span>＝新人</span>
        <span><span class="lg-tag multi">掛N</span>＝同日N件の掛け持ち</span>
        <span><span class="lg-cnt">N件（数）</span>＝その日の案件数（カッコ内＝<span style="color:var(--warn);font-weight:700;">D未定の数</span>）</span>
        <b>文字色＝部署</b>
        <span style="color:#c2410c;font-weight:700;">イベプラ</span>
        <span style="color:#4338ca;font-weight:700;">セールス</span>
        <span style="color:#16a34a;font-weight:700;">クリエイティブ</span>
      </div>
@endverbatim
      <!-- 保存フォーム（JSが dir[案件ID]/sd[案件ID] の hidden を作って送る） -->
      <form id="dirSaveForm" method="POST" action="/assign-director/save" style="display:none;">
        @csrf
        {{--
          状態（仮／確定）はこの画面では送らない。
          以前は「仮」を固定で送っていたため、確定済みのD・SD・FCがこの画面で保存し直すたびに
          「仮」へ落ちていた（＝確定が壊れる経路）。送らなければ、既にある行は今の状態を保ち、
          新しく決めた担当だけ「仮」から始まる（サーバー側 AssignDirectorController::save）。
        --}}
        <div id="dirSaveInputs"></div>
      </form>
@verbatim

      <div class="dir-layout">
        <!-- カレンダー -->
        <div class="cal">
          <div class="cal-dow">
            <div>月</div><div>火</div><div>水</div><div>木</div><div>金</div><div class="sat">土</div><div class="sun">日</div>
          </div>
          <div class="cal-grid" id="calGrid"></div>
        </div>

        <!-- 担当バランス集計（ライブ） -->
        <div class="agg-panel">
          <h3>📊 担当バランス</h3>
          <span class="live"><span class="dot"></span>この画面と連動中</span>
          <table class="agg-tbl">
            <thead>
              <tr>
                <th class="nm">社員</th>
                <th>D計</th>
                <th>FC</th>
                <th>大型D</th>
                <th>大型SD</th>
              </tr>
            </thead>
            <tbody id="aggBody"></tbody>
          </table>
          <p class="agg-note">
            この月の担当数です。<b>D計</b>が多い人ほど色が濃く、一番多い人は<span style="color:#b91c1c;font-weight:700;">赤字</span>＝偏りに注意。<b>FC</b>＝この月にFCでアサインされた数。<br>
            ※「リアルD」「オンラインD」も本番では集計します（別ウィンドウの「社員・ディレクター集計」と同じ数え方です）。
          </p>
        </div>
      </div>
@endverbatim
@endsection

@push('scripts')
<script src="/ecs/data/cases.js"></script>
<!-- D決めは DB（projects＋people＋assignments）から渡す。DBが空なら下の見本(cases.js)にフォールバック。 -->
<script>
  window.ECS_DIR_CASES   = @json($cases);        // 本物の案件（D/SDは assignments から・ID基準）
  window.ECS_EMPLOYEES   = @json($employees);    // 社員一覧（id・氏名・姓・部署・新人/イベプラ判定）
  window.ECS_EMP_BUSY    = @json($empBusy);      // 他ロール(FC等)のアサイン状況: {Y-m-d:{社員ID:[role]}}
  window.ECS_DIR_USINGDB = @json($usingDb);      // true＝DBの実データを使う（拠点で絞って0件でも見本に戻さない）
</script>
@verbatim
<script>
  // ===== 社員一覧（DB優先：id・氏名・姓・部署・新人/イベプラ判定。無ければ見本）=====
  // すべて社員ID基準で扱う（同姓の取り違え防止）。
  // DBの実データを使うかどうか。旗（ECS_DIR_USINGDB）が来ていればそれに従う。
  // ※ 拠点で絞った結果が0人/0件のときに見本データへ戻ってしまうのを防ぐため（見本の名前が出ると誤解を招く）。
  const USING_DB = (window.ECS_DIR_USINGDB !== undefined)
    ? !!window.ECS_DIR_USINGDB
    : !!(window.ECS_EMPLOYEES && window.ECS_EMPLOYEES.length);
  const EMP = USING_DB ? window.ECS_EMPLOYEES : [
    { id:'田中', name:'田中 健一', surname:'田中', department:'イベプラ',     planner:true,  newbie:false },
    { id:'佐藤', name:'佐藤 大輔', surname:'佐藤', department:'イベプラ',     planner:true,  newbie:false },
    { id:'中村', name:'中村 蓮',   surname:'中村', department:'イベプラ',     planner:true,  newbie:true  },
    { id:'山本', name:'山本 萌',   surname:'山本', department:'セールス',     planner:false, newbie:true  },
    { id:'鈴木', name:'鈴木 彩花', surname:'鈴木', department:'セールス',     planner:false, newbie:false },
    { id:'高橋', name:'高橋 直樹', surname:'高橋', department:'クリエイティブ', planner:false, newbie:false },
  ];
  const empById = {};
  EMP.forEach(e => empById[e.id] = e);
  function empName(id){ return empById[id] ? empById[id].surname : (id || ''); }

  // 他ロール(FC等)のアサイン状況 {Y-m-d:{社員ID:[role]}}。DBのassignments由来（D/SDは除外済み）。
  const EMP_BUSY = window.ECS_EMP_BUSY || {};

  // ===== 作業用の案件リスト（DB優先・無ければ見本 cases.js）。dirId/sdId をこの場で書き換える =====
  // 見本(cases.js)は姓文字列でD/SDを持つので、見本IDも姓に揃える（empById のキーも姓）。
  const SRC = USING_DB
    ? window.ECS_DIR_CASES
    : ECS_CASES.filter(c => !c.archived && !c.draft).map(c => ({
        ...c,
        dirId: (c.dir && c.dir !== '未定') ? c.dir : null,
        sdId:  (c.sd  && c.sd  !== 'なし') ? c.sd  : null,
      }));
  const cases = SRC.map(c => ({
      id:c.id, off:c.off, name:c.name, client:c.client, content:c.content,
      scale:c.scale, format:c.format||'', fmt:c.fmt,
      dirId:c.dirId || null, sdId:c.sdId || null,
      fcIds: Array.isArray(c.fcIds) ? c.fcIds.slice() : [],   // FC（複数可・この画面で増減）
      dayType:c.dayType, status:c.status,
      guests:c.guests, teams:c.teams, repeat:c.repeat,
      evStart:c.evStart, evEnd:c.evEnd, meet:c.meet, leave:c.leave,
      date:c.date || null,
    }));

  // ===== 日付ユーティリティ =====
  function addDays(n){ const x = new Date(); x.setHours(0,0,0,0); x.setDate(x.getDate()+n); return x; }
  function ymd(d){ return d.getFullYear() + '-' + d.getMonth() + '-' + d.getDate(); }
  // 実日付キー（DBの 'Y-m-d' と突き合わせる用。月は1始まり・ゼロ埋め）
  function pad2(n){ return (n < 10 ? '0' : '') + n; }
  function realYmd(d){ return d.getFullYear() + '-' + pad2(d.getMonth()+1) + '-' + pad2(d.getDate()); }

  // ===== 対象の月を決める（案件が一番多い月＝メインの月を自動で選ぶ）=====
  function pickTargetMonth(){
    const cnt = {};
    cases.forEach(c => { const d = addDays(c.off); const k = d.getFullYear()+'-'+d.getMonth(); cnt[k] = (cnt[k]||0)+1; });
    let best = null, bestN = -1;
    Object.keys(cnt).forEach(k => { if (cnt[k] > bestN) { bestN = cnt[k]; best = k; } });
    if (!best) { const t = addDays(0); return { y:t.getFullYear(), m:t.getMonth() }; }
    const [y, m] = best.split('-').map(Number);
    return { y, m };
  }
  // 最初に出す月＝案件が一番多い月。◀▶ で前後の月に移せる（2026-08-21 baba要望。
  // これまでは「モックのため切替はしません」と出るだけで、他の月のD決めができなかった）。
  let TARGET = pickTargetMonth();
  function monthLabel(){
    const n = cases.filter(inTarget).length;
    document.getElementById('monLabel').textContent =
      TARGET.y + '年 ' + (TARGET.m + 1) + '月' + (n ? '（' + n + '件）' : '（案件なし）');
  }
  // 前後の月へ。案件は全期間ぶん読み込んであるので、通信せずその場で切り替わる。
  function shiftMonth(n){
    const d = new Date(TARGET.y, TARGET.m + n, 1);
    TARGET = { y: d.getFullYear(), m: d.getMonth() };
    monthLabel();
    render();       // カレンダー（中で renderAgg も呼ばれる）
    renderPick();   // 下の「案件を選ぶ」パネル
  }

  // この月の案件だけ対象にしているか
  function inTarget(c){ const d = addDays(c.off); return d.getFullYear() === TARGET.y && d.getMonth() === TARGET.m; }
  // 日付キー(y-m-d) → その日の案件
  function dayCases(key){ return cases.filter(c => inTarget(c) && ymd(addDays(c.off)) === key); }

  // 時間（本番の開始〜終了。無ければ集合〜解散）
  function timeOf(c){
    if (c.evStart && c.evStart !== '—' && c.evEnd && c.evEnd !== '—') return c.evStart + '–' + c.evEnd;
    if (c.meet && c.meet !== '—') return c.meet + '–' + (c.leave && c.leave !== '—' ? c.leave : '');
    return '';
  }

  // ===== その社員のその日の担当状況（色・バッジ用）=====
  function empDayInfo(empId, dcs){
    let count = 0, big = false;
    dcs.forEach(c => {
      if (c.dirId === empId) { count++; if (c.scale === '大型') big = true; }
      if (c.sdId  === empId) { count++; if (c.scale === '大型') big = true; }
    });
    return { count, big };
  }

  // その日にマスへ並べる社員（既定＝イベプラ＋新人＋その日に担当済み/FC等で稼働中の人／「＋全社員」で全員）
  function shownEmployees(dcs, busyMap, showAll){
    const inUse = new Set();
    dcs.forEach(c => {
      if (c.dirId) inUse.add(c.dirId);
      if (c.sdId) inUse.add(c.sdId);
      (c.fcIds || []).forEach(id => inUse.add(id));
    });
    return EMP.filter(e => showAll || e.planner || e.newbie || inUse.has(e.id) || busyMap[e.id]);
  }

  // ===== 件数ふきだし（その日の案件一覧）=====
  function dayTip(dcs){
    const rows = dcs.map(c => {
      const t = timeOf(c);
      const dTxt = c.dirId ? ('D: ' + empName(c.dirId)) : '<span style="color:#b45309">D未定</span>';
      const sTxt = c.sdId ? ('｜SD: ' + empName(c.sdId)) : '';
      const meta = ['🎯' + c.content, c.client, t].filter(Boolean).join(' / ');
      // 案件名を押したら案件の詳細（編集画面）へ。見本データのときは飛べる先が無いのでそのまま（2026-08-21 baba）。
      const nameHtml = USING_DB
        ? `<a href="/project-form?project=${encodeURIComponent(c.id)}" title="案件の詳細・編集を開く" style="color:inherit;">${c.name}</a>`
        : c.name;
      return `<div class="dt-row"><div class="dt-name">${c.scale==='大型'?'⭐':''}${nameHtml}</div>`
           + `<div class="dt-meta">${meta}</div><div class="dt-dir">${dTxt}${sTxt}</div></div>`;
    }).join('');
    return `<div class="day-tip">${rows}</div>`;
  }

  // ===== カレンダー描画 =====
  const grid = document.getElementById('calGrid');
  function render(){
    const showAll = document.getElementById('showAllEmp').checked;

    const first = new Date(TARGET.y, TARGET.m, 1);
    const startDow = (first.getDay() + 6) % 7;                 // 月曜=0
    const gridStart = new Date(TARGET.y, TARGET.m, 1 - startDow);
    const last = new Date(TARGET.y, TARGET.m + 1, 0);
    const weeks = Math.ceil((startDow + last.getDate()) / 7);
    const todayKey = ymd(addDays(0));

    grid.innerHTML = '';
    for (let i = 0; i < weeks * 7; i++){
      const d = new Date(gridStart.getFullYear(), gridStart.getMonth(), gridStart.getDate() + i);
      const inMonth = d.getMonth() === TARGET.m;
      const dy = d.getDay();
      const dowC = dy === 0 ? 'sun' : (dy === 6 ? 'sat' : '');
      const key = ymd(d);

      const cell = document.createElement('div');
      cell.className = 'cal-cell' + (inMonth ? '' : ' other') + (key === todayKey ? ' today' : '');

      const dcs = inMonth ? dayCases(key) : [];
      const cnt = dcs.length;
      const undec = dcs.filter(c => !c.dirId).length;   // この日の「D未定」の数
      const countHtml = cnt
        ? `<span class="c-count ${undec ? 'has-undecided' : ''}">${cnt}件${undec ? '（' + undec + '）' : ''}</span>${dayTip(dcs)}`
        : '';
      let inner = `<div class="c-date"><span class="${dowC}">${d.getDate()}</span>${countHtml}</div>`;

      if (inMonth && cnt) {
        const busyMap = EMP_BUSY[realYmd(d)] || {};   // その日にFC等でアサイン済みの社員→[role]
        const emps = shownEmployees(dcs, busyMap, showAll);
        inner += '<div class="emp-list">' + emps.map(e => {
          const info = empDayInfo(e.id, dcs);
          const isFc = dcs.some(c => (c.fcIds || []).includes(e.id));   // この日FC担当（ライブ）
          const otherRoles = busyMap[e.id] ? [...new Set(busyMap[e.id])] : [];
          // 色：D/SD担当=緑 ＞ FC・他ロール=青 ＞ 空き=灰
          const cls = info.count > 0 ? 'assigned' : ((isFc || otherRoles.length) ? 'busy' : 'free');
          const star = info.big ? '<span class="e-star">⭐</span>' : '';       // 大型のD/SDは⭐だけ
          const roleTags = (isFc ? '<span class="e-role">FC</span>' : '')
                         + otherRoles.map(r => `<span class="e-role">${r}</span>`).join('');
          const multi = info.count >= 2 ? `<span class="e-multi">掛${info.count}</span>` : '';
          const newb = e.newbie ? '<span class="e-newbie">新</span>' : '';
          // 名前の文字色＝部署（イベプラ/セールス/クリエイティブ）
          // 色分けのコードはサーバー（App\Support\Departments）が付ける。
          // イベプラ／セールス／クリエイティブ以外は 'other' にまとまる。ここに部署名を書かない。
          const depCls = 'dep-' + (e.deptCode || 'none');
          return `<div class="emp-chip ${cls} ${depCls}" onclick="openPick(event,'${e.id}','${key}')">`
               + `<span class="e-dot"></span><span class="e-nm">${e.surname}</span>${star}${roleTags}${multi}${newb}</div>`;
        }).join('') + '</div>';
      } else if (inMonth) {
        inner += `<div class="cell-empty">—</div>`;
      }

      cell.innerHTML = inner;
      grid.appendChild(cell);
    }

    renderAgg();
  }

  // ===== 担当ピッカー（社員名クリック→その日の案件を選んで D/SD 確定）=====
  let PICK = null;   // { empId, dateKey, x, y }
  function openPick(ev, empId, dateKey){
    ev.stopPropagation();
    PICK = { empId, dateKey, x: ev.clientX, y: ev.clientY };
    renderPick();
  }
  function closePick(){ PICK = null; const el = document.getElementById('dpickPop'); if (el) el.style.display = 'none'; }
  function toggleRole(caseId, role){
    if (!PICK) return;
    const c = cases.find(x => x.id === caseId); if (!c) return;
    const id = PICK.empId;
    if (role === 'FC') {
      c.fcIds = c.fcIds || [];
      const i = c.fcIds.indexOf(id);
      if (i >= 0) {
        c.fcIds.splice(i, 1);                 // 再クリックで解除
      } else {
        c.fcIds.push(id);                     // FCに就く（他案件は兼任OK）
        if (c.dirId === id) c.dirId = null;   // 同案件のD/SDからは外す（1案件1役割）
        if (c.sdId === id) c.sdId = null;
      }
    } else {
      const key = role === 'D' ? 'dirId' : 'sdId';
      if (c[key] === id) {
        c[key] = null;                        // 再クリックで解除
      } else {
        c[key] = id;                          // D/SDに就く（他案件は兼任OK）
        c.fcIds = (c.fcIds || []).filter(x => x !== id);   // 同案件のFCからは外す
        const other = role === 'D' ? 'sdId' : 'dirId';
        if (c[other] === id) c[other] = null;              // 同案件でDとSDの掛け持ちは不可
      }
    }
    render();
    renderPick();
  }
  function renderPick(){
    let el = document.getElementById('dpickPop');
    if (!el) {
      el = document.createElement('div');
      el.id = 'dpickPop'; el.className = 'dpick-pop';
      el.addEventListener('click', e => e.stopPropagation());
      document.body.appendChild(el);
    }
    if (!PICK) { el.style.display = 'none'; return; }
    const emp = empById[PICK.empId];
    const dcs = dayCases(PICK.dateKey);
    const mine = dcs.filter(c => c.dirId === PICK.empId || c.sdId === PICK.empId || (c.fcIds || []).includes(PICK.empId)).length;
    const rows = dcs.length ? dcs.map(c => {
      const dOn = c.dirId === PICK.empId, sOn = c.sdId === PICK.empId, fcOn = (c.fcIds || []).includes(PICK.empId);
      const dTaken = c.dirId && !dOn, sTaken = c.sdId && !sOn;
      return `<div class="dp-case">
          <div class="dp-info"><div class="dp-nm">${c.scale==='大型'?'⭐':''}${c.name}</div><div class="dp-ct">🎯${c.content}</div></div>
          <div class="dp-btns">
            <button class="dp-btn d ${dOn?'on':''} ${dTaken?'taken':''}" onclick="toggleRole('${c.id}','D')">D${dTaken?`<span class="who"> ${empName(c.dirId)}</span>`:''}</button>
            <button class="dp-btn sd ${sOn?'on':''} ${sTaken?'taken':''}" onclick="toggleRole('${c.id}','SD')">SD${sTaken?`<span class="who"> ${empName(c.sdId)}</span>`:''}</button>
            <button class="dp-btn fc ${fcOn?'on':''}" onclick="toggleRole('${c.id}','FC')">FC</button>
          </div>
        </div>`;
    }).join('') : '<div class="dp-empty">この日に案件はありません</div>';
    el.innerHTML = `<h4>${emp ? emp.name : PICK.empId} を担当に</h4>${rows}`
      + (mine >= 2 ? `<div class="dp-warn">⚠ この日 ${mine} 件の掛け持ちです</div>` : '')
      + `<div class="dp-close"><button onclick="closePick()">閉じる</button></div>`;
    el.style.display = 'block';
    el.style.left = Math.max(8, Math.min(PICK.x, window.innerWidth  - 276)) + 'px';
    el.style.top  = Math.max(8, Math.min(PICK.y, window.innerHeight - 280)) + 'px';
  }
  document.addEventListener('click', closePick);   // 外側クリックで閉じる

  // ===== 担当バランス集計（この月の案件から、社員ID基準で数える）=====
  function computeAgg(){
    const map = {};
    function ensure(id){ if (!map[id]) map[id] = { id, name: empName(id), d:0, fc:0, bigD:0, bigSD:0 }; return map[id]; }
    EMP.forEach(e => ensure(e.id));   // 0件の社員も表に出す
    cases.forEach(c => {
      if (!inTarget(c)) return;
      const isBig = c.scale === '大型';
      if (c.dirId) { const r = ensure(c.dirId); r.d++; if (isBig) r.bigD++; }
      if (c.sdId && isBig) { ensure(c.sdId).bigSD++; }
      (c.fcIds || []).forEach(id => { ensure(id).fc++; });   // FC（この画面の選択に連動）
    });
    return Object.values(map).sort((a, b) => (b.d - a.d) || (b.fc - a.fc) || (b.bigD - a.bigD));
  }
  function renderAgg(){
    const rows = computeAgg();
    const maxD = Math.max(1, ...rows.map(r => r.d));
    const body = document.getElementById('aggBody');
    body.innerHTML = '';
    rows.forEach(r => {
      const most = r.d === maxD && r.d > 0;
      const tr = document.createElement('tr');
      if (most) tr.className = 'most';
      tr.innerHTML = `
        <td class="nm">${r.name}
          <div class="agg-bar"><i style="width:${Math.round(r.d / maxD * 100)}%;"></i></div>
        </td>
        <td class="num dcnt">${r.d}</td>
        <td class="num">${r.fc}</td>
        <td class="num">${r.bigD}</td>
        <td class="num">${r.bigSD}</td>`;
      body.appendChild(tr);
    });
  }

  // ===== 保存（選んだD/SDを assignments に送る。すべて社員ID基準）=====
  const saveBtn = document.getElementById('saveDirBtn');
  if (saveBtn) {
    saveBtn.addEventListener('click', function(){
      if (!USING_DB) {
        alert('いまは見本データのため保存できません。DBに案件・社員が登録されると保存できるようになります。');
        return;
      }
      const box = document.getElementById('dirSaveInputs');
      box.innerHTML = '';
      cases.forEach(c => {
        // 日付の無い案件はサーバ側でスキップされる（assignmentsは日付必須）
        box.insertAdjacentHTML('beforeend',
          `<input type="hidden" name="dir[${c.id}]" value="${c.dirId || ''}">` +
          `<input type="hidden" name="sd[${c.id}]" value="${c.sdId || ''}">`);
        (c.fcIds || []).forEach(id => {
          box.insertAdjacentHTML('beforeend',
            `<input type="hidden" name="fc[${c.id}][]" value="${id}">`);
        });
      });
      document.getElementById('dirSaveForm').submit();
    });
  }

  // 初期描画
  monthLabel();
  render();
</script>
@endverbatim
@endpush
