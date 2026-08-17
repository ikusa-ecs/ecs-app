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
    .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; max-width: 640px; margin: 0 auto; }
    .cal-grid .dow { text-align: center; font-size: 12px; color: var(--muted); padding-bottom: 4px; font-weight: 600; }
    .cal-grid .dow.sat { color: var(--brand); }
    .cal-grid .dow.sun { color: var(--danger); }
    .cell {
      min-height: 62px; border-radius: 10px; border: 1px solid var(--line);
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
    table.ov-tbl tr.me td.namecol { background: #fff7ec; }
    table.ov-tbl tr.me td { background: #fffaf2; }
    table.ov-tbl .vh th.we { color: var(--brand); }
    table.ov-tbl .vh th.holi { color: #b45309; }
    .ov-mark.ok    { color: #15803d; font-weight: 700; }
    .ov-mark.ng    { color: #b91c1c; }
    .ov-mark.maybe { color: #b45309; font-weight: 700; }
    .ov-mark.none  { color: #c7bba9; }
    table.ov-tbl th.we.big, table.ov-tbl th.holi.big { color: #7a5200; }
    table.ov-tbl td.offcol, table.ov-tbl th.offcol,
    table.ov-tbl td.memocol, table.ov-tbl th.memocol {
      text-align: left; white-space: normal; min-width: 130px; max-width: 220px;
    }
    table.ov-tbl td.offcol { color: #556683; }
    table.ov-tbl td.memocol { color: #5a4a38; font-size: 12px; }
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
      .cell { min-height: 52px; padding: 3px 1px; font-size: 12px; border-radius: 8px; }
      .cell .dnum { font-size: 11px; margin-left: 3px; }
      .cell .badge { font-size: 8px; padding: 1px 2px; top: 2px; right: 2px; }
      .cell .st { font-size: 15px; margin-top: 2px; }
      .cell .stsub { font-size: 8px; }
      .cell.weekday.off .st { font-size: 11px; }

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
            平日をクリックすると「希望休（休みたい日）」のオン／オフが切り替わります。
          </p>
          <div class="cal-grid" id="calGrid"></div>
          <div class="ea-legend">
            <span><i class="lg-need"></i>要入力（未入力の必須日）</span>
            <span><i class="lg-ok"></i>〇 出勤可</span>
            <span><i class="lg-ng"></i>× 不可</span>
            <span><i class="lg-maybe"></i>△ 条件つき・未定</span>
            <span><i class="lg-off"></i>平日の希望休</span>
            <span><i style="background:#fde68a;border-color:#e0b84a;"></i>大型＝大型案件の日</span>
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

      <!-- ===== タブ②：全社員の一覧 ===== -->
      <div class="ea-pane" id="pane-all">
        <div class="ea-card">
          <h3>👥 全社員の出勤可能日（この月の土日・祝日・長期休暇）</h3>
          <p class="sub">アサイン担当が「その日に誰が出られるか」をまとめて見る表です。<b>〇＝出勤可／×＝不可／△＝条件つき・未定</b>。一番下に「〇の人数」を出します。</p>
          <div class="ov-wrap">
            <table class="ov-tbl" id="ovTbl"></table>
          </div>
          <p class="ov-note">
            ※ 黄色い行があなた自身の行です。自分の行は「自分の入力」タブで入れた内容がそのまま反映されます。ほかの社員は、出勤可能日を登録済みならその内容、未登録ならグレーの仮データを表示します。<br>
            ※ 一番下の「〇の人数」が少ない日（赤）は、イベントがあるのに出られる社員が少ない＝注意したい日です。
          </p>
        </div>
      </div>
@endverbatim
@endsection

@push('scripts')
<script src="/ecs/data/cases.js"></script>
<!-- 社員一覧・登録済みの出勤可能日は DB（people＋shift_preferences）から渡す。空なら下の見本にフォールバック。 -->
<script>
  window.ECS_EMPLOYEES = @json($employees ?? []);
  window.ECS_PREFS     = @json($prefs ?? []);
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

  // ===== 大型案件のある日（cases.js から、表示中の月だけ集計） =====
  const bigDayMap = {}; // { 日(数値): コンテンツ名 }
  function computeBig(){
    for (const k in bigDayMap) delete bigDayMap[k];
    const y = cursor.getFullYear(), mo = cursor.getMonth(); // mo=0..11
    const base = new Date(); base.setHours(0,0,0,0);         // 今日（off の基準）
    (window.ECS_CASES || []).forEach(c=>{
      if (c.scale !== '大型' || c.draft) return;
      const dt = new Date(base); dt.setDate(dt.getDate() + c.off);
      if (dt.getFullYear()===y && dt.getMonth()===mo){
        const d = dt.getDate();
        if (!bigDayMap[d]) bigDayMap[d] = c.content;
      }
    });
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

  // ===== 「自分」＝認証導入前は社員一覧の先頭をログイン者として扱う（保存先＝本物のpeople.id） =====
  // DBに社員が居ればその先頭、居なければ null（モックのまま）。
  const EMP_LIST = (window.ECS_EMPLOYEES && window.ECS_EMPLOYEES.length) ? window.ECS_EMPLOYEES : null;
  const ME = EMP_LIST ? EMP_LIST[0] : null;             // { id, name } or null
  const PREFS = window.ECS_PREFS || {};                 // { "E-001": { state, memo }, ... }

  // 自分の登録済みデータ（DB）を myState / myFields に展開（あれば）。
  if (ME && PREFS[ME.id]){
    Object.assign(myState,  PREFS[ME.id].state || {});
    Object.assign(myFields, monMemoToFields(PREFS[ME.id].memo || {}));
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
        + '<div class="st"></div><div class="stsub"></div>';
      if (kind.big) cell.title = '大型案件：' + kind.big;
      const k = keyOf(y,m,d);
      if (kind.input){
        applyEvent(cell, myState[k]);
        cell.addEventListener('click', ()=>{
          const order = [undefined,'ok','ng','maybe'];
          const cur = order.indexOf(myState[k]);
          const next = order[(cur+1) % order.length];
          if (next === undefined) delete myState[k]; else myState[k] = next;
          applyEvent(cell, myState[k]);
        });
      } else {
        applyWeekday(cell, myState[k]);
        cell.addEventListener('click', ()=>{
          if (myState[k] === 'off') delete myState[k]; else myState[k] = 'off';
          applyWeekday(cell, myState[k]);
        });
      }
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

  function saveMine(){
    const y = cursor.getFullYear(), m = cursor.getMonth()+1;
    const memo = document.getElementById('memo').value;
    myFields[monKey(y,m)] = { memo: memo };
    // モック：localStorage にも保存（端末内のみ・DB保存できない場合の保険）
    try { localStorage.setItem('ecs_emp_avail', JSON.stringify({ state: myState, fields: myFields })); } catch(e){}

    const msg = document.getElementById('savedMsg');

    // DBに社員が居る＝本物の保存先がある場合は、この月の入力をサーバへ保存する。
    if (ME){
      // この月（Y-M）に属する state だけ抜き出して送る。
      const monthState = {};
      const prefix = y + '-' + m + '-';
      for (const k in myState){ if (k.indexOf(prefix) === 0) monthState[k] = myState[k]; }
      const period = y + '-' + String(m).padStart(2,'0'); // 例 2026-07
      fetch(window.ECS_SAVE_URL, {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF, 'Accept':'application/json' },
        body: JSON.stringify({ employee_id: ME.id, period: period, state: monthState, memo: memo })
      })
      .then(r => r.json())
      .then(res => {
        const ok = !!(res && res.ok);
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
      }
    } catch(e){}
  })();

  // ===== タブ②：全社員の一覧 =====
  // DBに社員が居れば本物の社員名（先頭＝自分）。居なければ従来の見本名。
  const EMPLOYEES = EMP_LIST
    ? EMP_LIST.map((e,i) => i===0 ? (e.name + '（自分）') : e.name)
    : ['baba（自分）','佐藤 健太','鈴木 彩','田中 翔','高橋 由依','山本 拓真','中村 さくら','伊藤 健','渡辺 美優'];
  // 社員index → その社員の登録済み state（DBから）。先頭(自分)は myState を使うので別扱い。
  function empState(idx){
    if (!EMP_LIST || idx===0) return null;            // 自分 or モックは null
    const e = EMP_LIST[idx];
    return (e && PREFS[e.id]) ? (PREFS[e.id].state || {}) : null;
  }
  // 社員index・年月 → その月の備考（DB）。データが無ければ null（＝seedMemoにフォールバック）。
  function empMemo(idx, y, m){
    if (!EMP_LIST || idx===0) return null;
    const e = EMP_LIST[idx];
    if (!e || !PREFS[e.id]) return null;
    const memoMap = PREFS[e.id].memo || {};
    return (monKey(y,m) in memoMap) ? (memoMap[monKey(y,m)] || '') : '';
  }
  // 社員index・日 → 〇×△の値。本物データが無い日は従来の仮マーク（seedMark）にフォールバック。
  function markFor(idx, name, y, m, d){
    if (idx===0) return myState[keyOf(y,m,d)];        // 自分＝入力タブの内容
    const st = empState(idx);
    if (st){ return st[keyOf(y,m,d)]; }               // 本物データ（未入力日は undefined＝「−」）
    return seedMark(name, d);                          // データ未登録の社員は従来の仮マーク
  }
  // 名前＋日からブレない仮の〇×△を作る
  function seedMark(name, d){
    let h = d * 13;
    for (let i=0;i<name.length;i++) h += name.charCodeAt(i) * (i+3);
    const r = h % 10;
    if (r <= 5) return 'ok';      // 6割くらい〇
    if (r <= 7) return 'ng';
    return 'maybe';
  }
  function markHtml(v){
    if (v==='ok')    return '<span class="ov-mark ok">〇</span>';
    if (v==='ng')    return '<span class="ov-mark ng">×</span>';
    if (v==='maybe') return '<span class="ov-mark maybe">△</span>';
    return '<span class="ov-mark none">−</span>';
  }
  // 他社員の仮の「備考」「平日希望休」
  const MEMO_POOL = ['', 'お盆は実家のため要相談', '平日昼は会議が多めです', '遠方案件は前泊できると助かります',
                     '特になし', '子どもの行事がある週は休み希望が多めです'];
  function hashName(name){ let h=0; for (let i=0;i<name.length;i++) h += name.charCodeAt(i)*(i+3); return h; }
  function seedMemo(name){ return MEMO_POOL[hashName(name) % MEMO_POOL.length]; }
  // 平日のうち希望休にしている日（自分＝myState、他＝仮）を配列で返す
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
      } else {
        if ((hashName(name) + d*7) % 11 === 0) out.push(d); // データ未登録＝従来の仮
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
        cols.push({ d, w, badge:kind.badge });
      }
    }
    const tbl = document.getElementById('ovTbl');
    if (cols.length === 0){
      tbl.innerHTML = '<thead><tr><th class="namecol">社員</th><th>この月は対象日（土日・祝日・長期休暇・大型）がありません</th></tr></thead>';
      return;
    }
    // ヘッダー（最後に「平日の希望休」「備考」列を追加）
    let head = '<thead><tr class="vh"><th class="namecol">社員</th>';
    cols.forEach(c=>{
      const cls = (c.badge ? 'holi' : 'we') + (c.badge==='大型' ? ' big' : '');
      head += '<th class="' + cls + '">' + c.d + '<br><small>(' + c.w + ')' + (c.badge?'<br>'+c.badge:'') + '</small></th>';
    });
    head += '<th class="offcol">平日の希望休</th><th class="memocol">備考</th></tr></thead>';
    // 本体
    const myMemo = document.getElementById('memo').value.trim(); // 自分は入力タブの内容を反映
    let body = '<tbody>';
    EMPLOYEES.forEach((name, idx)=>{
      const me = idx === 0;
      body += '<tr class="' + (me?'me':'') + '"><td class="namecol">' + name + '</td>';
      cols.forEach(c=>{
        const v = markFor(idx, name, y, m, c.d);
        body += '<td>' + markHtml(v) + '</td>';
      });
      const offs = weekdayOffDays(name, y, m, me, idx);
      const offTxt = offs.length ? offs.map(d=>d+'日').join('・') : '<span style="color:#c7bba9;">なし</span>';
      // 備考：自分＝入力タブ／本物データ＝その月のnote／データ未登録＝従来の仮memo
      const realMemo = empMemo(idx, y, m);
      const memo = me ? (myMemo || '<span style="color:#c7bba9;">（未入力）</span>')
                      : (realMemo !== null ? (realMemo || '<span style="color:#c7bba9;">―</span>')
                                           : (seedMemo(name) || '<span style="color:#c7bba9;">―</span>'));
      body += '<td class="offcol">' + offTxt + '</td><td class="memocol">' + memo + '</td>';
      body += '</tr>';
    });
    body += '</tbody>';
    // 〇の人数フッター（追加2列ぶんは空欄）
    let foot = '<tfoot><tr><td class="namecol">〇の人数</td>';
    cols.forEach(c=>{
      let cnt = 0;
      EMPLOYEES.forEach((name, idx)=>{
        const v = markFor(idx, name, y, m, c.d);
        if (v==='ok') cnt++;
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
  renderCalendar();
</script>
@endverbatim
@endpush
