@extends('layouts.app')
@section('title', 'ピックアップ')
@section('h1', 'ピックアップ')
@php($active = 'pickup')

@push('head')
@verbatim
<style>
    /* ===== ピックアップ 専用スタイル ===== */

    /* 絞り込みバー */
    .pk-filter {
      display:flex; flex-wrap:wrap; gap:10px; align-items:center;
      background:#fff; border:1px solid #e6dccf; border-radius:10px;
      padding:12px 14px; margin-bottom:14px;
    }
    .pk-filter input[type=text], .pk-filter input[type=date], .pk-filter select {
      padding:7px 10px; border:1px solid #d8c8b6; border-radius:7px; font-size:13px; color:#3a2d20;
    }
    .pk-filter .f-label { font-size:12px; color:#a08a73; }
    .pk-filter .f-tilde { color:#a08a73; font-size:13px; }

    /* セクション見出し */
    .pk-section-h {
      font-size:14px; font-weight:700; color:#3a2d20; margin:0 0 10px;
      display:flex; align-items:center; gap:8px;
    }
    .pk-section-h .step {
      display:inline-flex; align-items:center; justify-content:center;
      width:22px; height:22px; border-radius:50%; background:var(--brand,#b5673a);
      color:#fff; font-size:13px; font-weight:700;
    }

    /* 案件を選ぶリスト */
    .pk-pick-list { background:#fff; border:1px solid #e6dccf; border-radius:12px; padding:6px 4px; }
    .pk-row {
      display:flex; align-items:flex-start; gap:10px; padding:9px 12px;
      border-bottom:1px solid #f0e8dd; cursor:pointer;
    }
    .pk-row:last-child { border-bottom:none; }
    .pk-row:hover { background:#faf6ef; }
    .pk-row.on { background:#f6f1e9; }
    .pk-row.pk-row-head { background:#faf4ec; font-size:12.5px; font-weight:700; color:#6e5b49; align-items:center; }
    .pk-row.pk-row-head:hover { background:#f4ede1; }
    .pk-row input[type=checkbox] { width:17px; height:17px; margin-top:2px; accent-color:var(--brand,#b5673a); cursor:pointer; }
    .pk-row-head input[type=checkbox] { margin-top:0; }
    .pk-row-body { flex:1; min-width:0; }
    .pk-row-line1 { display:flex; flex-wrap:wrap; align-items:baseline; gap:8px; }
    .pk-row-date { font-size:12.5px; color:#6e5b49; min-width:78px; font-weight:600; }
    .pk-row-name { font-size:14px; font-weight:600; color:#3a2d20; }
    .pk-row-client { font-size:12px; color:#a08a73; }
    .pk-row-prog { font-size:12.5px; color:#6e5b49; white-space:nowrap; margin-left:auto; }
    .pk-row-prog b { color:#3a2d20; }
    .pk-row-prog.short b { color:#c0392b; }
    .pk-row-line2 { font-size:12px; color:#8a7355; margin-top:3px; }
    .pk-row-line2 span { margin-right:14px; white-space:nowrap; }
    .pk-row-parent { font-size:12px; color:#9c7a52; margin-top:3px; }

    .pk-actions { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin:12px 0 4px; }
    .pk-selcount { font-size:13px; color:#6e5b49; }
    .pk-selcount b { color:var(--brand,#b5673a); font-size:15px; }

    /* バッジ類 */
    .e-badge { display:inline-block; font-size:11px; padding:2px 8px; border-radius:20px; font-weight:600; }
    .dt-badge { background:#f0e6d8; color:#8a6a45; border:1px solid #e0cdb4; }
    .dt-badge.yobi { background:#e7eef0; color:#3d6b7a; border-color:#c9dde2; }
    .dt-badge.reha { background:#efe7da; color:#8a7355; border-color:#ddccb3; }
    .dt-badge.setup{ background:#f6e0d6; color:#b5673a; border-color:#ecc7b3; }
    .day-badge { background:#ece0f0; color:#7a4d8f; border:1px solid #ddc8e6; }

    /* サマリー */
    .pk-summary { display:flex; flex-wrap:wrap; gap:12px; margin:18px 0; }
    .sum-card { background:#fff; border:1px solid #e6dccf; border-radius:10px; padding:12px 18px; min-width:150px; }
    .sum-card .num { font-size:24px; font-weight:700; color:#3a2d20; }
    .sum-card .num.warn { color:#c0392b; }
    .sum-card .lbl { font-size:12px; color:#a08a73; margin-top:2px; }

    /* 選んだ案件カード（横並び・折り返し） */
    #picked { display:grid; grid-template-columns:repeat(auto-fill, minmax(330px, 1fr)); gap:16px; align-items:start; }
    .pk-case { background:#fff; border:1px solid #e6dccf; border-radius:12px; padding:14px 16px; }
    .pk-case-head { display:flex; flex-wrap:wrap; align-items:baseline; gap:10px; margin-bottom:4px; }
    .pk-case-date { font-size:13px; color:#6e5b49; font-weight:700; min-width:78px; }
    .pk-case-name { font-size:16px; font-weight:700; color:#3a2d20; }
    .pk-case-client { font-size:12.5px; color:#a08a73; }
    .pk-case-parent { font-size:12.5px; color:#9c7a52; margin:2px 0 0; }
    .pk-case-meta { font-size:12.5px; color:#6e5b49; margin:8px 0 12px; }
    .pk-case-meta span { margin-right:16px; white-space:nowrap; display:inline-block; }
    .pk-case-meta b { color:#3a2d20; }

    .pk-block-h { font-size:12.5px; font-weight:700; color:#6e5b49; margin:12px 0 6px; }
    .pk-block-h .cnt { color:#a08a73; font-weight:500; }
    .pk-block-h .cnt.short { color:#c0392b; }

    /* メンバー行（横一列・区分なし） */
    .pk-mem {
      display:flex; align-items:center; gap:10px;
      padding:5px 8px; border-bottom:1px solid #f4ede3; font-size:13px;
    }
    .pk-mem:last-child { border-bottom:none; }
    .pk-mem .m-name { font-weight:600; color:#3a2d20; min-width:78px; }
    .pk-mem .e-pos { display:inline-block; font-size:11px; padding:1px 7px; border-radius:6px; background:#eee3d4; color:#6e5b49; }
    .pk-mem .e-pos.key { background:#d9e3ef; color:#3d5a8a; }
    .pk-mem .m-spacer { flex:1; }
    /* 複数の案件（複数日など）に入っている人＝色つき。1案件だけの人は白のまま */
    .pk-mem.multi { background:#fbf1da; }
    .pk-mem.multi .m-name { color:#3a2d20; }
    .pk-mem .multi-flag { color:#b07d1a; font-size:10.5px; font-weight:700; margin-left:6px; }

    /* 稼働バッジ（日別ボードと統一） */
    .cstat { font-size:10px; font-weight:700; padding:1px 7px; border-radius:999px; white-space:nowrap; }
    .cstat.cal1 { background:#e3edf7; color:#2c6ca0; }   /* 終日〇 */
    .cstat.only { background:#ece3d4; color:#7a6a58; }   /* この案件のみ */
    .cstat.done { background:#dcebdc; color:#15803d; border:1px solid #cfe0cf; } /* アサイン済み */

    /* ＋追加 / ×外す ボタン */
    .pk-btn {
      font-size:11.5px; font-weight:600; border:1px solid #d8c8b6; background:#fff;
      color:#6e5b49; border-radius:6px; padding:2px 9px; cursor:pointer; white-space:nowrap;
    }
    .pk-btn:hover { background:#f6f1e9; }
    .pk-btn.add { color:#3d7a45; border-color:#bcd9bc; }
    .pk-btn.add:hover { background:#eef6ee; }
    .pk-btn.remove { color:#a85a4a; border-color:#e0c3bb; }
    .pk-btn.remove:hover { background:#fbeeea; }

    .empty-note { color:#a08a73; padding:30px; text-align:center; font-size:13px; }
</style>
@endverbatim
@endpush

@section('content')
@verbatim
      <div class="mock-note">
        これは見た目確認用のモックです。案件・人数・メンバー・希望者はすべて仮の見本です。<br>
        案件を選んで、メンバーと希望者をまとめて確認・編集できます。
      </div>

      <!-- ① 案件を選ぶ -->
      <div class="pk-section-h"><span class="step">1</span> 案件を選ぶ</div>

      <div class="pk-filter">
        <span class="f-label">絞り込み：</span>
        <input type="text" id="fKeyword" placeholder="案件名・会社名で検索" oninput="renderList()">
        <select id="fFormat" onchange="renderList()">
          <option value="">実施形態：すべて</option>
          <option value="real">リアル</option>
          <option value="long">リアルロング</option>
          <option value="online">オンライン・他拠点</option>
        </select>
        <select id="fDayType" onchange="renderList()">
          <option value="">日程種別：すべて</option>
          <option value="本番">本番</option>
          <option value="予備日">予備日</option>
          <option value="リハ">リハ</option>
          <option value="前日設営">前日設営</option>
        </select>
        <span class="f-label">日付：</span>
        <input type="date" id="fFrom" onchange="renderList()" title="開始日">
        <span class="f-tilde">〜</span>
        <input type="date" id="fTo" onchange="renderList()" title="終了日">
      </div>

      <div class="pk-pick-list" id="pickList"></div>

      <div class="pk-actions">
        <span class="pk-selcount">選択中：<b id="selCount">0</b> 件</span>
        <button class="btn ghost sm" onclick="selectRelated()">関連（本番＋予備日＋リハ）もまとめて選ぶ</button>
        <button class="btn ghost sm" onclick="clearSel()">選択をクリア</button>
      </div>

      <!-- ② 選んだ案件の中身 -->
      <div class="pk-section-h" style="margin-top:22px;"><span class="step">2</span> 選んだ案件の中身（まとめて表示・編集）</div>

      <div class="pk-summary" id="summary"></div>
      <div id="dupAlert"></div>
      <div id="picked"></div>

      <p class="muted" style="font-size:11.5px; margin:14px 0 0;">
        ※ メンバー・希望者は仮の見本です（本番はアサイン済みの人・「エントリーする」を押した人が並びます）。<br>
        ※ <span class="cstat cal1">終日〇</span>＝その日は終日 稼働可（どの案件にも入れる）／<span class="cstat only">この案件のみ</span>＝この案件にだけ希望。<br>
        ※ <b>＋追加</b>でメンバーに入れる・<b>×外す</b>で外せます（モックなので保存はされません）。<br>
        ※ <b style="background:#fbf1da; padding:1px 7px; border-radius:4px;">色つき</b>＝選んだ案件のうち<b>複数（複数日など）に入っている人</b>。同じ人を継続してアサインしたいときの目印です（白＝その案件だけの人）。
      </p>
@endverbatim
@endsection

@push('scripts')
<!-- 共通の案件データ（全画面で同じものを読む） -->
<script src="/ecs/data/cases.js"></script>
<!-- 名前プールは DB（people のスタッフ）から渡す。NAME_POOL の単一ソース。 -->
<script>window.ECS_STAFF_POOL = @json($staffPool);</script>
<!-- DBの案件＋候補者（応募＋当日稼働可）＋現メンバー。空のときは見本cases.jsにフォールバック。 -->
<script>window.ECS_PICKUP_CASES = @json($pickupCases ?? []);</script>
@verbatim
<script>
  // ===== 仮データ生成（entries.html / assign.html と同じ作り） =====
  // 名前プールは DB（window.ECS_STAFF_POOL）を優先。空のときだけ下のベタ書きを使う（保険）。
  const NAME_POOL = (window.ECS_STAFF_POOL && window.ECS_STAFF_POOL.length) ? window.ECS_STAFF_POOL : [
    '高橋 由依','伊藤 健','渡辺 さくら','鈴木 美咲','山田 涼','松本 美優','井上 大輝','木村 拓海',
    '林 美月','清水 陽','森 結菜','佐藤 健太','池田 莉子','橋本 颯','石川 葵','近藤 樹',
    '山本 翔太','中村 彩','小林 蓮','加藤 結衣','吉田 大和','斎藤 楓','岡田 悠','前田 凛',
    '藤田 海','後藤 蓮','長谷川 葵','村上 陽菜','遠藤 樹','坂本 美羽','青木 駿','西村 杏',
    '福田 翼','太田 七海','三浦 一','藤井 結','金子 蒼','中島 心','原田 楓','和田 凪'
  ];
  const POS_PATTERN = ['D','MC','OP','FC','FC','FC','受付','CK','軍師・サポーター','FC','受付','FC','CK','FC','受付','FC','FC','FC','受付','CK'];
  const KEY_POS = ['D','OP','MC','軍師・サポーター'];   // 経験者向け（青タグ）
  const DOW = ['日','月','火','水','木','金','土'];

  function seedOf(id){ let s = 0; for (const ch of id) s += ch.charCodeAt(0); return s; }

  // その案件の候補者。DBの実データ（c.entrants）があればそれを使う。見本のときだけ下で合成。
  function entrantsOf(c){
    if (Array.isArray(c.entrants)) return c.entrants;
    const seed  = seedOf(c.id);
    const total = c.need + 4;
    const arr = [];
    for (let i = 0; i < total; i++){
      arr.push({
        no:   i + 1,
        name: NAME_POOL[(seed + i) % NAME_POOL.length],
        pos:  POS_PATTERN[i] || 'FC',
        cal:  ((seed + i) % 3 === 0)   // 終日〇 / この案件のみ
      });
    }
    return arr;
  }

  // 日付
  function caseDate(off){ return window.ECS_caseDate(off); }
  function dateLabel(off){ const d = caseDate(off); return (d.getMonth()+1) + '/' + d.getDate() + '（' + DOW[d.getDay()] + '）'; }

  function dtClass(dt){
    if (dt === '予備日') return 'yobi';
    if (dt === 'リハ') return 'reha';
    if (dt === '前日設営') return 'setup';
    return '';
  }

  // ID→案件（本番日の参照などに使う。アーカイブ含む全件から探す）
  // DBの案件リスト（ECS_PICKUP_CASES）があればそれを使い、空なら見本cases.jsにフォールバック。
  const ALL = (window.ECS_PICKUP_CASES && window.ECS_PICKUP_CASES.length)
    ? window.ECS_PICKUP_CASES : (window.ECS_CASES || []);
  function getCase(id){ return ALL.find(c => c.id === id) || null; }

  // 子案件（前日設営・リハ・予備日）の「本番はいつか」
  // ※複数日（連続日程の本番）は下の seriesBadge で「◯日目/全◯日」を表示する
  function parentInfo(c){
    if (!c.parentId) return '';
    if (c.dayType === '本番') return '';   // 複数日は seriesBadge に任せる
    const p = getCase(c.parentId);
    if (!p) return '';
    return '↳ 本番：' + dateLabel(p.off) + '（' + (p.content || p.name) + '）';
  }

  // 複数日（連続日程）案件：同じグループ(root)で dayType=本番 の日を並べ、何日目かを返す
  function rootOf(c){ return c.parentId || c.id; }
  function seriesInfo(c){
    if (c.dayType !== '本番') return null;
    const root = rootOf(c);
    const sibs = CASES.filter(x => x.dayType === '本番' && rootOf(x) === root).sort((a,b) => a.off - b.off);
    if (sibs.length <= 1) return null;   // 単発（1日だけ）はバッジ無し
    const idx = sibs.findIndex(x => x.id === c.id);
    return { idx: idx + 1, total: sibs.length };
  }
  function seriesBadge(c){
    const s = seriesInfo(c);
    return s ? `<span class="e-badge day-badge">${s.idx}日目/全${s.total}日</span>` : '';
  }

  // ピックアップ対象＝過去（アーカイブ）と下書きを除いた案件、開催日の早い順
  const CASES = ALL.filter(c => !c.archived && !c.draft).slice().sort((a,b) => a.off - b.off);

  // ===== アサイン状態（モック編集用） =====
  // rosters[caseId] = その案件のメンバー名のSet（初期＝先頭 filled 名）
  const rosters = {};
  function ensureRoster(c){
    if (!rosters[c.id]){
      // DBの案件なら現メンバー（assignments由来 c.members）を初期値に。見本なら先頭 filled 名。
      const names = Array.isArray(c.members)
        ? c.members
        : entrantsOf(c).slice(0, c.filled).map(e => e.name);
      rosters[c.id] = new Set(names);
    }
    return rosters[c.id];
  }
  function memberCount(c){ return ensureRoster(c).size; }
  function isMember(c, name){ return ensureRoster(c).has(name); }
  function toggleMember(id, name){
    const c = getCase(id); if (!c) return;
    const r = ensureRoster(c);
    if (r.has(name)) r.delete(name); else r.add(name);
    renderList();
    renderPicked();
  }

  // 選択状態
  const selected = new Set();

  // ===== ① 案件を選ぶリスト =====
  // off → 'yyyy-mm-dd'（日付絞り込みの比較用）
  function isoOf(off){
    const d = caseDate(off);
    return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
  }

  function filteredList(){
    const kw = (document.getElementById('fKeyword').value || '').trim();
    const ff = document.getElementById('fFormat').value;
    const fd = document.getElementById('fDayType').value;
    const from = document.getElementById('fFrom').value;
    const to   = document.getElementById('fTo').value;
    return CASES.filter(c => {
      if (kw && !((c.name||'').includes(kw) || (c.client||'').includes(kw))) return false;
      if (ff && c.fmt !== ff) return false;
      if (fd && c.dayType !== fd) return false;
      if (from || to){
        const ds = isoOf(c.off);
        if (from && ds < from) return false;
        if (to   && ds > to)   return false;
      }
      return true;
    });
  }

  function renderList(){
    const list = filteredList();
    const box  = document.getElementById('pickList');
    if (!list.length){ box.innerHTML = '<div class="empty-note">条件に合う案件がありません。</div>'; updateSel(); return; }
    const allOn = list.every(c => selected.has(c.id));
    const headRow = `
      <label class="pk-row pk-row-head">
        <input type="checkbox" ${allOn ? 'checked' : ''} onchange="toggleAll(this.checked)">
        <span class="pk-row-body">表示中をすべて選択 / 外す（${list.length}件）</span>
      </label>`;
    box.innerHTML = headRow + list.map(c => {
      const on     = selected.has(c.id);
      const filled = memberCount(c);
      const short  = filled < c.need;
      const dt     = c.dayType && c.dayType !== '本番'
        ? `<span class="e-badge dt-badge ${dtClass(c.dayType)}">${c.dayType}</span>` : '';
      const parent = parentInfo(c);
      const guests = (c.guests && c.guests !== '—') ? `<span>参加者 ${c.guests}名</span>` : '';
      const teams  = (c.teams  && c.teams  !== '—') ? `<span>${c.teams}チーム</span>` : '';
      return `
        <label class="pk-row ${on ? 'on' : ''}">
          <input type="checkbox" ${on ? 'checked' : ''} onchange="toggle('${c.id}')">
          <span class="pk-row-body">
            <span class="pk-row-line1">
              <span class="pk-row-date">${dateLabel(c.off)}</span>
              <span class="pk-row-name">${c.content || c.name}</span>
              <span class="pk-row-client">${c.client || ''}</span>
              ${dt}${seriesBadge(c)}
              <span class="pk-row-prog ${short ? 'short' : ''}">アサイン <b>${filled}</b>/${c.need}</span>
            </span>
            ${parent ? `<span class="pk-row-parent">${parent}</span>` : ''}
            <span class="pk-row-line2">
              <span>⏱ ${c.meet || '—'}〜${c.leave || '—'}</span>
              ${guests}${teams}
              <span>📍 ${c.place || '—'}</span>
            </span>
          </span>
        </label>`;
    }).join('');
    updateSel();
  }

  function toggle(id){
    if (selected.has(id)) selected.delete(id); else selected.add(id);
    renderList();
    renderPicked();
  }

  function clearSel(){
    selected.clear();
    renderList();
    renderPicked();
  }

  // 表示中（絞り込み後）の案件をまとめてON/OFF
  function toggleAll(on){
    filteredList().forEach(c => { if (on) selected.add(c.id); else selected.delete(c.id); });
    renderList();
    renderPicked();
  }

  // 関連（本番＋予備日＋リハ＋前日設営）をまとめて選ぶ
  function selectRelated(){
    if (!selected.size){ alert('先に案件を1つ以上選んでください。'); return; }
    const roots = new Set();
    CASES.forEach(c => { if (selected.has(c.id)) roots.add(rootOf(c)); });
    CASES.forEach(c => { if (roots.has(rootOf(c))) selected.add(c.id); });
    renderList();
    renderPicked();
  }

  function updateSel(){ document.getElementById('selCount').textContent = selected.size; }

  // ===== ② 選んだ案件の中身 =====
  function selectedCases(){
    return CASES.filter(c => selected.has(c.id)).sort((a,b) => a.off - b.off);
  }

  // 選んだ案件のうち「メンバー（アサイン済み）が2件以上」の人＝複数の案件（複数日など）に入っている人
  // ※同じイベントの複数日では同じ人を継続して入れたい＝むしろ良い状態。色つきで目立たせる。
  function repeatMap(){
    const map = {};
    selectedCases().forEach(c => {
      ensureRoster(c).forEach(name => { (map[name] = map[name] || []).push(c); });
    });
    const rep = {};
    Object.keys(map).forEach(n => { if (map[n].length >= 2) rep[n] = map[n]; });
    return rep;
  }

  function renderPicked(){
    const sel = selectedCases();
    const sumBox = document.getElementById('summary');
    const dupBox = document.getElementById('dupAlert');
    const box    = document.getElementById('picked');
    updateSel();

    if (!sel.length){
      sumBox.innerHTML = '';
      dupBox.innerHTML = '';
      box.innerHTML = '<div class="empty-note">上のリストから案件を選ぶと、ここにメンバーと希望者が並びます。</div>';
      return;
    }

    // 集計
    let memTotal = 0, wishTotal = 0;
    sel.forEach(c => {
      const total = entrantsOf(c).length;
      const mem   = memberCount(c);
      memTotal  += mem;
      wishTotal += (total - mem);
    });
    const rep = repeatMap();
    const repNames = Object.keys(rep);

    sumBox.innerHTML = `
      <div class="sum-card"><div class="num">${sel.length}</div><div class="lbl">選択中の案件</div></div>
      <div class="sum-card"><div class="num">${memTotal}</div><div class="lbl">メンバー（アサイン済み・延べ）</div></div>
      <div class="sum-card"><div class="num">${wishTotal}</div><div class="lbl">希望者（未割当・延べ）</div></div>
      <div class="sum-card"><div class="num">${repNames.length}</div><div class="lbl">複数の案件に入っている人</div></div>`;

    dupBox.innerHTML = '';

    // 案件カード
    box.innerHTML = sel.map(c => {
      const ents   = entrantsOf(c);
      // メンバー（アサイン済み）を上に、希望者（未割当）を下に
      const members = ents.filter(e => isMember(c, e.name));
      const wishers = ents.filter(e => !isMember(c, e.name));
      const dt = c.dayType && c.dayType !== '本番'
        ? `<span class="e-badge dt-badge ${dtClass(c.dayType)}">${c.dayType}</span>` : '';
      const parent = parentInfo(c);
      const short  = members.length < c.need;

      const memRow = (e) => {
        const isMulti = !!rep[e.name];   // 複数の案件に入っている人＝色つき
        const posClass = KEY_POS.includes(e.pos) ? 'e-pos key' : 'e-pos';
        return `
          <div class="pk-mem ${isMulti ? 'multi' : ''}">
            <span class="m-name">${e.name}${isMulti ? '<span class="multi-flag">複数日</span>' : ''}</span>
            <span class="${posClass}">${e.pos}</span>
            <span class="m-spacer"></span>
            <span class="cstat done">✓ アサイン済み</span>
            <button class="pk-btn remove" onclick="toggleMember('${c.id}','${e.name}')">× 外す</button>
          </div>`;
      };

      const wishRow = (e) => {
        const posClass = KEY_POS.includes(e.pos) ? 'e-pos key' : 'e-pos';
        const avail = e.cal
          ? '<span class="cstat cal1">終日〇</span>'
          : '<span class="cstat only">この案件のみ</span>';
        return `
          <div class="pk-mem">
            <span class="m-name">${e.name}</span>
            <span class="${posClass}">${e.pos}</span>
            <span class="m-spacer"></span>
            ${avail}
            <button class="pk-btn add" onclick="toggleMember('${c.id}','${e.name}')">＋ 追加</button>
          </div>`;
      };

      const memHtml = members.length
        ? members.map(memRow).join('')
        : '<div class="muted" style="font-size:12px; padding:4px 8px;">（まだメンバーがいません）</div>';
      const wishHtml = wishers.length
        ? wishers.map(wishRow).join('')
        : '<div class="muted" style="font-size:12px; padding:4px 8px;">（希望者はいません）</div>';

      const guests = (c.guests && c.guests !== '—') ? `<span>参加者 <b>${c.guests}</b>名</span>` : '';
      const teams  = (c.teams  && c.teams  !== '—') ? `<span>チーム <b>${c.teams}</b></span>` : '';

      return `
        <div class="pk-case">
          <div class="pk-case-head">
            <span class="pk-case-date">${dateLabel(c.off)}</span>
            <span class="pk-case-name">${c.content || c.name}</span>
            <span class="pk-case-client">${c.client || ''}</span>
            ${dt}${seriesBadge(c)}
          </div>
          ${parent ? `<div class="pk-case-parent">${parent}</div>` : ''}
          <div class="pk-case-meta">
            <span>集合 <b>${c.meet || '—'}</b> / 解散 <b>${c.leave || '—'}</b></span>
            <span>会場 <b>${c.place || '—'}</b></span>
            <span>D <b>${c.dir || '—'}</b></span>
            ${guests}${teams}
          </div>
          <div class="pk-block-h">メンバー（アサイン済み） <span class="cnt ${short ? 'short' : ''}">${members.length} / 必要 ${c.need}名</span></div>
          ${memHtml}
          <div class="pk-block-h">希望者（未割当・エントリー中） <span class="cnt">${wishers.length}名</span></div>
          ${wishHtml}
        </div>`;
    }).join('');
  }

  // 初期描画
  renderList();
  renderPicked();
</script>
@endverbatim
@endpush
