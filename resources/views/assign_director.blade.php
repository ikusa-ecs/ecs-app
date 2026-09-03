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
    /* その月ぶんの社員をまとめて確定にするボタン（2026-09-03 baba要望）。
       保存ボタンと見分けがつくよう、青系にしてある。 */
    .btn-fix-month {
      border: 1px solid #2c6ca0; background: #eef4fb; color: #2c6ca0;
      border-radius: 8px; padding: 8px 14px; font-size: 13px; font-weight: 700;
      cursor: pointer; font-family: inherit; white-space: nowrap;
    }
    .btn-fix-month:hover { background: #2c6ca0; color: #fff; }
    .btn-fix-month:disabled { opacity: .5; cursor: default; }

    /* 都度保存の状態表示（2026-09-02）。⚠ 失敗を見落とすと「消えた」に戻るので、失敗だけ赤く強く出す。 */
    .save-state {
      display: inline-flex; align-items: center; gap: 5px;
      border-radius: 999px; padding: 5px 12px; font-size: 12.5px; font-weight: 700;
      border: 1px solid transparent; white-space: nowrap;
    }
    .save-state.ok     { background: #eef7f0; border-color: #cfe6d6; color: #15803d; }
    .save-state.saving { background: #fff7e8; border-color: #f2dcb4; color: #92600a; }
    .save-state.ng     { background: #fdecec; border-color: #f3c0c0; color: #b91c1c; }
    .save-state.ng     { animation: saveBlink 1s steps(1) infinite; }
    @keyframes saveBlink { 50% { background: #fff; } }

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
    /* その日の希望休（ディレクター）。⚠ 名前も出すので、折り返せるようにする
       （nowrap のままだとマスからはみ出して、カレンダーの並びが崩れる）。 */
    .c-dayoff { font-size: 9.5px; font-weight: 700; color: #b4530a; background: #fdecd9; border-radius: 5px;
                padding: 1px 5px; line-height: 1.4; overflow-wrap: anywhere; }

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

    /* 人ごと・その日ごとのメモ（2026-09-02 baba要望／2026-09-03「その日だけ」に変更）。
       社員名を押したときのふきだしに、押した日のメモを出す。 */
    .dp-note {
      display: flex; align-items: flex-start; gap: 6px;
      background: #fdf3e2; border: 1px solid #ecd9b6; border-radius: 8px;
      padding: 6px 8px; margin: 0 0 8px; font-size: 11.5px; line-height: 1.5; color: #8a5a10;
    }
    .dp-note .pn-txt { flex: 1; min-width: 0; overflow-wrap: anywhere; }
    .dp-note .pn-empty { color: var(--muted); }
    .dp-note .pn-by { color: var(--muted); font-size: 10.5px; margin-left: 4px; }
    .dp-note .pn-edit {
      border: 1px solid var(--line); background: #fff; border-radius: 6px;
      padding: 1px 7px; font-size: 12px; cursor: pointer; font-family: inherit; flex-shrink: 0;
    }
    .dp-note .pn-edit:hover { background: #f3ece0; }
    /* メモがある人の印（カレンダーのチップ）。押さないと気づけないので小さく出す。 */
    .emp-chip .e-memo { font-size: 9px; flex-shrink: 0; }

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

    .agg-note { font-size: 11px; color: var(--muted); margin: 4px 0 6px; line-height: 1.5; }
    /* 合計の行（2026-09-02 baba要望）。人の行と見分けが付くように上に線を引く。 */
    .agg-tbl tr.agg-total td { border-top: 2px solid var(--line); font-weight: 700; background: #faf6ee; }

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
          <b>各日に「イベプラの社員」を並べ、名前をクリックして担当（D／SD）を決める画面です。</b>
          日付の横の<b>●件数</b>にカーソルを当てると、その日の案件一覧が出ます。
          社員名をクリック → その日の案件を選び → <b>D</b>／<b>SD</b>／<b>FC</b>を押すと割当（もう一度押すと外せます）。同じ人を同日に複数案件へ兼任もできます。<br>
          <b>SD と FC は何人でも付けられます</b>（2026-09-02 追加。大型案件はコンテンツごとにSDが2名いたりするため）。<b>D は1案件1名</b>です。<br>
          <span style="color:#15803d; font-weight:700;">緑＝D/SD担当</span>／<span style="color:#2c6ca0; font-weight:700;">青＝FC等で稼働</span>／<span style="color:#9c8f80; font-weight:700;">グレー＝未アサイン</span>／<span style="color:#92600a; font-weight:700;">⭐＝大型のD/SD</span>／<span style="color:#7a6a58; font-weight:700;">掛N＝同日N件の掛け持ち</span>／<span style="color:#6d28d9; font-weight:700;">新＝新人</span>。
          名前の<b>文字色は部署</b>（<span style="color:#c2410c;font-weight:700;">オレンジ＝イベプラ</span>・<span style="color:#4338ca;font-weight:700;">藍＝セールス</span>・<span style="color:#16a34a;font-weight:700;">緑＝クリエイティブ</span>・<span style="color:#6e5b49;font-weight:700;">茶＝その他</span>）。
          右上の<b>「＋全社員を表示」</b>を押すと、セールスなど<b>全部の社員</b>が並びます（既定は<b>イベプラだけ</b>）。<br>
          <b>保存ボタンはありません。押したその場で保存されます</b>（2026-09-02 変更。保存の押し忘れで決めた担当が消えていたため）。
          右上に<b>「保存しました ○:○○」</b>と出ていれば保存できています。
          <span style="color:#b91c1c;font-weight:700;">赤い「⚠ 保存できていません」</span>が出たときだけ、<b>「保存し直す」</b>を押してください（保存先＝アサイン台帳）。<br>
          <b>2026-09-01 に変えたところ</b>＝
          ① <b>その日「×」「希望休」を出している方は並べません</b>（マスの下に「お休み ◯名」と出ます）。
          ② <b>他の拠点の方は並べません</b>（以前は、一度この拠点の案件で担当に入ると全部の日に出ていました）。
          ③ <b>すでに担当に入っている方でも、イベプラでなければ並べません</b>。
          変えたいときは<b>「＋全社員を表示」</b>を押してください。担当が外れることはありません（誰が担当かは案件カードの「D:」に出ています）。
          ④ <b>お休みの日なのに担当に入っている方には、赤い「休」の印</b>を出します（いちばん気づきたい間違いなので隠しません）。<br>
          <b>メモ</b>＝社員名を押したふきだしの下に、<b>その日のメモ</b>を書けます（例：10/3 に「大型入ってるからアサインしない」）。
          <b>書いた日にだけ</b>出ます（2026-09-03 変更。以前は全部の日に出ていました）。メモのある日は名前に📝が付きます。<br>
          <b>2026-09-02 に変えたところ</b>＝カレンダーに<b>前の月の終わりと、次の月の最初1週間</b>も出します（前後の予定を見ながら決められるように）。
          薄い色のマスが当月外です。<b>「📊 担当バランス」の数は、いま見ている月ぶんだけ</b>を数えています。
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
        <label class="chk"><input type="checkbox" id="showAllEmp" onchange="render()"> ＋全社員を表示（既定はイベプラだけ）</label>
        <!-- 都度保存（2026-09-02 baba要望）。押した瞬間に保存するので、保存ボタンは無い。
             ここは「いま保存できているか」を出すだけの表示。失敗したときだけ再送ボタンを出す。 -->
        <span id="dirSaveState" class="save-state ok">自動保存</span>
        <button type="button" id="dirRetryBtn" class="btn-save-dir" style="display:none;">保存し直す</button>
        <!-- その月ぶんの社員をまとめて確定にする（2026-09-03 baba要望）。
             使う場面＝D決めが終わってOKが出て、セールスにも共有した → その月ぶんを確定にする。
             ⚠ スタッフは触らない（まだ声を掛けていない人の画面に案件が出てしまう）。 -->
        <button type="button" id="fixMonthBtn" class="btn-fix-month"
                title="いま見ている月の「仮」の社員を、まとめて「確定」にします。スタッフは変わりません。">この月の社員を確定</button>
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
        {{-- ⚠ 保存したあと、見ていた月・拠点に戻ってくるための持ち回し（2026-09-02 baba報告）。
             これが無いと、保存のたびに既定の月へ飛ばされる。 --}}
        <input type="hidden" name="ym" id="dirYm" value="">
        {{-- ⚠ 「この月の社員を確定」も同じ拠点で動かすので、JSから読めるようidを付けてある。 --}}
        <input type="hidden" name="office" id="dirOfficeVal" value="{{ $officeScope ?? '' }}">
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
          <!-- ⚠ カレンダーには前後の月の日も出るので、数え方をはっきり書いておく（2026-09-02 baba指示）。 -->
          <p class="agg-note">並べているのは<b>社員だけ</b>です。数えているのは <b>いま見ている月ぶんだけ</b>です（カレンダーに出ている前後の月の日は数えません）。</p>
          <table class="agg-tbl">
            <thead>
              <tr>
                <th class="nm">社員</th>
                <th>D計</th>
                <th title="D・SD・FC・OP・MCなど、この月に担当する全部の数">合計</th>
                <th>大型D</th>
                <th>大型SD</th>
              </tr>
            </thead>
            <tbody id="aggBody"></tbody>
            <!-- 合計（2026-09-02 baba要望）。この月に何件のDが決まっているかが分かる。
                 ⚠ ここはBladeを解釈しない区間なので、コメントもHTMLの形で書くこと
                   （Bladeのコメントで書くと、その文字がそのまま画面に出る。今回それを踏んだ）。 -->
            <tfoot id="aggFoot"></tfoot>
          </table>
          <p class="agg-note">
            この月の担当数です。<b>D計</b>が多い人ほど色が濃く、一番多い人は<span style="color:#b91c1c;font-weight:700;">赤字</span>＝偏りに注意。<br>
            <b>合計</b>＝D・SD・FC に <b>OP・MCなども足した数</b>です（この画面で選べない役割も含みます）。<br>
            ※ 並べているのは<b>社員だけ</b>です（FCに入っているスタッフは混ぜません）。<br>
            ※ 「リアルD」「オンラインD」を含む詳しい集計は <a href="/projects-agg">社員・ディレクター集計</a> にあります。
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
  window.ECS_DIR_OTHERS  = @json($others ?? []);  // 社員一覧に居ない人（スタッフ等）の名前
  window.ECS_EMP_BUSY    = @json($empBusy);      // 他ロール(FC等)のアサイン状況: {Y-m-d:{社員ID:[role]}}
  window.ECS_DIR_USINGDB = @json($usingDb);      // true＝DBの実データを使う（拠点で絞って0件でも見本に戻さない）
  window.ECS_DIR_OFFICE  = @json($officeScope);  // いま絞っている拠点（null＝全拠点）
  window.ECS_DIR_DAYOFF  = @json($dayOff ?? []); // その日お休みの社員 {社員ID:{"Y-M-D":true}}
  {{-- ⚠ メモは「社員ID → 日付 → 中身」の2段（2026-09-03）。1段に戻すと全部の日に同じメモが出る。 --}}
  window.ECS_DIR_NOTES   = @json($personNotes ?? []); // {社員ID:{'2026-10-03':{note,by,at}}}
  window.ECS_CSRF        = '{{ csrf_token() }}';
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
  // 名前の引き方。社員一覧 → それ以外の人（スタッフ等）の順に探す。
  // ⚠ ここで見つからないと、S-015 のような番号がそのまま画面に出る。
  const OTHERS = window.ECS_DIR_OTHERS || {};
  function empName(id){
    if (empById[id]) return empById[id].surname;
    if (OTHERS[id]) return OTHERS[id].surname + '（' + OTHERS[id].kind + '）';
    return id || '';
  }

  // 他ロール(FC等)のアサイン状況 {Y-m-d:{社員ID:[role]}}。DBのassignments由来（D/SDは除外済み）。
  const EMP_BUSY = window.ECS_EMP_BUSY || {};

  // ===== 作業用の案件リスト（DB優先・無ければ見本 cases.js）。dirId/sdId をこの場で書き換える =====
  // 見本(cases.js)は姓文字列でD/SDを持つので、見本IDも姓に揃える（empById のキーも姓）。
  const SRC = USING_DB
    ? window.ECS_DIR_CASES
    : ECS_CASES.filter(c => !c.archived && !c.draft).map(c => ({
        ...c,
        dirId: (c.dir && c.dir !== '未定') ? c.dir : null,
        sdIds: (c.sd  && c.sd  !== 'なし') ? [c.sd] : [],
      }));
  const cases = SRC.map(c => ({
      id:c.id, off:c.off, name:c.name, client:c.client, content:c.content,
      scale:c.scale, format:c.format||'', fmt:c.fmt,
      dirId:c.dirId || null, sdIds: Array.isArray(c.sdIds) ? c.sdIds.slice() : (c.sdId ? [c.sdId] : []),
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
  // 最初に出す月。
  // ⚠ 以前は「案件が一番多い月」にしていたが、実データが入ると**過去の月**になり、
  //   画面を開くたび・保存するたびに**8月へ戻ってしまう**（2026-09-02 baba報告）。
  //   ふつうに使うときに見たいのは「今月」なので、既定は今月にする。
  // ⚠ URL に ?ym=2026-10 が付いていればその月（保存したあとに戻ってこられるように）。
  function pickTargetMonth(){
    const q = new URLSearchParams(location.search).get('ym') || '';
    const mm = q.match(/^(\d{4})-(\d{1,2})$/);
    if (mm) {
      const y = Number(mm[1]), m = Number(mm[2]) - 1;
      if (m >= 0 && m <= 11) return { y, m };
    }
    const t = addDays(0);
    return { y: t.getFullYear(), m: t.getMonth() };
  }
  let TARGET = pickTargetMonth();

  // いま見ている月を URL に残す（画面を読み込み直しても同じ月に戻る）。
  // ⚠ 履歴は増やさない（replaceState）＝「戻る」で月が1つずつ戻ると使いにくい。
  function rememberMonth(){
    const p = new URLSearchParams(location.search);
    p.set('ym', TARGET.y + '-' + String(TARGET.m + 1).padStart(2, '0'));
    history.replaceState(null, '', location.pathname + '?' + p.toString());
    const box = document.getElementById('dirYm');
    if (box) box.value = TARGET.y + '-' + String(TARGET.m + 1).padStart(2, '0');
  }
  function monthLabel(){
    const n = cases.filter(inTarget).length;
    document.getElementById('monLabel').textContent =
      TARGET.y + '年 ' + (TARGET.m + 1) + '月' + (n ? '（' + n + '件）' : '（案件なし）');
  }
  // 前後の月へ。案件は全期間ぶん読み込んであるので、通信せずその場で切り替わる。
  function shiftMonth(n){
    const d = new Date(TARGET.y, TARGET.m + n, 1);
    TARGET = { y: d.getFullYear(), m: d.getMonth() };
    rememberMonth();
    monthLabel();
    render();       // カレンダー（中で renderAgg も呼ばれる）
    renderPick();   // 下の「案件を選ぶ」パネル
  }

  // この月の案件だけ対象にしているか
  function inTarget(c){ const d = addDays(c.off); return d.getFullYear() === TARGET.y && d.getMonth() === TARGET.m; }
  // 日付キー(y-m-d) → その日の案件
  // ⚠ ここで当月に絞らない（2026-09-02 baba要望）。カレンダーには前の月の終わりと
  //   **次の月の最初1週間**も出すので、その日の案件も出す必要がある
  //   （9/30のDを決めるとき、10/1が見えないと前後の予定が分からない）。
  // ⚠ **集計（担当バランス）は月単位のまま**（baba指示）。そちらは inTarget で絞っている。
  function dayCases(key){ return cases.filter(c => ymd(addDays(c.off)) === key); }

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
      if ((c.sdIds || []).includes(empId)) { count++; if (c.scale === '大型') big = true; }
    });
    return { count, big };
  }

  // その日にマスへ並べる社員。既定は **イベプラだけ**（2026-08-26 baba要望。
  // 以前は「新人」も部署に関係なく並んでいた）。「＋全社員を表示」を押したときだけ全員。
  // ⚠ ただし その日すでに D/SD/FC に入っている人と、他の役割で稼動中の人は消さない。
  //   消すと「担当に入っているのに画面に居ない」状態になり、外すこともできなくなる。
  // いま絞っている拠点（null／空＝全拠点）。
  const DIR_OFFICE = (window.ECS_DIR_OFFICE || '');
  // その社員が「いま見ている拠点の人」か。
  // ⚠ 事務所が空の人は東京として扱う（名簿・案件と同じ決まり。サーバー側の OfficeScope と同じ）。
  function sameOffice(e){
    if (!DIR_OFFICE) return true;                       // 全拠点表示なら全員が対象
    const o = (e.office || '').trim();
    if (o === DIR_OFFICE) return true;
    return o === '' && DIR_OFFICE === '東京';           // 未設定は東京あつかい
  }
  // その日お休み（×／希望休）の社員か。
  // ⚠ この画面はこれまで出勤可能日をまったく見ていなかった（休みの人がふつうに並んでいた）。
  const DAYOFF = window.ECS_DIR_DAYOFF || {};
  function isDayOff(id, y, m, d){
    const k = y + '-' + (m + 1) + '-' + d;
    return !!(DAYOFF[id] && DAYOFF[id][k]);
  }

  function shownEmployees(dcs, busyMap, showAll, y, m, d){
    const inUse = new Set();
    dcs.forEach(c => {
      if (c.dirId) inUse.add(c.dirId);
      (c.sdIds || []).forEach(id => inUse.add(id));
      (c.fcIds || []).forEach(id => inUse.add(id));
    });
    return EMP.filter(e => {
      // ⚠ 他拠点の人は出さない（2026-09-01 baba報告）。
      //   一度でもこの拠点の案件で担当に入ると、その人は拠点の絞り込みを飛び越えて
      //   社員一覧に残る（サーバー側 keepIds。名前を出すために要る）。そこへ
      //   「イベプラなら毎日出す」が重なって、**福岡のイベプラが東京の全部の日に並んでいた**。
      if (!sameOffice(e)) return false;

      // ⚠ 「＋全社員を表示」を押していないときは**イベプラだけ**。
      //   すでに担当に入っている人も出さない（2026-09-01 baba要望）。
      //   担当が外れる心配はない＝保存は案件が持っているD/SD/FCから作るので、
      //   画面のチップとは関係がない。誰が担当かは案件カードに「D: 名前」と出ている。
      //   その人を変えたいときは「＋全社員を表示」を押す。
      if (!showAll && !e.planner) return false;

      // その日お休み（×／希望休）の人は出さない（2026-09-01 baba要望）。件数だけ下に出す。
      // ⚠ ただし **その日すでに担当に入っている人は、お休みでも出す**（赤い「休」印つき）。
      //   お休みの人を担当にしてしまっているのは、いちばん気づきたい間違いなので隠さない。
      if (y !== undefined && isDayOff(e.id, y, m, d) && !inUse.has(e.id) && !busyMap[e.id]) return false;

      return true;
    });
  }

  // その日お休みで、一覧から外した人（「お休み 1名（関根）」と出すため）。
  // ⚠ 黙って消すと「あの人が居ない」と探すことになるので、必ず出す。
  // ⚠ 数だけだと誰が休みか分からず、結局この画面を離れて調べることになる
  //   （2026-09-02 baba要望で名前も出すようにした）。名前は苗字だけ（マスが狭いため）。
  function dayOffPeople(dcs, busyMap, showAll, y, m, d){
    const shownIds = new Set(shownEmployees(dcs, busyMap, showAll, y, m, d).map(e => e.id));
    return EMP.filter(e => !shownIds.has(e.id) && sameOffice(e) && (showAll || e.planner)
      && isDayOff(e.id, y, m, d));
  }

  // ===== 件数ふきだし（その日の案件一覧）=====
  function dayTip(dcs){
    const rows = dcs.map(c => {
      const t = timeOf(c);
      const dTxt = c.dirId ? ('D: ' + empName(c.dirId)) : '<span style="color:#b45309">D未定</span>';
      const sTxt = (c.sdIds || []).length ? ('｜SD: ' + c.sdIds.map(empName).join('・')) : '';
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
    // ⚠ 月の最後の週のあとに、**もう1週間ぶん**出す（2026-09-02 baba要望）。
    //   「9/30までしか出ていないので10月の最初1週間も見たい」＝前後の予定を見ながら決めるため。
    const weeks = Math.ceil((startDow + last.getDate()) / 7) + 1;
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

      // ⚠ 当月外の日（前の月の終わり・次の月の始め）も中身を出す（2026-09-02 baba要望）。
      //   それまでは空にしていたので、**9/30のDを決めるときに10/1が見えなかった**。
      //   前後の予定（連勤・前日設営・翌日の大型）を見ながら決められるようにする。
      //   背景は薄いまま＝当月かどうかはひと目で分かる。
      const dcs = dayCases(key);
      const cnt = dcs.length;
      const undec = dcs.filter(c => !c.dirId).length;   // この日の「D未定」の数
      const countHtml = cnt
        ? `<span class="c-count ${undec ? 'has-undecided' : ''}">${cnt}件${undec ? '（' + undec + '）' : ''}</span>${dayTip(dcs)}`
        : '';
      // 当月外の日は「10/1」のように月から出す（数字だけだと何月か分からない）。
      const dLabel = inMonth ? d.getDate() : ((d.getMonth() + 1) + '/' + d.getDate());
      let inner = `<div class="c-date"><span class="${dowC}">${dLabel}</span>${countHtml}</div>`;

      if (cnt) {
        const busyMap = EMP_BUSY[realYmd(d)] || {};   // その日にFC等でアサイン済みの社員→[role]
        const emps = shownEmployees(dcs, busyMap, showAll, d.getFullYear(), d.getMonth(), d.getDate());
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
          // ⚠ メモは**その日のぶんだけ**（2026-09-03 baba報告）。印もその日だけ出す。
          const dayNote = noteOf(e.id, realYmd(d));
          const memo = dayNote
            ? `<span class="e-memo" title="${escAttrText(dayNote.note)}">📝</span>` : '';
          // ⚠ お休みの日なのに担当に入っている人。いちばん気づきたい間違いなので赤で出す。
          const offMark = isDayOff(e.id, d.getFullYear(), d.getMonth(), d.getDate())
            ? '<span class="dc-offwarn" title="この日は「×」または「希望休」を出しています">休</span>' : '';
          // 名前の文字色＝部署（イベプラ/セールス/クリエイティブ）
          // 色分けのコードはサーバー（App\Support\Departments）が付ける。
          // イベプラ／セールス／クリエイティブ以外は 'other' にまとまる。ここに部署名を書かない。
          const depCls = 'dep-' + (e.deptCode || 'none');
          return `<div class="emp-chip ${cls} ${depCls}" onclick="openPick(event,'${e.id}','${key}','${realYmd(d)}')">`
               + `<span class="e-dot"></span><span class="e-nm">${e.surname}</span>${star}${roleTags}${multi}${newb}${memo}${offMark}</div>`;
        }).join('') + '</div>';

        // お休みで一覧から外した人。⚠ 黙って消すと「あの人が居ない」と探すことになる。
        // ⚠ 名前も出す（2026-09-02 baba要望）。数だけだと誰が休みか分からない。
        //   多い日は名前が長くなるので、3人までにして残りは「ほか◯名」。
        const offList = dayOffPeople(dcs, busyMap, showAll, d.getFullYear(), d.getMonth(), d.getDate());
        if (offList.length > 0) {
          const names = offList.slice(0, 3).map(e => e.surname).join('・')
            + (offList.length > 3 ? '　ほか' + (offList.length - 3) + '名' : '');
          const allNames = offList.map(e => e.name).join('・');
          inner += `<div class="c-dayoff" title="この日「×」または「希望休」を出している方です（${allNames}）。「＋全社員を表示」でも出てきません。">`
                 + `お休み ${offList.length}名（${names}）</div>`;
        }
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
  function openPick(ev, empId, dateKey, realKey){
    ev.stopPropagation();
    PICK = { empId, dateKey, realKey, x: ev.clientX, y: ev.clientY };
    renderPick();
  }
  function closePick(){ PICK = null; const el = document.getElementById('dpickPop'); if (el) el.style.display = 'none'; }
  function toggleRole(caseId, role){
    if (!PICK) return;
    const c = cases.find(x => x.id === caseId); if (!c) return;
    const id = PICK.empId;
    c.fcIds = c.fcIds || [];
    c.sdIds = c.sdIds || [];

    // ⚠ SDは**複数可**（2026-09-02 baba要望）。大型案件はコンテンツごとにSDが2名いたりする。
    //   Dは1案件1名のまま（複数にすると「誰が責任者か」が分からなくなる）。
    // ⚠ 同じ案件で同じ人が2つの役割に就くことはない（D↔SD↔FCは入れ替え）。
    const dropFrom = (except) => {
      if (except !== 'D' && c.dirId === id) c.dirId = null;
      if (except !== 'SD') c.sdIds = c.sdIds.filter(x => x !== id);
      if (except !== 'FC') c.fcIds = c.fcIds.filter(x => x !== id);
    };

    if (role === 'FC') {
      if (c.fcIds.includes(id)) {
        c.fcIds = c.fcIds.filter(x => x !== id);   // 再クリックで解除
      } else {
        dropFrom('FC');
        c.fcIds.push(id);
      }
    } else if (role === 'SD') {
      if (c.sdIds.includes(id)) {
        c.sdIds = c.sdIds.filter(x => x !== id);   // 再クリックで解除
      } else {
        dropFrom('SD');
        c.sdIds.push(id);                          // 何人でも付けられる
      }
    } else {
      if (c.dirId === id) {
        c.dirId = null;                            // 再クリックで解除
      } else {
        dropFrom('D');
        c.dirId = id;
      }
    }
    render();
    renderPick();
    // ⚠ 押したその場で保存する（2026-09-02）。保存ボタンは無い＝押し忘れで消えないように。
    autoSave(caseId);
  }
  // ===== 人ごと・その日ごとのメモ（2026-09-02 baba要望／2026-09-03「その日だけ」に変更）=====
  // 10/3 のところに「大型入ってるからアサインしない」。社員名を押したときのふきだしに出す。
  // ⚠ メモは**押した日のもの**（PNOTES[社員ID][日付]）。
  //   最初は1人1行で持っていたため、**カレンダーの全部の日に同じメモが出て**しまい、
  //   かえって分からなくなった（2026-09-03 baba報告）。日付を外さないこと。
  // ⚠ 保存の正本はサーバー（App\Support\PersonNotes）。ここは出すだけ・持ち方を増やさない。
  // ⚠ これは「その人の個人情報」ではなく**アサインを決めるための業務のメモ**なので、
  //   できるポジション・NGペアと同じく社員以上が書ける。
  const PNOTES = window.ECS_DIR_NOTES || {};

  /** その人のその日のメモを取り出す（無ければ null）。日付は 'Y-m-d'。 */
  function noteOf(empId, realKey){
    return (PNOTES[empId] && PNOTES[empId][realKey]) || null;
  }

  // ⚠ メモは自由に書ける文字なので、必ずエスケープしてから画面に出す。
  //   これが無いと < や " を書いただけで画面が崩れる（この画面には部品が無かったので用意した）。
  function escHtml(t){
    return String(t == null ? '' : t)
      .split('&').join('&amp;')
      .split('<').join('&lt;')
      .split('>').join('&gt;')
      .split('"').join('&quot;')
      .split("'").join('&#39;');
  }
  // title="" の中に入れる用（改行はスペースにする）。
  // ⚠ 改行を書くときは String.fromCharCode を使う。
  //   「バックスラッシュ＋n」をシェル経由で書き込むと**本物の改行に化けて**、
  //   その行で文字列が閉じずJavaScriptが丸ごと止まる（この画面でも実際に踏んだ）。
  function escAttrText(t){
    const LF = String.fromCharCode(10), CR = String.fromCharCode(13);
    return escHtml(String(t == null ? '' : t).split(CR).join(LF).split(LF).join(' '));
  }

  /** 'Y-m-d' → '10/3'（画面に出す用）。 */
  function mdOf(realKey){
    const p = String(realKey || '').split('-');
    return p.length === 3 ? (Number(p[1]) + '/' + Number(p[2])) : String(realKey || '');
  }

  function personNoteHtml(empId, realKey){
    const n = noteOf(empId, realKey);
    const has = n && n.note;
    const body = has
      ? escHtml(n.note) + (n.by ? `<span class="pn-by">（${escHtml(n.by)} ${escHtml(n.at)}）</span>` : '')
      : '<span class="pn-empty">この日のメモはありません</span>';
    return `<div class="dp-note ${has ? 'has' : ''}">
        <span class="pn-txt">📝 <b>${mdOf(realKey)}</b> ${body}</span>
        <button class="pn-edit" onclick="editPersonNote('${empId}','${realKey}')" title="この日のメモを書く・直す">✎</button>
      </div>`;
  }

  function editPersonNote(empId, realKey){
    const before = noteOf(empId, realKey);
    const cur = (before && before.note) || '';
    const emp = empById[empId];
    // ⚠ 改行は String.fromCharCode(10) で作る（上の escAttrText と同じ理由）。
    const LF = String.fromCharCode(10);
    const input = prompt(
      (emp ? emp.name : empId) + ' さん ／ ' + mdOf(realKey) + ' のメモ' + LF
      + '例）大型入ってるからアサインしない' + LF + LF
      + '※ このメモは ' + mdOf(realKey) + ' にだけ出ます。空にすると消えます。ほかの社員にも見えます。', cur);
    if (input === null) return;                 // キャンセル
    const note = input.trim();

    // 先に画面へ出す（保存を待たない）。失敗したら元に戻す。
    if (!PNOTES[empId]) PNOTES[empId] = {};
    if (note) { PNOTES[empId][realKey] = { note: note, by: '', at: '' }; } else { delete PNOTES[empId][realKey]; }
    renderPick(); render();

    fetch('/assign-director/person-note', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF, 'Accept': 'application/json' },
      body: JSON.stringify({ person_id: empId, date: realKey, note: note })
    })
      .then(r => r.ok ? r.json() : Promise.reject(r.status))
      .catch(e => {
        if (!PNOTES[empId]) PNOTES[empId] = {};
        if (before) { PNOTES[empId][realKey] = before; } else { delete PNOTES[empId][realKey]; }
        renderPick(); render();
        alert('メモを保存できませんでした（' + e + '）。もう一度お試しください。');
      });
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
    const mine = dcs.filter(c => c.dirId === PICK.empId || (c.sdIds || []).includes(PICK.empId) || (c.fcIds || []).includes(PICK.empId)).length;
    const rows = dcs.length ? dcs.map(c => {
      const dOn = c.dirId === PICK.empId, sOn = (c.sdIds || []).includes(PICK.empId), fcOn = (c.fcIds || []).includes(PICK.empId);
      // ⚠ SDは複数可（2026-09-02 baba要望）なので「他の人に取られている」は無い。
      //   すでに入っている人がいれば名前を並べて出すだけ。
      const dTaken = c.dirId && !dOn;
      const sNames = (c.sdIds || []).filter(id => id !== PICK.empId).map(empName).join('・');
      return `<div class="dp-case">
          <div class="dp-info"><div class="dp-nm">${c.scale==='大型'?'⭐':''}${c.name}</div><div class="dp-ct">🎯${c.content}</div></div>
          <div class="dp-btns">
            <button class="dp-btn d ${dOn?'on':''} ${dTaken?'taken':''}" onclick="toggleRole('${c.id}','D')">D${dTaken?`<span class="who"> ${empName(c.dirId)}</span>`:''}</button>
            <button class="dp-btn sd ${sOn?'on':''}" onclick="toggleRole('${c.id}','SD')" title="SDは何人でも付けられます">SD${sNames?`<span class="who"> ${sNames}</span>`:''}</button>
            <button class="dp-btn fc ${fcOn?'on':''}" onclick="toggleRole('${c.id}','FC')">FC</button>
          </div>
        </div>`;
    }).join('') : '<div class="dp-empty">この日に案件はありません</div>';
    el.innerHTML = `<h4>${emp ? emp.name : PICK.empId} を担当に</h4>`
      + personNoteHtml(PICK.empId, PICK.realKey)
      + rows
      + (mine >= 2 ? `<div class="dp-warn">⚠ この日 ${mine} 件の掛け持ちです</div>` : '')
      + `<div class="dp-close"><button onclick="closePick()">閉じる</button></div>`;
    el.style.display = 'block';
    el.style.left = Math.max(8, Math.min(PICK.x, window.innerWidth  - 276)) + 'px';
    el.style.top  = Math.max(8, Math.min(PICK.y, window.innerHeight - 280)) + 'px';
  }
  document.addEventListener('click', closePick);   // 外側クリックで閉じる

  // ===== 担当バランス集計（この月の案件から、社員ID基準で数える）=====
  // 既定で表に並べるのは **イベプラだけ**（2026-08-26 baba要望。カレンダーのマスと同じ考え方）。
  // 「＋全社員を表示」を押しているときは全員。
  // ⚠ いずれにしても**実際にD/SD/FCに入っている人は必ず出す**（下のループで ensure される）。
  //   数字を持っている人を隠すと、合計が合わない表になる。
  // 担当バランス＝「Dの偏りを見る」ための表（2026-09-02 baba指示で作り直し）。
  // ⚠ **Dに入っている人だけ**を並べる。FCの列は出さない。
  //   ここはDを誰に振るかを決める画面なので、FCの件数やスタッフが混ざると
  //   「Dが偏っていないか」が読み取りにくくなる。
  // ⚠ **数えるのはいま見ている月ぶんだけ**（inTarget）。カレンダーには前後の月の日も
  //   出しているが、それは数えない（baba指示）。
  // ⚠ 大型SDは、Dに入っている人の行にだけ出る（SDだけの人は並べない）。
  function computeAgg(){
    const map = {};
    function ensure(id){ if (!map[id]) map[id] = { id, name: empName(id), d:0, total:0, bigD:0, bigSD:0 }; return map[id]; }

    // ⚠ 行に出すのは**社員だけ**（2026-09-01 baba）。FCに入っているスタッフを混ぜない
    //   ＝Dの偏りを見る表なので、名簿の人が全員並ぶと読み取れなくなる。
    const isEmp = id => !!empById[id];

    cases.forEach(c => {
      if (!inTarget(c)) return;
      const isBig = c.scale === '大型';
      if (c.dirId && isEmp(c.dirId)) { const r = ensure(c.dirId); r.d++; r.total++; if (isBig) r.bigD++; }
      (c.sdIds || []).forEach(id => { if (!isEmp(id)) return; const r = ensure(id); r.total++; if (isBig) r.bigSD++; });
      (c.fcIds || []).forEach(id => { if (isEmp(id)) ensure(id).total++; });
    });

    // ⚠ 合計には **D・SD・FC 以外（OP・MCなど）も入れる**（2026-09-02 baba要望）。
    //   「合計数をみてアサインを考える」ので、この画面で選べない役割も数に入れないと意味がない。
    //   元は EMP_BUSY（その日その人が入っている D/SD/FC 以外の役割）。
    //   ⚠ 数えるのは**いま見ている月ぶんだけ**（カレンダーに出ている前後の月は数えない）。
    Object.keys(EMP_BUSY).forEach(dateKey => {
      const p = dateKey.split('-').map(Number);
      if (p[0] !== TARGET.y || (p[1] - 1) !== TARGET.m) return;
      Object.keys(EMP_BUSY[dateKey]).forEach(id => {
        if (!isEmp(id)) return;
        ensure(id).total += EMP_BUSY[dateKey][id].length;
      });
    });

    // 合計の多い順 → D計の多い順（偏りを見る表なので、まず合計）。
    return Object.values(map).sort((a, b) => (b.total - a.total) || (b.d - a.d));
  }
  function renderAgg(){
    const rows = computeAgg();
    const maxD = Math.max(1, ...rows.map(r => r.d));
    const body = document.getElementById('aggBody');
    body.innerHTML = '';
    const foot = document.getElementById('aggFoot');
    if (foot) foot.innerHTML = '';
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="5" style="color:var(--muted); font-size:11.5px; padding:8px 4px;">'
        + 'この月は、まだDが決まっている案件がありません。</td></tr>';
      return;
    }
    rows.forEach(r => {
      const most = r.d === maxD && r.d > 0;
      const tr = document.createElement('tr');
      if (most) tr.className = 'most';
      tr.innerHTML = `
        <td class="nm">${r.name}
          <div class="agg-bar"><i style="width:${Math.round(r.d / maxD * 100)}%;"></i></div>
        </td>
        <td class="num dcnt">${r.d}</td>
        <td class="num total">${r.total}</td>
        <td class="num">${r.bigD}</td>
        <td class="num">${r.bigSD}</td>`;
      body.appendChild(tr);
    });

    // 合計（2026-09-02 baba要望）。
    // ⚠ 「この月に何件のDが決まっているか」が分かると、一人あたりの多い少ないが読める。
    //   ⚠ 数えるのは、いま見ている月ぶんだけ（上の computeAgg と同じ範囲）。
    if (foot) {
      const sum = k => rows.reduce((n, r) => n + r[k], 0);
      foot.innerHTML = `<tr class="agg-total">
        <td class="nm">合計（${rows.length}名）</td>
        <td class="num dcnt">${sum('d')}</td>
        <td class="num total">${sum('total')}</td>
        <td class="num">${sum('bigD')}</td>
        <td class="num">${sum('bigSD')}</td></tr>`;
    }
  }

  // ===== 都度保存（2026-09-02 baba要望）=====
  // ⚠ もともとは「D／SDを保存」ボタンを押すまで何も保存していなかったため、
  //   押し忘れたまま画面を離れて**決めた担当が消える事故が2回**起きた。
  //   そこで、D/SD/FCを押した**その瞬間に、その案件1件ぶんだけ**サーバーへ送る形にした。
  // ⚠ 送るのは「押した案件のいまの状態まるごと」（D・SD全員・FC全員）。
  //   差分ではなく丸ごとなので、通信が前後しても最後に送ったものが正しく残る。
  // ⚠ 失敗をだまって流すと結局「消えた」になる。失敗したら赤く点滅させ、
  //   ページを閉じようとしたら引き止め、「保存し直す」で送り直せるようにしてある。
  const SAVE = {
    waiting: new Map(),   // 案件ID => true（まだ送れていない案件）
    busy: false,
    stopped: false,       // 失敗したら止める（同じ失敗を延々くり返さないため）
    warned: false,
  };
  const saveState = document.getElementById('dirSaveState');
  const retryBtn  = document.getElementById('dirRetryBtn');

  function setSaveState(kind, text){
    if (!saveState) return;
    saveState.className = 'save-state ' + kind;
    saveState.textContent = text;
    if (retryBtn) retryBtn.style.display = (kind === 'ng') ? '' : 'none';
  }

  /** 1案件ぶんの送信内容を作る（保存フォームと同じ形＝サーバー側は1つの入口のまま）。 */
  function caseFormData(c){
    const form = document.getElementById('dirSaveForm');
    const fd = new FormData();
    fd.append('_token', window.ECS_CSRF || '');
    fd.append('ym', (document.getElementById('dirYm') || {}).value || '');
    const off = form ? form.querySelector('input[name="office"]') : null;
    fd.append('office', off ? off.value : '');
    fd.append('dir[' + c.id + ']', c.dirId || '');
    (c.sdIds || []).forEach(id => fd.append('sd[' + c.id + '][]', id));
    (c.fcIds || []).forEach(id => fd.append('fc[' + c.id + '][]', id));
    return fd;
  }

  /** 押されたら呼ぶ。すぐ送る（順番に1件ずつ）。 */
  function autoSave(caseId){
    if (!USING_DB) {
      if (!SAVE.warned) {
        SAVE.warned = true;
        alert('いまは見本データのため保存されません。DBに案件・社員が登録されると自動で保存されるようになります。');
      }
      setSaveState('ng', '見本データ＝保存されません');
      if (retryBtn) retryBtn.style.display = 'none';
      return;
    }
    SAVE.waiting.set(caseId, true);
    SAVE.stopped = false;
    pumpSave();
  }

  function pumpSave(){
    if (SAVE.busy || SAVE.stopped) return;
    const next = SAVE.waiting.keys().next();
    if (next.done) return;

    const caseId = next.value;
    SAVE.waiting.delete(caseId);
    const c = cases.find(x => x.id === caseId);
    if (!c) { pumpSave(); return; }

    SAVE.busy = true;
    setSaveState('saving', '保存中…');

    fetch('/assign-director/save', {
      method: 'POST',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: caseFormData(c),
      credentials: 'same-origin',
    })
      .then(r => r.ok ? r.json() : Promise.reject(new Error('通信エラー ' + r.status)))
      .then(j => {
        if (!j || !j.ok) throw new Error((j && j.message) || '保存できませんでした');
        SAVE.busy = false;
        if (SAVE.waiting.size === 0) {
          // ⚠ 開催日が未設定の案件は台帳に書けない（assignmentsは日付が必須）。
          //   だまって消えると事故なので、そのときだけ知らせる。
          if (j.skipped > 0) {
            setSaveState('ng', '開催日が未設定のため保存できません');
            SAVE.stopped = true;
            alert('この案件は開催日が入っていないため、担当を保存できませんでした。\n案件の詳細で開催日を入れてから決めてください。');
            return;
          }
          if (j.blocked > 0) {
            setSaveState('ng', '他の拠点の案件は保存できません');
            SAVE.stopped = true;
            return;
          }
          setSaveState('ok', '保存しました ' + (j.at || ''));
        }
        pumpSave();
      })
      .catch(e => {
        SAVE.busy = false;
        SAVE.stopped = true;
        SAVE.waiting.set(caseId, true);   // 送れなかったぶんは残す（保存し直すで再送）
        setSaveState('ng', '⚠ 保存できていません');
        alert('担当を保存できませんでした（' + e.message + '）。\n\n' +
              '画面の上の「保存し直す」を押してください。\n' +
              'それでも直らないときは、いったん画面を開き直してから決め直してください。');
      });
  }

  if (retryBtn) {
    retryBtn.addEventListener('click', function(){
      SAVE.stopped = false;
      // 念のため、いま画面にある案件を全部送り直す（どれが送れていないか分からなくなったとき用）。
      cases.forEach(c => SAVE.waiting.set(c.id, true));
      pumpSave();
    });
  }

  // ===== この月の社員をまとめて確定にする（2026-09-03 baba要望）=====
  // 使う場面＝D決めが終わってOKが出て、セールスにも共有した → その月ぶんを確定にする。
  // ⚠ スタッフは触らない（まだ声を掛けていない人の画面に案件が出てしまう）。
  // ⚠ 押す前に「誰が確定になるか」を必ず見せる（dry=1 で先に数と名前をもらう）。
  //   まとめて動く操作なので、black box にしない。
  const fixMonthBtn = document.getElementById('fixMonthBtn');
  if (fixMonthBtn) {
    fixMonthBtn.addEventListener('click', function(){
      const NL = String.fromCharCode(10);
      const ym = (document.getElementById('dirYm') || {}).value || '';
      if (!ym) { alert('対象の月が分かりません。画面を開き直してからもう一度お試しください。'); return; }
      const office = (document.getElementById('dirOfficeVal') || {}).value || '';
      const label = ym.replace('-', '年') + '月';

      function post(dry){
        const body = new URLSearchParams();
        body.append('ym', ym);
        body.append('only', 'employee');
        if (office) body.append('office', office);
        if (dry) body.append('dry', '1');
        return fetch('/assignments/confirm-month', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF || '' },
          body: body.toString()
        }).then(r => r.json().then(j => ({ ok: r.ok, j })));
      }

      fixMonthBtn.disabled = true;
      post(true)
        .then(({ ok, j }) => {
          if (!ok || !j || !j.ok) { alert((j && j.message) || '調べられませんでした。'); return; }
          if (!j.confirmed) { alert(label + 'に「仮」の社員はいません。'); return; }
          const names = (j.names || []).join('・');
          if (!confirm(label + 'の社員 ' + j.confirmed + '名を「確定」にします。' + NL
            + '（' + names + '）' + NL + NL
            + 'スタッフは変わりません。よろしいですか？')) return;
          return post(false).then(({ ok, j }) => {
            // ⚠ 失敗をだまって流さない。「確定にしたつもりで仮のまま」がいちばん困る。
            if (!ok || !j || !j.ok) { alert((j && j.message) || '確定にできませんでした。'); return; }
            alert('✓ ' + label + 'の社員 ' + j.confirmed + '名を「確定」にしました。');
            location.reload();
          });
        })
        .catch(() => alert('通信に失敗しました。もう一度お試しください。'))
        .then(() => { fixMonthBtn.disabled = false; });
    });
  }

  // ⚠ 送れていないものが残ったまま閉じられると、また「消えた」になる。ブラウザに引き止めてもらう。
  window.addEventListener('beforeunload', function(ev){
    if (SAVE.waiting.size > 0 || SAVE.busy) {
      ev.preventDefault();
      ev.returnValue = '';
    }
  });

  // 初期描画
  rememberMonth();
  monthLabel();
  render();
</script>
@endverbatim
@endpush
