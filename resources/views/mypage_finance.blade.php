@extends('layouts.app')
@section('title', '収支入力（マイページ）')
@section('h1', '収支入力（M-5）')
@php($active = 'mypage_finance')

@push('head')
@verbatim
<style>
    .mp-wrap { max-width: 1000px; }
    .back-link { font-size: 13px; color: var(--brand-dark); font-weight: 700; }
    .back-link:hover { color: var(--brand-ink); }

    /* 案件選択バー */
    .pick-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .pick-bar label { font-size: 13px; font-weight: 700; color: var(--ink); }
    .pick-bar select {
      padding: 8px 11px; border: 1px solid var(--line); border-radius: 8px;
      font-size: 13.5px; font-family: inherit; background: #fff; max-width: 100%;
    }
    .pick-bar #caseSelect { min-width: 320px; }

    .case-head { font-size: 15px; font-weight: 800; color: var(--ink); margin: 0 0 2px; }
    /* 案件の種別色（マイページと同じ：大型＝赤／オンライン＝青／リアル＝緑・やわらかい色）。見出しの左に色帯。 */
    .case-head.type-big    { border-left: 4px solid #e8a0a0; padding-left: 9px; }
    .case-head.type-online { border-left: 4px solid #9bb9e0; padding-left: 9px; }
    .case-head.type-real   { border-left: 4px solid #9ccbaa; padding-left: 9px; }
    /* 種別の小バッジ（見出しの横に「大型／リアル／オンライン」を表示） */
    .type-badge { display: inline-block; font-size: 11px; font-weight: 700; padding: 1px 8px;
      border-radius: 999px; margin-left: 8px; vertical-align: middle; white-space: nowrap; }
    .type-badge.type-big    { background: #fdf0f0; color: #c25b5b; }
    .type-badge.type-online { background: #eef3fb; color: #4f74ad; }
    .type-badge.type-real   { background: #eef6f0; color: #4f8a63; }
    .case-sub { font-size: 12.5px; color: var(--muted); margin: 0 0 14px; }
    /* 宿泊バッジ（前泊有・後泊あり など。会社名の横に表示） */
    .stay-badge {
      display: inline-block; font-size: 11px; font-weight: 700; padding: 1px 8px;
      border-radius: 999px; margin-left: 8px; vertical-align: middle;
      background: #fdecd8; color: #b4530a; white-space: nowrap;
    }

    /* 売上欄 */
    .rev-box { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 6px; }
    .rev-box .yen-input { width: 160px; }

    /* 金額入力 */
    .yen-input {
      width: 110px; padding: 7px 9px; border: 1px solid var(--line); border-radius: 8px;
      font-size: 13.5px; font-family: inherit; text-align: right; background: #fff;
    }
    .qty-input { width: 76px; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .unit-cell { color: var(--muted); font-size: 12.5px; }
    .price-cell { color: var(--ink); font-variant-numeric: tabular-nums; }
    .price-cell .jisshi { color: var(--muted); font-size: 12px; }
    .amount-cell { font-weight: 700; font-variant-numeric: tabular-nums; }
    .note-cell { font-size: 11.5px; color: var(--muted); }
    /* 経費表の備考は1行に収め、はみ出しは「…」で省略（マウスで全文表示） */
    #costBody td.note-cell {
      max-width: 240px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    #costBody td.note-cell:hover { cursor: help; }

    tr.total-row td { background: var(--brand-soft); font-weight: 800; color: var(--brand-dark); }
    tr.profit-row td { background: #eef6ee; font-weight: 800; }
    tr.profit-row td.minus { color: var(--danger); }

    .save-row { display: flex; align-items: center; gap: 14px; margin-top: 16px; }
    .saved-ping { color: #2e7d32; font-weight: 700; font-size: 13px; opacity: 0; transition: opacity .2s; }
    .saved-ping.show { opacity: 1; }
    .empty-note { color: var(--muted); font-size: 13px; margin: 10px 2px; }

    /* =========================================================
       スマホ表示（画面の横幅が720px以下のときだけ効く）
       PCの見た目は一切変えない。ここは「指で入力する画面」なので、
       ①はみ出さない ②文字を大きく ③押しやすい を最優先にしている。
       共通の土台（/ecs/style.css の同じ幅の指定）で足りないぶんだけ書く。
       ========================================================= */
    @media (max-width: 720px) {

      /* 案件を選ぶバー：横並びだと375pxに収まらないので、ラベルの下に選択欄を置く縦積みに */
      .pick-bar { display: block; }
      .pick-bar label { display: block; margin: 12px 0 4px; }
      .pick-bar label[for="monthFilter"] { margin-top: 0; }
      /* 選択欄は幅いっぱい。文字は16px＝これより小さいとiPhoneが触るたびに勝手に拡大するため */
      .pick-bar select { width: 100%; font-size: 16px; padding: 10px 11px; }
      /* 320px固定だと画面（375px）からはみ出すので、下限をやめて幅いっぱいに */
      .pick-bar #caseSelect { min-width: 0; }

      /* 売上欄：ラベル・入力・注意書きを縦に積む。入力欄は幅いっぱいで押しやすく */
      .rev-box { display: block; margin-bottom: 10px; }
      .rev-box label { display: block; margin-bottom: 5px; }
      .rev-box .yen-input { width: 100%; }
      .rev-box .note-cell { display: block; margin-top: 6px; line-height: 1.6; }

      /* 金額の入力欄も16px。小さいままだとiPhoneで拡大され、以後ずっと横スクロールになる */
      .yen-input { font-size: 16px; padding: 10px 11px; }
      /* メモ欄は文字サイズがHTMLに直接書いてあるので、!important で16pxに上書きする */
      #memoInput { font-size: 16px !important; padding: 10px 11px !important; }

      /* 経費の表：6列を横に並べると画面に入りきらず、横スクロールして入力欄を探すことになる。
         スマホでは1費目＝1枚のカードとして縦に積み、項目名はCSSで付け直す。 */
      #finPanel table.tbl { display: block; overflow-x: visible; font-size: 13px; }
      #finPanel table.tbl thead { display: none; }        /* 見出し行は各行に文字で付けるので隠す */
      #finPanel table.tbl tbody,
      #finPanel table.tbl tfoot { display: block; }

      #costBody tr {
        display: block;
        border: 1px solid var(--line);
        border-radius: 10px;
        padding: 10px 12px;
        margin-bottom: 10px;
        background: #fff;
      }
      #costBody tr:hover { background: #fff; }            /* PC用のマウス反転はスマホでは不要 */
      #costBody td {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        text-align: left;
        border-bottom: 0;                                  /* カードの中に横線が出ないように */
        padding: 4px 0;
        min-height: 34px;
      }
      /* 1列目＝費目の名前。カードの見出しとして幅いっぱいに出す */
      #costBody td:first-child {
        display: block;
        font-weight: 700;
        padding: 0 0 6px;
        line-height: 1.45;
      }
      /* 隠した見出し行の代わりに、各行の左へ項目名を出す */
      #costBody td::before {
        color: var(--muted);
        font-size: 12px;
        font-weight: 700;
        flex: 0 0 auto;
      }
      #costBody td:nth-child(2)::before { content: '単価'; }
      #costBody td:nth-child(3)::before { content: '数量'; }
      #costBody td:nth-child(4)::before { content: '単位'; }
      #costBody td:nth-child(5)::before { content: '金額'; }
      #costBody td.note-cell::before   { content: '備考 '; }
      /* 備考はPCでは1行に省略しているが、スマホでは折り返して全部読めるようにする */
      #costBody td.note-cell {
        display: block;
        max-width: none;
        white-space: normal;
        overflow: visible;
        text-overflow: clip;
        padding-top: 6px;
        line-height: 1.55;
      }
      #costBody td.note-cell:empty { display: none; }      /* 備考が無い費目は行ごと出さない */

      /* 入力欄はカードの右側に。指で押せる幅を確保する */
      #costBody input.yen-input { width: 120px; flex: 0 0 auto; text-align: right; }
      #costBody input.qty-input { width: 96px; }

      /* 合計・利益の行：カードと同じ横並び1行にする。
         色はもともと td に付いているが、この形だと文字の後ろにしか色が乗らないので tr に移す */
      #finPanel table.tbl tfoot tr {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        padding: 12px;
        border-radius: 10px;
        margin-bottom: 8px;
      }
      #finPanel table.tbl tfoot td { border-bottom: 0; padding: 0; background: transparent; }
      #finPanel table.tbl tfoot td:empty { display: none; }  /* 空のセルが余白として並ばないように */
      #finPanel tr.total-row  { background: var(--brand-soft); }
      #finPanel tr.profit-row { background: #eef6ee; }
      #finPanel table.tbl tfoot td.amount-cell { font-size: 15px; }

      /* 保存ボタン：画面幅いっぱい＋高さを出して、片手でも押し外さないように */
      .save-row { display: block; margin-top: 18px; }
      .save-row .btn { width: 100%; padding: 13px 16px; font-size: 15px; }
      .saved-ping { display: block; margin-top: 8px; text-align: center; }

      /* 下の注意書きは小さすぎると読めないので少しだけ大きく・行間を広げる */
      #finPanel > p.note-cell { font-size: 12px; line-height: 1.7; }
    }

    /* ここから下は「黒ベース（ダークモード）」のときだけ効く上書き。
       上の色は白地むけに直接書いてあるので、黒地だと白い入力欄や淡い色の帯が読みにくい。
       意味の色（大型＝赤／オンライン＝青／リアル＝緑／宿泊＝橙／利益＝緑）は残したまま、
       「暗い面＋明るい文字」に置き換える。 */
    html[data-theme="dark"] .pick-bar select,
    html[data-theme="dark"] .yen-input { background: var(--surface-2); color: var(--ink); border-color: var(--field-line); }
    html[data-theme="dark"] .type-badge.type-big    { background: var(--danger-soft); color: var(--danger-ink); }
    html[data-theme="dark"] .type-badge.type-online { background: var(--info-soft); color: var(--info-ink); }
    html[data-theme="dark"] .type-badge.type-real   { background: var(--ok-soft); color: var(--ok-ink); }
    html[data-theme="dark"] .stay-badge { background: var(--warn-soft); color: var(--warn-ink); }
    html[data-theme="dark"] tr.profit-row td { background: var(--ok-soft); color: var(--ink); }
    html[data-theme="dark"] tr.profit-row td.minus { color: var(--danger-ink); }
    html[data-theme="dark"] .saved-ping { color: var(--ok-ink); }
    /* スマホでカード表示になる経費の行（白地が書いてある）。マウスを乗せたときの色も合わせて指定する */
    html[data-theme="dark"] #costBody tr { background: var(--panel); }
    html[data-theme="dark"] #costBody tr:hover { background: var(--surface-2); }
    html[data-theme="dark"] #finPanel tr.profit-row { background: var(--ok-soft); }
  </style>
@endverbatim
@endpush

@section('content')
@verbatim
      <div class="mock-note">案件一覧は登録済みのデータ（DB）から表示しています。収支を入力できるのは<b>自分がディレクター（D）または営業担当の案件</b>です。項目は雛型「東京アサイン表」の収支シートに合わせています。入力した収支は<b>保存されます</b>（開き直すと前回の入力が復元されます）。<a class="back-link" href="/mypage">← マイページに戻る</a></div>

      <!-- 案件を選ぶ -->
      <div class="panel mp-wrap">
        <div class="pick-bar">
          <label for="monthFilter">月：</label>
          <select id="monthFilter" onchange="onMonthChange()"></select>
          <label for="caseSelect">案件：</label>
          <select id="caseSelect" onchange="loadCase()"></select>
        </div>
        <p class="empty-note" id="noCase" style="display:none; margin:10px 0 0;">この月に担当案件はありません。</p>
      </div>

      <!-- 収支シート -->
      <div class="panel mp-wrap" id="finPanel" style="margin-top:20px;">
        <div class="case-head" id="caseHead">—</div>
        <div class="case-sub" id="caseSub"></div>

        <!-- 売上 -->
        <div class="rev-box">
          <label style="font-size:14px; font-weight:700;">売上（受注額）</label>
          <input class="yen-input" type="number" min="0" step="1000" id="revInput" oninput="recalc()" placeholder="0">
          <span class="note-cell">※雛型の収支シートには無い項目です。利益を出すために追加しています（不要なら外せます）。</span>
        </div>

        <table class="tbl">
          <thead>
            <tr><th>種別（経費）</th><th class="num">単価</th><th class="num">数量</th><th>単位</th><th class="num">金額</th><th>備考</th></tr>
          </thead>
          <tbody id="costBody"></tbody>
          <tfoot>
            <tr class="total-row"><td>経費 合計</td><td></td><td></td><td></td><td class="num amount-cell" id="costTotal">¥0</td><td></td></tr>
            <tr class="profit-row"><td>利益（売上 − 経費）</td><td></td><td></td><td></td><td class="num amount-cell" id="profitCell">¥0</td><td></td></tr>
          </tfoot>
        </table>

        <!-- メモ（この案件の収支についての補足。保存されます） -->
        <div style="margin-top:14px;">
          <label for="memoInput" style="display:block; font-size:13px; font-weight:700; margin-bottom:5px;">メモ（任意）</label>
          <textarea id="memoInput" rows="2" placeholder="この案件の収支についての補足があれば入力"
            style="width:100%; max-width:640px; padding:8px 10px; border:1px solid var(--line); border-radius:8px; font-size:13.5px; font-family:inherit; box-sizing:border-box;"></textarea>
        </div>

        <div class="save-row">
          <button class="btn primary" onclick="saveCase()">この案件の収支を保存する</button>
          <span class="saved-ping" id="savedPing">✓ 保存しました</span>
        </div>
        <p class="note-cell" style="margin:12px 0 0;">
          ※ 「実費」項目は <b>1,000円単位に切り上げ</b>（例：499円→1,000円）して合計に反映します。<br>
          ※ <b>入力しなくてよい項目</b>：販管費（正社員人件費）／準備・片付けスタッフ費／リハ・レクチャー費／IKUSAカーのガソリン費／謎解き等の印刷代／コンテンツの必要経費（開発費・衣装・今後も使う備品）／通信費／事務所での印刷費。<br>
          ※ 単価・ルールの詳細は「収支の入力定義」を確認。本番では会計・請求の仕組みと連携する想定です。
        </p>
      </div>

      <!-- スタッフの手当（2026-10-13）。保存先は「請求書チェック・手当管理表」と同じ（/staff-allowances）。 -->
      <div class="panel mp-wrap" id="alPanel" style="margin-top:20px;">
        <div class="case-head" style="font-size:15px;">✍ この案件のスタッフの手当（運転・スライド・宿泊・設営・リハなど）</div>
        <p class="note-cell" style="margin:6px 0 10px;">手当を付ける人にチェックして（何人でも）、種類などを入れて「＋入れる」。チェックした全員に同じ内容が入ります。入れたものは「🧾 請求書チェック・手当管理表」の請求書の目安に足されます。<br>
          ※ 前日設営・リハ日のアサインは自動で入ります（いらなければ「消す」・6時間以上なら種類を「1案件として数える」に切り替え）。MC・前泊・ロングはアサインから自動で数えます。</p>
        <div id="alPeople" style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:6px;"></div>
        <div class="al-form" style="display:flex; flex-wrap:wrap; gap:6px; align-items:center;">
          <button class="btn" type="button" onclick="alCheckAll(true)">全員にチェック</button>
          <select id="alKind" onchange="alKindChanged()"></select>
          <input type="date" id="alDate">
          <input type="number" id="alCount" min="1" max="20" value="1" style="width:60px;" title="回数"> 回
          <input type="number" id="alAmount" min="0" max="200000" placeholder="金額（その他）" style="width:120px; display:none;">
          <input type="text" id="alNote" maxlength="200" placeholder="メモ（例：本能寺のレクチャー）" style="width:220px;">
          <button class="btn primary" type="button" onclick="alAdd()">＋ 入れる</button>
        </div>
        <div class="note-cell" id="alHint" style="margin-top:4px;"></div>
        <div class="note-cell" id="alMsg" style="margin-top:4px; font-weight:700;"></div>
        <table class="tbl" style="margin-top:8px;">
          <thead><tr><th>日付</th><th>スタッフ</th><th>手当</th><th>メモ</th><th class="num">金額</th><th>入れた人</th><th></th></tr></thead>
          <tbody id="alBody"></tbody>
        </table>
      </div>
@endverbatim
@endsection

@push('scripts')
<script src="/ecs/data/cases.js"></script>
<script>
  // DB（MyPageFinanceController）から渡したデータ。マイページと同じ共通部品で作る。
  window.ECS_ME = @json($me ?? null);
  window.MY_ASSIGN_DB = @json($myAssign ?? null);
  window.ECS_CASES_DB = @json($cases ?? null);
  window.ROLE_LABELS = @json(\App\Support\AssignmentRole::LABELS);
  // 保存済みの収支（案件ID→{revenue, items, memo}）。案件を選ぶとこの値で各欄を復元する。
  window.ECS_FINANCES = @json($finances ?? new stdClass);
  // 保存（POST）に必要な合言葉（CSRFトークン）。他画面と同じ ECS_CSRF を使う。
  window.ECS_CSRF = @json(csrf_token());
  // 経費の費目＝サーバー側の正本（App\Support\FinanceItems）。ここが唯一の定義で、
  // 収支の一覧（/finance-list）も同じ定義・同じ計算ルールで合計を出す（金額の食い違い防止）。
  window.ECS_COST_ITEMS = @json($costItems ?? []);
  // 収支一覧から「✏ 入力する」で来た案件（権限OKのときだけ入る）。担当以外の案件でも1件だけ選べるようにする。
  window.ECS_FORCED_CASE = @json($forcedCase ?? null);
  // 案件は必ずDBの内容にする。0件なら0件のまま（見本 cases.js の架空案件に戻さない）。
  window.ECS_CASES = window.ECS_CASES_DB || [];
</script>
@verbatim
<script>
  // ログイン中の本人（Controller が Auth から渡す）。
  const ME = (window.ECS_ME && window.ECS_ME.name) ? window.ECS_ME.name : 'baba';
  const WK = ['日','月','火','水','木','金','土'];
  const STORE = 'ecs_finance';   // { caseId: { rev:数値, items:{key:{qty,amount}}, memo } }

  // 自分のアサイン（案件ID→役割コード）。Controller が assignments から作る。
  // アサインが無ければ「無い」まま。架空の案件で収支を入力できてしまうのを防ぐ。
  const MY_ASSIGN = window.MY_ASSIGN_DB || {};
  // 自分が「D（ディレクター）」の案件か？ 役割は 'D' で入るが、古い表記ゆれ 'ディレクター' も拾う。
  function isMyDirector(id) { return MY_ASSIGN[id] === 'D' || MY_ASSIGN[id] === 'ディレクター'; }

  // 収支の入力定義（Notion）に合わせた経費項目。
  //   定義はサーバー側の正本（App\Support\FinanceItems）から受け取る。
  //   price 数値 … 単価が1つに決まる項目 → 数量を入れて金額を自動計算
  //   price null … 実費の項目 → 金額を直接入力（合計時に1,000円単位へ切り上げ）
  //   ※ ここに費目をベタ書きしないこと。一覧（/finance-list）の計算と食い違う原因になる。
  const COST_ITEMS = window.ECS_COST_ITEMS || [];

  function dateOf(off){ return (window.ECS_caseDate ? window.ECS_caseDate(off)
    : (function(){ var d=new Date(); d.setHours(0,0,0,0); d.setDate(d.getDate()+off); return d; })()); }
  function fmtDate(off){ const d=dateOf(off); return (d.getMonth()+1)+'/'+d.getDate()+'('+WK[d.getDay()]+')'; }
  // 案件の種別（マイページと同じ判定：大型を最優先→オンライン→リアル）。見出しの色帯とバッジに使う。
  function typeInfo(c){
    if (c.scale === '大型') return { cls: 'type-big', label: '大型' };
    if (c.fmt === 'online') return { cls: 'type-online', label: 'オンライン' };
    return { cls: 'type-real', label: 'リアル' };
  }
  function monthKey(off){ const d=dateOf(off); return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0'); }
  function monthLabel(k){ const [y,m]=k.split('-'); return y+'年'+Number(m)+'月'; }
  function yen(n){ return '¥' + Math.round(n||0).toLocaleString('ja-JP'); }
  function loadStore(){ try { return JSON.parse(localStorage.getItem(STORE)||'{}'); } catch(e){ return {}; } }
  function saveStore(o){ try { localStorage.setItem(STORE, JSON.stringify(o)); } catch(e){} }
  function byId(id){ return (window.ECS_CASES||[]).find(c => c.id===id); }

  // 収支の対象＝自分が「D（ディレクター）」の案件、または「営業担当」の案件
  // 収支を入力できる案件＝自分がD／営業担当の案件。
  // ＋収支一覧から「✏ 入力する」で来た案件（ECS_FORCED_CASE）も1件だけ加える
  //   （権限の判定はサーバー側で済んでいる＝管理者が他の人の担当案件を直すときの導線）。
  function targetCases(){
    const map = {};
    const forced = window.ECS_FORCED_CASE || null;
    (window.ECS_CASES||[]).forEach(c => {
      if (c.draft) return;
      if (isMyDirector(c.id) || c.sales === ME || c.id === forced) map[c.id] = c;
    });
    return Object.values(map).sort((a,b)=>a.off-b.off);
  }

  function buildMonthOptions(){
    const keys = new Set();
    targetCases().forEach(c => keys.add(monthKey(c.off)));
    const sorted = Array.from(keys).sort();
    document.getElementById('monthFilter').innerHTML =
      '<option value="">すべての月</option>' +
      sorted.map(k => '<option value="'+k+'">'+monthLabel(k)+'</option>').join('');
  }

  function buildCaseOptions(){
    const mf = document.getElementById('monthFilter').value;
    const list = targetCases().filter(c => !mf || monthKey(c.off)===mf);
    const sel = document.getElementById('caseSelect');
    sel.innerHTML = list.map(c => '<option value="'+c.id+'">'+fmtDate(c.off)+'　'+c.name+'</option>').join('');
    const has = list.length > 0;
    sel.style.display = has ? '' : 'none';
    document.getElementById('finPanel').style.display = has ? '' : 'none';
    document.getElementById('noCase').style.display = has ? 'none' : 'block';
    return has;
  }

  // 経費明細の行を生成
  function buildCostRows(){
    const body = document.getElementById('costBody');
    body.innerHTML = COST_ITEMS.map(it => {
      if (it.price !== null) {
        return '<tr data-key="'+it.key+'">' +
          '<td>'+it.name+'</td>' +
          '<td class="num price-cell">'+yen(it.price)+'</td>' +
          '<td class="num"><input class="yen-input qty-input" type="number" min="0" step="1" data-role="qty" value="0" oninput="recalc()"></td>' +
          '<td class="unit-cell">'+it.unit+'</td>' +
          '<td class="num amount-cell" data-role="amount">¥0</td>' +
          '<td class="note-cell" title="'+it.note+'">'+it.note+'</td>' +
        '</tr>';
      }
      return '<tr data-key="'+it.key+'">' +
        '<td>'+it.name+'</td>' +
        '<td class="num price-cell"><span class="jisshi">実費</span></td>' +
        '<td class="num">—</td>' +
        '<td class="unit-cell">'+it.unit+'</td>' +
        '<td class="num"><input class="yen-input" type="number" min="0" step="1000" data-role="amount-input" value="0" oninput="recalc()"></td>' +
        '<td class="note-cell" title="'+it.note+'">'+it.note+'</td>' +
      '</tr>';
    }).join('');
  }

  // 金額の再計算
  function recalc(){
    let total = 0;
    COST_ITEMS.forEach((it, i) => {
      const tr = document.querySelector('#costBody tr[data-key="'+it.key+'"]');
      let amount = 0;
      if (it.price !== null) {
        const qty = Number(tr.querySelector('[data-role="qty"]').value || 0);
        amount = it.price * qty;
        tr.querySelector('[data-role="amount"]').textContent = yen(amount);
      } else {
        // 実費は1,000円単位に切り上げ（例：499円→1,000円）。注記のルールに合わせて計算する。
        const raw = Number(tr.querySelector('[data-role="amount-input"]').value || 0);
        amount = raw > 0 ? Math.ceil(raw / 1000) * 1000 : 0;
      }
      total += amount;
    });
    const rev = Number(document.getElementById('revInput').value || 0);
    document.getElementById('costTotal').textContent = yen(total);
    const profit = rev - total;
    const pc = document.getElementById('profitCell');
    pc.textContent = yen(profit);
    pc.classList.toggle('minus', profit < 0);
  }

  // 選んだ案件の保存内容を読み込んで表示
  function loadCase(){
    const id = document.getElementById('caseSelect').value;
    const c = byId(id);
    if (!c) return;
    // 役割コード（D/SD…）→ ラベル。営業担当のみの場合は「営業担当」。
    const RL = window.ROLE_LABELS || {};
    const roleShort = (code) => (RL[code] || code || '').replace(/（.*?）/g, '');
    const role = (id in MY_ASSIGN) ? ('アサイン：' + roleShort(MY_ASSIGN[id])) : '営業担当';
    const head = document.getElementById('caseHead');
    head.textContent = c.name + '（' + c.client + '）';
    // 種別で見出しの左に色帯＋横に種別バッジ（マイページと同配色）。
    const ti = typeInfo(c);
    head.className = 'case-head ' + ti.cls;
    const tb = document.createElement('span');
    tb.className = 'type-badge ' + ti.cls;
    tb.textContent = ti.label;
    head.appendChild(tb);
    // 宿泊が「無」以外なら、会社名の横に宿泊バッジ（前泊有・後泊あり など）を出す。
    const lg = (c.lodging || '').trim();
    if (lg && lg !== '無') {
      const b = document.createElement('span');
      b.className = 'stay-badge';
      b.textContent = lg;
      head.appendChild(b);
    }
    document.getElementById('caseSub').textContent = fmtDate(c.off) + '　/　' + role + '　/　必要人数 ' + (c.need||'—') + '名';

    // 保存済みの収支は「サーバの値」が正（開き直しても残る）。
    // サーバに無い案件だけ、これまでのブラウザ記憶（localStorage）を使う。
    const srv = (window.ECS_FINANCES && window.ECS_FINANCES[id]) || null;
    const f = srv
      ? { rev: srv.revenue, items: srv.items || {}, memo: srv.memo || '' }
      : (loadStore()[id] || {});
    document.getElementById('revInput').value = f.rev || '';
    document.getElementById('memoInput').value = f.memo || '';
    // 当日スタッフ費の「仮の数量」＝案件の運営人数（DB）。まだ保存が無い案件だけ自動で入れる（手で変更可）。
    // 実施形態に合う行に入れる（オンライン→staff_online／リアルロング→staff_long／それ以外→staff_real）。
    const staffKey = c.fmt === 'online' ? 'staff_online' : (c.fmt === 'long' ? 'staff_long' : 'staff_real');
    const defaultStaff = Number(c.need) || 0;
    COST_ITEMS.forEach(it => {
      const tr = document.querySelector('#costBody tr[data-key="'+it.key+'"]');
      const saved = (f.items && f.items[it.key]) || {};
      if (it.price !== null) {
        // 保存済みならその値。未保存の案件は、当日スタッフ費の行だけ運営人数を仮入力。
        const fallback = (it.key === staffKey) ? defaultStaff : 0;
        tr.querySelector('[data-role="qty"]').value = ('qty' in saved) ? saved.qty : fallback;
      } else {
        tr.querySelector('[data-role="amount-input"]').value = saved.amount || 0;
      }
    });
    recalc();
    alLoad(id);
  }

  // ===== スタッフの手当（2026-10-13 baba「収支入力からも運転手当やレクチャー手当を追加できたら」）=====
  // 案件を選ぶたびに、その案件のスタッフと入っている手当をサーバーから読む（保存先は /staff-allowances の1つ）。
  // ⚠ 失敗をだまって流さない＝赤字で知らせる。
  let AL = { project: null, hints: {}, needsAmount: [], switchable: [] };
  function alEsc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch])); }
  function alMsg(text, ng){ const m = document.getElementById('alMsg'); m.textContent = text; m.style.color = ng ? '#b91c1c' : ''; }
  async function alLoad(id){
    AL.project = id;
    alMsg('');
    document.getElementById('alBody').innerHTML = '<tr><td colspan="7" class="note-cell">読み込み中…</td></tr>';
    try {
      const r = await fetch('/staff-allowances/project?project=' + encodeURIComponent(id), { headers: { 'Accept': 'application/json' } });
      if (!r.ok) throw new Error('通信エラー ' + r.status);
      const j = await r.json();
      if (AL.project !== id) return;   // 読み込みの途中で別の案件を選んだ
      // その案件のスタッフをチェックで並べる（2026-10-13 baba「複数チェックして同じ内容を入れたい」）。
      const box = document.getElementById('alPeople');
      box.innerHTML = j.staff.length ? j.staff.map(s => '<label style="display:inline-flex; gap:4px; align-items:center; font-size:12.5px; border:1px solid var(--line); border-radius:999px; padding:3px 10px; cursor:pointer;">'
        + '<input type="checkbox" class="al-pick" value="' + alEsc(s.id) + '"> ' + alEsc(s.name) + ' <span class="note-cell">' + alEsc(s.role.replace(/（.*?）/g, '')) + '</span></label>').join('')
        : '<span class="note-cell">（この案件にスタッフはいません）</span>';
      AL.needsAmount = j.needsAmount || [];
      AL.switchable = j.switchable || [];
      const kd = document.getElementById('alKind');
      kd.innerHTML = '';
      Object.keys(j.kinds).forEach(k => { const o = document.createElement('option'); o.value = k;
        o.textContent = k + (j.kinds[k] ? '（' + Number(j.kinds[k]).toLocaleString('ja-JP') + '円）' : (AL.needsAmount.indexOf(k) >= 0 ? '（金額を入れる）' : '（基本報酬）')); kd.appendChild(o); });
      AL.hints = j.hints || {};
      document.getElementById('alDate').value = j.date || '';
      alKindChanged();
      alRender(j.items || []);
    } catch (e) {
      document.getElementById('alBody').innerHTML = '';
      alMsg('⚠ 手当を読み込めませんでした（' + e.message + '）。開き直してください。', true);
    }
  }
  function alKindChanged(){
    const k = document.getElementById('alKind').value;
    document.getElementById('alAmount').style.display = (AL.needsAmount.indexOf(k) >= 0) ? '' : 'none';
    document.getElementById('alHint').textContent = AL.hints[k] || '';
  }
  function alCheckAll(on){ document.querySelectorAll('#alPeople .al-pick').forEach(c => { c.checked = on; }); }
  function alRender(items){
    const body = document.getElementById('alBody');
    if (!items.length) { body.innerHTML = '<tr><td colspan="7" class="note-cell">まだ入っていません。</td></tr>'; return; }
    // 自動で入った行（前日設営・リハ日）は印を付け、リハ・設営は種類を切り替えられる（6時間以上なら1案件）。
    const kindCell = a => (a.auto ? '<span class="note-cell" style="font-weight:700;">自動</span> ' : '')
      + (a.switchable
        ? '<select onchange="alSwitch(' + Number(a.id) + ', this.value)">' + AL.switchable.map(k => '<option' + (k === a.kind ? ' selected' : '') + '>' + alEsc(k) + '</option>').join('') + '</select>'
        : alEsc(a.kind))
      + (a.count > 1 ? ' ×' + a.count : '');
    body.innerHTML = items.map(a => '<tr><td>' + alEsc(a.md) + '</td><td>' + alEsc(a.staff) + '</td><td>' + kindCell(a)
      + '</td><td>' + alEsc(a.note) + '</td><td class="num">' + Number(a.amount).toLocaleString('ja-JP') + '円</td><td class="note-cell">' + alEsc(a.by)
      + '</td><td><button class="btn" type="button" onclick="alDel(' + Number(a.id) + ')">消す</button></td></tr>').join('');
  }
  async function alSwitch(aid, kind){
    try {
      const r = await fetch('/staff-allowances/' + aid + '/kind', { method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF }, body: JSON.stringify({ kind }) });
      if (!r.ok) throw new Error('通信エラー ' + r.status);
      alMsg('✓ 「' + kind + '」に切り替えました');
      alLoad(AL.project);
    } catch (e) {
      alMsg('⚠ 切り替えられませんでした（' + e.message + '）', true);
      alLoad(AL.project);
    }
  }
  async function alAdd(){
    const body = {
      staff_ids: [...document.querySelectorAll('#alPeople .al-pick:checked')].map(c => c.value),
      project_id: AL.project,
      date: document.getElementById('alDate').value,
      kind: document.getElementById('alKind').value,
      count: Number(document.getElementById('alCount').value || 1),
      amount: document.getElementById('alAmount').value === '' ? null : Number(document.getElementById('alAmount').value),
      note: document.getElementById('alNote').value,
    };
    if (!body.staff_ids.length) { alMsg('⚠ 手当を付けるスタッフにチェックしてください。', true); return; }
    if (!body.date) { alMsg('⚠ 日付を入れてください。', true); return; }
    try {
      const r = await fetch('/staff-allowances', { method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF }, body: JSON.stringify(body) });
      const j = await r.json().catch(() => null);
      if (!r.ok || !j || !j.ok) {
        const first = j && j.errors ? Object.values(j.errors)[0][0] : ('通信エラー ' + r.status);
        throw new Error(first);
      }
      document.getElementById('alNote').value = '';
      document.getElementById('alAmount').value = '';
      document.getElementById('alCount').value = 1;
      alMsg('✓ ' + (j.message || (j.item.kind + 'を入れました')));
      alLoad(AL.project);
    } catch (e) {
      alMsg('⚠ 入れられませんでした（' + e.message + '）', true);
    }
  }
  async function alDel(aid){
    if (!confirm('この手当を消しますか？')) return;
    try {
      const r = await fetch('/staff-allowances/' + aid + '/delete', { method: 'POST',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF } });
      if (!r.ok) throw new Error('通信エラー ' + r.status);
      alMsg('✓ 消しました');
      alLoad(AL.project);
    } catch (e) {
      alMsg('⚠ 消せませんでした（' + e.message + '）', true);
    }
  }

  async function saveCase(){
    const id = document.getElementById('caseSelect').value;
    if (!id) return;
    // 画面の入力を集める（売上・各経費行の数量/金額・メモ）。
    const items = {};
    COST_ITEMS.forEach(it => {
      const tr = document.querySelector('#costBody tr[data-key="'+it.key+'"]');
      if (it.price !== null) {
        const qty = Number(tr.querySelector('[data-role="qty"]').value || 0);
        items[it.key] = { qty, amount: it.price * qty };
      } else {
        items[it.key] = { amount: Number(tr.querySelector('[data-role="amount-input"]').value || 0) };
      }
    });
    const revenue = Number(document.getElementById('revInput').value || 0);
    const memo = document.getElementById('memoInput').value;

    // これまでのブラウザ記憶も残しておく（保険）。ただし正はサーバの値。
    const store = loadStore();
    store[id] = { rev: revenue, items, memo };
    saveStore(store);

    // サーバへ本物保存（共有DB）。合言葉（CSRF）を付けて送る。
    try {
      const res = await fetch('/mypage-finance/save', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': window.ECS_CSRF,
          'Accept': 'application/json',
        },
        body: JSON.stringify({ project_id: id, revenue, items, memo }),
      });
      if (!res.ok) throw new Error('save failed: ' + res.status);
      // 保存できたら、画面が持っている「保存済み収支」も最新に更新しておく。
      if (!window.ECS_FINANCES) window.ECS_FINANCES = {};
      window.ECS_FINANCES[id] = { revenue, items, memo };
      const ping = document.getElementById('savedPing');
      ping.classList.add('show');
      setTimeout(() => ping.classList.remove('show'), 2000);
    } catch (e) {
      alert('保存に失敗しました。通信状況を確認して、もう一度お試しください。');
    }
  }

  function onMonthChange(){
    if (buildCaseOptions()) loadCase();
  }

  // URLの ?case=<案件ID>（マイページのアーカイブ／営業担当からの導線）があれば、その案件を選んで開く。
  // 該当が無ければ従来どおり先頭の案件を表示。
  function preselectFromUrl(){
    const id = new URLSearchParams(location.search).get('case');
    if (!id) return;
    const sel = document.getElementById('caseSelect');
    if ([...sel.options].some(o => o.value === id)) sel.value = id;
  }

  // 初期化
  buildMonthOptions();
  buildCostRows();
  if (buildCaseOptions()) { preselectFromUrl(); loadCase(); }
</script>
@endverbatim
@endpush
