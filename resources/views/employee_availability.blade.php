@extends('layouts.app')
@section('title', '社員の出勤可能日（参加希望）')
@section('h1', '社員の出勤可能日（参加希望）')
@php($active = 'employee_availability')

@push('head')
@verbatim
<style>
    /* ===== 社員の出勤可能日（参加希望）専用スタイル ===== */

    /* タブ切替 */
    .ea-tabs { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
    .ea-tab {
      padding: 9px 18px; border: 1px solid var(--line); border-radius: 999px;
      background: #fff; cursor: pointer; font-size: 14px; color: #6b5544; font-weight: 600;
    }
    .ea-tab.active { background: var(--brand); border-color: var(--brand-dark); color: #fff; }

    /* 月切替バー */
    .ea-monthbar { display: flex; align-items: center; justify-content: center; gap: 16px; margin: 4px 0 16px; }
    .ea-monthbar button {
      border: 1px solid var(--line); background: #fff; border-radius: 8px;
      width: 36px; height: 36px; font-size: 16px; cursor: pointer; color: #6b5544;
    }
    .ea-monthbar button:hover { background: #f3ece2; }
    .ea-monthbar .mon { font-size: 18px; font-weight: 700; min-width: 150px; text-align: center; }

    .ea-pane { display: none; }
    .ea-pane.show { display: block; }

    /* カレンダー */
    .ea-card { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 18px; margin-bottom: 16px; }
    .ea-card h3 { margin: 0 0 4px; font-size: 15px; }
    .ea-card .sub { font-size: 12px; color: var(--muted); margin: 0 0 14px; line-height: 1.6; }
    /* ⚠ 列は minmax(0, 1fr) にする。ただの 1fr だと、中身（長い会社名など）に押されて
       列そのものが横に広がり、マスの大きさがバラバラになる（2026-08-28 修正）。 */
    .cal-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; max-width: 640px; margin: 0 auto; }
    .cal-grid .dow { text-align: center; font-size: 12px; color: var(--muted); padding-bottom: 4px; font-weight: 600; }
    .cal-grid .dow.sat { color: var(--brand); }
    .cal-grid .dow.sun { color: var(--danger); }
    /* ⚠ 高さは min-height ではなく height で固定する。min-height だと
       案件名・メモが入ったマスだけ縦に伸びて、カレンダーがガタガタになる（2026-08-28 修正）。
       入りきらないぶんは「＋n件」でまとめる（切り捨てない）。 */
    .cell {
      height: 110px; box-sizing: border-box; overflow: hidden;
      border-radius: 10px; border: 1px solid var(--line);
      display: flex; flex-direction: column; align-items: center; justify-content: flex-start;
      padding: 5px 2px 4px; font-size: 13px; background: #fff; position: relative;
    }
    .cell.empty { border: none; background: none; }
    .cell .dnum { font-size: 12px; color: #8a7a66; align-self: flex-start; margin-left: 6px; }
    .cell .badge {
      position: absolute; top: 4px; right: 4px; font-size: 9px; line-height: 1;
      padding: 2px 4px; border-radius: 4px; background: #f3e3c8; color: #92600a; font-weight: 700;
    }
    .cell .badge.big { background: #fde68a; color: #7a5200; border: 1px solid #e0b84a; }
    .cell .st { font-size: 16px; font-weight: 700; margin-top: 4px; }
    .cell .stsub { font-size: 9px; margin-top: 1px; }

    /* ===== マスに出す帯（大型案件の会社名／決まっている案件／その日のメモ） ===== */
    /* ⚠ 帯はぜんぶ同じ大きさ・同じ入れ物にする。別々の入れ物にすると、
       入っている中身の組み合わせでマスの高さが変わってしまう。 */
    .cell .chips { width: 100%; min-width: 0; }
    .cell .chip {
      max-width: calc(100% - 6px); box-sizing: border-box; margin: 2px auto 0;
      height: 14px; font-size: 9px; line-height: 12px; padding: 0 3px; border-radius: 3px;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-align: center;
    }
    /* 黄色＝大型案件のお客様の会社名（上の「大型」バッジと同じ色）。 */
    .cell .chip.big  { background: #fde68a; color: #7a5200; border: 1px solid #e0b84a; font-weight: 700; }
    /* 青＝ECSが自動で出したもの（アサインが確定した案件）。手では消せない。 */
    .cell .chip.auto { background: #e2edfb; color: #1d4e89; border: 1px solid #bcd6f2; font-weight: 700; }
    /* 茶の破線＝自分で書いたメモ。消せる。 */
    .cell .chip.note { background: #faf5ec; color: #6b5544; border: 1px dashed #d9cbb4; }
    /* 入りきらなかったぶん（マウスを乗せると全部出る）。 */
    .cell .chip.more { background: #f1ece4; color: #7a6a56; border: 1px solid #ded4c6; cursor: help; }
    /* 案件が決まっている日に「〇（出勤可）」のままのとき出す注意の印。自動では直さない。 */
    .cell .warn { position: absolute; left: 3px; top: 3px; font-size: 11px; line-height: 1; cursor: help; }
    /* その日のメモを書くボタン（マスをクリックすると〇×△が変わってしまうので別のボタンにする） */
    .cell .memobtn {
      position: absolute; right: 3px; bottom: 2px; font-size: 10px; line-height: 1;
      border: none; background: none; color: #b8a894; cursor: pointer; padding: 2px;
    }
    .cell .memobtn:hover { color: var(--brand-dark); }
    .cell.has-auto { border-color: #bcd6f2; }

    /* その日のメモを書く小窓 */
    .dn-back { position: fixed; inset: 0; background: rgba(0,0,0,.35); z-index: 60; display: none; }
    .dn-back.show { display: flex; align-items: center; justify-content: center; }
    .dn-box { background: #fff; border-radius: 14px; padding: 18px; width: min(420px, 92vw); box-shadow: 0 10px 30px rgba(0,0,0,.25); }
    .dn-box h4 { margin: 0 0 4px; font-size: 15px; }
    .dn-box .dn-sub { font-size: 12px; color: var(--muted); margin: 0 0 10px; line-height: 1.6; }
    .dn-box .dn-auto { font-size: 12px; color: #1d4e89; background: #eef5fd; border: 1px solid #bcd6f2;
                       border-radius: 8px; padding: 8px 10px; margin-bottom: 10px; line-height: 1.6; }
    .dn-box textarea { width: 100%; box-sizing: border-box; min-height: 80px; resize: vertical;
                       border: 1px solid var(--line); border-radius: 8px; padding: 8px 10px; font-size: 14px; font-family: inherit; }
    .dn-btns { display: flex; gap: 8px; justify-content: flex-end; margin-top: 12px; }
    .dn-btns button { border-radius: 8px; padding: 8px 16px; font-size: 13px; font-weight: 700; cursor: pointer; }
    .dn-btns .ok { background: var(--brand); color: #fff; border: none; }
    .dn-btns .cancel { background: #fff; color: #6b5544; border: 1px solid var(--line); }

    /* イベント候補日（土日祝・長期休暇）＝クリックで 〇×△ */
    .cell.event { cursor: pointer; }
    .cell.event:hover { box-shadow: 0 0 0 2px rgba(180,140,90,.18) inset; }
    .cell.v-ok    { background: var(--ok-soft);     border-color: #bbe3c6; }
    .cell.v-ok .st    { color: #15803d; }
    .cell.v-ng    { background: var(--danger-soft); border-color: #f4c2c2; }
    .cell.v-ng .st    { color: #b91c1c; }
    .cell.v-maybe { background: #fdf0d2;            border-color: #f0d79a; }
    .cell.v-maybe .st { color: #b45309; }
    /* 未入力の入力必須日（土日祝・長期休暇・大型）＝要入力の印 */
    .cell.event.v-need { background: #f3effa; border: 2px dashed #b9a3e0; }
    .cell.event.v-need .stsub { color: #7c5ec0; font-weight: 700; }

    /* 平日＝クリックで希望休 */
    .cell.weekday { cursor: pointer; }
    .cell.weekday:hover { box-shadow: 0 0 0 2px rgba(180,140,90,.12) inset; }
    .cell.weekday.off { background: #eef1f6; border-color: #cfd6e2; }
    .cell.weekday.off .st { color: #556683; font-size: 12px; }
    .cell.weekday.off .stsub { color: #6b7689; }

    .ea-legend { display: flex; justify-content: center; gap: 16px; flex-wrap: wrap; margin: 14px 0 2px; font-size: 12px; color: #555; }
    .ea-legend span { display: inline-flex; align-items: center; gap: 6px; }
    .ea-legend i { width: 16px; height: 16px; border-radius: 5px; display: inline-block; border: 1px solid var(--line); }
    .lg-ok { background: var(--ok-soft); border-color: #bbe3c6 !important; }
    .lg-ng { background: var(--danger-soft); border-color: #f4c2c2 !important; }
    .lg-maybe { background: #fdf0d2; border-color: #f0d79a !important; }
    .lg-off { background: #eef1f6; border-color: #cfd6e2 !important; }
    .lg-need { background: #f3effa; border-color: #b9a3e0 !important; border-style: dashed; }

    /* 入力フォーム */
    .ea-fields { display: grid; gap: 14px; max-width: 640px; }
    .ea-field label { display: block; font-size: 13px; font-weight: 700; margin-bottom: 5px; color: #4a3b2c; }
    .ea-field .hint { font-size: 11px; color: var(--muted); font-weight: 400; margin-left: 6px; }
    .ea-field input[type=number], .ea-field textarea {
      width: 100%; box-sizing: border-box; border: 1px solid var(--line); border-radius: 8px;
      padding: 8px 10px; font-size: 14px; font-family: inherit;
    }
    .ea-field input[type=number] { width: 120px; }
    .ea-field textarea { min-height: 60px; resize: vertical; }
    .ea-save { margin-top: 6px; }
    .ea-save button {
      background: var(--brand); color: #fff; border: none; border-radius: 8px;
      padding: 10px 22px; font-size: 14px; font-weight: 700; cursor: pointer;
    }
    .ea-save button:hover { background: var(--brand-dark); }
    .ea-saved { margin-left: 12px; color: #15803d; font-size: 13px; font-weight: 700; display: none; }

    /* 常に見える「保存」ボタン（右下に浮く）＝下までスクロールしなくても押せる */
    .ea-float-save {
      position: fixed; right: 28px; bottom: 24px; z-index: 50;
      background: var(--brand); color: #fff; border: none; border-radius: 999px;
      padding: 14px 26px; font-size: 15px; font-weight: 700; cursor: pointer;
      box-shadow: 0 6px 18px rgba(0,0,0,.18);
    }
    .ea-float-save:hover { background: var(--brand-dark); }

    /* 全社員一覧テーブル */
    .ov-wrap { overflow-x: auto; }
    table.ov-tbl { border-collapse: collapse; font-size: 13px; min-width: 600px; }
    table.ov-tbl th, table.ov-tbl td { border: 1px solid var(--line); padding: 6px 8px; text-align: center; white-space: nowrap; }
    table.ov-tbl thead th { background: #f3ece2; color: #5a4a38; font-weight: 700; }
    table.ov-tbl th.namecol { text-align: left; position: sticky; left: 0; background: #f3ece2; z-index: 1; }
    table.ov-tbl td.namecol { text-align: left; position: sticky; left: 0; background: #fff; font-weight: 600; }
    /* 所属ごとの背景色（2026-09-01 baba要望）。並びが イベプラ → セールス → その他 なので、
       色が変わるところが組の切れ目になる。
       ⚠ 色はここに書かない。App\Support\Departments が持っている名簿のバッジと同じ色を流し込む
         ＝2か所で色がずれない。名前のマスは表を横スクロールしても残る（sticky）ので、
         そこにも同じ色を敷かないと、スクロールしたとき組が分からなくなる。 */
@endverbatim
    {!! \App\Support\Departments::rowBgCss('table.ov-tbl tr', 'td.namecol') !!}
@verbatim
    /* 自分の行は今までどおり目立たせる（所属の色より優先）。 */
    table.ov-tbl tr.me td.namecol { background: #fff7ec; }
    table.ov-tbl tr.me td { background: #fffaf2; }
    table.ov-tbl .vh th.we { color: var(--brand); }
    table.ov-tbl .vh th.holi { color: #b45309; }
    .ov-mark.ok    { color: #15803d; font-weight: 700; }
    .ov-mark.ng    { color: #b91c1c; }
    .ov-mark.maybe { color: #b45309; font-weight: 700; }
    .ov-mark.none  { color: #c7bba9; }
    table.ov-tbl th.we.big, table.ov-tbl th.holi.big { color: #7a5200; }
    /* 大型案件のお客様の会社名（見出し）。⚠ 幅を決めて省略しないと列が横に伸びる。 */
    table.ov-tbl th .bigclient {
      display: block; max-width: 84px; margin: 3px auto 0; padding: 1px 3px;
      font-size: 9px; font-weight: 700; color: #7a5200;
      background: #fde68a; border: 1px solid #e0b84a; border-radius: 3px;
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    table.ov-tbl td.offcol, table.ov-tbl th.offcol,
    table.ov-tbl td.memocol, table.ov-tbl th.memocol {
      text-align: left; white-space: normal; min-width: 130px; max-width: 220px;
    }
    table.ov-tbl td.offcol { color: #556683; }
    table.ov-tbl td.memocol { color: #5a4a38; font-size: 12px; }
    /* もう案件が決まっている人の日＝薄い青。〇×△はそのまま（本人の申告なので変えない）。 */
    table.ov-tbl td.ovbusy { background: #eef5fd; }
    table.ov-tbl tr.me td.ovbusy { background: #eaf1fa; }
    table.ov-tbl td.ovbusy .ovbusy-txt {
      font-size: 9px; color: #1d4e89; margin-top: 1px; max-width: 74px;
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    /* 本人が書いた「その日のメモ」。⚠ カレンダーと同じ茶の破線にそろえる。 */
    table.ov-tbl td.ovnote { background: #fdfaf4; }
    table.ov-tbl tr.me td.ovnote { background: #fdf8ef; }
    table.ov-tbl td.ovbusy.ovnote { background: #f4f4f6; }
    table.ov-tbl td .ovnote-txt {
      font-size: 9px; color: #6b5544; margin-top: 1px; max-width: 74px;
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap; cursor: help;
    }
    table.ov-tbl tfoot td { background: #faf5ee; font-weight: 700; color: #5a4a38; }
    table.ov-tbl tfoot td.few { background: #fbe3e3; color: #b91c1c; }
    .ov-note { font-size: 12px; color: var(--muted); margin-top: 10px; line-height: 1.7; }

    /* =========================================================================
       スマホ対応（375px幅を想定）。ここから下は画面が狭いときだけ効く。
       PCの見た目は一切変えない。共通の指定は public/ecs/style.css の同じ
       @media に入っているので、ここには「この画面だけの困りごと」だけ書く。
       ========================================================================= */
    @media (max-width: 720px) {

      /* タブは2つを半分ずつに。指で押しやすい大きさをそろえるため。 */
      .ea-tabs { gap: 6px; margin-bottom: 12px; }
      .ea-tab { flex: 1 1 0; min-width: 0; padding: 10px 8px; font-size: 13px; }

      /* 月切替バー：「2026年 8月」の表示が固定150pxだと
         狭い画面で〈 〉ボタンごと外へはみ出すので、幅を縮めて折り返せるようにする。 */
      .ea-monthbar { gap: 10px; flex-wrap: wrap; margin: 2px 0 12px; }
      .ea-monthbar button { width: 44px; height: 44px; font-size: 18px; }  /* 指で押せる44px角に */
      .ea-monthbar .mon { min-width: 0; flex: 1 1 auto; font-size: 16px; }

      /* カード：内側の余白が18pxもあるとカレンダー7列ぶんの幅が足りなくなる。 */
      .ea-card { padding: 12px; border-radius: 10px; margin-bottom: 12px; }
      .ea-card .sub { font-size: 11.5px; margin-bottom: 10px; }

      /* カレンダーは7列のまま（曜日が縦にそろっていることが大事なので列は減らさない）。
         代わりに すき間と文字を小さくして、375pxでも1マス約44px＝指で押せる大きさを確保する。 */
      .cal-grid { gap: 3px; }
      .cal-grid .dow { font-size: 11px; }
      /* ⚠ スマホでも高さは固定（PCと同じ理由＝マスの高さが揃っていないと日付が追いにくい）。 */
      .cell { height: 92px; padding: 3px 1px; font-size: 12px; border-radius: 8px; }
      .cell .dnum { font-size: 11px; margin-left: 3px; }
      .cell .badge { font-size: 8px; padding: 1px 2px; top: 2px; right: 2px; }
      .cell .st { font-size: 15px; margin-top: 2px; }
      .cell .stsub { font-size: 8px; }
      .cell.weekday.off .st { font-size: 11px; }
      /* 画面が狭いので帯も小さくする（詳しくは長押しで出る）。 */
      .cell .chip { height: 12px; font-size: 8px; line-height: 10px; margin-top: 1px; padding: 0 2px; }
      .cell .memobtn { font-size: 9px; right: 1px; bottom: 0; }

      /* 凡例は項目が多いので、すき間をつめて2〜3段に折り返す。 */
      .ea-legend { gap: 8px 12px; font-size: 11px; margin-top: 10px; }

      /* 入力欄：max-width:640px は狭い画面では効かないが、
         親の幅からはみ出さないよう念のため上限を画面幅にそろえる。 */
      .ea-fields { max-width: 100%; min-width: 0; gap: 12px; }
      /* 数値欄の固定120pxと14pxの文字は、iPhoneで入力すると勝手に拡大されるので
         幅いっぱい・16pxにする（16px未満だと自動ズームが起きる）。 */
      .ea-field input[type=number] { width: 100%; font-size: 16px; }
      .ea-field textarea { font-size: 16px; }
      .ea-save button { width: 100%; padding: 12px 16px; font-size: 15px; }
      .ea-saved { display: block; margin: 8px 0 0; }

      /* 右下に浮く保存ボタン：PCと同じ大きさだと画面のはしに寄りすぎて
         カレンダーの日付に重なるため、少し小さくして角に寄せる。 */
      .ea-float-save { right: 12px; bottom: 12px; padding: 12px 18px; font-size: 14px; }

      /* 全社員一覧の表：入れ物の中だけを横スクロールさせる。
         入れ物自体が広がるとページごと横に伸びてしまうので、上限を親の幅にそろえる。 */
      .ov-wrap { max-width: 100%; min-width: 0; -webkit-overflow-scrolling: touch; }
      table.ov-tbl { font-size: 12px; }
      table.ov-tbl th, table.ov-tbl td { padding: 5px 6px; }
      /* 希望休・備考の列は最低130pxもいらない。表が横に長くなりすぎるのを防ぐ。 */
      table.ov-tbl td.offcol, table.ov-tbl th.offcol,
      table.ov-tbl td.memocol, table.ov-tbl th.memocol { min-width: 100px; max-width: 150px; }
      .ov-note { font-size: 11px; }
    }
  </style>
@endverbatim
@endpush

@section('content')
@verbatim
      <div class="mock-note">
        <b>社員が「イベントに入れる日」を月ごとに登録する画面です。</b>
        社員は普段は平日勤務で、イベントは主に<b>土日・祝日・長期休暇（お盆・正月）</b>に入るため、その日を
        <span style="color:#15803d;font-weight:700;">〇＝出勤可</span>／<span style="color:#b91c1c;font-weight:700;">×＝不可</span>／<span style="color:#b45309;font-weight:700;">△＝条件つき・未定</span>
        で入力します。平日は「希望休（休みたい日）」を登録できます。<br>
        入力して<b>「保存」</b>を押すと内容は保存され、次に開いたときも同じ内容が表示されます。
      </div>

      <!-- タブ -->
      <div class="ea-tabs">
        <button class="ea-tab active" data-pane="mine" onclick="switchTab('mine')">🙋 自分の入力</button>
        <button class="ea-tab" data-pane="all" onclick="switchTab('all')">👥 全社員の一覧</button>
      </div>

      <!-- 月切替（両タブ共通） -->
      <div class="ea-monthbar">
        <button onclick="moveMonth(-1)" title="前の月">‹</button>
        <div class="mon" id="monLabel">―</div>
        <button onclick="moveMonth(1)" title="次の月">›</button>
      </div>

      <!-- ===== タブ①：自分の入力 ===== -->
      <div class="ea-pane show" id="pane-mine">
        <div class="ea-card">
          <h3>📅 出勤できる日・希望休</h3>
          <p class="sub">
            土日・祝日・長期休暇（色つきの枠）をクリックすると <b>〇 → × → △ → 未入力</b> と切り替わります。<br>
            平日をクリックすると「希望休（休みたい日）」のオン／オフが切り替わります。<br>
            <b style="color:#1d4e89;">青い帯＝すでに自分のアサインが確定している案件</b>です。ECSが自動で出すので、書き写す必要はありません（手では消せません）。
            アサインが後から決まっても、次にこの画面を開いたときに自動で出ます。<br>
            マスの右下の <b>✎</b> を押すと、<b>その日のメモ</b>（「午後だけ可」「前泊」「ECSにまだ無い予定」など）を書けます。こちらは自分で消せます。
          </p>
          <div class="cal-grid" id="calGrid"></div>
          <div class="ea-legend">
            <span><i style="background:#e2edfb;border-color:#bcd6f2 !important;"></i>決まっている案件（自動）</span>
            <span><i style="background:#faf5ec;border-color:#d9cbb4 !important;border-style:dashed;"></i>その日のメモ（手入力）</span>
            <span><i class="lg-need"></i>要入力（未入力の必須日）</span>
            <span><i class="lg-ok"></i>〇 出勤可</span>
            <span><i class="lg-ng"></i>× 不可</span>
            <span><i class="lg-maybe"></i>△ 条件つき・未定</span>
            <span><i class="lg-off"></i>平日の希望休</span>
            <span><i style="background:#fde68a;border-color:#e0b84a;"></i>大型案件の日（お客様の会社名を出します）</span>
            <span><i style="background:#f3e3c8;border-color:#f0d79a;"></i>祝／お盆・正月＝祝日・長期休暇</span>
          </div>
        </div>

        <div class="ea-card">
          <h3>📝 備考（参加したいイベント・希望件数なども）</h3>
          <div class="ea-fields">
            <div class="ea-field">
              <label>備考 <span class="hint">参加したいイベント・希望件数・△にした理由・条件・連絡事項などを自由に書いてください</span></label>
              <textarea id="memo" style="min-height:90px;" placeholder="例：今月は3件くらい参加したい／運動会系をやりたい／8/12は午後だけ可／お盆は実家のため要相談 など"></textarea>
            </div>
            <div class="ea-save">
              <button onclick="saveMine()">この月の内容を保存</button>
              <span class="ea-saved" id="savedMsg">✓ 保存しました</span>
            </div>
          </div>
        </div>

        <!-- 常に見える保存ボタン（右下・下までスクロール不要） -->
        <button class="ea-float-save" id="floatSave" onclick="saveMine()">💾 この月の内容を保存</button>
      </div>

      <!-- その日のメモを書く小窓（✎ を押すと開く） -->
      <div class="dn-back" id="dnBack" onclick="if(event.target===this) closeDayNote()">
        <div class="dn-box">
          <h4 id="dnTitle">その日のメモ</h4>
          <p class="dn-sub">
            この日だけのメモです。<b>ECSがまだ知らない予定</b>（他部署の予定・私用・前泊など）や、
            <b>△にした理由</b>を書いておくと、アサイン担当が見て判断できます。
          </p>
          <div class="dn-auto" id="dnAuto" style="display:none;"></div>
          <textarea id="dnText" placeholder="例：午後だけ可／前泊で入る／別件の打ち合わせあり"></textarea>
          <div class="dn-btns">
            <button class="cancel" onclick="closeDayNote()">やめる</button>
            <button class="ok" onclick="applyDayNote()">この日に入れる</button>
          </div>
          <p class="dn-sub" style="margin:10px 0 0;">
            ※ 入れたあと、下の「この月の内容を保存」を押すと保存されます。
          </p>
        </div>
      </div>

      <!-- ===== タブ②：全社員の一覧 ===== -->
      <div class="ea-pane" id="pane-all">
        <div class="ea-card">
          <h3>👥 全社員の出勤可能日（この月の土日・祝日・長期休暇）</h3>
          <p class="sub">アサイン担当が「その日に誰が出られるか」をまとめて見る表です。<b>〇＝出勤可／×＝不可／△＝条件つき・未定</b>。一番下に「〇の人数」を出します。</p>
          <!-- 拠点で絞る。選択肢は拠点マスタから作る（ここに拠点名を書かない）。既定は自分の拠点。 -->
          <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:0 0 10px;">
            <select id="ovOffice" onchange="renderOverview()"
                    style="padding:7px 9px; border:1px solid #d1d5db; border-radius:8px; font-size:13px;"></select>
            <span class="sub" id="ovOfficeHint" style="margin:0;"></span>
          </div>
          <div class="ov-wrap">
            <table class="ov-tbl" id="ovTbl"></table>
          </div>
          <p class="ov-note">
            ※ 黄色い行があなた自身の行です。自分の行は「自分の入力」タブで入れた内容がそのまま反映されます。ほかの社員は、出勤可能日を登録済みならその内容、未登録ならグレーの仮データを表示します。<br>
            ※ 一番下の「〇の人数」が少ない日（赤）は、イベントがあるのに出られる社員が少ない＝注意したい日です。<br>
            ※ <b style="color:#1d4e89;">薄い青のマス</b>は、その人の<b>アサインがもう確定している日</b>です（案件名を小さく出します。マウスを乗せると詳しく出ます）。
            そのため「<b>うち空いている人数</b>」＝〇のうち、まだ案件が入っていない人＝<b>これから頼める人数</b>です。<br>
            ※ 本人が <b>✎ で書いたその日のメモ</b>（「午後だけ可」「前泊」など）も、その日のマスに小さく出ます。
            <b>平日など列になっていない日のメモは、右はしの「備考」に「◯日：〜」の形でまとめて出します。</b><br>
            ※ 上の<b>拠点</b>で絞れます（はじめは自分の拠点）。<b>「〇の人数」も、いま表に出ている人だけで数えます</b>
            ＝その拠点で何人出られるかが分かります。他拠点の人も見たいときは「拠点：すべて」にしてください。
          </p>
        </div>
      </div>
@endverbatim
@endsection

@push('scripts')
{{-- 社員一覧・出勤可能日・案件は、すべて DB（people＋shift_preferences＋projects）から渡す。
     凍結モックの案件ファイルの読み込みはやめた（架空の案件で「大型」の印が付いていたため）。 --}}
<script>
  window.ECS_EMPLOYEES = @json($employees ?? []);
  window.ECS_CASES     = @json($cases ?? []);
  window.ECS_ME        = @json($me ?? null);   // ログイン中の本人（保存先）
  window.ECS_PREFS     = @json($prefs ?? []);
  {{-- 拠点で絞って見るための選択肢と、自分の拠点（2026-08-26 baba要望）。
       ⚠ 拠点名をJSに書き足さない。正本は拠点マスタ（共通設定 → マスタ管理）。 --}}
  window.ECS_OFFICES = @json($offices ?? []);
  window.ECS_MY_OFFICE = @json($myOffice ?? '');
  {{-- その日にもう確定している案件（2026-08-28 baba要望）。
       ⚠ 保存された写しではなく、画面を開くたびに assignments から数え直したもの。
          希望を出したあとに決まった案件も、次に開けば自動で出る。 --}}
  window.ECS_ASSIGNED = @json($assigned ?? []);
  window.ECS_SAVE_URL  = '/employee-availability/save';
  window.ECS_CSRF      = '{{ csrf_token() }}';
</script>
@verbatim
<script>
  // ===== 共通：表示中の月 =====
  let cursor = new Date();
  cursor = new Date(cursor.getFullYear(), cursor.getMonth(), 1); // その月の1日に固定

  // ===== サンプルの祝日・長期休暇（モック用の見本） =====
  // 祝日は "M/D" で指定（(例)は年によって動く祝日の見本）
  const HOLIDAYS = {
    '1/1':'祝','1/13':'祝','2/11':'祝','2/23':'祝','3/20':'祝',
    '4/29':'祝','5/3':'祝','5/4':'祝','5/5':'祝','7/21':'祝',
    '8/11':'祝','9/15':'祝','9/23':'祝','10/13':'祝','11/3':'祝','11/23':'祝'
  };
  function isObon(m,d){ return m===8 && d>=13 && d<=16; }      // お盆
  function isNewYear(m,d){ return (m===12 && d>=29) || (m===1 && d<=3); } // 年末年始

  // ===== 大型案件のある日（DBの案件から、表示中の月だけ集計） =====
  // ⚠ 同じ日に大型が2件あることがあるので、1件だけ覚えるのではなく全部持つ。
  const bigDayMap = {}; // { 日(数値): [ { content, client }, ... ] }
  function computeBig(){
    for (const k in bigDayMap) delete bigDayMap[k];
    const y = cursor.getFullYear(), mo = cursor.getMonth(); // mo=0..11
    const base = new Date(); base.setHours(0,0,0,0);         // 今日（off の基準）
    (window.ECS_CASES || []).forEach(c=>{
      if (c.scale !== '大型' || c.draft) return;
      const dt = new Date(base); dt.setDate(dt.getDate() + c.off);
      if (dt.getFullYear()===y && dt.getMonth()===mo){
        const d = dt.getDate();
        if (!bigDayMap[d]) bigDayMap[d] = [];
        bigDayMap[d].push({ content: c.content || '', client: (c.client || '').trim() });
      }
    });
  }
  // マスに出す文字。⚠ お客様の会社名を出す（2026-08-28 baba要望＝
  //   前はマウスを乗せないと分からなかった）。会社名が空の案件はコンテンツ名で代用する。
  function bigLabel(a){
    if (a.client === '') return a.content || '大型案件';
    // ⚠ ECSは末尾の「様・御中」を外して保存しているので付け直す。すでに付いている人は二重にしない。
    return /(様|御中)$/.test(a.client) ? a.client : (a.client + '様');
  }
  // マウスを乗せたときに出す詳しい文字（会社名＋コンテンツ名）。
  function bigTitle(list){
    return list.map(function(a){
      return '大型案件：' + bigLabel(a) + (a.content && a.client !== '' ? '／' + a.content : '');
    }).join('\n');
  }
  // その日の大型案件をまとめて短く（一覧タブの見出し用）。
  function bigNames(list){
    return list.map(bigLabel).join('・');
  }

  // その日の種類を判定。input=true なら 〇×△ の入力対象（土日祝・長期休暇・大型案件の日）
  function dayMeta(y,m,d){
    if (bigDayMap[d])   return { input:true, badge:'大型', big:bigDayMap[d] }; // 大型を最優先で表示
    const w = new Date(y, m-1, d).getDay(); // 0=日..6=土
    if (isObon(m,d))    return { input:true, badge:'お盆' };
    if (isNewYear(m,d)) return { input:true, badge:'正月' };
    if (HOLIDAYS[m+'/'+d]) return { input:true, badge:'祝' };
    if (w===0 || w===6) return { input:true };   // 土日
    return { input:false };                       // 平日
  }

  // ===== 自分の入力データ（月をまたいで保持） =====
  // キー＝"YYYY-M-D"、値＝イベント日:'ok'|'ng'|'maybe' / 平日:'off'
  const myState = {};
  const myFields = {}; // 月ごとの { memo }（キー＝"YYYY-M"）
  function keyOf(y,m,d){ return y + '-' + m + '-' + d; }
  function monKey(y,m){ return y + '-' + m; }

  // ===== 「自分」＝ログイン中の本人（保存先＝その人の people.id） =====
  // 社員一覧はDBがすべて。居なければ空のまま（見本の社員名には戻さない）。
  // ⚠ 以前は EMP_LIST[0]（一覧の先頭＝E-001）を自分としていたため、
  //   誰がログインしても先頭の社員として保存されていた。必ず ECS_ME を使う。
  const EMP_LIST = window.ECS_EMPLOYEES || [];
  const ME = window.ECS_ME || null;                     // { id, name } or null
  const PREFS = window.ECS_PREFS || {};                 // { "E-001": { state, memo, dayNote }, ... }

  // ===== もう決まっている案件（自動・消せない） =====
  // 形： { "E-001": { "2026-9-13": [ {id,name,role,roleLabel,client,time}, ... ] } }
  // ⚠ ここは「見るだけ」。画面から書き換えない＝正本は assignments。
  const ASSIGNED = window.ECS_ASSIGNED || {};
  function assignedFor(personId, y, m, d){
    const a = personId ? ASSIGNED[personId] : null;
    return (a && a[keyOf(y,m,d)]) ? a[keyOf(y,m,d)] : [];
  }
  // 案件1件を短い文字にする（マスが狭いので名前だけ。詳しくはマウスを乗せると出る）。
  function assignedTitle(list){
    return list.map(function(a){
      const bits = [a.name];
      if (a.roleLabel) bits.push('（' + a.roleLabel + '）');
      if (a.client) bits.push(' / ' + a.client + '様');
      if (a.time) bits.push(' / ' + a.time);
      return bits.join('');
    }).join('\n');
  }

  // ===== その日のメモ（手入力・消せる） =====
  // キー＝"YYYY-M-D"、値＝メモ本文。〇×△とは別に持つ（メモだけ書きたい日があるため）。
  const myNotes = {};
  // 保存済みのメモ（DBから来たもの）の控え。⚠ 「消した日」を見分けるために要る
  //   ＝消した日は空文字で送らないと、サーバ側は「変更なし」と見なして残してしまう。
  let savedNotes = {};

  // 自分の登録済みデータ（DB）を myState / myFields / myNotes に展開（あれば）。
  if (ME && PREFS[ME.id]){
    Object.assign(myState,  PREFS[ME.id].state || {});
    Object.assign(myFields, monMemoToFields(PREFS[ME.id].memo || {}));
    Object.assign(myNotes,  PREFS[ME.id].dayNote || {});
    savedNotes = Object.assign({}, myNotes);
  }
  // memo は { "Y-M": "本文" } → myFields の { "Y-M": { memo } } 形へ。
  function monMemoToFields(memoMap){
    const out = {};
    for (const k in memoMap) out[k] = { memo: memoMap[k] };
    return out;
  }

  // ===== カレンダー描画 =====
  function renderCalendar(){
    computeBig();
    const y = cursor.getFullYear(), m = cursor.getMonth()+1;
    document.getElementById('monLabel').textContent = y + '年 ' + m + '月';
    const grid = document.getElementById('calGrid');
    grid.innerHTML = '';
    // 曜日見出し（月曜始まり）
    ['月','火','水','木','金','土','日'].forEach((w,i)=>{
      const h = document.createElement('div');
      h.className = 'dow' + (i===5?' sat':'') + (i===6?' sun':'');
      h.textContent = w;
      grid.appendChild(h);
    });
    // 月初の空きセル（月曜始まり）
    const firstDow = new Date(y, m-1, 1).getDay(); // 0=日..6=土
    const lead = (firstDow + 6) % 7;               // 月曜始まりの先頭空き数
    for (let i=0;i<lead;i++){
      const e = document.createElement('div'); e.className='cell empty'; grid.appendChild(e);
    }
    const days = new Date(y, m, 0).getDate();
    for (let d=1; d<=days; d++){
      const kind = dayMeta(y, m, d);
      const cell = document.createElement('div');
      cell.className = 'cell ' + (kind.input ? 'event' : 'weekday');
      cell.innerHTML = '<div class="dnum">' + d + '</div>'
        + (kind.badge ? '<div class="badge' + (kind.badge==='大型'?' big':'') + '">' + kind.badge + '</div>' : '')
        + '<div class="st"></div><div class="stsub"></div>'
        + '<div class="chips"></div>'
        + '<button type="button" class="memobtn" title="この日のメモを書く">✎</button>';
      if (kind.big) cell.title = bigTitle(kind.big);
      const k = keyOf(y,m,d);
      if (kind.input){
        applyEvent(cell, myState[k]);
        cell.addEventListener('click', ()=>{
          const order = [undefined,'ok','ng','maybe'];
          const cur = order.indexOf(myState[k]);
          const next = order[(cur+1) % order.length];
          if (next === undefined) delete myState[k]; else myState[k] = next;
          applyEvent(cell, myState[k]);
          applyExtras(cell, y, m, d);   // 〇に戻したら注意の印も出し直す
        });
      } else {
        applyWeekday(cell, myState[k]);
        cell.addEventListener('click', ()=>{
          if (myState[k] === 'off') delete myState[k]; else myState[k] = 'off';
          applyWeekday(cell, myState[k]);
          applyExtras(cell, y, m, d);
        });
      }
      // ✎ はマスのクリック（〇×△の切替）と別扱いにする。
      cell.querySelector('.memobtn').addEventListener('click', (ev)=>{
        ev.stopPropagation();
        openDayNote(y, m, d);
      });
      applyExtras(cell, y, m, d);
      grid.appendChild(cell);
    }
    // 備考をこの月の保存値に
    const f = myFields[monKey(y,m)] || {};
    document.getElementById('memo').value = f.memo || '';
    document.getElementById('savedMsg').style.display = 'none';
  }
  function applyEvent(cell, v){
    cell.classList.remove('v-ok','v-ng','v-maybe','v-need');
    const st = cell.querySelector('.st'), sub = cell.querySelector('.stsub');
    if (v==='ok')    { cell.classList.add('v-ok');    st.textContent='〇'; sub.textContent='出勤可'; }
    else if (v==='ng')   { cell.classList.add('v-ng');    st.textContent='×'; sub.textContent='不可'; }
    else if (v==='maybe'){ cell.classList.add('v-maybe'); st.textContent='△'; sub.textContent='条件つき'; }
    else { cell.classList.add('v-need'); st.textContent=''; sub.textContent='要入力'; } // 未入力＝必ず入れる日
  }
  function applyWeekday(cell, v){
    cell.classList.toggle('off', v==='off');
    const st = cell.querySelector('.st'), sub = cell.querySelector('.stsub');
    if (v==='off'){ st.textContent='休'; sub.textContent='希望休'; }
    else { st.textContent=''; sub.textContent=''; }
  }

  // マスに「もう決まっている案件」「その日のメモ」「注意の印」を出し直す。
  function applyExtras(cell, y, m, d){
    const k = keyOf(y,m,d);
    const list = assignedFor(ME ? ME.id : null, y, m, d);

    // マスに出す帯を1本の並びにまとめる。⚠ まとめるのは、入れ物を分けると
    //   中身の組み合わせでマスの高さが変わって、カレンダーがガタガタになるため。
    const chips = [];
    // ① 大型案件のお客様の会社名（マウスを乗せなくても読めるように・2026-08-28 baba要望）。
    (bigDayMap[d] || []).forEach(function(a){
      chips.push({ cls: 'big', text: bigLabel(a), title: bigTitle([a]) });
    });
    // ② 決まっている案件（自動）。ここは書き換えられない＝正本はアサインのデータ。
    list.forEach(function(a){
      chips.push({ cls: 'auto', text: a.name, title: assignedTitle([a]) });
    });
    // ③ その日のメモ（手入力）。
    const note = (myNotes[k] || '').trim();
    if (note) chips.push({ cls: 'note', text: '✎ ' + note, title: note });

    // ⚠ 入りきらないぶんは切り捨てず「＋n件」にまとめる（マウスを乗せると全部出る）。
    const MAX = 3;
    let html = '';
    const shown = chips.length > MAX ? chips.slice(0, MAX - 1) : chips;
    shown.forEach(function(c){
      html += '<div class="chip ' + c.cls + '" title="' + ovEsc(c.title) + '">' + ovEsc(c.text) + '</div>';
    });
    if (chips.length > MAX){
      const rest = chips.slice(MAX - 1);
      html += '<div class="chip more" title="' + ovEsc(rest.map(function(c){ return c.title; }).join('\n')) + '">'
            + '＋' + rest.length + '件</div>';
    }
    cell.querySelector('.chips').innerHTML = html;
    cell.classList.toggle('has-auto', list.length > 0);

    // ⚠ 案件が決まっているのに「〇（出勤可）」のままの日は、印を出して知らせるだけ。
    //   勝手に×にはしない。前泊・半日・掛け持ちなど「それでも入れる」ことが本当にあるため。
    let warn = cell.querySelector('.warn');
    if (list.length > 0 && myState[k] === 'ok'){
      if (!warn){ warn = document.createElement('div'); warn.className = 'warn'; cell.appendChild(warn); }
      warn.textContent = '⚠';
      warn.title = 'この日はすでに案件が決まっています。それでも入れるなら〇のままで大丈夫です。';
    } else if (warn){
      warn.remove();
    }
  }

  // ===== その日のメモの小窓 =====
  let dnKey = null;   // いま開いている日（"Y-M-D"）。閉じているときは null。
  function openDayNote(y, m, d){
    dnKey = keyOf(y,m,d);
    document.getElementById('dnTitle').textContent = y + '年' + m + '月' + d + '日 のメモ';
    const list = assignedFor(ME ? ME.id : null, y, m, d);
    const auto = document.getElementById('dnAuto');
    if (list.length){
      auto.innerHTML = '<b>この日にもう決まっている案件</b><br>'
        + list.map(function(a){ return '・' + ovEsc(assignedTitle([a])); }).join('<br>');
      auto.style.display = 'block';
    } else {
      auto.innerHTML = ''; auto.style.display = 'none';
    }
    document.getElementById('dnText').value = myNotes[dnKey] || '';
    document.getElementById('dnBack').classList.add('show');
    document.getElementById('dnText').focus();
  }
  function closeDayNote(){
    dnKey = null;
    document.getElementById('dnBack').classList.remove('show');
  }
  function applyDayNote(){
    if (dnKey === null) return;
    const v = document.getElementById('dnText').value.trim();
    if (v === '') delete myNotes[dnKey]; else myNotes[dnKey] = v;
    closeDayNote();
    // ⚠ 描き直すと備考欄が保存値に戻るので、先に打ちかけの備考を控えておく
    //   （メモを書いただけで、書きかけの備考が消えないように）。
    const y = cursor.getFullYear(), m = cursor.getMonth()+1;
    myFields[monKey(y,m)] = { memo: document.getElementById('memo').value };
    renderCalendar();
  }
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeDayNote(); });

  function saveMine(){
    const y = cursor.getFullYear(), m = cursor.getMonth()+1;
    const memo = document.getElementById('memo').value;
    myFields[monKey(y,m)] = { memo: memo };
    // モック：localStorage にも保存（端末内のみ・DB保存できない場合の保険）
    try { localStorage.setItem('ecs_emp_avail', JSON.stringify({ state: myState, fields: myFields, notes: myNotes })); } catch(e){}

    const msg = document.getElementById('savedMsg');

    // DBに社員が居る＝本物の保存先がある場合は、この月の入力をサーバへ保存する。
    if (ME){
      // この月（Y-M）に属する state だけ抜き出して送る。
      const monthState = {};
      const prefix = y + '-' + m + '-';
      for (const k in myState){ if (k.indexOf(prefix) === 0) monthState[k] = myState[k]; }
      // その日のメモも同じ月ぶんだけ送る。
      // ⚠ その月に一度でも書いた日は「空文字」でも送る＝消したことをサーバに伝えるため
      //   （送らないと「変更なし」と見なされて、消したはずのメモが残る）。
      const monthNotes = {};
      const days = new Date(y, m, 0).getDate();
      for (let d=1; d<=days; d++){
        const k = keyOf(y,m,d);
        if (k in myNotes) monthNotes[k] = myNotes[k];
        else if (k in savedNotes) monthNotes[k] = '';   // 消された日
      }
      const period = y + '-' + String(m).padStart(2,'0'); // 例 2026-07
      fetch(window.ECS_SAVE_URL, {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF, 'Accept':'application/json' },
        body: JSON.stringify({ employee_id: ME.id, period: period, state: monthState, memo: memo, day_notes: monthNotes })
      })
      .then(r => r.json())
      .then(res => {
        const ok = !!(res && res.ok);
        // 保存できたら「保存済みのメモ」の控えを取り直す（次に消したときも正しく伝わるように）。
        if (ok) savedNotes = Object.assign({}, myNotes);
        msg.textContent = ok ? '✓ 保存しました' : '保存に失敗しました';
        msg.style.display = 'inline';
        flashFloat(ok);
      })
      .catch(() => { msg.textContent = '保存に失敗しました（通信エラー）'; msg.style.display = 'inline'; flashFloat(false); });
    } else {
      // モック（DBに社員が無い）：従来どおり端末内保存のみ。
      msg.textContent = '✓ 保存しました';
      msg.style.display = 'inline';
      flashFloat(true);
    }
  }

  // 保存済みデータの読み込み（モック用）。DBに自分のデータがある場合はDBを優先し、端末内データでは上書きしない。
  (function loadSaved(){
    if (ME) return; // 本物の社員＝DBが正。localStorage では上書きしない。
    try {
      const raw = localStorage.getItem('ecs_emp_avail');
      if (raw){
        const o = JSON.parse(raw);
        Object.assign(myState, o.state||{});
        Object.assign(myFields, o.fields||{});
        Object.assign(myNotes, o.notes||{});
      }
    } catch(e){}
  })();

  // ===== タブ②：全社員の一覧 =====
  // 社員名（先頭＝自分）。DBに社員が居なければ空。
  const EMPLOYEES = EMP_LIST.map((e,i) => i===0 ? (e.name + '（自分）') : e.name);

  // ===== 拠点で絞る（2026-08-26 baba要望）=====
  function ovEsc(s){
    return String(s == null ? '' : s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  // ⚠ 事務所が空の人は「東京」として扱う（名簿・案件と同じ決まり）。
  //   そうしないと、拠点が未入力の人がどの拠点にも出てこなくなる。
  function ovOfficeOf(idx){
    const e = EMP_LIST[idx];
    const o = (e && e.office) ? String(e.office).trim() : '';
    return o !== '' ? o : '東京';
  }
  // 選択肢は拠点マスタから作る（画面に拠点名を書かない）。既定は自分の拠点。
  // 「拠点：すべて」も残す＝他拠点へヘルプに行く／来てもらう運用があるので隠さない。
  function buildOvOfficeFilter(){
    const sel = document.getElementById('ovOffice');
    if (!sel) return;
    const mine = (window.ECS_MY_OFFICE || '').trim();
    let html = '<option value="">拠点：すべて</option>';
    (window.ECS_OFFICES || []).forEach(function(o){
      html += '<option value="' + ovEsc(o) + '"' + (o === mine ? ' selected' : '') + '>'
            + ovEsc(o) + '</option>';
    });
    sel.innerHTML = html;
  }
  // 表に出す社員（絞り込み後）。
  // ⚠ 元の並びの番号(idx)を持ち回す＝「先頭(0)が自分」という決まりが絞り込みで崩れないように
  //   （崩れると、他人の行に自分の入力が出る）。
  function ovRows(){
    const sel = document.getElementById('ovOffice');
    const want = sel ? sel.value : '';
    const rows = [];
    EMPLOYEES.forEach(function(name, idx){
      if (want === '' || ovOfficeOf(idx) === want) rows.push({ name: name, idx: idx });
    });
    return rows;
  }
  // 既定が自分の拠点なので「他拠点の人が消えた」と誤解されないように出す。
  function ovOfficeHint(shown){
    const el = document.getElementById('ovOfficeHint');
    if (!el) return;
    const sel = document.getElementById('ovOffice');
    const want = sel ? sel.value : '';
    el.innerHTML = want === ''
      ? (shown + '名を表示中（すべての拠点）')
      : (shown + '名を表示中（<b>' + ovEsc(want) + '</b>の社員だけ）');
  }
  // 社員index・日 → その人がその日に書いたメモ。
  // ⚠ 自分は入力タブの内容（myNotes）を使う＝保存前に書いた内容も一覧に出るように。
  //   他の人はDBに保存された内容（PREFS[].dayNote）。
  function empDayNote(idx, y, m, d){
    const k = keyOf(y,m,d);
    if (idx === 0) return (myNotes[k] || '').trim();
    const e = EMP_LIST[idx];
    if (!e || !PREFS[e.id]) return '';
    return ((PREFS[e.id].dayNote || {})[k] || '').trim();
  }
  // 表の列になっていない日（＝平日）に書いたメモ。備考の欄にまとめて出す。
  // ⚠ 一覧の列は土日祝・大型だけなので、ここに出さないと平日のメモが誰にも見えない。
  function otherDayNotes(idx, y, m, cols){
    const isCol = {};
    cols.forEach(function(c){ isCol[c.d] = true; });
    const days = new Date(y, m, 0).getDate();
    const out = [];
    for (let d=1; d<=days; d++){
      if (isCol[d]) continue;
      const note = empDayNote(idx, y, m, d);
      if (note) out.push('<div style="font-size:11px; color:#6b5544; margin-top:2px;">'
        + d + '日：' + ovEsc(note) + '</div>');
    }
    return out.join('');
  }
  // 社員index → その社員の登録済み state（DBから）。先頭(自分)は myState を使うので別扱い。
  function empState(idx){
    if (idx===0) return null;                         // 自分は myState を使う
    const e = EMP_LIST[idx];
    return (e && PREFS[e.id]) ? (PREFS[e.id].state || {}) : null;
  }
  // 社員index・年月 → その月の備考（DB）。まだ登録が無ければ null（画面には「―」を出す）。
  function empMemo(idx, y, m){
    if (idx===0) return null;
    const e = EMP_LIST[idx];
    if (!e || !PREFS[e.id]) return null;
    const memoMap = PREFS[e.id].memo || {};
    return (monKey(y,m) in memoMap) ? (memoMap[monKey(y,m)] || '') : '';
  }
  // 社員index・日 → 〇×△の値。本人が入力していない日は undefined＝「−」。
  // ⚠ 未入力の人に架空の〇×△を作らない（出られない人を出られると誤解する事故になるため）。
  function markFor(idx, name, y, m, d){
    if (idx===0) return myState[keyOf(y,m,d)];        // 自分＝入力タブの内容
    const st = empState(idx);
    return st ? st[keyOf(y,m,d)] : undefined;         // 未登録の社員は空欄（「−」）
  }
  function markHtml(v){
    if (v==='ok')    return '<span class="ov-mark ok">〇</span>';
    if (v==='ng')    return '<span class="ov-mark ng">×</span>';
    if (v==='maybe') return '<span class="ov-mark maybe">△</span>';
    return '<span class="ov-mark none">−</span>';
  }
  // 社員index → その人のID（拠点で絞ったあとも番号は元のままなので、これで引ける）。
  function empIdOf(idx){
    const e = EMP_LIST[idx];
    return e ? e.id : null;
  }
  // その人のその日に、もう決まっている案件（一覧タブ用）。
  function ovAssigned(idx, y, m, d){
    return assignedFor(empIdOf(idx), y, m, d);
  }
  // 〇×△のうしろに「もう案件が入っている」ことを小さく出す。
  // ⚠ 〇×△そのものは本人の申告なので書き換えない（勝手に×にしない）。
  function ovCell(idx, name, y, m, d){
    const v = markFor(idx, name, y, m, d);
    const list = ovAssigned(idx, y, m, d);
    const note = empDayNote(idx, y, m, d);

    let inner = markHtml(v);
    let tips = [];
    // もう決まっている案件（薄い青）。
    if (list.length){
      tips.push(assignedTitle(list));
      inner += '<div class="ovbusy-txt">' + ovEsc(list.map(function(a){ return a.name; }).join('・')) + '</div>';
    }
    // ⚠ その日のメモも出す。ここに出さないと、本人が書いたメモをアサイン担当が見られない
    //   （2026-08-28 baba指摘。前は自分のカレンダーにしか出ていなかった）。
    if (note){
      tips.push('メモ：' + note);
      inner += '<div class="ovnote-txt" title="' + ovEsc(note) + '">✎ ' + ovEsc(note) + '</div>';
    }
    if (tips.length === 0) return '<td>' + inner + '</td>';

    const cls = (list.length ? 'ovbusy' : '') + (note ? ' ovnote' : '');
    return '<td class="' + cls.trim() + '" title="' + ovEsc(tips.join('\n')) + '">' + inner + '</td>';
  }
  // 平日のうち希望休にしている日を配列で返す（自分＝myState、他＝その人が登録した内容）。
  // ⚠ 未登録の人は空のまま。架空の希望休を作らない。
  function weekdayOffDays(name, y, m, me, idx){
    const days = new Date(y, m, 0).getDate();
    const out = [];
    const st = (idx !== undefined) ? empState(idx) : null; // 本物データ（あれば）
    for (let d=1; d<=days; d++){
      if (dayMeta(y, m, d).input) continue; // 平日のみ対象
      if (me){
        if (myState[keyOf(y,m,d)] === 'off') out.push(d);
      } else if (st){
        if (st[keyOf(y,m,d)] === 'off') out.push(d); // 本物データ：希望休の日
      }
    }
    return out;
  }
  function renderOverview(){
    computeBig();
    const y = cursor.getFullYear(), m = cursor.getMonth()+1;
    const days = new Date(y, m, 0).getDate();
    // この月の入力対象日（土日祝・長期休暇・大型）だけを列にする
    const cols = [];
    for (let d=1; d<=days; d++){
      const kind = dayMeta(y,m,d);
      if (kind.input){
        const w = ['日','月','火','水','木','金','土'][new Date(y,m-1,d).getDay()];
        // 大型案件はお客様の会社名も見出しに出す（2026-08-28 baba要望）。
        cols.push({ d, w, badge:kind.badge, big: kind.big || null });
      }
    }
    const tbl = document.getElementById('ovTbl');
    // 社員がまだ1人も居ないとき（名簿の取り込み前）。見本の社員名は出さない。
    if (EMPLOYEES.length === 0){
      tbl.innerHTML = '<thead><tr><th class="namecol">社員</th>'
        + '<th>社員がまだ登録されていません（名簿を取り込むと、ここに一覧が出ます）</th></tr></thead>';
      return;
    }
    if (cols.length === 0){
      tbl.innerHTML = '<thead><tr><th class="namecol">社員</th><th>この月は対象日（土日・祝日・長期休暇・大型）がありません</th></tr></thead>';
      return;
    }
    // 拠点で絞ったあとの行（元の番号を持ったまま）。
    const rows = ovRows();
    ovOfficeHint(rows.length);
    if (rows.length === 0){
      tbl.innerHTML = '<thead><tr><th class="namecol">社員</th>'
        + '<th>この拠点の社員はいません（「拠点：すべて」にすると全員出ます）</th></tr></thead>';
      return;
    }
    // ヘッダー（最後に「平日の希望休」「備考」列を追加）
    let head = '<thead><tr class="vh"><th class="namecol">社員</th>';
    cols.forEach(c=>{
      const cls = (c.badge ? 'holi' : 'we') + (c.badge==='大型' ? ' big' : '');
      // ⚠ 会社名は長いことがあるので幅を決めて省略する（列が横に伸びて表が読めなくなるため）。
      //   全部見たいときはマウスを乗せると出る。
      const bigTxt = c.big
        ? '<span class="bigclient" title="' + ovEsc(bigTitle(c.big)) + '">' + ovEsc(bigNames(c.big)) + '</span>'
        : '';
      head += '<th class="' + cls + '">' + c.d + '<br><small>(' + c.w + ')' + (c.badge?'<br>'+c.badge:'') + '</small>'
            + bigTxt + '</th>';
    });
    head += '<th class="offcol">平日の希望休</th><th class="memocol">備考</th></tr></thead>';
    // 本体
    const myMemo = document.getElementById('memo').value.trim(); // 自分は入力タブの内容を反映
    let body = '<tbody>';
    rows.forEach(({name, idx})=>{
      const me = idx === 0;
      // 所属で背景色を変える（2026-09-01 baba要望）。並びは イベプラ → セールス → その他 なので、
      // 色が変わるところが組の切れ目になる。
      // ⚠ 色は画面に書かない。サーバーが渡した dept（plan/sales/…）を class にするだけ
      //   ＝色の正本は App\Support\Departments の1か所（名簿のバッジと同じ色）。
      const dep = (EMP_LIST[idx] && EMP_LIST[idx].dept) ? EMP_LIST[idx].dept : 'none';
      const depTitle = (EMP_LIST[idx] && EMP_LIST[idx].deptLabel) ? EMP_LIST[idx].deptLabel : '';
      body += '<tr class="dep-' + dep + (me?' me':'') + '">'
            + '<td class="namecol" title="' + ovEsc(depTitle) + '">' + name + '</td>';
      cols.forEach(c=>{
        body += ovCell(idx, name, y, m, c.d);
      });
      const offs = weekdayOffDays(name, y, m, me, idx);
      const offTxt = offs.length ? offs.map(d=>d+'日').join('・') : '<span style="color:#c7bba9;">なし</span>';
      // 備考：自分＝入力タブ／他＝その月に本人が書いた内容。未登録は「―」（架空の備考は出さない）。
      const realMemo = empMemo(idx, y, m);
      let memo = me ? (myMemo || '<span style="color:#c7bba9;">（未入力）</span>')
                    : ((realMemo || '') || '<span style="color:#c7bba9;">―</span>');
      // ⚠ 平日など「列になっていない日」に書いたメモは、ここに出さないと誰にも見えない。
      const others = otherDayNotes(idx, y, m, cols);
      if (others) memo += others;
      body += '<td class="offcol">' + offTxt + '</td><td class="memocol">' + memo + '</td>';
      body += '</tr>';
    });
    body += '</tbody>';
    // 〇の人数フッター（追加2列ぶんは空欄）
    let foot = '<tfoot><tr><td class="namecol">〇の人数</td>';
    cols.forEach(c=>{
      let cnt = 0;
      // ⚠ 〇の人数も「いま表に出ている人」で数える（拠点で絞ったら、その拠点の人数になる）。
      rows.forEach(({name, idx})=>{
        const v = markFor(idx, name, y, m, c.d);
        if (v==='ok') cnt++;
      });
      foot += '<td class="' + (cnt<=3?'few':'') + '">' + cnt + '人</td>';
    });
    foot += '<td class="offcol"></td><td class="memocol"></td></tr>';
    // ⚠ 「まだ空いている人数」＝〇のうち、その日にまだ案件が決まっていない人。
    //   アサインするときに本当に見たいのはこちら（〇でも、もう他の案件で埋まっている人がいる）。
    foot += '<tr><td class="namecol">うち空いている人数</td>';
    cols.forEach(c=>{
      let cnt = 0;
      rows.forEach(({name, idx})=>{
        if (markFor(idx, name, y, m, c.d) === 'ok' && ovAssigned(idx, y, m, c.d).length === 0) cnt++;
      });
      foot += '<td class="' + (cnt<=3?'few':'') + '">' + cnt + '人</td>';
    });
    foot += '<td class="offcol"></td><td class="memocol"></td></tr></tfoot>';
    tbl.innerHTML = head + body + foot;
  }

  // ===== タブ切替・月移動 =====
  function switchTab(pane){
    document.querySelectorAll('.ea-tab').forEach(t=> t.classList.toggle('active', t.dataset.pane===pane));
    document.getElementById('pane-mine').classList.toggle('show', pane==='mine');
    document.getElementById('pane-all').classList.toggle('show', pane==='all');
    // 浮く保存ボタンは「自分の入力」タブでだけ出す（一覧タブは見るだけなので隠す）
    document.getElementById('floatSave').style.display = (pane==='mine') ? 'block' : 'none';
    if (pane==='all') renderOverview();
  }

  // 浮く保存ボタンに保存結果を一瞬だけ表示（下までスクロールしなくても分かるように）
  function flashFloat(ok){
    const fb = document.getElementById('floatSave');
    if (!fb) return;
    fb.textContent = ok ? '✓ 保存しました' : '保存に失敗しました';
    setTimeout(()=>{ fb.textContent = '💾 この月の内容を保存'; }, 1800);
  }
  function moveMonth(diff){
    cursor = new Date(cursor.getFullYear(), cursor.getMonth()+diff, 1);
    renderCalendar();
    if (document.getElementById('pane-all').classList.contains('show')) renderOverview();
  }

  // 初期表示
  buildOvOfficeFilter();   // 拠点の選択肢（既定＝自分の拠点）。表を描く前に作る。
  renderCalendar();
</script>
@endverbatim
@endpush
