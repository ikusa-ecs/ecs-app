@extends('layouts.app')
@section('title', 'エントリー一覧')
@section('h1', 'エントリー一覧')
@php($active = 'entries')

@push('head')
@verbatim
  <style>
    /* ===== エントリー一覧 専用スタイル ===== */

    /* タブ（案件ごと／月ごと） */
    .ent-tabs { display:flex; gap:8px; margin:0 0 16px; border-bottom:2px solid #e6dccf; }
    .ent-tab {
      background:none; border:none; padding:10px 18px; cursor:pointer;
      font-size:14px; color:#6e5b49; font-weight:600; border-bottom:3px solid transparent;
      margin-bottom:-2px;
    }
    .ent-tab:hover { color:#3a2d20; }
    .ent-tab.active { color:var(--brand,#b5673a); border-bottom-color:var(--brand,#b5673a); }

    /* 絞り込みバー */
    .ent-filter {
      display:flex; flex-wrap:wrap; gap:10px; align-items:center;
      background:#fff; border:1px solid #e6dccf; border-radius:10px;
      padding:12px 14px; margin-bottom:16px;
    }
    .ent-filter input[type=text], .ent-filter select {
      padding:7px 10px; border:1px solid #d8c8b6; border-radius:7px; font-size:13px; color:#3a2d20;
    }
    .ent-filter .f-label { font-size:12px; color:#a08a73; }

    /* サマリー（数値カード） */
    .ent-summary { display:flex; flex-wrap:wrap; gap:12px; margin-bottom:18px; }
    .sum-card {
      background:#fff; border:1px solid #e6dccf; border-radius:10px;
      padding:12px 18px; min-width:140px;
    }
    .sum-card .num { font-size:24px; font-weight:700; color:#3a2d20; }
    .sum-card .num.warn { color:#c0392b; }
    .sum-card .lbl { font-size:12px; color:#a08a73; margin-top:2px; }

    /* 案件ごと：案件カード */
    .ecase {
      background:#fff; border:1px solid #e6dccf; border-radius:12px;
      padding:14px 16px; margin-bottom:16px;
    }
    .ecase-head { display:flex; flex-wrap:wrap; align-items:baseline; gap:10px; margin-bottom:4px; }
    .ecase-date { font-size:13px; color:#6e5b49; font-weight:600; min-width:70px; }
    .ecase-name { font-size:16px; font-weight:700; color:#3a2d20; }
    .ecase-client { font-size:12.5px; color:#a08a73; }
    .ecase-counts { display:flex; gap:18px; margin:8px 0 10px; flex-wrap:wrap; }
    .ecase-counts .c { font-size:13px; color:#6e5b49; }
    .ecase-counts .c b { font-size:16px; color:#3a2d20; }
    .ecase-counts .c.short b { color:#c0392b; }

    /* バッジ類 */
    .e-badge { display:inline-block; font-size:11px; padding:2px 8px; border-radius:20px; font-weight:600; }
    .cat-通常 { background:#eee3d4; color:#6e5b49; }
    .cat-体力 { background:#f6ddd0; color:#b5673a; }
    .cat-安定重視 { background:#dde7e3; color:#3d6b5a; }
    .cat-育成 { background:#e3e0ef; color:#5b4d8a; }
    .dt-badge { background:#f0e6d8; color:#8a6a45; border:1px solid #e0cdb4; }
    .st-badge.todo { background:#efe7da; color:#8a7355; }
    .st-badge.adj  { background:#fcefd6; color:#b5824a; }
    .st-badge.fix  { background:#dcebdc; color:#3d7a45; }
    .st-badge.pub  { background:#d5e8e5; color:#2f8a7a; }
    .rec-badge.open  { background:#dcebdc; color:#3d7a45; }
    .rec-badge.close { background:#ece4da; color:#988168; }

    /* エントリー者テーブル */
    .ent-table { width:100%; border-collapse:collapse; font-size:13px; }
    .ent-table th, .ent-table td { padding:6px 8px; text-align:left; border-bottom:1px solid #f0e8dd; }
    .ent-table th { font-size:11.5px; color:#a08a73; font-weight:600; }
    .ent-table tr.assigned { background:#f6f1e9; }
    .e-lv { font-size:11px; padding:1px 7px; border-radius:20px; }
    .e-lv.new { background:#e3e0ef; color:#5b4d8a; }
    .e-lv.mid { background:#eee3d4; color:#6e5b49; }
    .e-lv.vet { background:#f6ddd0; color:#b5673a; }
    .e-pos { display:inline-block; font-size:11px; padding:1px 7px; border-radius:6px; background:#eee3d4; color:#6e5b49; margin:1px; }
    .e-pos.key { background:#d9e3ef; color:#3d5a8a; }
    .e-stat { font-size:11.5px; font-weight:600; }
    .e-stat.assigned { color:#3d7a45; }
    .e-stat.waiting  { color:#a08a73; }

    /* 月ごと */
    .month-group { margin-bottom:24px; }
    .month-head {
      font-size:15px; font-weight:700; color:#3a2d20;
      border-left:4px solid var(--brand,#b5673a); padding:4px 0 4px 10px; margin-bottom:10px;
    }
    .month-head .cnt { font-size:12px; color:#a08a73; font-weight:500; margin-left:6px; }
    .month-head.past { border-left-color:#cbb89f; color:#a08a73; }
    .mrow {
      background:#fff; border:1px solid #e6dccf; border-radius:10px;
      padding:10px 14px; margin-bottom:8px;
    }
    .mrow-head { display:flex; flex-wrap:wrap; align-items:center; gap:10px; cursor:pointer; }
    .mrow-date { font-size:12.5px; color:#6e5b49; min-width:60px; }
    .mrow-name { font-size:14px; font-weight:600; color:#3a2d20; }
    .mrow-spacer { flex:1; }
    .mrow-mini { font-size:12px; color:#6e5b49; }
    .mrow-mini b { color:#3a2d20; }
    .mrow-toggle { font-size:12px; color:var(--brand,#b5673a); user-select:none; }
    .mrow-ent { display:none; margin-top:8px; padding-top:8px; border-top:1px dashed #e6dccf; }
    .mrow-ent.open { display:block; }
    .ent-chip {
      display:inline-block; font-size:12px; padding:3px 9px; border-radius:20px;
      background:#f4ede3; color:#6e5b49; margin:2px;
    }
    .ent-chip.assigned { background:#dcebdc; color:#3d7a45; }

    .empty-note { color:#a08a73; padding:30px; text-align:center; font-size:13px; }
    .view { display:none; }
    .view.active { display:block; }
  </style>
@endverbatim
@endpush

@section('content')
@verbatim
      <div class="mock-note">
        これは見た目確認用のモックです。案件・人数・エントリー者はすべて仮の見本です。<br>
        <b>どの案件に、誰がエントリー（希望）してくれているか</b>を確認する画面です。
        「案件ごと」と「月ごと」で見方を切り替えられます。
      </div>

      <!-- 見方の切替タブ -->
      <div class="ent-tabs">
        <button class="ent-tab active" id="tab-bycase"  onclick="switchView('bycase')">📋 案件ごと</button>
        <button class="ent-tab"        id="tab-bymonth" onclick="switchView('bymonth')">🗓 月ごと</button>
      </div>

      <!-- 絞り込み -->
      <div class="ent-filter">
        <span class="f-label">絞り込み：</span>
        <input type="text" id="fKeyword" placeholder="案件名・会社名で検索" oninput="render()">
        <select id="fRecruit" onchange="render()">
          <option value="">募集状態：すべて</option>
          <option value="open">募集中のみ</option>
          <option value="close">締切のみ</option>
        </select>
        <select id="fPos" onchange="render()">
          <option value="">できるポジション：すべて</option>
          <option value="D">D（ディレクター）</option>
          <option value="OP">OP（音響）</option>
          <option value="MC">MC（司会）</option>
          <option value="FC">FC（巡回）</option>
          <option value="CK">CK（チェッカー）</option>
          <option value="軍師・サポーター">軍師・サポーター</option>
          <option value="受付">受付</option>
        </select>
      </div>

      <!-- サマリー -->
      <div class="ent-summary" id="summary"></div>

      <!-- 案件ごと -->
      <div class="view active" id="view-bycase"></div>

      <!-- 月ごと -->
      <div class="view" id="view-bymonth"></div>

      <p class="muted" style="font-size:11.5px; margin:14px 0 0;">
        ※ エントリー者は仮の見本です（本番はスタッフが「エントリーする」を押した人が並びます）。<br>
        ※ <b>アサイン済み</b>＝すでにその案件のメンバーに入れた人。<b>エントリー中</b>＝希望はくれたがまだ未割当の人。<br>
        ※ 募集状態は「確定／必要人数を満たした」案件を<b>締切</b>、それ以外を<b>募集中</b>として仮表示しています。
      </p>
@endverbatim
@endsection

@push('scripts')
<!-- 共通の案件データ（全画面で同じものを読む） -->
<script src="/ecs/data/cases.js"></script>
<!-- 名前プールは DB（people のスタッフ）から渡す。NAME_POOL の単一ソース。 -->
<script>window.ECS_STAFF_POOL = @json($staffPool);</script>
<!-- DBの案件＋応募者（実データ）。空のときは見本cases.jsにフォールバック。 -->
<script>window.ECS_ENTRIES_CASES = @json($entriesCases ?? []);</script>
@verbatim
<script>
  // ===== 仮データ生成（assign.html と同じ作り） =====
  // 名前プールは DB（window.ECS_STAFF_POOL）を優先。空のときだけ下のベタ書きを使う（保険）。
  const NAME_POOL = (window.ECS_STAFF_POOL && window.ECS_STAFF_POOL.length) ? window.ECS_STAFF_POOL : [
    '高橋 由依','伊藤 健','渡辺 さくら','鈴木 美咲','山田 涼','松本 美優','井上 大輝','木村 拓海',
    '林 美月','清水 陽','森 結菜','佐藤 健太','池田 莉子','橋本 颯','石川 葵','近藤 樹',
    '山本 翔太','中村 彩','小林 蓮','加藤 結衣','吉田 大和','斎藤 楓','岡田 悠','前田 凛',
    '藤田 海','後藤 蓮','長谷川 葵','村上 陽菜','遠藤 樹','坂本 美羽','青木 駿','西村 杏',
    '福田 翼','太田 七海','三浦 一','藤井 結','金子 蒼','中島 心','原田 楓','和田 凪'
  ];
  const POS_PATTERN = ['D','MC','OP','FC','FC','FC','受付','CK','軍師・サポーター','FC','受付','FC','CK','FC','受付','FC','FC','FC','受付','CK'];
  const LVS = ['vet','mid','mid','new','mid','vet','new','mid','new','vet','mid','new'];
  const lvLabel = { new:'新人', mid:'中堅', vet:'ベテラン' };
  const KEY_POS = ['D','OP','MC','軍師・サポーター'];   // 経験者向け（青タグ）
  const DOW = ['日','月','火','水','木','金','土'];

  function seedOf(id){ let s = 0; for (const ch of id) s += ch.charCodeAt(0); return s; }

  // その案件にエントリー（希望）してくれた人たち（必要数より少し多め）
  // 先頭 filled 名は「アサイン済み」、それ以降は「エントリー中（未割当）」
  function entrantsOf(c){
    // DBの案件（応募者の実データ）があればそれを使う。見本のときだけ下で合成する。
    if (Array.isArray(c.entrants)) return c.entrants;
    const seed  = seedOf(c.id);
    const total = c.need + 4;
    const arr = [];
    for (let i = 0; i < total; i++){
      arr.push({
        no: i + 1,
        name: NAME_POOL[(seed + i) % NAME_POOL.length],
        lv:   LVS[i % LVS.length],
        pos:  POS_PATTERN[i] || 'FC',
        assigned: i < c.filled
      });
    }
    return arr;
  }

  // 日付関連
  function caseDate(off){ return window.ECS_caseDate(off); }
  function dateLabel(off){ const d = caseDate(off); return (d.getMonth()+1) + '/' + d.getDate() + '（' + DOW[d.getDay()] + '）'; }
  function ymKey(off){ const d = caseDate(off); return d.getFullYear() + '-' + (d.getMonth()+1); }
  function ymLabel(off){ const d = caseDate(off); return d.getFullYear() + '年 ' + (d.getMonth()+1) + '月'; }

  // 募集状態（モック簡易ルール）：確定 or 必要人数を満たした → 締切／それ以外 → 募集中
  function recruitState(c){
    if (c.status === '確定' || c.filled >= c.need) return 'close';
    return 'open';
  }

  // 募集対象の案件だけ（募集する・過去でない・下書きでない）
  // DBの案件リスト（ECS_ENTRIES_CASES）があればそれを使い、空なら見本cases.jsにフォールバック。
  function targetCases(){
    const src = (window.ECS_ENTRIES_CASES && window.ECS_ENTRIES_CASES.length)
      ? window.ECS_ENTRIES_CASES : window.ECS_CASES;
    return src
      .filter(c => c.recruit && !c.archived && !c.draft)
      .sort((a,b) => a.off - b.off);
  }

  let currentView = 'bycase';
  function switchView(v){
    currentView = v;
    document.getElementById('tab-bycase').classList.toggle('active', v === 'bycase');
    document.getElementById('tab-bymonth').classList.toggle('active', v === 'bymonth');
    document.getElementById('view-bycase').classList.toggle('active', v === 'bycase');
    document.getElementById('view-bymonth').classList.toggle('active', v === 'bymonth');
    render();
  }

  function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  // 絞り込みを通すか
  function passFilter(c){
    const kw  = document.getElementById('fKeyword').value.trim();
    const rec = document.getElementById('fRecruit').value;
    const pos = document.getElementById('fPos').value;
    if (kw && !((c.name || '').includes(kw) || (c.client || '').includes(kw))) return false;
    if (rec && recruitState(c) !== rec) return false;
    if (pos && !entrantsOf(c).some(e => e.pos === pos)) return false;
    return true;
  }

  // 状態バッジ
  function stateBadge(c){
    const map = { todo:['todo','未着手'], adj:['adj','調整中'], fix:['fix','確定'], pub:['pub','公開済'] };
    const m = map[c.state] || ['todo','未着手'];
    return `<span class="e-badge st-badge ${m[0]}">${m[1]}</span>`;
  }
  function recBadge(c){
    return recruitState(c) === 'open'
      ? '<span class="e-badge rec-badge open">募集中</span>'
      : '<span class="e-badge rec-badge close">締切</span>';
  }
  function posTag(p){
    const key = KEY_POS.includes(p) ? ' key' : '';
    return `<span class="e-pos${key}">${esc(p)}</span>`;
  }

  // ===== 描画 =====
  function render(){
    const list = targetCases().filter(passFilter);
    renderSummary(list);
    if (currentView === 'bycase') renderByCase(list);
    else renderByMonth(list);
  }

  function renderSummary(list){
    const totalEnt = list.reduce((s,c) => s + entrantsOf(c).length, 0);
    const totalAsg = list.reduce((s,c) => s + Math.min(c.filled, c.need), 0);
    const totalNeed = list.reduce((s,c) => s + c.need, 0);
    const shortCases = list.filter(c => recruitState(c) === 'open').length;
    document.getElementById('summary').innerHTML = `
      <div class="sum-card"><div class="num">${list.length}</div><div class="lbl">募集中・調整中の案件</div></div>
      <div class="sum-card"><div class="num">${totalEnt}</div><div class="lbl">エントリー総数（のべ）</div></div>
      <div class="sum-card"><div class="num">${totalAsg}<span style="font-size:14px;color:#a08a73;">/${totalNeed}</span></div><div class="lbl">アサイン済み／必要人数</div></div>
      <div class="sum-card"><div class="num ${shortCases?'warn':''}">${shortCases}</div><div class="lbl">まだ募集中の案件</div></div>`;
  }

  // ① 案件ごと
  function renderByCase(list){
    const box = document.getElementById('view-bycase');
    if (!list.length){ box.innerHTML = '<div class="empty-note">条件に合う案件がありません。</div>'; return; }
    box.innerHTML = list.map(c => {
      const ents = entrantsOf(c);
      const dt = (c.dayType && c.dayType !== '本番') ? `<span class="e-badge dt-badge">${c.dayType}</span>` : '';
      const shortCls = c.filled < c.need ? ' short' : '';
      const rows = ents.map(e => `
        <tr class="${e.assigned?'assigned':''}">
          <td>${e.no}</td>
          <td>${esc(e.name)}</td>
          <td><span class="e-lv ${e.lv}">${lvLabel[e.lv]}</span></td>
          <td>${posTag(e.pos)}</td>
          <td><span class="e-stat ${e.assigned?'assigned':'waiting'}">${e.assigned?'✓ アサイン済み':'エントリー中'}</span></td>
        </tr>`).join('');
      return `
        <div class="ecase" id="ent-${c.id}">
          <div class="ecase-head">
            <span class="ecase-date">${dateLabel(c.off)}</span>
            <span class="ecase-name">${esc(c.name)}</span>
            <span class="ecase-client">${esc(c.client)}</span>
            <span class="e-badge cat-${c.cat}">${c.cat}</span>
            ${dt} ${recBadge(c)} ${stateBadge(c)}
          </div>
          <div class="ecase-counts">
            <span class="c">必要 <b>${c.need}</b>名</span>
            <span class="c">エントリー <b>${ents.length}</b>名</span>
            <span class="c${shortCls}">アサイン済み <b>${Math.min(c.filled,c.need)}</b>名</span>
          </div>
          <table class="ent-table">
            <thead><tr><th>No</th><th>名前</th><th>区分</th><th>できるポジション</th><th>状態</th></tr></thead>
            <tbody>${rows}</tbody>
          </table>
        </div>`;
    }).join('');
  }

  // ② 月ごと
  function renderByMonth(list){
    const box = document.getElementById('view-bymonth');
    if (!list.length){ box.innerHTML = '<div class="empty-note">条件に合う案件がありません。</div>'; return; }
    // 年月ごとにまとめる（日付順）
    const groups = [];
    const map = {};
    list.forEach(c => {
      const k = ymKey(c.off);
      if (!map[k]){ map[k] = { key:k, label:ymLabel(c.off), off:c.off, items:[] }; groups.push(map[k]); }
      map[k].items.push(c);
    });
    groups.sort((a,b) => a.off - b.off);
    const todayY = caseDate(0).getFullYear(), todayM = caseDate(0).getMonth()+1;
    const todayKey = todayY + '-' + todayM;

    box.innerHTML = groups.map(g => {
      const past = caseDate(g.off) < caseDate(0) && g.key !== todayKey;
      const rows = g.items.map((c, gi) => {
        const ents = entrantsOf(c);
        const chips = ents.map(e =>
          `<span class="ent-chip ${e.assigned?'assigned':''}">${esc(e.name)}<span style="opacity:.6;font-size:10px;"> ${e.pos}</span></span>`
        ).join('');
        const rid = 'm-' + c.id;
        return `
          <div class="mrow">
            <div class="mrow-head" onclick="toggleMonthRow('${rid}', this)">
              <span class="mrow-date">${dateLabel(c.off)}</span>
              <span class="mrow-name">${esc(c.name)}</span>
              <span class="e-badge cat-${c.cat}">${c.cat}</span>
              ${stateBadge(c)}
              <span class="mrow-spacer"></span>
              <span class="mrow-mini">エントリー <b>${ents.length}</b>／アサイン <b>${Math.min(c.filled,c.need)}</b>／必要 <b>${c.need}</b></span>
              <span class="mrow-toggle">▸ エントリー者</span>
            </div>
            <div class="mrow-ent" id="${rid}">${chips}</div>
          </div>`;
      }).join('');
      return `
        <div class="month-group">
          <div class="month-head ${past?'past':''}">${g.label}<span class="cnt">（${g.items.length}件）${past?' ・終了':''}</span></div>
          ${rows}
        </div>`;
    }).join('');
  }

  function toggleMonthRow(id, head){
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.toggle('open');
    const t = head.querySelector('.mrow-toggle');
    if (t) t.textContent = el.classList.contains('open') ? '▾ エントリー者' : '▸ エントリー者';
  }

  // ダッシュボード「アサインが必要な案件」などから ?focus=<案件ID> で来たら、その案件カードへ
  // スクロールして一時的に強調する（受け取り側）。月ごとビューにはカードが無いので案件ごとに切替。
  function applyFocus(){
    const id = new URLSearchParams(location.search).get('focus');
    if (!id) return;
    if (currentView !== 'bycase') switchView('bycase');   // switchView内でrenderされる
    const el = document.getElementById('ent-' + id);
    if (!el) return;
    el.scrollIntoView({ behavior:'smooth', block:'center' });
    el.style.transition = 'box-shadow .3s';
    el.style.boxShadow = '0 0 0 3px #e8833a, 0 8px 24px rgba(0,0,0,.14)';
    setTimeout(() => { el.style.boxShadow = ''; }, 4000);
  }

  // 初期描画
  render();
  applyFocus();
</script>
@endverbatim
@endpush
