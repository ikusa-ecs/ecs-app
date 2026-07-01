@extends('layouts.app')
@section('title', '収支入力（マイページ）')
@section('h1', '収支入力（M-5）')
@php($active = 'mypage_finance')

@push('head')
@verbatim
<style>
    .mp-wrap { max-width: 1000px; }
    .back-link { font-size: 13px; color: var(--brand-dark); font-weight: 700; }
    .back-link:hover { color: var(--brand); }

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
  </style>
@endverbatim
@endpush

@section('content')
@verbatim
      <div class="mock-note">案件一覧は登録済みのデータ（DB）から表示しています。収支を入力できるのは<b>自分がディレクター（D）または営業担当の案件</b>です。項目は雛型「東京アサイン表」の収支シートに合わせています。入力の保存は準備中で、今はこのブラウザにだけ記憶されます（本番はMTG後）。<a class="back-link" href="/mypage">← マイページに戻る</a></div>

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
  if (window.ECS_CASES_DB && window.ECS_CASES_DB.length) { window.ECS_CASES = window.ECS_CASES_DB; }
</script>
@verbatim
<script>
  // ログイン中の社員（認証はMTG後。今は Controller が固定した「自分」）。
  const ME = (window.ECS_ME && window.ECS_ME.name) ? window.ECS_ME.name : 'baba';
  const WK = ['日','月','火','水','木','金','土'];
  const STORE = 'ecs_finance';   // { caseId: { rev:数値, items:{key:{qty,amount}}, memo } }

  // 自分のアサイン（案件ID→役割コード）。DB があればそれ、無ければ見本。
  const MY_ASSIGN_MOCK = {
    'past_fes':'ディレクター','undo_d1':'ディレクター','undo_d2':'ディレクター','undo_d3':'ディレクター',
    'mizu':'ディレクター','shinkan':'ディレクター','konshin':'SD','enni1':'MC',
    'bousai':'ディレクター','fes_setup':'ディレクター'
  };
  const MY_ASSIGN = (window.MY_ASSIGN_DB && Object.keys(window.MY_ASSIGN_DB).length)
    ? window.MY_ASSIGN_DB : MY_ASSIGN_MOCK;
  // 自分が「D（ディレクター）」の案件か？ 見本は'ディレクター'、DBは'D'で入る。
  function isMyDirector(id) { return MY_ASSIGN[id] === 'D' || MY_ASSIGN[id] === 'ディレクター'; }

  // 収支の入力定義（Notion）に合わせた経費項目
  //   price 数値 … 単価が1つに決まる項目 → 数量を入れて金額を自動計算
  //   price null … 単価が複数 or 実費の項目 → 金額を直接入力（自動計算はしない。料金表は備考に参考表示）
  const COST_ITEMS = [
    // ── 当日スタッフ費（形態別。単価は1人1日ぶん。数量＝スタッフ人数） ──
    { key:'staff_online', name:'当日スタッフ費（オンライン）',   price:7000,  unit:'名', note:'交通費込み・1人1日ぶん' },
    { key:'staff_real',   name:'当日スタッフ費（リアル）',       price:10000, unit:'名', note:'交通費込み・1人1日ぶん' },
    { key:'staff_long',   name:'当日スタッフ費（リアルロング）', price:12000, unit:'名', note:'交通費込み・1人1日ぶん' },
    { key:'stay_pre',     name:'前泊手当',                       price:2000,  unit:'件', note:'前泊ありの場合 ＋2,000/件' },
    { key:'stay_post',    name:'後泊手当',                       price:2000,  unit:'件', note:'後泊ありの場合 ＋2,000/件' },
    // ── 単価が決まっている費用 ──
    { key:'food',          name:'飲食費（水分含む）', price:1000,  unit:'人',            note:'' },
    { key:'print_conveni', name:'コンビニ印刷費',     price:1000,  unit:'件',            note:'コンビニで印刷した分' },
    { key:'goods_move',    name:'輸送費',             price:2000,  unit:'コンテナ(片道)', note:'1コンテナ1箱・片道。大型/チャーターは下の実費へ' },
    { key:'move_air',      name:'スタッフ移動費（飛行機）',   price:20000, unit:'片道', note:'' },
    { key:'move_taxi',     name:'スタッフ移動費（タクシー）', price:2000,  unit:'片道', note:'' },
    { key:'move_bus',      name:'スタッフ移動費（高速バス）', price:2000,  unit:'片道', note:'' },
    { key:'parking',       name:'駐車場費',           price:3000,  unit:'日', note:'' },
    { key:'lodging',       name:'宿泊費',             price:11000, unit:'泊', note:'5人で泊まったら5泊。前泊・後泊手当も含む' },
    { key:'trainer',       name:'研修講師費',         price:77000, unit:'日', note:'OODAチャンバラ・サバ研はスタッフ費で計上' },
    // ── 単価が複数 or 実費（金額を直接入力。料金表は備考の参考。1,000円未満は四捨五入） ──
    { key:'highway',       name:'高速費（ガソリン・ETC含む）', price:null, unit:'実費', note:'片道：30km1,800/50km3,000/90km5,400/120km7,200/150km9,000/180km10,800/210km12,600' },
    { key:'rentacar',      name:'レンタカー費',               price:null, unit:'実費', note:'ハイエース基準：1泊2日16,000/2泊3日29,000/3泊4日42,000/4泊5日55,000' },
    { key:'food_cater',    name:'フード手配費',               price:null, unit:'実費', note:'ケータリング/オードブル/BBQ/格付け/マグロ等。業者別ルールは収支定義を参照' },
    { key:'outsource',     name:'外注費',                     price:null, unit:'実費', note:'MC・音響・配信・警備・設備など' },
    { key:'print_input',   name:'入稿印刷費',                 price:null, unit:'実費', note:'パッケージ以外' },
    { key:'goods_buy',     name:'物品購入費',                 price:null, unit:'実費', note:'該当イベントのみで使う物品（今後使う物は計上しない）' },
    { key:'move_irregular',name:'イレギュラー輸送費',         price:null, unit:'実費', note:'大型物品輸送・チャーター便など' },
    { key:'onsite',        name:'緊急購入物品費',             price:null, unit:'実費', note:'現場で緊急的に購入した物品' }
  ];

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
  function targetCases(){
    const map = {};
    (window.ECS_CASES||[]).forEach(c => {
      if (c.draft) return;
      if (isMyDirector(c.id) || c.sales === ME) map[c.id] = c;
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

    const f = loadStore()[id] || {};
    document.getElementById('revInput').value = f.rev || '';
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
  }

  function saveCase(){
    const id = document.getElementById('caseSelect').value;
    if (!id) return;
    const store = loadStore();
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
    store[id] = { rev: Number(document.getElementById('revInput').value || 0), items };
    saveStore(store);
    const ping = document.getElementById('savedPing');
    ping.classList.add('show');
    setTimeout(() => ping.classList.remove('show'), 2000);
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
