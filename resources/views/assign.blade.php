@extends('layouts.app')
@section('title', 'アサインボード（日別）')
@section('h1', 'アサインボード')
@php($active = 'assign')

@push('head')
@verbatim
<style>
    /* ===== アサインボード（日別）専用スタイル ===== */

    /* 上部の操作バー（月切替・絞り込み） */
    .board-controls {
      display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
      background: var(--panel); border: 1px solid var(--line); border-radius: 12px;
      padding: 12px 16px; margin-bottom: 18px;
    }
    .month-nav { display: flex; align-items: center; gap: 10px; }
    .month-nav button {
      border: 1px solid var(--line); background: #fff; color: var(--ink);
      border-radius: 8px; width: 32px; height: 32px; font-size: 16px; cursor: pointer; font-family: inherit;
    }
    .month-nav .mon { font-size: 16px; font-weight: 700; min-width: 110px; text-align: center; }
    .board-controls .spacer { flex: 1; }
    .board-controls select {
      padding: 8px 11px; border: 1px solid var(--line); border-radius: 8px;
      font-size: 13.5px; font-family: inherit; background: #fff;
    }
    .board-controls label.chk { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--ink); cursor: pointer; }
    /* リストの高さ切替ボタン（全カードまとめて） */
    .lh-bar { display: flex; align-items: center; flex-wrap: wrap; gap: 6px;
      background: var(--panel); border: 1px solid var(--line); border-radius: 10px;
      padding: 8px 12px; margin-bottom: 10px; }
    .lh-bar .lh-lbl { font-weight: 700; font-size: 12.5px; color: var(--ink); margin-right: 4px; }
    .lh-btn { border: 1px solid var(--line); background: #fff; color: var(--ink); border-radius: 8px;
      padding: 7px 11px; font-size: 12.5px; font-weight: 700; cursor: pointer; font-family: inherit; }
    .lh-btn:hover { background: var(--brand-soft); }
    .lh-btn.active { background: var(--brand); color: #fff; border-color: var(--brand); }

    /* 1日のかたまり */
    .day-block { margin-bottom: 12px; }
    .day-head {
      display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
      padding: 5px 10px; border-radius: 10px;
      background: var(--brand-soft); color: var(--brand-dark);
      font-weight: 700; margin-bottom: 7px;
      /* 日付の帯を画面の上に貼り付ける（2026-09-01 スタッフからのご意見）。
         ⚠ 同じ日に案件が何件もあると、下へスクロールしたときに日付が画面の外へ出てしまい
           「いま何日を見ているんだっけ？」になる。JSは使わずブラウザ標準の sticky。
         ⚠ 上に重ねるので背景色は必ず付いたままにする（透けると下の文字と重なって読めない）。 */
      position: sticky;
      top: 0;
      z-index: 20;
      /* 貼り付いたときに、下のカードとの境目が分かるように薄く影を落とす。 */
      box-shadow: 0 2px 6px rgba(110, 95, 79, .12);
    }
    /* スマホは上のバーが貼り付いているので、その下に来るようにずらす（バーの高さ52px）。 */
    @media (max-width: 720px) {
      .day-head { top: 52px; }
    }
    .day-head .d-date { font-size: 15px; font-variant-numeric: tabular-nums; }
    .day-head .d-date .sun { color: var(--danger); } .day-head .d-date .sat { color: var(--brand); }
    .day-head .d-pool { font-size: 12.5px; font-weight: 600; color: var(--ink); display: flex; gap: 12px; flex-wrap: wrap; }
    .day-head .d-pool b { font-variant-numeric: tabular-nums; }
    .day-head .d-pool .remain.ok  { color: #15803d; }
    .day-head .d-pool .remain.bad { color: var(--danger); }
    .day-head .d-warn { font-size: 12px; font-weight: 700; color: #fff; background: var(--danger); padding: 2px 9px; border-radius: 999px; }
    /* その日をまとめて確定・まとめて公開（2026-08-28 baba要望）。1件ずつ押す手間をなくす。
       ⚠ 「候補◯名」のすぐ横に置く（baba指摘：右端に離すと見つけられない）。
          確定（白）と公開（緑）で色を分けて、押し間違いを防ぐ。 */
    .day-head .day-bulk {
      font-size: 12px; font-weight: 700; cursor: pointer;
      border: 1px solid var(--line); background: #fff; color: #6b5544;
      padding: 3px 11px; border-radius: 999px; white-space: nowrap;
    }
    .day-head .day-bulk + .day-bulk { margin-left: 6px; }
    .day-head .day-bulk:hover { background: #f7f1e8; }
    .day-head .day-bulk.pub { background: #16a34a; border-color: #15803d; color: #fff; }
    .day-head .day-bulk.pub:hover { background: #15803d; }

    /* 実施形態のバッジ（2026-09-01 baba要望）。
       ⚠ 色は案件一覧・スタッフ画面と同じにそろえる（画面ごとに色が違うと見間違える）。
       ⚠ どの色にするかの判定はサーバー（ProjectFormats::badgeCode）が決めて fmtCls で渡す。 */
    .fbadge { display: inline-block; font-size: 11px; font-weight: 700; padding: 1px 8px; border-radius: 6px; }
    .fbadge.fmt-real   { background: #e7f0e9; color: #3f7d52; }  /* リアル */
    .fbadge.fmt-long   { background: #fdecd9; color: #b4530a; }  /* リアルロング */
    .fbadge.fmt-online { background: #e3edf7; color: #2c6ca0; }  /* オンライン */
    .fbadge.fmt-arena  { background: #efe6f6; color: #6d28d9; }  /* ARENA場所貸し */
    .fbadge.fmt-other  { background: #e1f1ee; color: #0f766e; }  /* 他拠点 */
    .fbadge.fmt-etc    { background: #ece3d4; color: #7a6a58; }  /* その他 */

    /* その日の案件カードを横に並べる */
    .case-row { display: flex; gap: 10px; flex-wrap: wrap; }

    .case-card {
      flex: 1 1 360px; max-width: 460px;
      background: #fff; border: 1px solid var(--line); border-radius: 12px;
      box-shadow: var(--shadow); padding: 9px 11px; display: flex; flex-direction: column; gap: 6px;
    }
    /* メンバー｜希望者 を横並び（ノートPC1画面でアサインしやすく） */
    .cc-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 0; }
    @media (max-width: 560px) { .cc-cols { grid-template-columns: 1fr; } }
    /* グリッドの子は既定で min-width:auto ＝中身の最小幅で膨らみ、カードからはみ出す。0にして縮めるように。 */
    .cc-col { min-width: 0; }
    .cc-col .col-h { font-size: 12px; font-weight: 700; color: var(--brand-dark); margin-bottom: 4px; display: flex; align-items: center; gap: 6px; }
    .cc-col .col-h .cl-toggle { cursor: pointer; user-select: none; }
    .cc-col .col-h .cl-toggle:hover { text-decoration: underline; }
    /* 列見出しクリックで開閉。高さは上の「リストの高さ」で全カードまとめて切替（1つずつドラッグ不要）。 */
    .col-list { display: flex; flex-direction: column; gap: 2px; height: 220px; min-height: 48px; overflow: auto;
      border-top: 1px dashed var(--line); padding-top: 4px; }
    .col-list.hide { display: none; }
    /* 全カードのリスト高さをまとめて切替（#boardBody のクラスで一括指定） */
    #boardBody.lh-compact .col-list { height: 120px; }
    #boardBody.lh-normal  .col-list { height: 240px; }
    #boardBody.lh-all     .col-list { height: auto; overflow: visible; }

    /* メンバーが他に出ている案件の小タグ（同日=赤＝かぶり／別日=緑＝連続起用OK）＝旧方式。今は未使用 */
    .xcase { font-size: 9.5px; font-weight: 700; padding: 0 5px; border-radius: 999px; margin-left: 3px; white-space: nowrap; }
    .xcase.same { background: var(--danger-soft); color: #b91c1c; }
    .xcase.cont { background: var(--ok-soft);     color: #15803d; }
    /* 連勤まとめバッジ（この期間に何日ぶん出ているか）。2〜3日=控えめ／4日以上=赤（働きすぎ注意）。 */
    .renkin-badge { font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 999px; white-space: nowrap;
      background: #ece3d4; color: #7a6a58; }
    .renkin-badge.hi { background: var(--danger-soft); color: #b91c1c; }
    .case-card.todo { border-left: 4px solid #d97706; }
    .case-card.adj  { border-left: 4px solid #2c6ca0; }
    .case-card.fix  { border-left: 4px solid #16a34a; }
    .case-card.pub  { border-left: 4px solid #16a34a; }

    .cc-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .cc-headmain { flex: 1 1 auto; min-width: 0; }
    /* タイトルは1行で、長い分は「…」。全文はマウスを乗せると出る（title属性）。カードが横長にならないように。 */
    .cc-name { font-size: 15px; font-weight: 700; line-height: 1.35;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    /* コンテンツ未登録の印（案件名で仮表示していることを一目で示す） */
    .cc-nocontent { font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 6px;
      background: var(--warn-soft); color: #b45309; white-space: nowrap; }
    .cc-client { font-size: 12px; color: var(--muted); font-weight: 400; }
    /* 時間・開催場所（日別ボードのアサイン作業用） */
    .cc-meta { font-size: 11.5px; color: var(--ink); margin-top: 3px; display: flex; flex-wrap: wrap; gap: 2px 12px; }
    .cc-meta .ic { color: var(--muted); }
    .cc-meta .venue { font-weight: 600; }
    .cc-head .sb { flex-shrink: 0; }
    /* 案件名の欄にまとめた操作ボタン（手動編集・自動アサイン・確定/公開・詳細） */
    .cc-actions { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; width: 100%;
      padding-top: 6px; border-top: 1px dashed var(--line); }
    .cc-actions .open-btn { margin-left: auto; }

    /* 状態バッジ */
    .sb { font-size: 11px; font-weight: 700; padding: 2px 9px; border-radius: 999px; white-space: nowrap; }
    .sb.todo { background: var(--warn-soft);  color: #b45309; }
    .sb.adj  { background: #e3edf7;            color: #2c6ca0; }
    .sb.fix  { background: var(--ok-soft);     color: #15803d; }
    .sb.pub  { background: #16a34a;            color: #fff; }
    /* 締切＝人数が埋まってスタッフ画面では「締切・満員」になっている（2026-08-28 baba指摘）。
       ⚠ 募集中（緑）と見間違えないよう灰色にする。 */
    .sb.full { background: #8a7a66;            color: #fff; }

    /* 充足バー */
    .cc-fill { display: flex; align-items: center; gap: 9px; }
    .cc-fill .fbar { flex: 1; height: 9px; background: #ece3d4; border-radius: 999px; overflow: hidden; }
    .cc-fill .fbar > i { display: block; height: 100%; }
    .cc-fill .fbar > i.full { background: var(--ok); }
    .cc-fill .fbar > i.mid  { background: var(--brand); }
    .cc-fill .fbar > i.low  { background: var(--warn); }
    .cc-fill .fnum { font-size: 12.5px; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .cc-fill .fnum .need { color: var(--muted); font-weight: 400; }

    /* ポジション充足ランプ */
    .cc-pos { display: flex; flex-wrap: wrap; gap: 6px; }
    .plamp { font-size: 11px; font-weight: 700; padding: 1px 8px; border-radius: 6px; }
    .plamp.ok    { background: var(--ok-soft);     color: #15803d; }
    .plamp.short { background: var(--danger-soft); color: #b91c1c; }
    .plamp.none  { background: #ece3d4;            color: #7a6a58; }

    /* 案件カードのタグ（前日設営・連勤・前泊・◯日目） */
    .cc-tags { display: flex; flex-wrap: wrap; gap: 5px; }
    .ctag { font-size: 10.5px; font-weight: 700; padding: 1px 7px; border-radius: 6px; }
    .ctag.setup  { background: #ece3d4;            color: #7a6a58; }
    .ctag.renkin { background: var(--danger-soft); color: #b91c1c; }
    .ctag.stay   { background: #fdecd9;            color: #b4530a; }
    .ctag.day    { background: var(--brand-soft);  color: var(--brand-dark); }

    /* 詳細ボタン（案件名の欄に配置） */
    .open-btn {
      border: none; border-radius: 8px; padding: 7px 14px;
      font-size: 12.5px; font-weight: 700; cursor: pointer; font-family: inherit;
      background: var(--brand); color: #fff; text-decoration: none;
    }
    .open-btn:hover { background: var(--brand-dark); }

    .empty-note { text-align: center; color: var(--muted); font-size: 13px; padding: 26px 0; }

    /* 案件の備考の見た目は共通部品（partials/project_note）に移した＝ここには置かない。 */
    /* 案件名のリンク（押すと案件の詳細・編集へ）。色は見出しのまま、マウスを乗せたときだけ下線。 */
    .cc-name a { color: inherit; text-decoration: none; }
    .cc-name a:hover { text-decoration: underline; }
    /* メンバーの「仮／確定」。押すと入れ替わる。仮＝橙で目立たせる（スタッフに見えないので気づきたい）。 */
    .m-st { flex: 0 0 auto; font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 999px;
      white-space: nowrap; cursor: pointer; border: 1px solid transparent; }
    .m-st.kari { background: #fdecd9; color: #b45309; border-color: #f0d5b0; }
    .m-st.fix  { background: var(--ok-soft); color: #15803d; border-color: #cdeccf; }
    .m-st:hover { filter: brightness(.96); text-decoration: underline; }
    /* メンバー欄の見出しに出す「仮 ◯名」（仮の人はスタッフに見えない＝公開前に気づくため） */
    .kari-warn { margin-left: 6px; font-size: 10px; font-weight: 700; color: #b45309;
      background: #fdecd9; border-radius: 999px; padding: 1px 7px; cursor: help; }
    /* メンバー行の担当メモ・巡回（小さく控えめに） */
    .m-note  { font-size: 10.5px; font-weight: 700; color: #6d28d9; white-space: nowrap; }
    .m-patrol{ font-size: 10.5px; font-weight: 700; padding: 1px 6px; border-radius: 999px;
      background: #e3edf7; color: #2c6ca0; white-space: nowrap; }
    /* 手動編集中の担当メモ入力・巡回数入力 */
    .m-note-inp { flex: 0 0 auto; font-family: inherit; font-size: 10.5px; padding: 1px 4px;
      border: 1px solid var(--brand); border-radius: 6px; width: 66px; }
    .m-patrol-inp { flex: 0 0 auto; font-family: inherit; font-size: 10.5px; padding: 1px 4px;
      border: 1px solid var(--brand); border-radius: 6px; width: 42px; }
    /* メンバー行の兼任（サブ役割）バッジ */
    .m-kenin { font-size: 10.5px; font-weight: 700; padding: 1px 6px; border-radius: 999px;
      background: #efe7fb; color: #6d28d9; white-space: nowrap; }
    /* メンバー行の備考（一言）。控えめなグレーで表示・編集中は入力 */
    .m-remark { font-size: 10.5px; color: #6b7280; white-space: nowrap; overflow: hidden;
      text-overflow: ellipsis; max-width: 150px; }
    .m-remark-inp { flex: 0 0 auto; font-family: inherit; font-size: 10.5px; padding: 1px 4px;
      border: 1px solid var(--brand); border-radius: 6px; width: 100px; }

    /* カード内：割当メンバー／希望者の折りたたみ（雛形の「名前／P」に対応） */
    .cc-members { margin-top: 2px; }
    .mem-toggle {
      border: none; background: none; color: var(--brand-dark); cursor: pointer;
      font-family: inherit; font-size: 12.5px; font-weight: 700; padding: 2px 0; text-align: left;
    }
    .mem-toggle:hover { text-decoration: underline; }
    /* ↓ display:none を既定にし、.open のときだけ開く（hidden属性はflexに負けるため使わない） */
    .mem-list { display: none; margin-top: 6px; border-top: 1px dashed var(--line); padding-top: 6px; flex-direction: column; gap: 3px; }
    .mem-list.open { display: flex; }
    /* 要素が多いので、入り切らない分は下の行へ回す（flex-wrap）＝名前が潰れて縦書きになるのを防ぐ */
    .mem-row { display: flex; align-items: center; gap: 6px; font-size: 11.5px; flex-wrap: wrap; }
    .mem-row .m-no { flex: 0 0 auto; width: 18px; text-align: right; color: var(--muted); font-variant-numeric: tabular-nums; }
    /* 名前は最低幅を確保し、はみ出す分は「…」で省略（1文字ずつ縦に折り返さない） */
    .mem-row .m-name { flex: 1 1 60px; min-width: 60px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .mem-row .m-pos { font-size: 10.5px; font-weight: 700; padding: 1px 7px; border-radius: 6px; background: var(--brand-soft); color: var(--brand-dark); white-space: nowrap; }
    /* 手動編集中のポジション選択プルダウン（選ぶと保存） */
    .mem-row .m-pos-sel { flex: 0 0 auto; font-family: inherit; font-size: 10.5px; font-weight: 700;
      padding: 1px 4px; border: 1px solid var(--brand); border-radius: 6px; background: #fff; color: var(--brand-dark); max-width: 110px; }
    .mem-row .m-lv { font-size: 10.5px; font-weight: 700; padding: 1px 6px; border-radius: 999px; }
    .mem-row .m-lv.new { background: var(--brand-soft); color: var(--brand-dark); }
    .mem-row .m-lv.mid { background: #ece3d4; color: #7a6a58; }
    .mem-row .m-lv.vet { background: var(--ok-soft); color: #15803d; }
    .mem-none { font-size: 12px; color: var(--muted); margin-top: 2px; }
    .cand-toggle { color: #b4530a; }   /* 希望者トグルは少し色を変える */

    /* 自動アサインボタン */
    .auto-btn {
      border: 1px solid var(--brand); background: #fff; color: var(--brand-dark);
      border-radius: 8px; padding: 7px 13px; font-size: 12.5px; font-weight: 700;
      cursor: pointer; font-family: inherit;
    }
    .auto-btn:hover { background: var(--brand-soft); }
    .cc-foot { gap: 8px; flex-wrap: wrap; }

    /* メンバーの種別バッジ（社員・派遣） */
    .m-type { font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 999px; white-space: nowrap; }
    .m-type.emp   { background: #e3edf7; color: #2c6ca0; }   /* 社員 */
    .m-type.haken { background: #efe6f6; color: #6d28d9; }   /* 派遣 */
    /* 確度（Aヨミ/Bヨミ/Cヨミ）の印。⚠ 案件一覧と同じ見た目にそろえる（画面ごとに変えない）。 */
    .ymk { font-size: 11px; font-weight: 700; padding: 0 7px; border-radius: 999px; margin-left: 6px; }
    .ymk.a { background: var(--brand-soft); color: var(--brand-dark); }
    .ymk.b { background: var(--warn-soft);  color: #b45309; }
    .ymk.c { background: #ece3d4;           color: #7a6a58; }

    /* 社員は名前も色を変える（2026-08-28 baba要望）＝ぱっと見でスタッフと見分けられるように。
       色は横に出るバッジと同じにそろえる（社員＝青／派遣＝紫）。 */
    .m-name.emp   { color: #2c6ca0; font-weight: 700; }
    .m-name.haken { color: #6d28d9; font-weight: 700; }
    /* 社員だけまとめて確定にするボタン（2026-09-03 baba要望）。 */
    .fix-emp { margin-left: 6px; border: 1px solid #2c6ca0; background: #eef4fb; color: #2c6ca0;
      border-radius: 999px; padding: 1px 9px; font-size: 10.5px; font-weight: 700;
      cursor: pointer; font-family: inherit; white-space: nowrap; }
    .fix-emp:hover { background: #2c6ca0; color: #fff; }
    /* 保存済みの派遣依頼（2026-09-03）。直す・消すのは派遣一覧から＝ここは見るだけ。 */
    .hk-row { background: #f8f4fd; border-radius: 6px; padding: 2px 4px; }
    .hk-row.off { opacity: .5; }
    .hk-row.off .m-name { text-decoration: line-through; }
    .hk-st { font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 999px; white-space: nowrap; }
    .hk-st.asked { background: var(--warn-soft); color: #8a5a10; }
    .hk-st.fixed { background: var(--ok-soft); color: #15803d; }
    .hk-st.cancelled { background: #ece3d4; color: #7a6a58; }
    /* メンバーを外す × ／ 希望者を入れる ＋追加 */
    /* 同じ日に複数案件でかぶっている人＝赤文字。
       ⚠ 社員の青より後ろに書く＝かぶりの赤を優先する（気づかないと事故になるため）。 */
    .m-name.dup { color: var(--danger); font-weight: 700; }
    .m-x   { color: var(--danger); font-weight: 700; cursor: pointer; padding: 0 4px; }
    .m-x:hover { background: var(--danger-soft); border-radius: 6px; }
    .c-add { color: var(--brand);  font-weight: 700; cursor: pointer; padding: 0 4px; white-space: nowrap; }
    .c-add:hover { text-decoration: underline; }

    /* 希望者の色分け（複数案件希望／カレンダー〇／複数〇） */
    .cand-row.multi-apply { background: #fdecd9; border-radius: 6px; padding: 2px 4px; }  /* 同じ日の複数案件に希望＝取り合い */
    .cand-row.multi-cal   { background: #efe6f6; border-radius: 6px; padding: 2px 4px; }  /* カレンダー〇が複数 */
    .cand-row.cal-one     { background: #e3edf7; border-radius: 6px; padding: 2px 4px; }  /* カレンダー〇 */
    /* 本人からの一言（エントリー時のコメント）。行の下に小さく出す。 */
    .cand-row .cand-note { flex-basis: 100%; font-size: 11px; color: #6b5544; margin-top: 2px; line-height: 1.5; overflow-wrap: anywhere; }
    .cstat { font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 999px; white-space: nowrap; }
    .cstat.apply2 { background: var(--danger-soft); color: #b91c1c; }
    .cstat.cal2   { background: #efe6f6; color: #6d28d9; }
    .cstat.cal1   { background: #e3edf7; color: #2c6ca0; }
    .cstat.only   { background: #ece3d4; color: #7a6a58; }
    .cstat.done   { background: var(--ok-soft); color: #15803d; }
    /* 月間アサイン上限（過重労働防止・一律20件）バッジ */
    .capb { font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 999px; white-space: nowrap; }
    .capb.over { background: var(--danger-soft); color: #b91c1c; }   /* 上限到達・超過 */
    .capb.near { background: #fdecd2; color: #b45309; }              /* 残りわずか */
    /* すでにこの案件のメンバーに入れた希望者＝グレーアウト（二重アサイン防止・残りの人を探しやすく） */
    .cand-row.picked { opacity: 0.45; background: #f1ece4; border-radius: 6px; padding: 2px 4px; }
    .cand-row.picked .m-name { text-decoration: line-through; }
    /* ⚠ その日、**別の案件**にもう入っている人（2026-09-03 baba要望）。
       これまでは押したあとの確認ダイアログでしか分からず、押してから気づいていた。
       赤い枠で囲って、どの案件に入っているかを名前のとなりに出す。 */
    .cand-row.busy { background: #fdecec; border: 1px solid #f3b7b7; border-radius: 6px; padding: 2px 4px; }
    /* ⚠ 印は ⛔ だけ（2026-09-03 baba「幅を取るのでストップマークだけでいい」）。
       どの案件に入っているかは、マウスを乗せたとき（title）に出す。 */
    .cstat.busy { background: var(--danger-soft); color: #b91c1c; padding: 1px 5px; }
    /* 社員はふだんイベントに出ないので、希望者カラムではたたんでおく（2026-09-03 baba要望）。 */
    .cand-emp { margin-top: 6px; border-top: 1px dashed var(--line); padding-top: 4px; }
    .cand-emp > summary { cursor: pointer; font-size: 11px; color: var(--muted, #8a7a6b); list-style: none; padding: 2px 0; }
    .cand-emp > summary::-webkit-details-marker { display: none; }
    .cand-emp > summary::before { content: 'b8 '; }
    .cand-emp[open] > summary::before { content: 'be '; }
    .cand-emp > summary:hover { color: var(--brand-dark, #8a5a33); }
    /* 拠点がちがうので出していない人数（2026-09-03）。黙って消えると原因が分からないため。 */
    .cand-hidden { margin-top: 6px; font-size: 11px; color: var(--muted, #8a7a6b); cursor: help; }

    /* 手動編集の操作 */
    .edit-btn { border: 1px solid var(--line); background: #fff; color: var(--ink);
      border-radius: 8px; padding: 7px 12px; font-size: 12.5px; font-weight: 700; cursor: pointer; font-family: inherit; }
    .edit-btn.on { background: var(--brand); color: #fff; border-color: var(--brand); }
    .add-row { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
    .add-row .mini { border: 1px dashed var(--brand); background: #fff; color: var(--brand-dark);
      border-radius: 8px; padding: 5px 10px; font-size: 12px; font-weight: 700; cursor: pointer; font-family: inherit; }
    .add-row .mini:hover { background: var(--brand-soft); }
    /* メンバーの追加パネル（2026-08-26 baba要望）。
       長いプルダウンをやめ、名前でしぼって**チェックした人をまとめて入れる**形にした。 */
    .pick-box { border: 1px solid var(--brand); border-radius: 10px; background: #fff; padding: 8px; margin-top: 6px; }
    .pick-box .pk-head { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; color: var(--brand-dark); margin-bottom: 6px; }
    .pick-box .pk-head .sp { flex: 1; }
    .pick-box .pk-q { width: 100%; border: 1px solid var(--line); border-radius: 8px; padding: 6px 8px; font-size: 12.5px; font-family: inherit; }
    .pick-box .pk-list { max-height: 190px; overflow-y: auto; margin: 6px 0; border: 1px solid var(--line); border-radius: 8px; }
    .pick-box .pk-item { display: flex; align-items: center; gap: 7px; padding: 4px 8px; font-size: 12.5px; cursor: pointer; }
    .pick-box .pk-item:nth-child(even) { background: #fbf9f6; }
    .pick-box .pk-item:hover { background: var(--brand-soft); }
    .pick-box .pk-item input { width: auto; margin: 0; }
    .pick-box .pk-item .lv { color: var(--muted, #8a7a6b); font-size: 11px; }
    /* ⚠ その日、別の案件にもう入っている人（2026-09-03 baba要望）。一覧の下へ回して赤く出す。 */
    .pick-box .pk-item.busy { background: #fdecec; }
    .pick-box .pk-item.busy:hover { background: #fbdcdc; }
    .pick-box .pk-item .busy-tag { color: #b91c1c; font-size: 11px; font-weight: 700; white-space: nowrap; }
    .pick-box .pk-sep { padding: 4px 8px; font-size: 11px; font-weight: 700; color: #b91c1c; background: #fdecec; border-top: 1px solid #f3b7b7; }
    .pick-box .pk-none { padding: 10px 8px; font-size: 12px; color: var(--muted, #8a7a6b); }
    .pick-box .pk-foot { display: flex; align-items: center; gap: 6px; }
    .pick-box .pk-foot .sp { flex: 1; }
    .pick-box .pk-spot { margin-top: 6px; padding: 8px; background: #fbf6ef; border: 1px dashed var(--brand); border-radius: 8px; font-size: 12px; }

    /* 希望者の色の凡例 */
    .legend { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; font-size: 11.5px; color: var(--ink);
      background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 6px 12px; margin-bottom: 10px; }
    .legend .lg { display: inline-flex; align-items: center; gap: 6px; }
    .legend .sw { width: 14px; height: 14px; border-radius: 4px; display: inline-block; border: 1px solid var(--line); }
    .legend .sw.a { background: #fdecd9; } .legend .sw.c1 { background: #e3edf7; } .legend .sw.c2 { background: #efe6f6; } .legend .sw.only { background: #ece3d4; }
  </style>
@endverbatim
@endpush

@section('content')
      {{-- 案件の備考（表示＋その場編集）の共通部品。見た目と保存先はこの1か所にまとめている --}}
      @include('partials.project_note')
      {{-- 拠点の切替（管理者以上だけ表示。一般社員は自拠点固定＝スイッチは出ない） --}}
      @include('partials.office_switch')
      @if ($officeScope)
        <p class="mock-note" style="background:#fbf6ef;">
          <b>{{ $officeScope }}</b>の案件と、{{ $officeScope }}のスタッフ（希望者）だけを表示しています（{{ $officeScope }}に共有された他拠点の案件も含みます）。
        </p>
      @endif
@verbatim
      <div class="mock-note">
        案件・割当メンバー・希望者・その日の稼働可・件数バッジは、登録済みのデータ（DB）を表示しています。<br>
        <b>※この画面での操作（メンバーの追加・⚡自動アサイン・仮／確定の切替・✓確定にする・📣スタッフに公開・備考の編集）は、押した時点で保存されます。</b>
        追加した人は<b>「仮」で入ります</b>。<b>「✓確定にする」を押すと、そのカードのメンバーは全員「確定」になります</b>（2026-08-26）。<br>
        <b>そのあとで足した人は また「仮」から始まります</b>ので、<b>「✓ 仮の◯名を確定にする」</b>（1人だけなら名前の横の<b>「仮」</b>）を押してください。
        <span style="display:inline-block;">※ 本人の画面に出るのは「確定」の人だけです。</span><br>
        <b>「募集中」の印は「スタッフ公開ボードでエントリーを募っている」という意味</b>で、アサインとは別のことです。
        <b>募集中でも「✓確定にする」は押せます</b>（募集して人が集まってから確定にするのが普通の流れです）。<br>
        <b>「確定」の人数が運営人数に達すると、印は「締切」に変わります</b>＝スタッフの画面でも「締切・満員」になってエントリーできません。
        <b>「仮」の人は数えません</b>（まだ決まっていないので募集を続けます）。<br>
        <b>運営人数が埋まっていなくても「これで足りている」ときは、カードの「🔒 この人数で足りている」を押してください</b>
        （2026-09-01 追加）。募集が締まって<b>「募集中 あと◯名」が消え</b>、
        <b>人数のバーも緑（足りている）になり</b>、その日の<b>「あと◯名」からも外れます</b>＝もう人を足さなくてよいことが、ひと目で分かります。
        数字は「<b>6名（この人数で確定・予定8名）</b>」と出るので、<b>運営人数（セールスが入れた数）も残ります。</b>
        ⚠ <b>「✓確定にする」では募集は締まりません</b>（本当に人が足りなくて、確定にしても募集を続けたい案件があるため）。<br>
        また募集したいときは<b>「＋ 追加募集する」</b>を押してください（<b>公開し直す必要はありません</b>）。<br>
        <b>日付の横のボタンから、その日をまとめて確定・まとめて公開できます</b>（対象がある日だけ出ます。押す前に案件名を出して確認します）。<br>
        <b>日ごとに、その日の案件を横に並べて表示します。</b>同じ日のスタッフは取り合いになるため、各日の「稼働可／割当済／残り」を見ながら割り当てます。
        カードの<b>「📄 案件の詳細 →」</b>で案件の登録・編集画面（時間・会場・運営人数・備考など）、<b>「アサイン画面 →」</b>でその案件のアサイン画面に進みます（案件名を押しても詳細が開きます）。
        <span style="display:inline-block; margin-top:4px;">全体の状況（募集中・要注意スタッフ・確定履歴）は <a href="/assign-dashboard">▣ アサインダッシュボード</a> にまとめています。</span>
      </div>

      <!-- 操作バー -->
      <div class="board-controls">
        <div class="month-nav">
          <label for="fromDate" style="font-size:12.5px; font-weight:600; white-space:nowrap;">表示開始日</label>
          <button type="button" onclick="shiftAnchor(-7)" title="1週間前へ">◀</button>
          <input type="date" id="fromDate" onchange="jumpToDate(this.value)"
                 style="padding:5px 8px; border:1px solid var(--line); border-radius:8px; font-family:inherit; font-size:13px;">
          <button type="button" onclick="shiftAnchor(7)" title="1週間後へ">▶</button>
          <button type="button" class="btn sm" onclick="jumpToDate('')" style="margin-left:4px; width:auto; height:auto; padding:6px 12px; white-space:nowrap; flex:0 0 auto;">今日に戻る</button>
        </div>
        <div class="spacer"></div>
        <select id="stateFilter" onchange="render()">
          <option value="">状態：すべて</option>
          <option value="todo">未着手のみ</option>
          <option value="adj">調整中のみ</option>
          <option value="fix">確定のみ</option>
          <option value="pub">募集中のみ（スタッフに公開ずみ）</option>
          <option value="unpub">まだ募集していないもののみ</option>
        </select>
        <label class="chk"><input type="checkbox" id="mineOnly" onchange="render()"> 自分の担当のみ</label>
        <button class="btn" onclick="openWishlist()" style="margin-left:8px;">👥 スタッフ一覧（別ウィンドウ）</button>
      </div>

      <!-- 希望者の色の凡例 -->
      <div class="legend">
        <span style="font-weight:700;">希望者の色：</span>
        <span class="lg"><span class="sw a"></span> <b>複数〇</b>＝同じ日の複数案件に希望（取り合い）</span>
        <span class="lg"><span class="sw c1"></span> <b>終日〇</b>＝カレンダー〇（その日は稼働可＝どの案件にも入れる）</span>
        <span class="lg"><span class="sw only"></span> <b>この案件</b>＝この案件だけに希望</span>
        <span class="lg"><span class="cstat done" style="border:1px solid var(--line);">✓ アサイン済み</span>＝すでにメンバーに入れた人（グレー表示）</span>
        <span class="muted" style="font-size:11.5px;">※左「メンバー」｜右「希望者」を横並び。列の見出しクリックで各列を開閉。高さは上の「リストの高さ」で全カードまとめて変えられます。メンバー名の右タグ＝他に出ている案件（<span style="color:#b91c1c; font-weight:700;">赤=同日かぶり</span>／<span style="color:#15803d; font-weight:700;">緑=別日（連続起用OK）</span>）。同日かぶりは⚠赤文字。操作ボタン（✎手動編集／⚡自動アサイン／✓確定／📣公開／詳細）は案件名の欄にまとめています。</span>
      </div>

      <!-- リストの高さ切替（案件カードのすぐ上・全カードまとめて） -->
      <div class="lh-bar">
        <span class="lh-lbl">メンバー／希望者リストの高さ：</span>
        <button type="button" class="lh-btn" data-h="compact" onclick="setListHeight('compact')">たたむ</button>
        <button type="button" class="lh-btn active" data-h="normal" onclick="setListHeight('normal')">標準</button>
        <button type="button" class="lh-btn" data-h="all" onclick="setListHeight('all')">ぜんぶ表示</button>
      </div>

      <div id="boardBody" class="lh-normal"></div>
      <div class="empty-note" id="boardEmpty" style="display:none;">条件に合う案件がありません。</div>
@endverbatim
@endsection

@push('scripts')
<!-- 共通の案件データ（全画面で同じ1つのリストを読む） -->
<script src="/ecs/data/cases.js"></script>
{{-- 凍結モック /ecs/data/people.js の読み込みはやめた（2026-08-24）。
     「名簿から追加…」が架空の名簿（ECS_PEOPLE）を並べていて、選ぶと
     その架空の人のIDで本物のアサインが保存される＝人の取り違えが起きる状態だった。
     いまは下の ECS_ROSTER（DBの名簿）を使う。 --}}
<!-- DBのスタッフ名一覧（NAME_POOL の単一ソース）。空のときは下のべた書きにフォールバック。 -->
<script>window.ECS_STAFF_POOL = @json($staffPool);</script>
<!-- 「＋社員を追加」「＋スタッフを追加」に出す人（DBが元・五十音順・退職者は除く） -->
<script>window.ECS_ROSTER = @json($roster ?? []);</script>
<!-- DBのボード用案件＋割当メンバー（実データ）。空のときは見本cases.jsにフォールバック。 -->
<script>window.ECS_BOARD_CASES = @json($boardCases ?? []);</script>
<!-- DBに案件があるか（拠点で絞って0件になっても見本データに戻さないための旗）。 -->
<script>window.ECS_USINGDB = @json($usingDb ?? null);</script>
<!-- 希望者カラム用：その日に稼働可/希望のスタッフ（off→一覧）と、今月のアサイン件数（名前→件数）。 -->
<script>window.ECS_BOARD_AVAIL = @json($boardAvail ?? []);</script>
{{-- 拠点がちがうので希望者に出していない人数（off → 人数）。
     ⚠ 黙って消すと「終日〇を出したのに出てこない」になるので、理由を画面に出す（2026-09-03）。 --}}
<script>window.ECS_BOARD_AVAIL_HIDDEN = @json($boardAvailHidden ?? []);</script>
<script>window.ECS_BOARD_MONTH = @json($boardMonth ?? []);</script>
<!-- 表示の基準日（先頭の日）。日付計算の起点・日付ピッカーの初期値に使う。 -->
<script>window.ECS_BOARD_ANCHOR = @json($anchor ?? null);</script>
<!-- ポジション編集：選択肢（役割コードの正本）＋保存先＋CSRF。メンバーのポジションをDB(assignments)に保存する。 -->
<script>
  window.ECS_ROLE_OPTIONS = @json($roleOptions ?? []);
  // 担当メモ（軍師・サポ等）の入力候補。datalist に流し込む。
  window.ECS_NOTE_OPTIONS = @json($noteOptions ?? []);
  window.ECS_QUICK_URL = '/entries/assign';
  // 今この画面が見ている拠点（管理者が全拠点で見ているときは空）。公開のときに「違う拠点は触らない」保険として送る。
  window.ECS_OFFICE_SCOPE = @json($officeScope ?? null);
  window.ECS_CSRF = '{{ csrf_token() }}';
</script>
@verbatim
<script>
  // ===== 案件データ（共通リスト data/cases.js から作る）=====
  // off    … 今日から何日後に開催か／cat … 現場種別／need … 必要人数／filled … 割当済み
  // state  … todo(未着手) / adj(調整中) / fix(確定) / pub(公開済)
  // pos    … 主要ポジションの充足ランプ ／ mine … 自分(baba)担当か
  // ※ボードは「近い日（今日〜3週間先）の本番・予備日」を日ごとに並べる。過去・下書き・
  //   遠い月の案件は出さない（それらは案件一覧／スタッフ画面で見る）。同じ1つのデータから作る。
  // DBのボードデータ（割当メンバー込み）があればそれを使う。空なら見本cases.jsで動かす（フォールバック）。
  // ※ 旗（ECS_USINGDB）が立っていれば、DBの結果が0件でも見本には戻さない（拠点で絞った結果を正しく「0件」と見せる）。
  const USING_DB = (window.ECS_USINGDB !== undefined && window.ECS_USINGDB !== null)
    ? !!window.ECS_USINGDB
    : !!(window.ECS_BOARD_CASES && window.ECS_BOARD_CASES.length);
  const ECS_BOARD = USING_DB ? (window.ECS_BOARD_CASES || []) : null;
  const cases = (ECS_BOARD || ECS_CASES)
    .filter(c => !c.archived && !c.draft && c.off >= 0 && c.off <= 21)
    .map(c => ({
      id:c.id, off:c.off, name:c.name, contentMissing:c.contentMissing, client:c.client, cat:c.cat,
      need:c.need, filled:c.filled, state:c.state, mine:c.mine,
      // ⚠ 「案件の進み具合(stat)」と「募集中か(pubOn)」は別のこと。
      //   ここで詰め替えを忘れると、ボタンの出し分けが効かなくなる。
      stat:c.stat, pubOn:c.pubOn,
      yomi:c.yomi,   // 確度（Aヨミ/Bヨミ/Cヨミ）。詰め替え忘れるとカードに出ない。
      needStaff:c.needStaff,   // スタッフ画面で使っている必要人数（未入力なら既定）。締切の判定に使う。
      meet:c.meet, leave:c.leave, enter:c.enter, evStart:c.evStart, evEnd:c.evEnd,
      place:c.place, placeShort:c.placeShort, meetPlace:c.meetPlace,
      note:c.note,   // 案件の備考（見落とすと事故るのでカードに出す）
      // ⚠ 応募者（エントリー）。ここで詰め替え忘れると「希望者」欄に誰も出ない
      //   （2026-08-21 baba指摘。/entries と /pickup では出るのにこの画面だけ出なかった）。
      applicants:(c.applicants||[]).map(a => ({ id:a.id, name:a.name, lv:a.lv, pos:a.pos, roleCode:a.roleCode, note:a.note })),
      tags:(c.tags||[]).slice(), pos:(c.pos||[]).map(p => p.slice()),
      // 割当メンバー：DBボードならその実データ、見本なら後で candPool から作る（下の forEach）。
      // note＝担当メモ（軍師/サポ等）・patrol＝巡回数。マップで捨てると表示できないので保持する。
      assigned:(c.assigned||[]).map(m => ({ name:m.name, lv:m.lv, pos:m.pos, type:m.type, id:m.id, roleCode:m.roleCode, roleCode2:m.roleCode2, status:m.status, note:m.note, patrol:m.patrol, remark:m.remark }))
    }));

  // 備考をこの画面で直したときは、持っているデータにも書き戻す（再描画で古い備考に戻らないように）。
  window.ecsNoteApplied = function (id, note) {
    const c = cases.find(z => z.id === id);
    if (c) c.note = note;
  };

  // 各日の「稼働可スタッフ数」（仮）。off → その日に稼働可と出している人数。
  // ※off12は「運動会3日目＋水合戦＋縁日」で割当済が稼働可を超える＝重複警告が出る例。
  //   ボードに出る日のうち未設定の日は既定(26名)を使う。
  const dayAvailMap = { 9:20, 10:30, 11:28, 12:30, 14:28, 16:26, 17:30, 18:24, 19:22 };
  const dayAvail = new Proxy(dayAvailMap, { get:(t,k)=> (k in t ? t[k] : 26) });

  // 案件の短縮名（メンバーが「他にどの案件に出ているか」を小さく表示するのに使う）
  const SHORT = { undo_setup:'設営', undo_d1:'運1', undo_d2:'運2', undo_d3:'運3', mizu:'水', enni1:'縁日',
                  shinkan:'新歓', shinkan_yobi:'新歓予', konshin:'懇親', hyosho:'表彰',
                  fes_reha:'リハ', mizu_yobi:'水予' };
  function shortOf(id){ return SHORT[id] || id; }
  // 名前 → その人が割り当てられている案件idの一覧（同日かぶり／複数日の連続起用の把握に使う）
  function assignmentMap(){
    const m = {};
    cases.forEach(x => x.assigned.forEach(mem => { (m[mem.name] = m[mem.name] || []).push(x.id); }));
    return m;
  }

  // ===== 割当メンバーの仮データ生成 =====
  // 雛形（東京アサイン表）の「NO／名前／P（ポジション）」に合わせ、
  // 各案件カードに割り当て済みメンバーの名前＋ポジションを出せるようにする。
  // 割当メンバーの名前プール。DB（people のスタッフ）から渡された一覧を優先し、
  // 空のとき（未シード等）だけ下のべた書きにフォールバックする＝名簿の単一ソース化。
  const NAME_POOL = (window.ECS_STAFF_POOL && window.ECS_STAFF_POOL.length) ? window.ECS_STAFF_POOL : [
    '高橋 由依','伊藤 健','渡辺 さくら','鈴木 美咲','山田 涼','松本 美優','井上 大輝','木村 拓海',
    '林 美月','清水 陽','森 結菜','佐藤 健太','池田 莉子','橋本 颯','石川 葵','近藤 樹',
    '山本 翔太','中村 彩','小林 蓮','加藤 結衣','吉田 大和','斎藤 楓','岡田 悠','前田 凛',
    '藤田 海','後藤 蓮','長谷川 葵','村上 陽菜','遠藤 樹','坂本 美羽','青木 駿','西村 杏',
    '福田 翼','太田 七海','三浦 一','藤井 結','金子 蒼','中島 心','原田 楓','和田 凪'
  ];
  // ポジションの並び（雛形の P 列に近い順：D→MC→OP→FC…）
  const POS_PATTERN = ['D','MC','OP','FC','FC','FC','受付','CK','軍師・サポーター','FC','受付','FC','CK','FC','受付','FC','FC','FC','受付','CK'];
  // 案件IDから安定した開始位置を作る（同じ案件はいつ開いても同じ名前になる）
  function seedOf(id){ let s = 0; for (const ch of id) s += ch.charCodeAt(0); return s; }

  // その案件の「希望者プール」（必要数より少し多め）。割当メンバーはこの先頭から取る＝
  // 自動アサインで選ばれる人と、カードに出す希望者が食い違わないようにする。
  const LVS = ['vet','mid','mid','new','mid','vet','new','mid','new','vet','mid','new'];
  const lvLabel = { new:'新人', mid:'中堅', vet:'ベテラン' };
  function candPool(c){
    const seed  = seedOf(c.id);
    const total = c.need + 5;
    const arr = [];
    for (let i = 0; i < total; i++){
      arr.push({ no:i+1, name: NAME_POOL[(seed + i) % NAME_POOL.length], lv: LVS[i % LVS.length], pos: POS_PATTERN[i] || 'FC' });
    }
    return arr;
  }
  // 各案件の「割当メンバー」を実体（配列）で持つ。
  // DBボード（ECS_BOARD）のときは上のmapで実データ（assignments由来）を入れ済み＝そのまま使う。
  // 見本(cases.js)フォールバック時だけ、希望者プールの先頭 filled 名で合成する。
  // type: 'staff'(スタッフ) / 'emp'(社員) / 'haken'(派遣)
  if (!ECS_BOARD) {
    cases.forEach(c => {
      c.assigned = candPool(c).slice(0, c.filled).map(m => ({ name:m.name, lv:m.lv, pos:m.pos, type:'staff' }));
    });
  }
  function filledOf(c){ return c.assigned.length; }
  // メンバーの並び順＝上から D → (SD) → MC → OP → FC → CK → 軍師/サポ → 受付 → その他。
  const ECS_ROLE_RANK = { D:0, SD:1, MC:2, OP:3, FC:4, CK:5, SP:6, GUN:6, RP:7, UKE:7 };
  function roleRank(code){ return (code && (code in ECS_ROLE_RANK)) ? ECS_ROLE_RANK[code] : 99; }
  // 表示用にソートしたコピーを返す（c.assigned 自体は触らない＝削除は id で行う）。
  function buildMembers(c){
    return c.assigned.slice().sort((a, b) => {
      const d = roleRank(a.roleCode) - roleRank(b.roleCode);
      if (d !== 0) return d;
      return roleRank(a.roleCode2) - roleRank(b.roleCode2);   // 主役割が同じなら兼任で並べる
    });
  }
  function typeBadge(type){
    if (type === 'emp')   return '<span class="m-type emp">社員</span>';
    if (type === 'haken') return '<span class="m-type haken">派遣</span>';
    return '';
  }

  // ===== 月間アサイン上限（過重労働防止・一律20件／設計書11章F・実装仕様書8章）=====
  // モックなので「今月の件数」＝(ボード外で既にアサイン済みの下敷き)＋(このボード上で割当済みの件数)。
  // 下敷きは一部の名前を高めに置き、上限まわりの挙動が見えるようにしている。
  const MONTH_CAP = 20;
  const MONTH_BASE = { '高橋 由依':19, '伊藤 健':18, '渡辺 さくら':17, '清水 陽':16, '松本 美優':15 };
  function baseCountOf(name){
    // DBボードのとき：今月のアサイン件数は実データ（ECS_BOARD_MONTH）から。
    if (ECS_BOARD) return (window.ECS_BOARD_MONTH && window.ECS_BOARD_MONTH[name]) || 0;
    if (name in MONTH_BASE) return MONTH_BASE[name];
    return seedOf(name) % 13;   // それ以外は名前から決まる 0〜12
  }
  // amap（名前→出ている案件id配列）があれば渡す。無ければその場で集計。
  function monthCountOf(name, amap){
    // DBは ECS_BOARD_MONTH にボード期間の件数が入っている＝二重に足さない。
    if (ECS_BOARD) return baseCountOf(name);
    const board = ((amap || assignmentMap())[name] || []).length;
    return baseCountOf(name) + board;
  }
  // 行に付ける上限バッジ（上限到達=赤／残り2以内=橙／それ以外は出さない）
  function capBadge(name, amap){
    const n = monthCountOf(name, amap);
    if (n >= MONTH_CAP)     return `<span class="capb over" title="今月のアサインが上限(${MONTH_CAP}件)に達しています">今月${n}/${MONTH_CAP} 上限</span>`;
    if (n >= MONTH_CAP - 2) return `<span class="capb near" title="今月のアサインが上限(${MONTH_CAP}件)に近づいています">今月${n}/${MONTH_CAP}</span>`;
    return '';
  }

  // ===== その日の希望者（カレンダー〇・案件応募）=====
  // applied: その日のどの案件に応募(希望)したか ／ cal: カレンダーの〇の数(0/1/2)
  function dayPeople(off, dayCases){
    // DBボードのとき：その日の各案件への応募者（c.applicants）＋その日の稼働可スタッフ（ECS_BOARD_AVAIL）を
    // 名前で集約する。applied＝その日のどの案件に応募したか／cal＝その日に稼働可〇を出しているか。
    if (ECS_BOARD) {
      const byName = {};
      dayCases.forEach(c => (c.applicants || []).forEach(a => {
        const e = byName[a.name] || (byName[a.name] = { id:a.id, name:a.name, lv:a.lv, pos:a.pos, roleCode:a.roleCode, emp:!!a.emp, applied:[], cal:false, notes:{} });
        if (a.id && !e.id) e.id = a.id;                 // id を取りこぼさない（DB保存に必要）
        if (a.roleCode && !e.roleCode) e.roleCode = a.roleCode;
        if (a.emp) e.emp = true;                        // 社員の印（希望者カラムでたたむのに使う）
        if (!e.applied.includes(c.id)) e.applied.push(c.id);
        if (!e.notes) e.notes = {};
        if (a.note) e.notes[c.id] = a.note;             // 本人が応募時に書いた一言（案件ごと）
      }));
      ((window.ECS_BOARD_AVAIL && window.ECS_BOARD_AVAIL[off]) || []).forEach(a => {
        const e = byName[a.name] || (byName[a.name] = { id:a.id, name:a.name, lv:a.lv, pos:a.pos, roleCode:a.roleCode, emp:!!a.emp, applied:[], cal:false, notes:{} });
        if (a.id && !e.id) e.id = a.id;
        if (a.roleCode && !e.roleCode) e.roleCode = a.roleCode;
        if (a.emp) e.emp = true;
        e.cal = true;
      });
      return Object.values(byName);
    }
    const ids = dayCases.map(c => c.id);
    const totalNeed = dayCases.reduce((s,c) => s + c.need, 0);
    const count = Math.min(NAME_POOL.length, totalNeed + 6);
    const seed  = (off * 7) % NAME_POOL.length;
    const arr = [];
    for (let i = 0; i < count; i++){
      const applied = [ ids[i % ids.length] ];
      if (ids.length > 1 && i % 4 === 0) applied.push(ids[(i + 1) % ids.length]); // 4人に1人は同日の別案件にも希望
      const cal = (i % 3 === 0);   // カレンダーで〇（その日の稼働可）を出している人
      arr.push({
        name: NAME_POOL[(seed + i) % NAME_POOL.length],
        lv:   LVS[i % LVS.length],
        pos:  POS_PATTERN[i] || 'FC',
        applied: [...new Set(applied)],
        cal
      });
    }
    return arr;
  }
  // 希望者1人の色分け（短いタグ）
  //  複数〇＝同じ日の複数案件に希望（取り合い）／終日〇＝カレンダー〇（その日は稼働可＝どの案件にも入れる）
  //  この案件＝この案件だけに希望
  function candStatus(p){
    if (p.applied.length >= 2) return { cls:'multi-apply', tag:'<span class="cstat apply2">複数〇</span>' };
    if (p.cal)                 return { cls:'cal-one',     tag:'<span class="cstat cal1">終日〇</span>' };
    return { cls:'', tag:'<span class="cstat only">この案件</span>' };
  }
  // 列（メンバー／希望者）の開閉
  function toggleCol(el){
    const col = el.closest('.cc-col');
    const list = col.querySelector('.col-list');
    list.classList.toggle('hide');
    const ar = el.querySelector('.cl-arrow');
    if (ar) ar.textContent = list.classList.contains('hide') ? '▸' : '▾';
  }

  // 折りたたみ開閉（.open クラスで切替。hidden属性はCSSのdisplay:flexに負けるため使わない）
  function toggleList(elId, btn){
    const el = document.getElementById(elId);
    if (!el) return;
    el.classList.toggle('open');
    const open = el.classList.contains('open');
    btn.textContent = open ? btn.textContent.replace('▸','▾') : btn.textContent.replace('▾','▸');
  }
  function toggleMem(id, btn){ toggleList('mem-'  + id, btn); }
  function toggleCand(id, btn){ toggleList('cand-' + id, btn); }

  // ===== 自動アサイン／手動編集 =====
  const editing = new Set();   // 手動編集モードにしている案件id
  function toggleEdit(id){ editing.has(id) ? editing.delete(id) : editing.add(id); render(); }

  // 同じ日の「他の案件」で割当済みの名前（＝かぶり防止に使う）
  function takenSameDay(c){
    return new Set(
      cases.filter(x => x.off === c.off && x.id !== c.id)
           .flatMap(x => x.assigned.map(m => m.name))
    );
  }

  // 同じ日の「他の案件」に入っている人 → 入っている案件名の一覧。
  // ⚠ 2026-09-03 baba要望「すでにアサインされてる人はもっとわかりやすく」。
  //   これまでは追加を押したあとの確認ダイアログでしか分からず、**押してから気づいて**いた。
  //   選ぶ前に「⛔ 別案件」と、どの案件かを出すために使う。
  function takenSameDayWhere(c){
    const m = new Map();
    cases.filter(x => x.off === c.off && x.id !== c.id).forEach(x => {
      x.assigned.forEach(a => {
        if (!m.has(a.name)) m.set(a.name, []);
        const arr = m.get(a.name);
        if (arr.indexOf(x.name) === -1) arr.push(x.name);
      });
    });
    return m;
  }

  // 「⛔ 別案件」の見せ方（希望者カラムと追加パネルで同じ文言にする）。
  function busyTitle(name, where){
    return name + ' さんは、この日すでに「' + where.join('」「') + '」に入っています。';
  }

  // 自動アサイン＝希望者から、同じ日にかぶらない人を必要数ぶん埋める。
  // DBボード（ECS_BOARD）＝この案件の実際の希望者（id付き）から選び、1人ずつ本物のアサインとして保存する
  //   ＝追加後すぐ担当/巡回/備考を編集できる。見本フォールバックのときだけ従来の合成プールを使う。
  function autoAssign(id){
    const c = cases.find(x => x.id === id);
    if (!c) return;
    if (filledOf(c) >= c.need) { alert('この案件はすでに必要人数を満たしています。'); return; }
    const taken  = takenSameDay(c);
    const amap   = assignmentMap();
    const already = new Set(c.assigned.map(m => m.name));
    const room = c.need - filledOf(c);   // 追加できる残り枠

    if (ECS_BOARD) {
      // この案件の希望者（応募＋当日稼働可）＝id付き。すでにメンバー・同日かぶり・今月上限は除く。
      // 重複防止は「id」でも見る（同姓同名や表記ゆれでも二重アサインしないため）。
      const assignedIds = new Set(c.assigned.map(m => m.id).filter(Boolean));
      const dayCases = cases.filter(z => z.off === c.off);
      const seenPick = new Set();   // このクリック内で同じ人を2回入れない
      const pool = dayPeople(c.off, dayCases)
        .filter(p => p.applied.includes(c.id) || p.cal)
        .filter(p => p.id && !assignedIds.has(p.id) && !already.has(p.name) && !taken.has(p.name)
                     && monthCountOf(p.name, amap) < MONTH_CAP)
        .filter(p => { if (seenPick.has(p.id)) return false; seenPick.add(p.id); return true; });
      const picked = pool.slice(0, room);
      if (c.state === 'todo') c.state = 'adj';
      if ((c.stat || c.state) === 'todo') c.stat = 'adj';
      // 基本1案件につきDは1名。すでにDがいる／2人目以降のDは役割を付けずに追加（あとで手動指定）。
      let dCount = c.assigned.filter(m => m.roleCode === 'D').length;
      picked.forEach(p => {
        let rc = p.roleCode || '';
        if (rc === 'D') { if (dCount >= 1) rc = ''; else dCount++; }
        const posLabel = (window.ECS_ROLE_OPTIONS || {})[rc] || p.pos || rc;
        c.assigned.push({ id: p.id, name: p.name, lv: (p.lv || '-'), pos: posLabel, roleCode: rc, roleCode2: '', note: '', patrol: null, remark: '', status: '仮', type: 'staff' });
        fetch(window.ECS_QUICK_URL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF, 'Accept': 'application/json' },
          body: JSON.stringify({ project_id: c.id, staff_id: p.id, action: 'assign', role: rc, status: '仮' })
        }).then(r => r.json()).then(res => { if (!(res && res.ok)) render(); }).catch(() => render());
      });
      render();
      const total = filledOf(c);
      if (picked.length === 0) {
        alert('⚡ 自動で足せる希望者がいませんでした。\n（この案件の希望者が、すでにメンバー／同日かぶり／今月上限のいずれかです）\n→「手動編集」で名簿・社員・派遣から足してください。');
      } else if (total < c.need) {
        alert('⚡ 自動アサインしました（希望者から ' + picked.length + '名）。\n必要 ' + c.need + '名に ' + (c.need - total) + '名 不足しています。\n→「手動編集」で名簿・社員・派遣を足して補ってください。');
      } else {
        alert('⚡ 自動アサインしました（希望者から ' + picked.length + '名）。\n「' + c.name + '」の必要人数を満たしました。担当や備考はこのあと手動編集で入れられます。');
      }
      return;
    }

    // 見本フォールバック（DBでない）＝従来の合成プールから（保存はしない飾り）。
    const picked = candPool(c)
      .filter(m => !taken.has(m.name) && monthCountOf(m.name, amap) < MONTH_CAP)
      .slice(0, c.need);
    c.assigned = picked.map(m => ({ name:m.name, lv:m.lv, pos:m.pos, type:'staff' }));
    if (c.state === 'todo') c.state = 'adj';
    if ((c.stat || c.state) === 'todo') c.stat = 'adj';
    render();
    if (picked.length < c.need) {
      alert('⚡ 自動アサインしました（モック）。\n「' + c.name + '」に ' + picked.length + '名を割り当てました（必要 ' + c.need + '名に ' + (c.need - picked.length) + '名 不足）。');
    } else {
      alert('⚡ 自動アサインしました（モック）。\n「' + c.name + '」に希望者から ' + c.need + '名を割り当てました。');
    }
  }
  // メンバーを外す（id基準＝並び替えの影響を受けない）。本物のアサイン（id有）はDBからも外す。
  function removeMember(caseId, key){
    const c = cases.find(x => x.id === caseId);
    if (!c) return;
    key = decodeURIComponent(key || '');
    const i = c.assigned.findIndex(m => String(m.id || m.name) === key);
    if (i < 0) return;
    const m = c.assigned[i];
    c.assigned.splice(i, 1);
    render();
    if (m.id) {   // DBに保存済みのメンバーは assignments からも削除する
      fetch(window.ECS_QUICK_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF, 'Accept': 'application/json' },
        body: JSON.stringify({ project_id: caseId, staff_id: m.id, action: 'unassign' })
      }).then(r => r.json()).then(res => { if (!(res && res.ok)) render(); }).catch(() => render());
    }
  }
  // 希望者をメンバーに入れる（同じ日に他案件とかぶる場合は確認）。
  // id があれば（DBボード）＝本物のアサインとして quickToggle で保存し、担当/巡回/備考も編集できる形で足す。
  // id が無いとき（見本フォールバック）は従来どおり簡易表示のまま。
  function addCandidate(caseId, id, name, lv, pos, roleCode){
    name = decodeURIComponent(name || '');
    pos = decodeURIComponent(pos || '');
    const c = cases.find(x => x.id === caseId);
    if (!c) return;
    if (c.assigned.some(m => m.name === name)) { alert(name + ' さんはすでにこの案件のメンバーに入っています。'); return; }
    if (takenSameDay(c).has(name)) {
      if (!confirm(name + ' さんは同じ日の別の案件にすでに割り当てられています。\nそれでも追加しますか？（かぶりは赤文字で表示されます）')) return;
    }
    // 月間アサイン上限（過重労働防止・一律20件）のチェック
    const mc = monthCountOf(name);
    if (mc >= MONTH_CAP) {
      if (!confirm(name + ' さんは今月のアサインがすでに上限の ' + MONTH_CAP + '件 に達しています（現在 ' + mc + '件）。\n過重労働防止のための上限を超えます。それでも追加しますか？')) return;
    }

    if (!id) {   // 見本フォールバック（id なし）＝従来どおり簡易表示
      c.assigned.push({ name, lv, pos, type:'staff' });
      render();
      return;
    }

    // 本物のアサイン：id・roleCode・status を持たせる＝追加直後から担当/巡回/備考を編集・保存できる。
    const rc = roleCode || '';
    const posLabel = (window.ECS_ROLE_OPTIONS || {})[rc] || pos || rc;
    const m = { id: id, name: name, lv: (lv || '-'), pos: posLabel, roleCode: rc, roleCode2: '', note: '', patrol: null, remark: '', status: '仮', type: 'staff' };
    c.assigned.push(m);
    if (c.state === 'todo') c.state = 'adj';
    if ((c.stat || c.state) === 'todo') c.stat = 'adj';
    fetch(window.ECS_QUICK_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF, 'Accept': 'application/json' },
      body: JSON.stringify({ project_id: caseId, staff_id: id, action: 'assign', role: rc, status: '仮' })
    })
      .then(r => r.json())
      .then(res => { if (!(res && res.ok)) { alert('メンバー追加の保存に失敗しました。' + (res && res.message ? '\n' + res.message : '')); } })
      .catch(() => { alert('通信エラーでメンバー追加を保存できませんでした。'); });
    render();
  }
  // ===== ボード上で完結：確定・公開 =====
  // ⚠ 2026-08-21 まで、この2つは「画面の色が変わるだけ」で何も保存していなかった（見せかけのまま残っていた）。
  //   いまは本物：確定＝案件のアサイン状況を保存／公開＝スタッフ公開ボードと同じ処理を呼ぶ。
  function markFix(id){
    const c = cases.find(x => x.id === id);
    if (!c) return;
    if (filledOf(c) < c.need
      && !confirm('必要人数（' + c.need + '名）に対して ' + filledOf(c) + '名です。\nこのまま確定にしますか？')) return;
    // ⚠ 確定にするだけ。公開（staff_published）は触らない＝「確定」と「公開」は別の操作。
    //   すでに募集中の案件は 'pub' のままにする（上書きすると募集中の印が消えてしまう）。
    if (!USING_DB){ c.stat = 'fix'; if (!c.pubOn) c.state = 'fix'; render(); return; }   // 見本データのときは画面だけ
    fetch('/projects/cells', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF, 'Accept': 'application/json' },
      body: JSON.stringify({ id: id, status: '確定' })
    })
      .then(r => r.ok ? r.json() : Promise.reject(r.status))
      // メンバーも全員「確定」にする（2026-08-26 baba要望）。
      // ⚠ 案件だけ確定にしても、メンバーが「仮」のままではスタッフの画面に出ない。
      .then(() => confirmAllMembers(c))
      .then(() => { c.stat = 'fix'; if (!c.pubOn) c.state = 'fix'; render(); })
      .catch(e => alert('確定にできませんでした（' + e + '）。もう一度お試しください。'));
  }

  // この案件のメンバー（仮の人）を全員「確定」にする。保存はサーバーで1回だけ（1人ずつ通信しない）。
  // すでに確定の人は触らない＝確定した日時の記録を上書きしないため。
  // 公開したあとで足した人は「仮」から始まるので、名前の横の「仮」を押して確定にする。
  function confirmAllMembers(c){
    const kari = c.assigned.filter(m => m.status === '仮' && m.id);
    if (!kari.length) return Promise.resolve(0);
    return fetch('/projects/confirm-members', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF, 'Accept': 'application/json' },
      body: JSON.stringify({ project_id: c.id })
    })
      .then(r => r.json())
      .then(res => {
        if (!res || !res.ok) {
          alert('メンバーを確定にできませんでした。' + (res && res.message ? '\n' + res.message : ''));
          return 0;
        }
        kari.forEach(m => { m.status = '確定'; });
        return res.confirmed || kari.length;
      })
      .catch(() => { alert('通信エラーでメンバーを確定にできませんでした。'); return 0; });
  }
  // 社員だけまとめて確定にする（2026-09-03 baba要望）。
  // ⚠ 社員はスタッフ画面への公開に関係ないので、先に確定にしてしまいたい、が背景。
  //   スタッフは触らない＝公開の段取りを崩さないため。入口は confirm-members と同じ1つ（only=employee）。
  function fixEmployees(id){
    const c = cases.find(x => x.id === id);
    if (!c) return;
    const kari = c.assigned.filter(m => m.status === '仮' && m.type === 'emp' && m.id);
    if (!kari.length) { alert('「仮」の社員はいません。'); return; }
    // ⚠ 確認文の改行は String.fromCharCode(10) で作る。
    //   「\」＋「n」で書くと、置換の途中で本物の改行に化けてこの画面のJSが丸ごと死ぬことがある
    //   （2026-09-03 にここで実際にやった。過去にも本番で同じ事故がある）。
    const NL = String.fromCharCode(10);
    if (!confirm('「' + c.name + '」の社員 ' + kari.length + '名を「確定」にします。' + NL
      + '（' + kari.map(m => m.name).join('・') + '）' + NL + NL
      + 'スタッフは変わりません。よろしいですか？')) return;
    if (!USING_DB){ kari.forEach(m => { m.status = '確定'; }); render(); return; }   // 見本データのときは画面だけ

    fetch('/projects/confirm-members', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF, 'Accept': 'application/json' },
      body: JSON.stringify({ project_id: c.id, only: 'employee' })
    })
      .then(r => r.json())
      .then(res => {
        // ⚠ 失敗をだまって流さない。「確定にしたつもりで仮のまま」がいちばん困る。
        if (!res || !res.ok) { alert('社員を確定にできませんでした。' + (res && res.message ? NL + res.message : '')); return; }
        kari.forEach(m => { m.status = '確定'; });
        render();
        alert('✓ 社員 ' + (res.confirmed || kari.length) + '名を「確定」にしました。');
      })
      .catch(() => alert('通信エラーで社員を確定にできませんでした。'));
  }

  // 公開ずみの案件で、あとから足した「仮」の人をまとめて確定にする（2026-08-28 baba指摘）。
  // ⚠ スタッフの画面に出るのは「確定」の人だけ。仮のままだと本人に伝わらない。
  //   公開のときと同じ入口（confirmAllMembers）を使う＝確定のやり方を2つ作らない。
  function fixMembers(id){
    const c = cases.find(x => x.id === id);
    if (!c) return;
    const kari = c.assigned.filter(m => m.status === '仮');
    if (!kari.length) { alert('「仮」の人はいません。'); return; }
    if (!confirm('「' + c.name + '」の「仮」の ' + kari.length + '名を「確定」にします。\n'
      + '確定にすると、その人の画面にこの案件が出ます。\n\nよろしいですか？')) return;
    if (!USING_DB){ kari.forEach(m => { m.status = '確定'; }); render(); return; }  // 見本データのときは画面だけ
    confirmAllMembers(c).then(n => {
      render();
      if (n) alert('✓ メンバー ' + n + '名を「確定」にしました（本人の画面に出ます）。');
    });
  }
  function markPub(id){
    const c = cases.find(x => x.id === id);
    if (!c) return;
    const kari = c.assigned.filter(m => m.status === '仮').length;
    const short = filledOf(c) < c.need
      ? '\n（必要 ' + c.need + '名に対して ' + filledOf(c) + '名です。人数が足りなくても公開できます）' : '';
    if (!confirm('「' + c.name + '」をスタッフに公開します。' + short
      + '\n\n公開すると、この案件が募集としてスタッフ画面に出ます。'
      + (kari ? '\nいま「仮」の ' + kari + '名も、あわせて「確定」にします（確定にしないと本人の画面に出ません）。' : '')
      + '\n公開してよろしいですか？')) return;
    if (!USING_DB){ c.state = 'pub'; c.pubOn = true; render(); return; }   // 見本データのときは画面だけ
    // 公開の処理はスタッフ公開ボードと同じ入口を使う（staff_published を立てる＝編集履歴にも残る）。
    const body = { ids: [id], publish: true };
    if (window.ECS_OFFICE_SCOPE) body.office = window.ECS_OFFICE_SCOPE;
    fetch('/assign-publish/set', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF, 'Accept': 'application/json' },
      body: JSON.stringify(body)
    })
      .then(r => r.ok ? r.json() : Promise.reject(r.status))
      .then(res => {
        if (!res || !res.updated){ alert('公開できませんでした（他の拠点の案件か、すでに公開済みです）。'); return; }
        // 公開と同時にメンバーも全員「確定」にする（2026-08-26 baba要望）。
        // ⚠ 公開しても仮の人は本人の画面に出ないため、ここで揃える。
        confirmAllMembers(c).then(n => {
          c.state = 'pub';
          c.pubOn = true;
          render();
          alert('📣 スタッフに公開しました。'
            + (n ? '\nメンバー ' + n + '名を「確定」にしました（本人の画面に出ます）。' : '')
            + '\n\nこのあとで足した人は「仮」から始まります。名前の横の「仮」を押すと確定になります。');
        });
      })
      .catch(e => alert('公開できませんでした（' + e + '）。もう一度お試しください。'));
  }
  // 社員を足す（人手不足時）
  function addEmployee(caseId){
    const c = cases.find(x => x.id === caseId);
    if (!c) return;
    const name = prompt('追加する社員の名前を入力してください（モック）', '');
    if (!name) return;
    c.assigned.push({ name: name.trim(), lv:'-', pos:'FC', type:'emp' });
    render();
  }
  // 派遣を足す（人手不足時）
  // ＋派遣（2026-09-03 baba要望でDB保存に変えた）。
  // ⚠ それまでは画面の配列に足すだけで**保存していなかった**＝読み込み直すと消え、
  //   「どの案件に派遣を頼んだか」の記録がどこにも無かった。一覧は /dispatch-list。
  function addHaken(caseId){
    const c = cases.find(x => x.id === caseId);
    if (!c) return;
    const name = prompt('派遣先（派遣会社名など）を入れてください。', '');
    if (!name || !name.trim()) return;
    const n = prompt('何名お願いしますか。（数字）', '1');
    if (n === null) return;
    const role = prompt('役割があれば入れてください（例：受付）。無ければ空のままでOKです。', '');
    if (role === null) return;

    const body = new URLSearchParams();
    body.append('project_id', caseId);
    body.append('agency', name.trim());
    body.append('count', String(Math.max(1, parseInt(n, 10) || 1)));
    if (role.trim()) body.append('role', role.trim());

    fetch('/dispatches', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF },
      body: body.toString()
    })
    .then(r => r.json().then(j => ({ ok: r.ok, j })))
    .then(({ ok, j }) => {
      // ⚠ 失敗をだまって流さない。「入れたつもりで残っていない」がいちばん困る。
      if (!ok) { alert((j && j.message) || '派遣依頼を保存できませんでした。'); return; }
      location.reload();   // 一覧・メンバー欄をそろえるため読み直す
    })
    .catch(() => alert('保存に失敗しました。通信を確認して、もう一度お試しください。'));
  }

  // 保存済みの派遣依頼を、メンバー欄の下に出す（2026-09-03）。
  // ⚠ 消すのは派遣一覧（/dispatch-list）から。ここでは見るだけ＝押し間違いで記録が消えないように。
  function dispatchRowsHtml(c){
    const list = (c.dispatches || []);
    if (!list.length) return '';
    return list.map(d => {
      const cancelled = d.status === 'キャンセル';
      const tip = d.agency + '（' + d.count + '名'
        + (d.role ? '・' + d.role : '') + '・' + d.status + '）'
        + (d.note ? '／' + d.note : '')
        + '　※直す・消すのは「派遣一覧」から';
      return `<div class="mem-row hk-row${cancelled ? ' off' : ''}">`
        + `<span class="m-no">派</span>`
        + `<span class="m-name haken" title="${escAttr(tip)}">${escHtml(d.agency)}</span>`
        + `<span class="m-type haken">派遣 ${d.count}名</span>`
        + (d.role ? `<span class="m-pos">${escHtml(d.role)}</span>` : '')
        + `<span class="hk-st ${d.status === '確定' ? 'fixed' : (cancelled ? 'cancelled' : 'asked')}">${escHtml(d.status)}</span>`
        + `</div>`;
    }).join('');
  }

  // ===== M-9：名簿（people.js）から選んで追加（社員・スタッフ）=====
  // people.js の pos:{...} から、できる役割の先頭をその人の担当ポジションにする
  function firstPosOf(pos){
    const order = [['D','D'],['OP','OP'],['MC','MC'],['FC','FC'],['CK','CK'],['SP','軍師・サポーター'],['RP','受付']];
    for (const [k, label] of order) { if (pos && pos[k]) return label; }
    return 'FC';
  }
  // 上と同じ優先順で「役割コード（D/OP/…）」を返す。DB保存(assignments.role)にはラベルでなくコードが要るため。
  function firstPosCodeOf(pos){
    const order = ['D','OP','MC','FC','CK','SP','RP'];
    for (const k of order) { if (pos && pos[k]) return k; }
    return 'FC';
  }
  // ===== メンバーの追加パネル（2026-08-26 baba要望）=====
  // 以前は「名簿から追加…」の長いプルダウン1つに社員とスタッフが全部入っていて使いにくかった。
  //  ・「＋社員を追加」「＋スタッフを追加」に分ける
  //  ・名前でしぼって、**チェックした人をまとめて**入れる
  //  ・スタッフ側で1人も見つからないときだけ「臨時スタッフとして名簿に追加」を出す
  //    （名簿にいない人を入れる道。アサイン画面と同じやり方＝入れ口を増やしていない）
  let PICK = { caseId: null, kind: 'staff', q: '', checked: new Set() };

  function openPicker(caseId, kind){
    // 同じボタンをもう一度押したら閉じる。
    if (PICK.caseId === caseId && PICK.kind === kind) { closePicker(); return; }
    closePicker();
    PICK = { caseId: caseId, kind: kind, q: '', checked: new Set() };
    renderPicker();
  }
  function closePicker(){
    const el = PICK.caseId ? document.getElementById('pick-' + PICK.caseId) : null;
    if (el) { el.style.display = 'none'; el.innerHTML = ''; }
    PICK = { caseId: null, kind: 'staff', q: '', checked: new Set() };
  }
  // しぼり込みの入力。リストだけ書き直す（入力欄を作り直すと文字が消える）。
  function pickerQuery(v){ PICK.q = v; renderPickList(); }
  function pickToggle(id, on){ if (on) PICK.checked.add(id); else PICK.checked.delete(id); renderPickFoot(); }

  function pickCandidates(){
    const roster = window.ECS_ROSTER || [];
    const c = cases.find(x => x.id === PICK.caseId);
    const taken = new Set(c ? c.assigned.map(m => m.name) : []);
    const q = PICK.q.trim();
    const list = roster.filter(pp => pp.role === PICK.kind && !taken.has(pp.name)
      && (!q || String(pp.name).indexOf(q) !== -1));

    // ⚠ その日、別の案件にもう入っている人は**下へ回す**（2026-09-03 baba要望）。
    //   これまでは名簿の五十音順に混ざっていて、押して確認が出てはじめて気づいていた。
    //   消してしまうと「事情があって重ねたい」ときに困るので、消さずに下げて赤くする。
    const busyWhere = c ? takenSameDayWhere(c) : new Map();
    const free = [], busy = [];
    list.forEach(pp => {
      const where = busyWhere.get(pp.name);
      (where ? busy : free).push(Object.assign({}, pp, { busyWhere: where || null }));
    });
    return free.concat(busy);
  }

  function renderPicker(){
    const el = document.getElementById('pick-' + PICK.caseId);
    if (!el) return;
    const label = PICK.kind === 'employee' ? '社員' : 'スタッフ';
    el.style.display = 'block';
    el.innerHTML =
      '<div class="pk-head"><span>' + label + 'を追加</span><span class="sp"></span>'
      + '<button class="mini" onclick="closePicker()">閉じる</button></div>'
      + '<input class="pk-q" type="text" placeholder="名前でしぼる（例）山田" oninput="pickerQuery(this.value)">'
      + '<div class="pk-list" id="pkList"></div>'
      + '<div class="pk-foot" id="pkFoot"></div>'
      + '<div id="pkSpot"></div>';
    renderPickList();
    const q = el.querySelector('.pk-q');
    if (q) q.focus();
  }

  function renderPickList(){
    const box = document.getElementById('pkList');
    if (!box) return;
    const list = pickCandidates();
    if (!list.length) {
      box.innerHTML = '<div class="pk-none">この名前の'
        + (PICK.kind === 'employee' ? '社員' : 'スタッフ')
        + 'は名簿にいません（すでにこの案件に入っている人は出ません）。</div>';
    } else {
      // ⚠ 別案件に入っている人は下にまとまる（pickCandidates で並べ替え済み）。
      //   その境目に見出しを1本入れて、どこから先が「その日もう埋まっている人」か分かるようにする。
      let sepDone = false;
      box.innerHTML = list.map(pp => {
        const where = pp.busyWhere;
        let head = '';
        if (where && !sepDone) {
          sepDone = true;
          head = '<div class="pk-sep">⛔ ここから下は、この日すでに別の案件に入っている人です</div>';
        }
        const tip = where ? busyTitle(pp.name, where) : '';
        return head
          + '<label class="pk-item' + (where ? ' busy' : '') + '"'
          + (tip ? ' title="' + escHtml(tip) + '"' : '') + '>'
          + '<input type="checkbox" ' + (PICK.checked.has(pp.id) ? 'checked' : '')
          + ' onchange="pickToggle(\'' + pp.id + '\', this.checked)">'
          + '<span>' + escHtml(pp.name) + '</span>'
          + '<span class="lv">' + escHtml(pp.lvLabel || '') + '</span>'
          + (where ? '<span class="busy-tag">⛔</span>' : '')
          + '</label>';
      }).join('');
    }
    renderPickFoot();
  }

  function renderPickFoot(){
    const foot = document.getElementById('pkFoot');
    if (!foot) return;
    const n = PICK.checked.size;
    foot.innerHTML = '<span class="sp">' + (n ? n + '人を選んでいます' : '') + '</span>'
      + '<button class="mini" ' + (n ? '' : 'disabled style="opacity:.5; cursor:default;"')
      + ' onclick="addPicked()">チェックした人を追加</button>';

    // 名簿にいない方（インターン・助っ人）を入れる道。
    // ⚠ 社員はここから作らない（社員の登録はアカウント発行から）。
    const spot = document.getElementById('pkSpot');
    if (!spot) return;
    const q = PICK.q.trim();
    const none = pickCandidates().length === 0;
    spot.innerHTML = (PICK.kind === 'staff' && q && none)
      ? '<div class="pk-spot">「<b>' + escHtml(q) + '</b>」は名簿にいません。<br>'
        + '<button class="mini" style="margin-top:6px;" onclick="addSpotFromPicker()">'
        + '＋ 臨時スタッフとして名簿に追加して、この案件に入れる</button>'
        + '<div style="margin-top:4px; color:#8a7a6b;">ログインは付きません。'
        + '出勤数・稼働状況には、ふつうのスタッフと同じように数えます。</div></div>'
      : '';
  }

  // チェックした人をまとめて入れる（入れ方は今までと同じ addRosterMember）。
  function addPicked(){
    const caseId = PICK.caseId;
    const ids = Array.from(PICK.checked);
    closePicker();
    ids.forEach(id => addRosterMember(caseId, id));
  }

  // 名簿にいない方を臨時スタッフとして追加し、そのままこの案件に入れる。
  function addSpotFromPicker(){
    const name = PICK.q.trim();
    const caseId = PICK.caseId;
    if (!name || !caseId) return;
    if (!confirm('「' + name + '」さんを臨時スタッフとして名簿に追加し、この案件に入れます。\n\n'
      + '・ログインは付きません（メール・パスワードなし）\n'
      + '・出勤数や稼働状況には、ふつうのスタッフと同じように数えます\n\nよろしいですか？')) return;
    fetch('/people/spot', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': window.ECS_CSRF },
      // いま見ている拠点の人として追加する（空のときは自分の拠点になる）。
      body: JSON.stringify({ name: name, office: window.ECS_OFFICE_SCOPE || '' })
    })
      .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
      .then(r => {
        if (!r.ok || !r.data.ok) {
          alert(r.data && r.data.message ? r.data.message : '臨時スタッフの追加に失敗しました。');
          return;
        }
        // 画面が持っている名簿にも足す（開き直さなくても使えるように）。
        window.ECS_ROSTER = (window.ECS_ROSTER || []).concat([
          { id: r.data.id, name: name, role: 'staff', lv: '-', lvLabel: '臨時', pos: {} }
        ]);
        closePicker();
        addRosterMember(caseId, r.data.id);
      })
      .catch(() => alert('通信エラーで臨時スタッフを追加できませんでした。'));
  }
  // プルダウンで選んだ人をこの案件のメンバーに追加（かぶり・月上限のチェックは addCandidate と同じ）
  function addRosterMember(caseId, id){
    if (!id) return;
    const c = cases.find(x => x.id === caseId);
    if (!c) return;
    const pp = (window.ECS_ROSTER || []).find(x => x.id === id);
    if (!pp) return;
    if (c.assigned.some(m => m.name === pp.name)) { alert(pp.name + ' さんはすでにこの案件のメンバーに入っています。'); return; }
    if (takenSameDay(c).has(pp.name)) {
      if (!confirm(pp.name + ' さんは同じ日の別の案件にすでに割り当てられています。\nそれでも追加しますか？（かぶりは赤文字で表示されます）')) return;
    }
    const mc = monthCountOf(pp.name);
    if (mc >= MONTH_CAP) {
      if (!confirm(pp.name + ' さんは今月のアサインがすでに上限の ' + MONTH_CAP + '件 に達しています（現在 ' + mc + '件）。\n過重労働防止のための上限を超えます。それでも追加しますか？')) return;
    }
    const isEmp = pp.role === 'employee';
    // 役割は「コード」で持つ（保存に必要）。表示ラベルは ECS_ROLE_OPTIONS から引く。
    const roleCode = isEmp ? ((pp.dexp && pp.dexp.length) ? 'D' : 'FC') : firstPosCodeOf(pp.pos);
    const posLabel = (window.ECS_ROLE_OPTIONS || {})[roleCode] || roleCode;
    // id・roleCode・status を持たせる＝追加直後から担当/巡回を編集・保存できる（id が無いと入力欄が出ない）。
    const m = { id: pp.id, name: pp.name, lv: (isEmp ? '-' : (pp.lv || '-')), pos: posLabel, roleCode: roleCode, roleCode2: '', note: '', patrol: null, remark: '', status: '仮', type: (isEmp ? 'emp' : 'staff') };
    c.assigned.push(m);
    // 追加した時点で assignments に「仮」で保存する（見本ではなく本物のアサインにする）。
    fetch(window.ECS_QUICK_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF, 'Accept': 'application/json' },
      body: JSON.stringify({ project_id: caseId, staff_id: pp.id, action: 'assign', role: roleCode, status: '仮' })
    })
      .then(r => r.json())
      .then(res => { if (!(res && res.ok)) { alert('メンバー追加の保存に失敗しました。' + (res && res.message ? '\n' + res.message : '')); } })
      .catch(() => { alert('通信エラーでメンバー追加を保存できませんでした。'); });
    render();
  }

  // リストの高さを全カードまとめて切替（たたむ／標準／ぜんぶ表示）
  function setListHeight(v){
    const b = document.getElementById('boardBody');
    b.classList.remove('lh-compact', 'lh-normal', 'lh-all');
    b.classList.add('lh-' + v);
    // 押したボタンを目立たせる
    document.querySelectorAll('.lh-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.h === v));
  }

  // 文字列をHTMLに安全に埋め込む（備考・担当メモにタグ文字が入っても崩れないように）
  function escHtml(s){
    return String(s == null ? '' : s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  // 案件カードのタイトル部分のHTML。タイトルは1行省略（長い分は「…」・ホバーで全文）。
  // コンテンツ未登録の案件は先頭に小さな「⚠未登録」バッジを付け、案件名で仮表示する。
  // 確度（Aヨミ/Bヨミ/Cヨミ）の印。⚠ 印も色も案件一覧と同じにそろえる（画面ごとに変えない）。
  //   「確定」は数が多いので印を出さない＝印が付いている＝まだ確定していない、と読める。
  const yomiMark = { 'Aヨミ':{ t:'A', c:'a' }, 'Bヨミ':{ t:'B', c:'b' }, 'Cヨミ':{ t:'C', c:'c' } };
  function yomiHtml(c){
    const y = yomiMark[c.yomi];
    if (!y) return '';
    return `<span class="ymk ${y.c}" title="確度：${c.yomi}（まだ確定していない案件です）">${y.t}</span>`;
  }

  // 実施形態のバッジ（2026-09-01 baba要望）。現地かオンラインかで声を掛ける人が変わるため、
  // アサインを詰めるこの画面にも出す。
  // ⚠ ここで実施形態を判定しない。色の振り分けはサーバーが済ませて fmtCls で渡している
  //   （正本＝App\Support\ProjectFormats::badgeCode）。画面ごとに書くと必ず食い違う。
  // ⚠ 未設定の案件には何も出さない（「リアル」と決めつけない）。
  function fmtBadgeHtml(c){
    const text = (c.format || '').trim();
    if (!text) return '';
    return `<span class="fbadge ${c.fmtCls || 'fmt-etc'}" title="実施形態">${escHtml(text)}</span>`;
  }

  // 案件の詳細（登録・編集画面）へ行くボタン（2026-09-01 baba要望）。
  // ⚠ 案件名を押しても同じところへ行けるが、**リンクだと気づけない**というご意見。
  //   他のボタンと同じ並びに置いて、押せることが分かるようにする。
  // ⚠ 見本データのときは出さない（開いても中身が無い案件のため）。
  function detailBtnHtml(c){
    if (!USING_DB) return '';
    return `<a class="open-btn" href="/project-form?project=${encodeURIComponent(c.id)}"`
      + ` title="この案件の詳細（登録・編集画面）を開きます。時間・会場・運営人数・備考などはそこで直せます。">📄 案件の詳細 →</a>`;
  }

  function titleBlockHtml(c){
    const name = c.name || '';
    const badge = c.contentMissing ? '<span class="cc-nocontent" title="コンテンツがマスタに未登録です。案件名で仮表示しています。">⚠未登録</span> ' : '';
    // 案件名を押したら案件の詳細（編集画面）へ。アサインしながら中身を確認できる（2026-08-21 baba）。
    const inner = USING_DB
      ? `<a href="/project-form?project=${encodeURIComponent(c.id)}" title="案件の詳細・編集を開く">${badge}${name}</a>`
      : `${badge}${name}`;
    return `<div class="cc-name" title="${name}">${inner}${yomiHtml(c)}</div>`;
  }

  // メンバーのポジション欄。手動編集中でスタッフIDがあればプルダウン（選ぶとDB保存）、それ以外は表示のみ。
  function posCellHtml(c, m){
    const label = m.pos || '—';
    if (!editing.has(c.id) || !m.id) return `<span class="m-pos">${label}</span>`;
    const opts = window.ECS_ROLE_OPTIONS || {};
    // 役割が未設定のときの初期選択は FC（先頭のDにしない）。
    const current = m.roleCode || 'FC';
    let html = '';
    let found = false;
    for (const code in opts){
      const sel = code === current ? ' selected' : '';
      if (code === current) found = true;
      html += `<option value="${code}"${sel}>${opts[code]}</option>`;
    }
    // 今のコードが選択肢に無い場合（旧コード等）は、消えないよう先頭に足して選択済みにする
    if (!found && current) html = `<option value="${current}" selected>${opts[current] || label}</option>` + html;
    return `<select class="m-pos-sel" title="ポジションを変更（選ぶと保存されます）" onchange="changeMemberPos('${c.id}','${m.id}', this.value)">${html}</select>`;
  }
  // メンバーの兼任（サブ役割）欄。1人が2役こなす場合に選ぶ。手動編集中で id があればプルダウン、それ以外はバッジ表示。
  function role2CellHtml(c, m){
    const opts = window.ECS_ROLE_OPTIONS || {};
    if (!editing.has(c.id) || !m.id){
      return (m.roleCode2) ? `<span class="m-kenin" title="兼任">兼${escHtml(opts[m.roleCode2] || m.roleCode2)}</span>` : '';
    }
    const cur = m.roleCode2 || '';
    let html = `<option value="">＋兼任なし</option>`;
    for (const code in opts){
      html += `<option value="${code}"${code === cur ? ' selected' : ''}>兼${opts[code]}</option>`;
    }
    return `<select class="m-pos-sel m-kenin-sel" title="兼任（サブ役割）＝1人で2役こなす場合に選ぶ（選ぶと保存されます）" onchange="changeMemberRole2('${c.id}','${m.id}', this.value)">${html}</select>`;
  }

  // メンバーの担当メモ欄。手動編集中でスタッフIDがあれば入力（datalistで候補）、それ以外は表示のみ。
  function noteCellHtml(c, m){
    if (!editing.has(c.id) || !m.id) {
      return (m.note && String(m.note).trim()) ? `<span class="m-note" title="担当メモ">· ${escHtml(m.note)}</span>` : '';
    }
    const v = m.note ? escHtml(m.note) : '';
    return `<input class="m-note-inp" list="ecsNoteList" placeholder="担当" value="${v}" title="担当メモ（軍師・サポ等。入力すると保存されます）" onchange="changeMemberNote('${c.id}','${m.id}', this.value)">`;
  }
  // メンバーの巡回数欄。手動編集中でスタッフIDがあれば数値入力、それ以外は数値があれば表示。
  function patrolCellHtml(c, m){
    if (!editing.has(c.id) || !m.id) {
      return (m.patrol != null && m.patrol !== '') ? `<span class="m-patrol" title="巡回数">巡回${m.patrol}</span>` : '';
    }
    const v = (m.patrol != null && m.patrol !== '') ? m.patrol : '';
    return `<input class="m-patrol-inp" type="number" min="0" placeholder="巡回" value="${v}" title="巡回数（入力すると保存されます）" onchange="changeMemberPatrol('${c.id}','${m.id}', this.value)">`;
  }
  // メンバーの備考（一言）欄。手動編集中でスタッフIDがあれば入力、それ以外は一言があれば表示。
  function remarkCellHtml(c, m){
    if (!editing.has(c.id) || !m.id) {
      return (m.remark && String(m.remark).trim()) ? `<span class="m-remark" title="備考：${escHtml(m.remark)}">✎ ${escHtml(m.remark)}</span>` : '';
    }
    const v = m.remark ? escHtml(m.remark) : '';
    return `<input class="m-remark-inp" placeholder="一言" value="${v}" title="備考（一言・入力すると保存されます）" onchange="changeMemberRemark('${c.id}','${m.id}', this.value)">`;
  }

  // メンバーの「仮／確定」。押すと入れ替わる（2026-08-21 baba要望）。
  // なぜ要るか＝スタッフの画面に出るのは「確定」の人だけ。追加でアサインした人は「仮」で入るので、
  // ここで確定にしないと本人に見えない。これまで日別ボードには仮か確定かの表示すら無かった。
  function statusCellHtml(c, m){
    const st = m.status || '確定';
    // 見本データ・スタッフIDが無い行（派遣など）は押せないので、仮のときだけ印を出す。
    if (!USING_DB || !m.id) return st === '仮' ? '<span class="m-st kari">仮</span>' : '';
    const next = st === '仮' ? '確定' : '仮';
    const cls  = st === '仮' ? 'kari' : 'fix';
    const tip  = st === '仮'
      ? 'いまは仮＝スタッフに見えません。押すと確定になります。'
      : '確定です（公開済みならスタッフに見えています）。押すと仮に戻します。';
    return `<span class="m-st ${cls}" title="${tip}" onclick="changeMemberStatus('${c.id}','${m.id}','${next}')">${st}</span>`;
  }
  function changeMemberStatus(caseId, staffId, next){
    const c = cases.find(z => z.id === caseId);
    const m = c && c.assigned.find(x => x.id === staffId);
    if (!c || !m) return;
    // 確定 → 仮 は「公開済みならスタッフの画面から消える」＝重い操作なので確認を挟む。
    if (next === '仮' && !confirm(m.name + ' さんを「仮」に戻します。\n公開済みの案件では、スタッフの画面から消えます。\nよろしいですか？')) return;
    fetch(window.ECS_QUICK_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF, 'Accept': 'application/json' },
      body: JSON.stringify({ project_id: caseId, staff_id: staffId, action: 'assign', status: next })
    })
      .then(r => r.json())
      .then(res => {
        if (res && res.ok){ m.status = res.status || next; render(); }
        else { alert('保存に失敗しました。' + (res && res.message ? '\n' + res.message : '')); }
      })
      .catch(() => alert('通信エラーで保存できませんでした。'));
  }

  // ポジション変更を assignments に保存（エントリー一覧と同じ quickToggle を再利用）。状態(仮/確定)は維持する。
  function changeMemberPos(caseId, staffId, roleCode){
    const c = cases.find(z => z.id === caseId);
    const m = c && c.assigned.find(x => x.id === staffId);
    const status = (m && m.status) ? m.status : '確定';
    fetch(window.ECS_QUICK_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF, 'Accept': 'application/json' },
      body: JSON.stringify({ project_id: caseId, staff_id: staffId, action: 'assign', role: roleCode, status: status })
    })
      .then(r => r.json())
      .then(res => {
        if (res && res.ok){
          if (m){ m.roleCode = roleCode; m.pos = (window.ECS_ROLE_OPTIONS || {})[roleCode] || roleCode; }
        } else {
          alert('ポジションの保存に失敗しました。' + (res && res.message ? '\n' + res.message : ''));
          render();
        }
      })
      .catch(() => { alert('通信エラーでポジションを保存できませんでした。'); render(); });
  }

  // 兼任（サブ役割）を assignments に保存。空欄なら解除（null）。状態は維持。
  function changeMemberRole2(caseId, staffId, roleCode2){
    const c = cases.find(z => z.id === caseId);
    const m = c && c.assigned.find(x => x.id === staffId);
    const status = (m && m.status) ? m.status : '確定';
    fetch(window.ECS_QUICK_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF, 'Accept': 'application/json' },
      body: JSON.stringify({ project_id: caseId, staff_id: staffId, action: 'assign', role2: roleCode2, status: status })
    })
      .then(r => r.json())
      .then(res => {
        if (res && res.ok){ if (m) m.roleCode2 = roleCode2; }
        else { alert('兼任の保存に失敗しました。' + (res && res.message ? '\n' + res.message : '')); render(); }
      })
      .catch(() => { alert('通信エラーで兼任を保存できませんでした。'); render(); });
  }

  // 担当メモ（軍師・サポ等）を assignments に保存。役割変更(changeMemberPos)と同じ quickToggle を使う。状態は維持。
  function changeMemberNote(caseId, staffId, note){
    const c = cases.find(z => z.id === caseId);
    const m = c && c.assigned.find(x => x.id === staffId);
    const status = (m && m.status) ? m.status : '確定';
    fetch(window.ECS_QUICK_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF, 'Accept': 'application/json' },
      body: JSON.stringify({ project_id: caseId, staff_id: staffId, action: 'assign', note: note, status: status })
    })
      .then(r => r.json())
      .then(res => {
        if (res && res.ok){ if (m) m.note = note; }
        else { alert('担当メモの保存に失敗しました。' + (res && res.message ? '\n' + res.message : '')); render(); }
      })
      .catch(() => { alert('通信エラーで担当メモを保存できませんでした。'); render(); });
  }

  // 巡回数を assignments に保存。空欄なら null（未設定）として送る。状態は維持。
  function changeMemberPatrol(caseId, staffId, patrol){
    const c = cases.find(z => z.id === caseId);
    const m = c && c.assigned.find(x => x.id === staffId);
    const status = (m && m.status) ? m.status : '確定';
    const val = (patrol === '' || patrol == null) ? null : Number(patrol);
    fetch(window.ECS_QUICK_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF, 'Accept': 'application/json' },
      body: JSON.stringify({ project_id: caseId, staff_id: staffId, action: 'assign', patrol: val, status: status })
    })
      .then(r => r.json())
      .then(res => {
        if (res && res.ok){ if (m) m.patrol = val; }
        else { alert('巡回数の保存に失敗しました。' + (res && res.message ? '\n' + res.message : '')); render(); }
      })
      .catch(() => { alert('通信エラーで巡回数を保存できませんでした。'); render(); });
  }

  // 備考（一言）を assignments に保存。他と同じ quickToggle を使う。状態は維持。
  function changeMemberRemark(caseId, staffId, remark){
    const c = cases.find(z => z.id === caseId);
    const m = c && c.assigned.find(x => x.id === staffId);
    const status = (m && m.status) ? m.status : '確定';
    fetch(window.ECS_QUICK_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF, 'Accept': 'application/json' },
      body: JSON.stringify({ project_id: caseId, staff_id: staffId, action: 'assign', remark: remark, status: status })
    })
      .then(r => r.json())
      .then(res => {
        if (res && res.ok){ if (m) m.remark = remark; }
        else { alert('備考の保存に失敗しました。' + (res && res.message ? '\n' + res.message : '')); render(); }
      })
      .catch(() => { alert('通信エラーで備考を保存できませんでした。'); render(); });
  }

  // 希望者一覧を別ウィンドウで開く
  function openWishlist(){
    const w = window.open('/assign-wishlist', 'ecs_wishlist', 'width=880,height=700');
    if (!w) { alert('ポップアップがブロックされたようです。ブラウザのポップアップ許可を確認してください。'); return; }
    w.focus();
  }

  // ===== 日付ユーティリティ =====
  const DOW = ['日','月','火','水','木','金','土'];
  // 表示の基準日（先頭の日）。?from で来た日。無ければ今日。off はこの日からの日数。
  function boardAnchorDate(){
    const s = window.ECS_BOARD_ANCHOR;
    if (s && /^\d{4}-\d{2}-\d{2}$/.test(s)) {
      const p = s.split('-').map(Number);
      return new Date(p[0], p[1]-1, p[2]);
    }
    return new Date();
  }
  function addDays(n){ const x = boardAnchorDate(); x.setHours(0,0,0,0); x.setDate(x.getDate()+n); return x; }
  function ymd(d){ const m = String(d.getMonth()+1).padStart(2,'0'), day = String(d.getDate()).padStart(2,'0'); return d.getFullYear()+'-'+m+'-'+day; }

  // 基準日を変えてボードを開き直す（空文字＝今日に戻る）。focus 等の他パラメータは引き継がない。
  function jumpToDate(v){
    location.href = (v && /^\d{4}-\d{2}-\d{2}$/.test(v)) ? ('/assign?from=' + v) : '/assign';
  }
  // 今の基準日から n 日ずらして開き直す（◀▶ボタン用）。
  function shiftAnchor(n){
    const d = boardAnchorDate(); d.setHours(0,0,0,0); d.setDate(d.getDate()+n);
    jumpToDate(ymd(d));
  }

  const stateLabel = { todo:'未着手', adj:'調整中', fix:'確定', pub:'公開済' };

  const body  = document.getElementById('boardBody');
  const empty = document.getElementById('boardEmpty');

  function render(){
    const sf   = document.getElementById('stateFilter').value;
    const mine = document.getElementById('mineOnly').checked;

    // 絞り込み
    const list = cases.filter(c => {
      if (mine && !c.mine) return false;
      if (!sf) return true;
      // ⚠ 「募集中か」と「案件の進み具合」は別々に見る。
      //   前は1つの状態にまとめていたので、公開ずみの案件は「確定のみ」で絞っても出てこなかった。
      const pubOn = (c.pubOn !== undefined) ? !!c.pubOn : (c.state === 'pub');
      if (sf === 'pub')   return pubOn;
      if (sf === 'unpub') return !pubOn;
      return (c.stat || c.state) === sf;
    });

    // 開催日（off）ごとにまとめる
    const offs = [...new Set(list.map(c => c.off))].sort((a,b) => a - b);

    body.innerHTML = '';
    empty.style.display = list.length === 0 ? '' : 'none';

    offs.forEach(off => {
      const dayCases = list.filter(c => c.off === off);
      const date = addDays(off);
      const dy   = date.getDay();
      const dowC = dy === 0 ? 'sun' : (dy === 6 ? 'sat' : '');

      // その日に複数案件でかぶっている人（＝ほんとうの重複）。ヘッダーの注意にも使う。
      const nameCount = {};
      dayCases.forEach(dc => dc.assigned.forEach(m => { nameCount[m.name] = (nameCount[m.name] || 0) + 1; }));
      const dupNames = new Set(Object.keys(nameCount).filter(n => nameCount[n] >= 2));

      // その日の数字（2026-08-21 baba指摘で作り直し）。
      //  ・必要   ＝その日の全案件の運営人数の合計（Dも含む）
      //  ・割当済 ＝その日の全案件に入っている人の合計
      //  ・あと   ＝必要−割当済（マイナスなら「超過」）
      //  ・候補   ＝稼働希望〇＋その日の案件へのエントリー（すでに入っている人は除く）
      // 以前は「稼働可−割当済」を『残り』と出していたため、希望カレンダーが空の日は
      // 必ず「残り −1名／稼働可を超過（重複の可能性）」と出て、意味が分からなかった。
      // ⚠ 「🔒 この人数で足りている」で締めた案件は、いま入っている人数を「必要」として数える
      //   （2026-09-01 baba指摘）。運営人数のまま数えると、もう足さなくてよいのに
      //   その日の「あと◯名」が減らず、まだ人が要るように見えてしまう。
      //   運営人数（セールスが書いた予定）そのものは変えていない。
      const needOf = (c) => (bPubOn(c) && !bRecruit(c)) ? filledOf(c) : (c.need || 0);
      const needTotal = dayCases.reduce((s,c) => s + needOf(c), 0);
      const assigned  = dayCases.reduce((s,c) => s + filledOf(c), 0);
      const remain    = needTotal - assigned;
      const assignedNames = new Set();
      dayCases.forEach(dc => dc.assigned.forEach(m => assignedNames.add(m.name)));
      const candNames = new Set();
      if (ECS_BOARD) {
        dayPeople(off, dayCases).forEach(p => { if (!assignedNames.has(p.name)) candNames.add(p.name); });
      }
      const cand = ECS_BOARD ? candNames.size : (dayAvail[off] || 0);

      const block = document.createElement('div');
      block.className = 'day-block';

      const warnHtml = dupNames.size > 0
        ? `<span class="d-warn">⚠ 同じ人が2件以上に入っています（${Array.from(dupNames).join('・')}）</span>`
        : '';

      block.innerHTML = `
        <div class="day-head">
          <span class="d-date">${date.getMonth()+1}/${date.getDate()}<span class="${dowC}">（${DOW[dy]}）</span></span>
          <span class="d-pool">
            <span>必要 <b>${needTotal}</b>名</span>
            <span>割当済 <b>${assigned}</b>名</span>
            <span class="remain ${remain > 0 ? 'bad' : 'ok'}">${remain >= 0 ? 'あと' : '超過'} <b>${Math.abs(remain)}</b>名</span>
            <span title="稼働希望で〇を出した人＋この日の案件にエントリーした人（すでに入っている人は除く）">候補 <b>${cand}</b>名</span>
          </span>
          ${dayBulkHtml(off, dayCases)}
          ${warnHtml}
        </div>
        <div class="case-row" id="row-${off}"></div>`;
      body.appendChild(block);

      const amap = assignmentMap();   // 名前→出ている案件id（メンバーに「他にどこに出ているか」を出す）
      const row = block.querySelector('.case-row');
      dayCases.forEach(c => row.appendChild(buildCard(c, dayCases, dupNames, amap)));
    });
  }

  // ===== その日をまとめて確定・まとめて公開（2026-08-28 baba要望）=====
  // ⚠ 1件ずつ押すのが手間なので、日付の横から一括でできるようにする。
  //   ただし「まとめて」は取り返しがつきにくいので、必ず**件数と案件名を見せて確認**する。
  //   確定と公開は別のボタンにする（1回で公開まで進めない＝いまの2段階のままにする）。
  function dayBulkHtml(off, dayCases){
    const toFix = dayCases.filter(c => (c.stat || c.state) !== 'fix');
    const toPub = dayCases.filter(c => (c.stat || c.state) === 'fix' && !bPubOn(c));
    let html = '';
    if (toFix.length) {
      html += `<button class="day-bulk" onclick="bulkFixDay(${off})" title="この日の「未着手・調整中」の案件を、まとめて確定にします（メンバーも全員「確定」になります）">✓ この日の${toFix.length}件を確定にする</button>`;
    }
    if (toPub.length) {
      html += `<button class="day-bulk pub" onclick="bulkPubDay(${off})" title="この日の「確定・まだ募集していない」案件を、まとめてスタッフに公開します">📣 この日の${toPub.length}件を公開する</button>`;
    }
    return html;
  }
  // 募集中か（古いデータには pubOn が無いので state からも読めるようにする）。
  function bPubOn(c){ return (c.pubOn !== undefined) ? !!c.pubOn : (c.state === 'pub'); }

  // ===== スタッフの画面でどう見えているか（2026-08-28 baba指摘）=====
  // ⚠ 公開しているだけで「募集中」と出していたが、**スタッフの画面では人数が埋まると
  //   「締切・満員」になっていてエントリーできない**。同じ案件なのに社員とスタッフで
  //   言うことが違っていた。ここは必ずスタッフ画面と同じ数で判定する。
  // ⚠ 判定の数（運営人数が未入力のときの既定）はサーバー（App\Support\RecruitStatus）から
  //   needStaff で受け取る。ここに数字を書かないこと（片方だけ直すと必ず食い違う）。
  function needStaffOf(c){ return (c.needStaff && c.needStaff > 0) ? c.needStaff : (c.need || 0); }
  // ⚠ 締切の判定に数えるのは「確定」の人だけ（2026-08-28 baba決定）。
  //   「仮」＝まだ声掛け中で決まっていないので、その枠は募集を続ける。
  //   （取込でシートのメンバーが仮で入った瞬間に締切になり、スタッフがエントリー
  //     できなくなっていたため。カードの「割当済」は今までどおり仮も入れて数える）。
  function confirmedOf(c){ return c.assigned.filter(m => m.status === '確定').length; }
  function isFullForStaff(c){ return confirmedOf(c) >= needStaffOf(c); }
  function remainForStaff(c){ return Math.max(0, needStaffOf(c) - confirmedOf(c)); }

  // 募集を続けているか（古いデータには recruit が無いので、無ければ「募集中」とみなす）。
  function bRecruit(c){ return (c.recruit !== undefined) ? !!c.recruit : true; }

  // カードに出す「いまスタッフからどう見えているか」の印。公開していない案件には出さない。
  // ⚠ 募集を締めた案件には何も出さない（2026-09-01 スタッフからのご意見）。
  //   運営人数より少なくても「これでOK」と確定にしたとき、「募集中 あと◯名」が残っていると
  //   まだ人を足すのか・もう要らないのかが分からなかった。
  //   確定にすると募集は締まる（正本＝App\Support\RecruitStatus）。足したくなったら「＋ 追加募集する」。
  function recruitBadge(c){
    if (!bPubOn(c) || !bRecruit(c)) return '';
    if (isFullForStaff(c)) {
      return '<span class="sb full" title="「確定」の人数が運営人数に達しているので、スタッフの画面では「締切・満員」に見えています。'
        + '運営人数を増やすと、その場でまた募集中に戻ります（公開し直す必要はありません）。">締切</span>';
    }
    return '<span class="sb pub" title="スタッフの画面に募集として出ています。'
      + '「仮」の人は数えていません（まだ決まっていないので募集を続けます）。やめるのは公開ボードから。">募集中 あと'
      + remainForStaff(c) + '名</span>';
  }

  // 案件名を確認の文にする（何をまとめて変えるのか必ず見せる）。
  function bulkNames(list){
    return list.map(c => '・' + c.name).join('\n');
  }

  function bulkFixDay(off){
    const list = cases.filter(c => c.off === off && (c.stat || c.state) !== 'fix');
    if (!list.length) { alert('この日に確定にできる案件はありません。'); return; }
    // 人数が足りない案件は先に知らせる（1件ずつのときと同じ気づきを残す）。
    const short = list.filter(c => filledOf(c) < c.need);
    const shortMsg = short.length
      ? '\n\n⚠ 人数が足りない案件が ' + short.length + '件あります：\n' + bulkNames(short) : '';
    if (!confirm('この日の ' + list.length + '件を「確定」にします。\n' + bulkNames(list)
      + '\n\nメンバーの「仮」も全員「確定」になります（確定にしないと本人の画面に出ません）。'
      + shortMsg + '\n\nよろしいですか？')) return;
    bulkRun(list, c => oneFix(c), '確定にしました');
  }

  function bulkPubDay(off){
    const list = cases.filter(c => c.off === off && (c.stat || c.state) === 'fix' && !bPubOn(c));
    if (!list.length) { alert('この日に公開できる案件はありません（確定していないか、すでに募集中です）。'); return; }
    if (!confirm('この日の ' + list.length + '件をスタッフに公開します。\n' + bulkNames(list)
      + '\n\n公開すると、この案件が募集としてスタッフ画面に出ます。'
      + '\nいま「仮」の人も、あわせて「確定」にします。\n\nよろしいですか？')) return;
    bulkRun(list, c => onePub(c), '公開しました');
  }

  // まとめて実行する共通部分。⚠ 1件ずつ順に流す（同時に送るとサーバーが取りこぼす）。
  //   途中で失敗しても止めず、最後に「◯件できた／◯件できなかった」を出す。
  function bulkRun(list, fn, doneWord){
    let ok = 0; const ng = [];
    const step = (i) => {
      if (i >= list.length){
        render();
        alert(ok + '件を' + doneWord + '。'
          + (ng.length ? '\n\n⚠ 次の ' + ng.length + '件はできませんでした：\n' + ng.map(n => '・' + n).join('\n') : ''));
        return;
      }
      const c = list[i];
      Promise.resolve(fn(c))
        .then(() => { ok++; })
        .catch(() => { ng.push(c.name); })
        .then(() => step(i + 1));
    };
    step(0);
  }

  // 1件を確定にする（確認は出さない＝まとめての確認で済ませているため）。
  // ⚠ 保存の中身は markFix と同じ道を通す（確定のやり方を2つ作らない）。
  function oneFix(c){
    if (!USING_DB){ c.stat = 'fix'; if (!c.pubOn) c.state = 'fix'; return Promise.resolve(); }
    return fetch('/projects/cells', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF, 'Accept': 'application/json' },
      body: JSON.stringify({ id: c.id, status: '確定' })
    })
      .then(r => r.ok ? r.json() : Promise.reject(r.status))
      .then(() => confirmAllMembers(c))
      .then(() => { c.stat = 'fix'; if (!c.pubOn) c.state = 'fix'; });
  }

  // 1件を公開する。公開の入口は公開ボードと同じ（staff_published を立てる＝編集履歴に残る）。
  function onePub(c){
    if (!USING_DB){ c.state = 'pub'; c.pubOn = true; return Promise.resolve(); }
    const body = { ids: [c.id], publish: true };
    if (window.ECS_OFFICE_SCOPE) body.office = window.ECS_OFFICE_SCOPE;
    return fetch('/assign-publish/set', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF, 'Accept': 'application/json' },
      body: JSON.stringify(body)
    })
      .then(r => r.ok ? r.json() : Promise.reject(r.status))
      .then(res => {
        if (!res || !res.updated) return Promise.reject('公開できませんでした');
        return confirmAllMembers(c);
      })
      .then(() => { c.state = 'pub'; c.pubOn = true; });
  }

  // 追加募集＝運営人数を増やす（2026-08-28 baba要望）。
  // ⚠ 公開の状態は触らない。スタッフ画面の「締切・満員」は人数から毎回決まるので、
  //   人数を増やせばそれだけでまた募集中に戻る。
  // ⚠ 保存はアサイン表と同じ入口（/assign-sheet/project）を使う＝運営人数の直し方を2つ作らない。
  //   「6〜8」のような範囲もそのまま入れられる（Headcount が読む）。
  // 「🔒 この人数で足りている」＝募集を締める（2026-09-01 baba要望）。
  // ⚠ 運営人数（セールスが書いた数）は変えない。変えるとセールスの予定が消えてしまう。
  // ⚠ バッジを消すだけにしない＝スタッフ画面の募集も止める（社員とスタッフで言うことを合わせる）。
  function closeRecruit(id){
    const c = cases.find(x => x.id === id);
    if (!c) return;
    if (!confirm('「' + c.name + '」の募集を締めます。\n'
      + '運営人数 ' + needStaffOf(c) + '名 に対して、いま確定は ' + confirmedOf(c) + '名です。\n\n'
      + '・スタッフの画面から、この案件の募集が消えます（確定した方の予定には残ります）\n'
      + '・運営人数は ' + needStaffOf(c) + '名 のままです（変えません）\n'
      + '・あとで足したくなったら「＋ 追加募集する」で戻せます\n\n'
      + 'この人数で足りていますか？')) return;
    if (!USING_DB){ c.recruit = false; render(); return; }
    fetch('/projects/cells', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF, 'Accept': 'application/json' },
      body: JSON.stringify({ id: id, recruit: false })
    })
      .then(r => r.ok ? r.json() : Promise.reject(r.status))
      .then(() => { c.recruit = false; render(); })
      .catch(e => alert('募集を締められませんでした（' + e + '）。もう一度お試しください。'));
  }

  // 「＋ 追加募集する」＝運営人数を増やす／締めた募集を再開する。
  // ⚠ 締めた案件は、人数を増やしただけではスタッフの画面に出ない＝ここで募集も開け直す。
  function addRecruit(id){
    const c = cases.find(x => x.id === id);
    if (!c) return;
    const now = needStaffOf(c);
    const closed = !bRecruit(c);
    const head = closed
      ? '「' + c.name + '」の募集を再開します（「この人数で足りている」で締めていました）。\n'
        + 'いまの運営人数：' + now + '名（確定 ' + confirmedOf(c) + '名）\n\n'
        + '運営人数はこのままでも再開できます。増やすときだけ数字を変えてください（「6〜8」のような範囲も可）。'
      : '「' + c.name + '」の運営人数を増やします。\n'
        + 'いまの運営人数：' + now + '名（確定 ' + confirmedOf(c) + '名で満員です）\n\n'
        + '新しい運営人数を入れてください（「6〜8」のような範囲でも入れられます）。';
    const input = prompt(head, closed ? String(now) : String(now + 1));
    if (input === null) return;
    const value = input.trim();
    if (value === '') return;
    if (!USING_DB){ c.need = parseInt(value, 10) || c.need; c.needStaff = c.need; c.recruit = true; render(); return; }
    fetch('/assign-sheet/project', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF, 'Accept': 'application/json' },
      body: JSON.stringify({ project_id: id, field: 'required_count', value: value })
    })
      .then(r => r.ok ? r.json() : Promise.reject(r.status))
      .then(res => {
        if (!res || !res.ok) return Promise.reject((res && res.message) || '運営人数を変えられませんでした。');
        // ⚠ 募集を締めていたら、ここで開け直す（保存の入口は案件一覧のセルと同じ）。
        return fetch('/projects/cells', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF, 'Accept': 'application/json' },
          body: JSON.stringify({ id: id, recruit: true })
        }).then(r => r.ok ? r.json() : Promise.reject(r.status));
      })
      .then(() => {
        // 画面を開き直さなくても分かるように、その場で数字と募集の印を入れ替える。
        const max = Math.max.apply(null, (value.match(/\d+/g) || ['0']).map(Number));
        if (max > 0) { c.need = max; c.needStaff = max; }
        c.recruit = true;
        render();
        alert('運営人数を「' + value + '」にして、募集を再開しました。\nスタッフの画面にまた募集として出ます。');
      })
      .catch(e => alert('募集を再開できませんでした（' + e + '）。もう一度お試しください。'));
  }

  function buildCard(c, dayCases, dupNames, amap){
    dupNames = dupNames || new Set();
    amap = amap || {};
    const card = document.createElement('div');
    card.className = 'case-card ' + c.state;
    card.id = 'case-' + c.id;   // 本物の案件ID。ダッシュボードからの ?focus=案件ID で狙い撃ちできる
    const editMode = editing.has(c.id);
    const filled = filledOf(c);

    // 充足バー
    // ⚠ 「🔒 この人数で足りている」で募集を締めた案件は、人数が運営人数に届いていなくても
    //   バーを満たして緑にする（2026-09-01 baba指摘）。
    //   締めたのにバーが「足りていない」ままだと、まだ人を足すのかどうかが分からない。
    //   運営人数（セールスが書いた予定）は消さず、数字の横に「予定◯名」として残す。
    const settled = bPubOn(c) && !bRecruit(c);
    const ratio = settled ? 1 : (c.need ? Math.min(1, filled / c.need) : 0);
    const barCls = (settled || filled >= c.need) ? 'full' : (ratio >= 0.7 ? 'mid' : 'low');

    // ポジション充足ランプ
    const posHtml = c.pos.map(p => {
      const cls = p[1];
      const mark = cls === 'ok' ? '✓' : (cls === 'short' ? '△不足' : '未');
      return `<span class="plamp ${cls}">${p[0]} ${mark}</span>`;
    }).join('');

    // タグ（前日設営・連勤・前泊・◯日目）
    const tagHtml = (c.tags || []).map(t => {
      let cls = 'day';
      if (t.includes('連勤'))      cls = 'renkin';
      else if (t.includes('設営')) cls = 'setup';
      else if (t.includes('前泊')) cls = 'stay';
      else if (t.includes('日目')) cls = 'day';
      return `<span class="ctag ${cls}">${t}</span>`;
    }).join('');

    // 案件の備考（見落とし＝事故を防ぐため必ず出す）。この帯からその場で直せる（2026-08-21 baba）。
    // 未記入でも「📌 備考 未記入 ✎直す」を出す＝ここが入力の入口になる。
    const noteHtml = window.ecsNoteHtml(c.id, c.note, USING_DB);

    // 割当メンバー（スタッフ＋社員＋派遣）。同じ日にかぶる人は赤文字。
    // 各メンバーの右に「他にどの案件に出ているか」を小タグで表示（同日=赤／別日=緑＝連続起用OK）。
    const members = buildMembers(c);
    const memRows = members.map((m, i) => {
      const dup = dupNames.has(m.name) ? 'dup' : '';
      const x = editMode ? `<span class="m-x" title="外す" onclick="removeMember('${c.id}','${encodeURIComponent(m.id||m.name)}')">×</span>` : '';
      // 連勤（この期間に何日ぶん出ているか）を1個のまとめバッジで表示。
      // 案件ごとの細かいタグ（旧xcase）はやめて、行を短く保つ。同日かぶりは名前の⚠(dup)で警告する。
      // バッジにカーソルを合わせると「どの日・どの案件か」の内訳が出る（title属性）。
      const myCases = (amap[m.name] || [])
        .map(id => cases.find(z => z.id === id))
        .filter(Boolean)
        .sort((a, b) => a.off - b.off);
      const myOffs = new Set(myCases.map(oc => oc.off));
      const dayN = myOffs.size;
      const renkinList = myCases.map(oc => {
        const d = addDays(oc.off);
        return `・${d.getMonth() + 1}/${d.getDate()} ${oc.name}`;
      }).join('\n');
      const renkinTag = dayN >= 2
        ? `<span class="renkin-badge ${dayN >= 4 ? 'hi' : ''}" title="連勤の内訳（この期間に ${dayN}日ぶん）：\n${renkinList}">連${dayN}日</span>`
        : '';
      const capb = m.type === 'staff' ? capBadge(m.name, amap) : '';
      // 名前の色でも区別する（2026-08-28 baba要望）＝社員は青・派遣は紫。横に出るバッジと同じ色。
      // ⚠ かぶり(dup)の赤が勝つようにCSS側で書く順番を決めている。
      const kindCls = m.type === 'emp' ? ' emp' : (m.type === 'haken' ? ' haken' : '');
      return `<div class="mem-row"><span class="m-no">${i+1}</span><span class="m-name ${dup}${kindCls}" title="${m.name}">${dup ? '⚠' : ''}${m.name}</span>${typeBadge(m.type)}${statusCellHtml(c, m)}${posCellHtml(c, m)}${role2CellHtml(c, m)}${noteCellHtml(c, m)}${patrolCellHtml(c, m)}${remarkCellHtml(c, m)}${capb}${renkinTag}${x}</div>`;
    }).join('');
    // 「仮」の人数（この人たちはスタッフの画面に出ないので、見出しで気づけるようにする・2026-08-21 baba）
    const kariN = members.filter(m => m.status === '仮').length;
    // 「仮」の社員の人数（2026-09-03 baba要望）。社員だけ先にまとめて確定にできるようにする。
    const kariEmpN = members.filter(m => m.status === '仮' && m.type === 'emp' && m.id).length;
    const fixEmpBtn = kariEmpN
      ? `<button class="fix-emp" title="この案件の「仮」の社員 ${kariEmpN}名を、まとめて「確定」にします（スタッフは変わりません）。" onclick="fixEmployees('${c.id}')">社員${kariEmpN}名を確定</button>`
      : '';
    const memCol =
      `<div class="cc-col">
         <div class="col-h"><span class="cl-toggle" onclick="toggleCol(this)"><span class="cl-arrow">▾</span> メンバー（${filled}/${c.need}名）</span>${kariN ? `<span class="kari-warn" title="「仮」の人はスタッフの画面に出ません。名前の横の「仮」を押すと確定にできます。">仮 ${kariN}名</span>` : ''}${fixEmpBtn}</div>
         <div class="col-list">${memRows || '<div class="mem-none">メンバー未割当</div>'}${dispatchRowsHtml(c)}</div>
         ${editMode ? `<div class="add-row">
             <button class="mini" onclick="openPicker('${c.id}','employee')" title="社員を名前でしぼって、チェックした人をまとめて入れます">＋社員を追加</button>
             <button class="mini" onclick="openPicker('${c.id}','staff')" title="スタッフを名前でしぼって、チェックした人をまとめて入れます。LINEで入れると言われた方もここから。">＋スタッフを追加</button>
             <button class="mini" onclick="addHaken('${c.id}')" title="派遣先・人数・役割を入れて保存します。あとから直すのは「派遣一覧」から。">＋派遣</button>
           </div>
           <div class="pick-box" id="pick-${c.id}" style="display:none;"></div>` : ''}
       </div>`;

    // この日の希望者（応募＋カレンダー〇）＝色分け。手動編集中は ＋ でメンバーへ。
    const dp = dayPeople(c.off, dayCases).filter(p => p.applied.includes(c.id) || p.cal);
    // ⚠ その日、別の案件にもう入っている人（2026-09-03 baba要望）。
    //   希望を出していても、その日はもう埋まっている＝声を掛けても入れない。
    //   ⚠ 案件名まで出すと横幅を取ると言われたので、印は ⛔ だけにする（2026-09-03）。
    //     どの案件に入っているかは、マウスを乗せたとき（title）に出す。
    const busyWhere = takenSameDayWhere(c);

    // すでに埋まっている度合い。0＝空いている／1＝その日の別案件／2＝この案件のメンバー。
    // ⚠ これで「下へ回す」並べ替えをする（2026-09-03 baba要望）。
    //   上から順に見れば、まだ声を掛けられる人だけになる。
    function busyRank(p){
      if (c.assigned.some(m => m.name === p.name)) return 2;
      if (busyWhere.has(p.name)) return 1;
      return 0;
    }
    // 空いている人を先に。同じ組の中の並びは元のまま（勝手に入れ替えない）。
    function sortFreeFirst(list){
      return list.map((p, i) => ({ p: p, i: i }))
                 .sort((a, b) => (busyRank(a.p) - busyRank(b.p)) || (a.i - b.i))
                 .map(x => x.p);
    }

    // 1行ぶんの HTML。
    function candRowHtml(p){
      // すでにこの案件のメンバーに入っている人＝アサイン済み（グレーアウト・＋ボタン無し）
      const picked = c.assigned.some(m => m.name === p.name);
      const where  = (!picked && busyWhere.get(p.name)) || null;
      const st = candStatus(p);
      const busyTag = where ? `<span class="cstat busy" title="${escHtml(busyTitle(p.name, where))}">⛔</span>` : '';
      const statTag = picked ? '<span class="cstat done">✓ アサイン済み</span>' : st.tag;
      const rowCls  = picked ? 'picked' : (where ? 'busy' : st.cls);
      const addTitle = where ? busyTitle(p.name, where) + ' それでも入れるときは押してください。' : 'メンバーに入れる';
      const addBtn = (editMode && !picked) ? `<span class="c-add" title="${escHtml(addTitle)}" onclick="addCandidate('${c.id}','${p.id||''}','${encodeURIComponent(p.name)}','${p.lv}','${encodeURIComponent(p.pos||'')}','${p.roleCode||''}')">＋</span>` : '';
      // 本人が応募時に書いた一言。アサインの判断材料なので必ず見せる（2026-08-21 baba）。
      const cmt = (p.notes && p.notes[c.id]) ? p.notes[c.id] : '';
      const cmtHtml = cmt ? `<div class="cand-note" title="本人からの一言">💬 ${escHtml(cmt)}</div>` : '';
      return `<div class="mem-row cand-row ${rowCls}"><span class="m-name">${p.name}</span><span class="m-lv ${p.lv}">${lvLabel[p.lv]}</span><span class="m-pos">${p.pos}</span>${capBadge(p.name, amap)}${busyTag}${statTag}${addBtn}${cmtHtml}</div>`;
    }

    // ⚠ 社員は「基本イベントには出ない」ので、たたんでおく（2026-09-03 baba要望）。
    //   社員の出勤可能日もスタッフと同じ表（shift_preferences）に入るため、
    //   混ぜて並べると、声を掛ける相手を探すのに邪魔になる。
    const dpStaff = sortFreeFirst(dp.filter(p => !p.emp));
    const dpEmp   = sortFreeFirst(dp.filter(p => p.emp));
    const freeCount = dpStaff.filter(p => busyRank(p) === 0).length;

    const staffRows = dpStaff.map(candRowHtml).join('');
    const empRows = dpEmp.length
      ? `<details class="cand-emp"><summary>社員 ${dpEmp.length}名（ふだんイベントには出ません）</summary>${dpEmp.map(candRowHtml).join('')}</details>`
      : '';
    // 見出しは「まだ声を掛けられる人が何人か」を先に出す。
    const candHead = (dpStaff.length && freeCount < dpStaff.length)
      ? `希望者（${dpStaff.length}名 <span style="color:#15803d;">空き${freeCount}名</span>）`
      : `希望者（${dpStaff.length}名）`;
    // ⚠ 拠点がちがうので出していない人がいるときは、その人数と理由を出す（2026-09-03）。
    //   黙って消すと「終日〇を出したのに希望者に出てこない」になり、原因にたどり着けない。
    const hiddenN = (window.ECS_BOARD_AVAIL_HIDDEN || {})[c.off] || 0;
    const hiddenHtml = hiddenN
      ? `<div class="cand-hidden" title="この画面はいまの拠点で絞って見ています。ほかの拠点の方を出すには、上の拠点の選び方を変えるか、その方の事務所を直してください。">ほかの拠点のため ${hiddenN}名を出していません</div>`
      : '';
    const candCol =
      `<div class="cc-col">
         <div class="col-h"><span class="cl-toggle" onclick="toggleCol(this)"><span class="cl-arrow">▾</span> ${candHead}</span></div>
         <div class="col-list">${staffRows || '<div class="mem-none">希望者はいません。</div>'}${empRows}${hiddenHtml}</div>
       </div>`;

    // ===== 状態を進めるボタン =====
    // ⚠ 「公開（＝募集をかける）」と「案件の進み具合（未着手→調整中→確定）」は**別のこと**。
    //   以前は公開ずみを最優先の状態にしていたため、**募集をかけた瞬間に
    //   「✓ 確定にする」（＝メンバーを確定にする）ボタンが消えて**いた。
    //   実際は 公開して募集 → エントリーが集まる → アサイン → **そこで確定**、なので
    //   いちばん必要なときにボタンが無かった（2026-08-28 baba指摘）。
    //   いまは公開していても、案件が確定になるまで「✓ 確定にする」を出し続ける。
    const stat  = c.stat  || c.state;              // 案件の進み具合だけ（todo/adj/fix）
    const pubOn = (c.pubOn !== undefined) ? !!c.pubOn : (c.state === 'pub');  // 募集中か
    const kari  = c.assigned.filter(m => m.status === '仮').length;

    let stateBtn = '';
    if (stat === 'todo' || stat === 'adj') {
      stateBtn += `<button class="edit-btn" onclick="markFix('${c.id}')" title="案件を確定にし、あわせてメンバー全員を「確定」にします">✓ 確定にする</button>`;
    } else if (!pubOn) {
      // 確定ずみ・まだ募集をかけていない＝ここから公開できる。
      stateBtn += `<button class="auto-btn" onclick="markPub('${c.id}')">📣 スタッフに公開</button>`;
    }
    // 確定ずみでも、あとから足した人は「仮」で入る。まとめて確定にできるようにする。
    // ⚠ スタッフの画面に出るのは「確定」の人だけ＝仮のままだと本人に伝わらない。
    if (stat === 'fix' && kari) {
      stateBtn += `<button class="edit-btn" onclick="fixMembers('${c.id}')" title="あとから足した人は「仮」で入ります。押すと全員「確定」になり、本人の画面に出ます。">✓ 仮の${kari}名を確定にする</button>`;
    }
    // 公開をやめる・締切や伝えることを直すのは公開ボードに任せる
    // （公開のON/OFFの入口を増やすと、どこで切ったか分からなくなるため）。
    // 締切（満員）のときは「追加募集する」＝運営人数を増やす道を出す（2026-08-28 baba要望）。
    // ⚠ 公開し直す必要はない。人数を増やせば、その場でまたスタッフ画面に「募集中」で出る。
    // 「🔒 この人数で足りている」＝運営人数は埋まっていないが、これで決まりにする（2026-09-01 baba要望）。
    // ⚠ 「確定にする」とは別の意思表示。確定にしても**本当に人が足りなくて募集を続けたい**案件があるため。
    //   押すと募集が締まり、「募集中 あと◯名」が消える。運営人数（セールスが書いた数）は変えない。
    if (pubOn && bRecruit(c) && !isFullForStaff(c)) {
      stateBtn += `<button class="edit-btn" onclick="closeRecruit('${c.id}')" title="運営人数は埋まっていませんが、この人数で決まりにします。スタッフの募集を締めて「募集中 あと◯名」を消します（運営人数は変えません）。あとで足したくなったら「＋ 追加募集する」で戻せます。">🔒 この人数で足りている</button>`;
    }
    // ⚠ 募集を締めたあとにも出す（2026-09-01 baba「確定のあとに追加募集することはある」）。
    //   ここを満員のときだけにしていると、締めた案件に人を足せなくなる。
    if (pubOn && (isFullForStaff(c) || !bRecruit(c))) {
      stateBtn += `<button class="edit-btn" onclick="addRecruit('${c.id}')" title="スタッフの画面にまた募集として出します（公開し直す必要はありません）">＋ 追加募集する</button>`;
    }
    // ⚠ 「?project=案件ID」を付けて、公開ボードの**その案件の行**まで飛ばす（2026-08-28 baba要望）。
    //   前はただ公開ボードを開くだけで、どの案件だったか探し直す必要があった。
    if (pubOn) {
      stateBtn += `<a class="open-btn" href="/assign-publish?project=${encodeURIComponent(c.id)}" title="この案件の行を公開ボードで開きます（公開をやめる・締切や伝えることを直すのはそこから）">公開ボードで開く →</a>`;
    }

    card.innerHTML = `
      <div class="cc-head">
        <div class="cc-headmain">
          ${titleBlockHtml(c)}
          <div class="cc-client">${c.client}</div>
          <div class="cc-meta">
            <span><span class="ic">🕘</span> 集合 ${c.meet || '—'}〜解散 ${c.leave || '—'}</span>
            <span><span class="ic">📍</span> <span class="venue" title="${c.place || ''}">${c.placeShort || c.place || '—'}</span></span>
            ${c.meetPlace ? `<span><span class="ic">🚩</span> 集合場所：${c.meetPlace}</span>` : ''}
          </div>
        </div>
        <!-- ⚠ 案件の進み具合と「募集中（公開）」は別のことなので、印も分けて出す。
             前は公開すると進み具合が「公開済」で隠れ、確定なのか調整中なのか分からなかった。 -->
        <span class="sb ${stat}" title="案件の進み具合">${stateLabel[stat] || ''}</span>${recruitBadge(c)}
        <div class="cc-actions">
          <button class="edit-btn ${editMode ? 'on' : ''}" onclick="toggleEdit('${c.id}')">✎ ${editMode ? '編集を終える' : '手動編集'}</button>
          ${(filled < c.need && !settled) ? `<button class="auto-btn" onclick="autoAssign('${c.id}')">⚡ 自動アサイン</button>` : ''}
          ${stateBtn}
          ${detailBtnHtml(c)}
          <a class="open-btn" href="/project-assign?project=${c.id}">アサイン画面 →</a>
        </div>
      </div>
      ${noteHtml}
      ${tagHtml ? `<div class="cc-tags">${tagHtml}</div>` : ''}
      <div class="cc-pos"><span class="badge cat-${c.cat}">${c.cat}</span>${fmtBadgeHtml(c)}${posHtml}</div>
      <div class="cc-fill">
        <div class="fbar"><i class="${barCls}" style="width:${Math.round(ratio*100)}%;"></i></div>
        <span class="fnum">${filled}${settled
          ? `<span class="need">名（この人数で確定・予定${c.need}名）</span>`
          : `<span class="need"> / ${c.need}名</span>`}</span>
      </div>
      <div class="cc-cols">
        ${memCol}
        ${candCol}
      </div>`;
    return card;
  }

  // アサインダッシュボード「アサインが必要な案件」などから ?focus=<案件ID> で来たら、
  // その案件カードまでスクロールして一時的に強調する（受け取り側）。
  // 絞り込みで対象が隠れていると見つからないので、先にフィルタを解除してから描画し直す。
  function applyFocus(){
    const id = new URLSearchParams(location.search).get('focus');
    if (!id) return;
    const sf = document.getElementById('stateFilter');
    const mine = document.getElementById('mineOnly');
    if (sf) sf.value = '';
    if (mine) mine.checked = false;
    render();
    const el = document.getElementById('case-' + id);
    if (!el) return;
    el.scrollIntoView({ behavior:'smooth', block:'center' });
    el.style.transition = 'box-shadow .3s';
    el.style.boxShadow = '0 0 0 3px #e8833a, 0 8px 24px rgba(0,0,0,.14)';
    setTimeout(() => { el.style.boxShadow = ''; }, 4000);
  }

  // 担当メモ入力の候補（datalist）を1度だけ用意する。各入力は list="ecsNoteList" で参照する。
  (function initNoteDatalist(){
    if (document.getElementById('ecsNoteList')) return;
    const dl = document.createElement('datalist');
    dl.id = 'ecsNoteList';
    (window.ECS_NOTE_OPTIONS || []).forEach(v => {
      const op = document.createElement('option');
      op.value = v;
      dl.appendChild(op);
    });
    document.body.appendChild(dl);
  })();

  // 日付ピッカーに現在の基準日を表示（空なら今日）。
  (function initFromDate(){
    const el = document.getElementById('fromDate');
    if (el) el.value = (window.ECS_BOARD_ANCHOR && /^\d{4}-\d{2}-\d{2}$/.test(window.ECS_BOARD_ANCHOR))
      ? window.ECS_BOARD_ANCHOR : ymd(new Date());
  })();

  // 初期描画
  render();
  applyFocus();
</script>
@endverbatim
@endpush
