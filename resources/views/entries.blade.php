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
      display:flex; flex-wrap:wrap; gap:8px; align-items:center;
      background:#fff; border:1px solid #e6dccf; border-radius:10px;
      padding:8px 12px; margin-bottom:12px;
    }
    .ent-filter input[type=date] { padding:6px 8px; border:1px solid #d8c8b6; border-radius:7px; font-size:13px; color:#3a2d20; }
    .ent-filter .f-today { padding:6px 10px; border:1px solid #d8c8b6; border-radius:7px; background:#f4ede3; color:#6e5b49; font-size:12px; cursor:pointer; }
    .ent-filter .f-today:hover { background:#eee3d4; }
    .ent-filter input[type=text], .ent-filter select {
      padding:7px 10px; border:1px solid #d8c8b6; border-radius:7px; font-size:13px; color:#3a2d20;
    }
    .ent-filter .f-label { font-size:12px; color:#a08a73; }

    /* サマリー（数値カード） */
    .ent-summary { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:12px; }
    .sum-card {
      background:#fff; border:1px solid #e6dccf; border-radius:10px;
      padding:8px 14px; min-width:118px;
    }
    .sum-card .num { font-size:20px; font-weight:700; color:#3a2d20; }
    .sum-card .num.warn { color:#c0392b; }
    .sum-card .lbl { font-size:12px; color:#a08a73; margin-top:2px; }

    /* 案件ごと：案件カード */
    .ecase {
      background:#fff; border:1px solid #e6dccf; border-radius:12px;
      padding:10px 12px; margin-bottom:10px;
    }
    .ecase-head { display:flex; flex-wrap:wrap; align-items:baseline; gap:8px; margin-bottom:3px; }
    .ecase-date { font-size:13px; color:#6e5b49; font-weight:600; min-width:70px; }
    .ecase-name { font-size:16px; font-weight:700; color:#3a2d20; }
    .ecase-client { font-size:12.5px; color:#a08a73; }
    .ecase-counts { display:flex; gap:16px; margin:5px 0 6px; flex-wrap:wrap; }
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
    .ent-table th, .ent-table td { padding:3px 7px; text-align:left; border-bottom:1px solid #f0e8dd; }
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

    /* 月ごと：スタッフ（縦）×案件（横）の一覧表 */
    .mtx-legend { font-size:12px; color:#6e5b49; margin:0 0 10px; display:flex; gap:16px; flex-wrap:wrap; }
    .mtx-legend b { font-size:13px; }
    .mtx-wrap { overflow-x:auto; border:1px solid #e6dccf; border-radius:10px; background:#fff; margin-bottom:6px; }
    table.mtx { border-collapse:separate; border-spacing:0; font-size:12px; }
    table.mtx th, table.mtx td { border-bottom:1px solid #f0e8dd; border-right:1px solid #f0e8dd; padding:3px 6px; white-space:nowrap; }
    table.mtx thead th { position:sticky; top:0; background:#f3ece2; color:#5a4a38; z-index:2; font-weight:700; text-align:center; vertical-align:bottom; }
    table.mtx th.staffcol, table.mtx td.staffcol { position:sticky; left:0; background:#fff; text-align:left; z-index:1; font-weight:600; min-width:140px; }
    table.mtx th.entcol, table.mtx td.entcol { background:#faf7f1; text-align:center; min-width:42px; font-weight:700; }
    table.mtx thead th.staffcol { z-index:3; background:#f3ece2; }
    table.mtx td.cell { text-align:center; }
    table.mtx .coldate { font-size:11px; color:#6e5b49; }
    table.mtx .colname { font-size:11px; max-width:96px; overflow:hidden; text-overflow:ellipsis; display:inline-block; vertical-align:bottom; }
    table.mtx .colmeta { font-size:10px; color:#a08a73; font-weight:600; }
    table.mtx td.totcol, table.mtx th.totcol { background:#faf7f1; text-align:center; }
    table.mtx .m-asg  { color:#3d7a45; font-weight:700; }
    table.mtx .m-tmp  { color:#b5824a; font-weight:700; font-size:11px; border:1px solid #e0cdb4; border-radius:4px; padding:0 4px; background:#fcefd6; }
    table.mtx .m-ent  { color:#b5824a; font-weight:700; }
    table.mtx .m-none { color:#d8cbb8; }
    /* クリックで 未→仮→確定→未 と切り替えできるセル */
    table.mtx td.assignable { cursor:pointer; }
    table.mtx td.assignable:hover { outline:2px solid var(--brand,#b5673a); outline-offset:-2px; background:#fff7ec; }
    table.mtx td.is-tmp { background:#fdf6e7; }         /* 仮アサイン */
    table.mtx td.is-fix { background:#eaf3ea; }         /* 確定 */
    table.mtx td.is-pub { background:#dceee9; }         /* 確定＋公開済み＝解除に確認が要る */
    table.mtx .m-lock { font-size:10px; margin-left:1px; }
    /* 「×」＝このアサインを外すボタン（本体クリックでは解除されない） */
    table.mtx .m-x { color:#c99; font-weight:700; font-size:12px; margin-left:3px; cursor:pointer; }
    table.mtx .m-x:hover { color:#c0392b; }
    table.mtx tbody tr:hover td { background:#faf5ee; }
    table.mtx tbody tr:hover td.staffcol { background:#fff7ec; }
    table.mtx tfoot td { background:#faf5ee; font-weight:700; text-align:center; color:#5a4a38; }
    table.mtx tfoot td.staffcol { text-align:right; }

    .empty-note { color:#a08a73; padding:30px; text-align:center; font-size:13px; }
    .view { display:none; }
    .view.active { display:block; }
  </style>
@endverbatim
@endpush

@section('content')
@verbatim
      <div class="mock-note">
        <b>どの案件に、誰がエントリー（希望）してくれているか</b>を確認する画面です（DBの本物データ）。<br>
        上の「この日から」で表示開始日を選べます。<b>「案件ごと」</b>＝案件単位の応募者一覧／<b>「月ごと」</b>＝スタッフ（縦）×案件（横）の一覧表で見られます。
      </div>

      <!-- 見方の切替タブ -->
      <div class="ent-tabs">
        <button class="ent-tab active" id="tab-bycase"  onclick="switchView('bycase')">📋 案件ごと</button>
        <button class="ent-tab"        id="tab-bymonth" onclick="switchView('bymonth')">🗓 月ごと</button>
      </div>

      <!-- 絞り込み -->
      <div class="ent-filter">
        <span class="f-label">この日から：</span>
        <input type="date" id="fFromDate" onchange="render()">
        <button type="button" class="f-today" onclick="resetFromToday()">今日から</button>
        <span class="f-label" style="margin-left:6px;">絞り込み：</span>
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
        ※ <b>アサイン済み</b>＝すでにその案件のメンバーに入れた人。<b>エントリー中</b>＝希望はくれたがまだ未割当の人。<br>
        ※ 募集状態は「確定／必要人数を満たした」案件を<b>締切</b>、それ以外を<b>募集中</b>として表示しています。
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
<!-- エントリー一覧「月ごと」からのアサイン保存（A案）用。 -->
<script>
  window.ECS_ASSIGN_URL = '/entries/assign';
  window.ECS_CSRF = '{{ csrf_token() }}';
</script>
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
  function isoOf(d){ const m=String(d.getMonth()+1).padStart(2,'0'), dd=String(d.getDate()).padStart(2,'0'); return d.getFullYear()+'-'+m+'-'+dd; }
  function dateLabel(off){ const d = caseDate(off); return (d.getMonth()+1) + '/' + d.getDate() + '（' + DOW[d.getDay()] + '）'; }
  function ymKey(off){ const d = caseDate(off); return d.getFullYear() + '-' + (d.getMonth()+1); }
  function ymLabel(off){ const d = caseDate(off); return d.getFullYear() + '年 ' + (d.getMonth()+1) + '月'; }

  // 募集状態（モック簡易ルール）：確定 or 必要人数を満たした → 締切／それ以外 → 募集中
  function recruitState(c){
    if (c.status === '確定' || c.filled >= c.need) return 'close';
    return 'open';
  }

  // 募集対象の案件だけ（募集する・下書きでない）。過去/未来の絞りは「この日から」で行う（passFilter）。
  // DBの案件リスト（ECS_ENTRIES_CASES）があればそれを使い、空なら見本cases.jsにフォールバック。
  function targetCases(){
    const src = (window.ECS_ENTRIES_CASES && window.ECS_ENTRIES_CASES.length)
      ? window.ECS_ENTRIES_CASES : window.ECS_CASES;
    return src
      .filter(c => c.recruit && !c.draft)
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
    const from = document.getElementById('fFromDate').value;   // "YYYY-MM-DD"（空なら制限なし）
    const kw  = document.getElementById('fKeyword').value.trim();
    const rec = document.getElementById('fRecruit').value;
    const pos = document.getElementById('fPos').value;
    if (from && isoOf(caseDate(c.off)) < from) return false;    // 選んだ日より前は出さない
    if (kw && !((c.name || '').includes(kw) || (c.client || '').includes(kw))) return false;
    if (rec && recruitState(c) !== rec) return false;
    if (pos && !entrantsOf(c).some(e => e.pos === pos)) return false;
    return true;
  }
  // 「今日から」ボタン：表示開始日を今日に戻す
  function resetFromToday(){
    document.getElementById('fFromDate').value = isoOf(caseDate(0));
    render();
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

  // ② 月ごと ＝ スタッフ（縦）× 案件（横）の一覧表
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
    const todayKey = caseDate(0).getFullYear() + '-' + (caseDate(0).getMonth()+1);

    const legend = '<div class="mtx-legend">見方：<span class="m-ent">〇</span> エントリー中'
      + '　<span class="m-tmp">仮</span> 仮アサイン　<span class="m-asg">✓</span> 確定'
      + '　<span class="m-none">・</span> 応募なし　<span class="m-lock">🔒</span> 確定＋公開済み（解除に確認）'
      + '　｜　縦＝スタッフ／横＝案件　｜　<b>クリックで 未→仮→確定（前に進むだけ）／外すのは「×」</b></div>';

    box.innerHTML = legend + groups.map(g => {
      const past = caseDate(g.off) < caseDate(0) && g.key !== todayKey;
      const cases = g.items.slice().sort((a,b) => a.off - b.off);

      // この月の案件に関わるスタッフ（応募＋アサイン）を縦に並べる。名前をキーに集約。
      const staffMap = {}; const staffOrder = [];
      cases.forEach(c => entrantsOf(c).forEach(e => {
        if (!staffMap[e.name]){ staffMap[e.name] = { name:e.name, lv:e.lv, ent:0, asg:0 }; staffOrder.push(e.name); }
        staffMap[e.name].ent++;
        if (e.assigned) staffMap[e.name].asg++;
      }));

      if (!staffOrder.length){
        return `<div class="month-group"><div class="month-head ${past?'past':''}">${g.label}`
          + `<span class="cnt">（${cases.length}件）${past?' ・終了':''}</span></div>`
          + '<div class="empty-note" style="padding:16px;">この月はまだエントリーがありません。</div></div>';
      }
      // 応募数の多い順（同数はアサイン数の多い順）
      staffOrder.sort((a,b) => staffMap[b].ent - staffMap[a].ent || staffMap[b].asg - staffMap[a].asg);

      // 案件ごとに「名前→エントリー情報（状態・スタッフID・役割）」の対応表を作る
      // st： 'fix'=確定 / 'tmp'=仮 / 'ent'=エントリー中（未アサイン）
      const caseStatus = cases.map(c => {
        const m = {};
        entrantsOf(c).forEach(e => {
          const st = (e.status === '確定') ? 'fix' : (e.status === '仮' ? 'tmp' : 'ent');
          m[e.name] = { st: st, id: e.id, role: e.roleCode || '' };
        });
        return m;
      });

      // ヘッダー（案件を横に）
      let head = '<thead><tr><th class="staffcol">スタッフ</th><th class="entcol">応募<br>数</th>';
      cases.forEach(c => {
        const cnt = entrantsOf(c).length;
        head += `<th title="${esc(c.name)}"><span class="coldate">${dateLabel(c.off)}</span><br>`
          + `<span class="colname">${esc(c.name)}</span><br>`
          + `<span class="colmeta">必${c.need}/応${cnt}</span></th>`;
      });
      head += '</tr></thead>';

      // 本体（スタッフを縦に）
      let body = '<tbody>';
      staffOrder.forEach(name => {
        const s = staffMap[name];
        body += `<tr><td class="staffcol">${esc(name)} <span class="e-lv ${s.lv}">${lvLabel[s.lv]}</span></td>`
          + `<td class="cell entcol"><b>${s.ent}</b></td>`;
        caseStatus.forEach((m, ci) => {
          const c = cases[ci];
          const e = m[name];
          if (!e){ body += '<td class="cell"><span class="m-none">・</span></td>'; return; }
          const st = e.st;                 // 'fix'=確定 / 'tmp'=仮 / 'ent'=エントリー中
          const pub = c.state === 'pub';   // スタッフに公開済みの案件か
          // 状態ごとのマーク
          const mark = st === 'fix' ? '<span class="m-asg">✓</span>'
                     : st === 'tmp' ? '<span class="m-tmp">仮</span>'
                     : '<span class="m-ent">〇</span>';
          const lock = (st === 'fix' && pub) ? '<span class="m-lock" title="確定＋公開済み">🔒</span>' : '';
          const rmBtn = (st !== 'ent') ? ' <span class="m-x" title="このアサインを外す">×</span>' : '';
          const tip = st === 'fix' ? (pub ? '確定＋公開済み（×で解除・確認あり）' : '確定（外すには×）')
                    : st === 'tmp' ? 'クリックで確定／×で外す'
                    : 'クリックで仮アサイン';
          // スタッフID がある（DBの本物データ）ならクリックで保存できるようにする
          if (e.id){
            const cls = st === 'fix' ? 'is-fix' : (st === 'tmp' ? 'is-tmp' : 'is-ent');
            body += `<td class="cell assignable ${cls}${(st==='fix'&&pub)?' is-pub':''}"`
              + ` data-pid="${esc(c.id)}" data-sid="${esc(e.id)}" data-role="${esc(e.role)}" data-state="${st}"`
              + ` data-pub="${pub?'1':''}" data-sname="${esc(name)}" data-cname="${esc(c.name)}"`
              + ` title="${tip}">` + mark + lock + rmBtn + '</td>';
          } else {
            body += `<td class="cell">${mark}</td>`;
          }
        });
        body += `</tr>`;
      });
      body += '</tbody>';

      // フッター（案件ごとの アサイン/応募 数）
      let foot = '<tfoot><tr><td class="staffcol">アサイン / 応募</td><td class="cell entcol"></td>';
      cases.forEach(c => {
        const ents = entrantsOf(c);
        const asg = ents.filter(e => e.assigned).length;
        foot += `<td class="cell">${asg}/${ents.length}</td>`;
      });
      foot += '</tr></tfoot>';

      return `<div class="month-group">`
        + `<div class="month-head ${past?'past':''}">${g.label}`
        + `<span class="cnt">（案件${cases.length}件・スタッフ${staffOrder.length}名）${past?' ・終了':''}</span></div>`
        + `<div class="mtx-wrap"><table class="mtx">${head}${body}${foot}</table></div>`
        + `</div>`;
    }).join('');
  }

  // ===== 月ごとの表：セルをクリックしてアサイン↔解除（A案・即保存） =====
  let toggling = false; // 二重クリック防止
  function onMatrixClick(ev){
    const cell = ev.target.closest('td.assignable');
    if (!cell || toggling) return;
    const pid = cell.dataset.pid, sid = cell.dataset.sid, role = cell.dataset.role || '';
    const state = cell.dataset.state; // 'ent'=未 / 'tmp'=仮 / 'fix'=確定
    const isRemove = !!ev.target.closest('.m-x'); // 「×」を押したか
    // クリックは前に進むだけ：未→仮→確定。確定は押しても戻らない。外すのは「×」だけ。
    let action, status = null;
    if (isRemove){
      action = 'unassign';
    } else if (state === 'ent'){
      action = 'assign'; status = '仮';
    } else if (state === 'tmp'){
      action = 'assign'; status = '確定';
    } else {
      return; // 確定セルの本体クリックは何もしない（うっかり解除を防ぐ）
    }
    // 確定＋公開済みを外すのは重大＝確認を挟む
    if (action === 'unassign' && state === 'fix' && cell.dataset.pub){
      const sn = cell.dataset.sname || 'この人', cn = cell.dataset.cname || 'この案件';
      if (!confirm('「' + cn + '」はスタッフに公開済みの案件です。\n' + sn + ' さんの確定アサインを外します。よろしいですか？\n（公開中の案件なので、誤操作を防ぐため確認しています）')) return;
    }
    toggling = true;
    cell.style.opacity = '.4';
    fetch(window.ECS_ASSIGN_URL, {
      method: 'POST',
      headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF, 'Accept':'application/json' },
      body: JSON.stringify({ project_id: pid, staff_id: sid, role: role, action: action, status: status })
    })
    .then(r => r.json())
    .then(res => {
      toggling = false;
      if (!res || !res.ok){ alert((res && res.message) || '保存に失敗しました。'); cell.style.opacity=''; return; }
      applyToggleToData(pid, sid, res.assigned, res.status); // 手元のデータも更新
      // 表を作り直すと横スクロールが左端に戻ってしまうので、位置を覚えて戻す
      const wraps = Array.from(document.querySelectorAll('#view-bymonth .mtx-wrap'));
      const scrolls = wraps.map(w => w.scrollLeft);
      render();                                              // 表・フッター・サマリーを作り直し
      document.querySelectorAll('#view-bymonth .mtx-wrap').forEach((w, i) => { if (scrolls[i] != null) w.scrollLeft = scrolls[i]; });
    })
    .catch(() => { toggling = false; cell.style.opacity=''; alert('保存に失敗しました（通信エラー）。'); });
  }
  // 保存結果を画面のデータ（ECS_ENTRIES_CASES）に反映して、集計もそろえる
  function applyToggleToData(pid, sid, assigned, status){
    const src = (window.ECS_ENTRIES_CASES && window.ECS_ENTRIES_CASES.length) ? window.ECS_ENTRIES_CASES : window.ECS_CASES;
    const c = src.find(x => String(x.id) === String(pid));
    if (!c || !Array.isArray(c.entrants)) return;
    const e = c.entrants.find(x => String(x.id) === String(sid));
    if (!e) return;
    const wasAssigned = !!e.assigned;
    e.assigned = assigned;
    e.status = assigned ? status : null;
    // アサイン済み数（サマリー用）＝仮＋確定。仮↔確定の切替では増減しない。
    if (assigned && !wasAssigned)      c.filled = (c.filled || 0) + 1;
    else if (!assigned && wasAssigned) c.filled = Math.max(0, (c.filled || 0) - 1);
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

  // 初期描画（表示開始日は既定＝今日）
  document.getElementById('fFromDate').value = isoOf(caseDate(0));
  // 月ごとの表のクリックを1回だけ登録（innerHTML を作り直しても親要素は残るので有効）
  document.getElementById('view-bymonth').addEventListener('click', onMatrixClick);
  render();
  applyFocus();
</script>
@endverbatim
@endpush
