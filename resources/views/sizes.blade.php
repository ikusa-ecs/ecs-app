@extends('layouts.app')
@section('title', 'サイズ一覧')
@section('h1', 'サイズ一覧（靴・服・身長）')
@php $active = 'sizes'; @endphp

@push('head')
<style>
  /* 色は共通の変数だけで書く＝3つのテーマぜんぶで読める。形は社員別アサイン一覧と同じ（2026-11-02）。 */
  .sz-intro { font-size: 13px; color: var(--muted); line-height: 1.8; margin: 0 0 12px; }
  .sz-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
  .sz-bar input, .sz-bar select { padding: 7px 10px; border: 1px solid var(--line); border-radius: 8px; font-size: 13.5px; font-family: inherit; background: var(--panel); color: var(--ink); }
  .sz-bar input { min-width: 200px; }
  .sz-bar .cnt { font-size: 12.5px; color: var(--muted); }
  .sz-sec { margin: 18px 0 8px; font-size: 15px; font-weight: 800; color: var(--ink); }
  .sz-dept { margin: 12px 0 6px; font-size: 13px; font-weight: 700; color: var(--muted); }
  .sz-table { width: 100%; border-collapse: collapse; background: var(--panel); color: var(--ink); border: 1px solid var(--line); border-radius: 10px; overflow: hidden; }
  .sz-table th, .sz-table td { border-bottom: 1px solid var(--line); padding: 8px 10px; text-align: left; font-size: 13.5px; }
  .sz-table th { font-size: 12px; color: var(--muted); }
  .sz-table td.sz-n { width: 34%; }
  .sz-name { font-weight: 700; }
  .sz-small { font-size: 11px; color: var(--muted); margin-left: 6px; }
  .sz-none { color: var(--muted); }
  .sz-table .sz-ck { width: 28px; padding-right: 0; }
  .sz-bar button { padding: 7px 12px; border: 1px solid var(--line); border-radius: 8px; font-size: 13px; font-family: inherit; background: var(--panel); color: var(--ink); cursor: pointer; }
  .sz-sum { display: none; background: var(--panel); color: var(--ink); border: 1px solid var(--line); border-radius: 10px; padding: 10px 14px; margin-bottom: 12px; font-size: 13.5px; line-height: 1.9; }
  .sz-sum b { margin-right: 4px; }
  .sz-sum .sz-chip { display: inline-block; border: 1px solid var(--line); border-radius: 999px; padding: 0 10px; margin: 2px 4px 2px 0; }
  /* 日付から選ぶ（2026-11-04） */
  .sz-dayblk { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 10px 14px; margin-bottom: 16px; }
  .sz-crumb { font-size: 13.5px; margin: 4px 0 8px; color: var(--muted); }
  .sz-crumb a { color: var(--ink); font-weight: 700; cursor: pointer; text-decoration: underline; }
  .sz-crumb .sep { margin: 0 6px; }
  .sz-crumb .cur { color: var(--ink); font-weight: 800; }
  .sz-pj { display: inline-block; text-align: left; border: 1px solid var(--line); border-radius: 8px; padding: 6px 10px; margin: 0 6px 6px 0; background: var(--panel); color: var(--ink); font-family: inherit; font-size: 13px; cursor: pointer; }
  .sz-pj.on { outline: 2px solid var(--ink); }
  .sz-pj .sz-small { margin-left: 0; display: block; }
  .sz-dayblk .sz-sum { display: block; border: none; padding: 6px 0 0; margin: 0; }
  .sz-kari { font-size: 11px; color: var(--muted); border: 1px solid var(--line); border-radius: 4px; padding: 0 4px; margin-left: 4px; }
</style>
@endpush

@section('content')
  <p class="sz-intro">
    在籍中のスタッフと社員の<b>靴（足袋）・服・身長</b>だけを並べた一覧です（衣装の準備用）。サイズは本人がプロフィールで入れたものです。
    スタッフはサイズを登録している人だけ出ます。空の欄は「—」になります。
  </p>
  @include('partials.office_switch')

  {{-- 常勤のスタッフに見せる／見せない（社員だけに出る・押した瞬間に保存・2026-11-02 小沼さん「つど権限を付与する」）。 --}}
  @if ($fulltime !== null)
    <div class="sz-grant">
      <div class="sz-sec" style="margin-top:4px;">👀 この一覧を見せる常勤スタッフ</div>
      <p class="sz-intro" style="margin-bottom:6px;">チェックした人だけ、スタッフ画面からこの一覧を開けます。使い終わったらチェックを外してください。常勤の印はスタッフ名簿の「詳細」→「区分」で付けます。</p>
      @if (count($fulltime) === 0)
        <p class="sz-intro">常勤のスタッフがいません。</p>
      @else
        <div style="display:flex; flex-wrap:wrap; gap:6px 16px;">
          @foreach ($fulltime as $f)
            <label style="font-size:13.5px; cursor:pointer;">
              <input type="checkbox" class="sz-grant-cb" data-id="{{ $f['id'] }}" @checked($f['on']) onchange="szGrant(this)">
              {{ $f['name'] }}@if ($f['office'] !== '')<span class="sz-small">{{ $f['office'] }}</span>@endif
              <span class="sz-small sz-grant-msg"></span>
            </label>
          @endforeach
        </div>
      @endif
    </div>
  @endif
  {{-- 日付から選ぶ（2026-11-04 小沼さん要望）。日付 › 案件 と選ぶと、その案件に入っている人がポジションつきで出る。
       チェックの付け外しで、靴・服をサイズごとに数え直す（画面の中だけ・保存しない）。下の全員一覧のチェックとは別。 --}}
  <div class="sz-dayblk">
    <div class="sz-sec" style="margin-top:4px;">📅 日付から選ぶ（その日の案件に入っている人）</div>
    <div class="sz-bar" style="margin-bottom:6px;">
      <button type="button" onclick="szDayMove(-1)" aria-label="前の日">◀</button>
      <input type="date" id="szDate" onchange="szDayLoad()" style="min-width:0;">
      <button type="button" onclick="szDayMove(1)" aria-label="次の日">▶</button>
      <button type="button" onclick="szDayToday()">今日</button>
    </div>
    <div class="sz-crumb" id="szCrumb"></div>
    <div id="szDayProjects"></div>
    <div id="szDayBody"></div>
  </div>

  <div class="sz-sec">👥 全員の一覧</div>
  <div class="sz-bar">
    <input type="text" id="szSearch" placeholder="名前・ふりがなで探す" oninput="szFilter()">
    <select id="szKind" onchange="szFilter()">
      <option value="">スタッフと社員</option>
      <option value="staff">スタッフだけ</option>
      <option value="emp">社員だけ</option>
    </select>
    <span class="cnt" id="szCount">{{ count($staff) + collect($employees)->sum(fn ($r) => count($r)) }}人</span>
    <button type="button" onclick="szSum(true)">🧮 チェックした人を集計（<span id="szPickN">0</span>人）</button>
    <button type="button" onclick="szPickClear()">チェックを全部外す</button>
  </div>
  {{-- 名前で探して表に出てこないとき、サイズ未登録のスタッフかどうかを出す（2026-11-02 小沼さん）。 --}}
  <div class="sz-intro" id="szMiss" style="display:none;"></div>
  {{-- 集計の結果（2026-11-02 スタッフ要望「どのサイズが何個要るか」）。チェックするたびに数え直す。画面の中だけ＝保存しない。
       下に「選んだ人」を並べ、そこでも付け外しできる（外した人も一覧に残す＝付け直せる。「全部外す」で一覧ごと消える）。 --}}
  <div class="sz-sum" id="szSum"></div>

  {{-- スタッフ --}}
  <div class="sz-part" data-kind="staff">
    <div class="sz-sec">スタッフ（{{ count($staff) }}人）</div>
    @include('partials.sizes_table', ['rows' => $staff])
  </div>

  {{-- 社員（部署ごと・イベプラが先頭） --}}
  <div class="sz-part" data-kind="emp">
    <div class="sz-sec">社員（{{ collect($employees)->sum(fn ($r) => count($r)) }}人）</div>
    @foreach ($employees as $dept => $rows)
      <div class="sz-group">
        <div class="sz-dept">{{ $dept }}（{{ count($rows) }}人）</div>
        @include('partials.sizes_table', ['rows' => $rows])
      </div>
    @endforeach
  </div>
@endsection

@push('scripts')
<script>
  // 常勤のスタッフに見せる／見せない。押した瞬間に保存。失敗したらチェックを戻して赤字（請求書チェックと同じ作り）。
  function szGrant(cb) {
    const msg = cb.parentElement.querySelector('.sz-grant-msg');
    const on = cb.checked;
    msg.textContent = '保存中…'; msg.style.color = '';
    fetch('/sizes/grant', {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: cb.dataset.id, on: on })
    })
    .then(r => r.ok ? r.json() : Promise.reject(r.status))
    .then(() => { msg.textContent = on ? '見せています' : ''; })
    .catch(err => { cb.checked = !on; msg.textContent = '保存できませんでした（' + err + '）'; msg.style.color = '#b91c1c'; });
  }
</script>
<script>
  // 名前・ふりがなで探す／スタッフ・社員で絞る。画面の中だけで絞る（保存はしない）。
  function szFilter() {
    try {
      const q = (document.getElementById('szSearch').value || '').trim().replace(/\s+/g, '');
      const kind = document.getElementById('szKind').value;
      let n = 0;
      document.querySelectorAll('.sz-part').forEach(function (part) {
        const partOn = !kind || part.dataset.kind === kind;
        part.style.display = partOn ? '' : 'none';
        part.querySelectorAll('.sz-row').forEach(function (tr) {
          const hay = ((tr.dataset.name || '') + (tr.dataset.kana || '')).replace(/\s+/g, '');
          const show = partOn && (!q || hay.includes(q));
          tr.style.display = show ? '' : 'none';
          if (show) n++;
        });
      });
      // 名前で探したとき、1人も当たらない部署の見出しは隠す。
      document.querySelectorAll('.sz-group').forEach(function (g) {
        const any = Array.prototype.some.call(g.querySelectorAll('.sz-row'), function (tr) { return tr.style.display !== 'none'; });
        g.style.display = any ? '' : 'none';
      });
      document.getElementById('szCount').textContent = n + '人';
      szMissNote(q, kind, n);
    } catch (e) { console.error('サイズ一覧の絞り込み', e); }
  }
  // 表に出ない＝サイズ未登録のスタッフ（名前だけ）。探した名前がここにあれば「未登録」と出す。
  const SZ_UNREGISTERED = @json($unregistered);
  function szMissNote(q, kind, n) {
    const box = document.getElementById('szMiss');
    if (!q) { box.style.display = 'none'; return; }
    const hits = kind === 'emp' ? [] : SZ_UNREGISTERED.filter(function (p) {
      return ((p.name || '') + (p.kana || '')).replace(/\s+/g, '').includes(q);
    });
    let html = '';
    if (hits.length) {
      html = '⚠ <b>サイズ未登録のスタッフ：</b>' + hits.map(function (p) { return szEsc(p.name); }).join('、')
        + '（本人がプロフィールでサイズを入れると、この一覧に出ます）';
    } else if (n === 0) {
      html = '「' + szEsc(q) + '」は見つかりません（名前の違い・別の拠点・アカウントがまだ無い、のどれかです）。';
    }
    box.innerHTML = html;
    box.style.display = html ? '' : 'none';
  }
</script>
<script>
  // チェックした人の靴・服を、サイズごとに何個要るかまとめる（未登録は「未登録」で数える）。
  // 選んだ順に覚える（外した人も残す＝下の一覧から付け直せる）。正は表のチェック。
  let SZ_PICKED = [];
  function szBox(pid) { return document.querySelector('.sz-row[data-pid="' + pid + '"] .sz-pick'); }
  function szPick(cb) {
    const pid = cb.closest('tr').dataset.pid;
    if (cb.checked && SZ_PICKED.indexOf(pid) < 0) SZ_PICKED.push(pid);
    szSum(false);
  }
  // 下の「選んだ人」から付け外し → 表のチェックも同じにする。
  function szPickToggle(pid, on) {
    const cb = szBox(pid);
    if (cb) cb.checked = on;
    szSum(false);
  }
  function szPickClear() {
    document.querySelectorAll('.sz-pick:checked').forEach(function (cb) { cb.checked = false; });
    SZ_PICKED = [];
    szSum(false);
  }
  function szEsc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  // 並び順：服は SS→S→M→L→LL→3L…、靴は数の小さい順。どちらでもないものは後ろに文字順。
  const SZ_WEAR_ORDER = ['XXS', 'SS', 'XS', 'S', 'M', 'L', 'LL', 'XL', '2L', '3L', 'XXL', '2XL', '4L', '3XL', '5L', '4XL', '6L'];
  function szRank(v) {
    const u = v.toUpperCase().replace(/サイズ/g, '').trim();
    const i = SZ_WEAR_ORDER.indexOf(u);
    if (i >= 0) return [0, i, ''];
    const n = parseFloat(u.replace(/[^\d.]/g, ''));
    if (!isNaN(n)) return [1, n, u];
    return [2, 0, u];
  }
  function szTally(rows, key) {
    const m = {};
    let none = 0;
    rows.forEach(function (tr) {
      const v = (tr.dataset[key] || '').trim();
      if (v === '') { none++; return; }
      m[v] = (m[v] || 0) + 1;
    });
    const keys = Object.keys(m).sort(function (a, b) {
      const x = szRank(a), y = szRank(b);
      return x[0] - y[0] || x[1] - y[1] || (x[2] < y[2] ? -1 : x[2] > y[2] ? 1 : 0);
    });
    let html = keys.map(function (k) { return '<span class="sz-chip">' + szEsc(k) + '：<b>' + m[k] + '</b>個</span>'; }).join('');
    if (none) html += '<span class="sz-chip sz-none">未登録：' + none + '人</span>';
    return html || '<span class="sz-none">なし</span>';
  }
  // チェックするたびに数え直す（scroll＝ボタンで押したときだけ集計の場所へ動く）。
  function szSum(scroll) {
    try {
      const rows = Array.prototype.map.call(document.querySelectorAll('.sz-pick:checked'), function (cb) { return cb.closest('tr'); });
      document.getElementById('szPickN').textContent = rows.length;
      const box = document.getElementById('szSum');
      if (!SZ_PICKED.length) {
        box.style.display = scroll ? 'block' : 'none';
        box.innerHTML = '<span class="sz-none">集計したい人の左のチェックを入れてください。</span>';
        return;
      }
      box.style.display = 'block';
      let html = '<div><b>集計（' + rows.length + '人）</b></div>';
      if (rows.length) {
        html += '<div><b>👟 靴（足袋）</b>' + szTally(rows, 'shoe') + '</div>'
          + '<div><b>👕 服</b>' + szTally(rows, 'wear') + '</div>';
      }
      // 選んだ人（選んだ順）。ここでも付け外しできる。
      html += '<div style="margin-top:6px;"><b>選んだ人</b><span class="sz-small">（チェックを外すと集計から抜けます）</span></div><div>'
        + SZ_PICKED.map(function (pid) {
          const cb = szBox(pid);
          if (!cb) return '';
          const tr = cb.closest('tr');
          return '<label class="sz-chip" style="cursor:pointer;"><input type="checkbox" ' + (cb.checked ? 'checked ' : '')
            + 'onchange="szPickToggle(\'' + szEsc(pid) + '\', this.checked)"> ' + szEsc(tr.dataset.name || '')
            + '<span class="sz-small">👟' + szEsc(tr.dataset.shoe || '—') + ' 👕' + szEsc(tr.dataset.wear || '—') + '</span></label>';
        }).join('') + '</div>';
      box.innerHTML = html;
      if (scroll) box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (e) { console.error('サイズ一覧の集計', e); }
  }
</script>
<script>
  // 日付から選ぶ（2026-11-04）。日付 › 案件 › 入っている人。数えるのは szTally（下の全員一覧と同じ数え方）。
  let SZ_DAY = { date: '', projects: [], pid: '' };
  const SZ_WEEK = ['日', '月', '火', '水', '木', '金', '土'];
  function szYmd(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function szDayLabel(ymd) {
    const p = ymd.split('-').map(Number);
    const d = new Date(p[0], p[1] - 1, p[2]);
    return (p[1]) + '/' + p[2] + '（' + SZ_WEEK[d.getDay()] + '）';
  }
  function szDayToday() { document.getElementById('szDate').value = szYmd(new Date()); szDayLoad(); }
  function szDayMove(n) {
    const v = document.getElementById('szDate').value;
    if (!v) { szDayToday(); return; }
    const p = v.split('-').map(Number);
    document.getElementById('szDate').value = szYmd(new Date(p[0], p[1] - 1, p[2] + n));
    szDayLoad();
  }
  function szDayLoad() {
    const date = document.getElementById('szDate').value;
    SZ_DAY = { date: date, projects: [], pid: '' };
    document.getElementById('szDayBody').innerHTML = '';
    if (!date) { szDayRender(); return; }
    document.getElementById('szDayProjects').innerHTML = '<span class="sz-none">読み込み中…</span>';
    const office = new URLSearchParams(location.search).get('office');
    fetch('/sizes/day?date=' + encodeURIComponent(date) + (office ? '&office=' + encodeURIComponent(office) : ''), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
      .then(function (j) {
        if (document.getElementById('szDate').value !== date) return;   // 連打で古い答えが後から来たら捨てる
        SZ_DAY.projects = j.projects || [];
        if (SZ_DAY.projects.length === 1) SZ_DAY.pid = SZ_DAY.projects[0].id;   // 1件だけならそのまま開く
        szDayRender();
      })
      .catch(function (err) {
        document.getElementById('szDayProjects').innerHTML = '<span style="color:#b91c1c;">読み込めませんでした（' + szEsc(err) + '）</span>';
      });
  }
  function szDayPick(pid) { SZ_DAY.pid = pid; szDayRender(); }
  function szDayRender() {
    try {
      const crumb = document.getElementById('szCrumb');
      const list = document.getElementById('szDayProjects');
      const body = document.getElementById('szDayBody');
      if (!SZ_DAY.date) { crumb.innerHTML = ''; list.innerHTML = '<span class="sz-none">日付を選んでください。</span>'; body.innerHTML = ''; return; }
      const pj = SZ_DAY.projects.find(function (p) { return p.id === SZ_DAY.pid; });
      // パンくず：日付 › 案件
      crumb.innerHTML = (pj ? '<a onclick="szDayPick(\'\')">' + szDayLabel(SZ_DAY.date) + '</a>' : '<span class="cur">' + szDayLabel(SZ_DAY.date) + '</span>')
        + (pj ? '<span class="sep">›</span><span class="cur">' + szEsc(pj.name) + (pj.client ? '（' + szEsc(pj.client) + '）' : '') + '</span>' : '');
      if (!SZ_DAY.projects.length) { list.innerHTML = '<span class="sz-none">この日の案件はありません。</span>'; body.innerHTML = ''; return; }
      list.innerHTML = SZ_DAY.projects.map(function (p) {
        return '<button type="button" class="sz-pj' + (p.id === SZ_DAY.pid ? ' on' : '') + '" onclick="szDayPick(\'' + szEsc(p.id) + '\')">'
          + '<b>' + szEsc(p.name) + '</b>' + (p.client ? '（' + szEsc(p.client) + '）' : '')
          + '<span class="sz-small">' + (p.meet ? '集合 ' + szEsc(p.meet) + '・' : '') + p.members.length + '人</span></button>';
      }).join('');
      if (!pj) { body.innerHTML = '<span class="sz-none">案件を選ぶと、入っている人が出ます。</span>'; return; }
      if (!pj.members.length) { body.innerHTML = '<span class="sz-none">この案件にはまだ誰も入っていません。</span>'; return; }
      const cell = function (v) { return v ? szEsc(v) : '<span class="sz-none">—</span>'; };
      body.innerHTML = '<div class="sz-bar" style="margin:4px 0 6px;"><button type="button" onclick="szDayAll(true)">全部つける</button>'
        + '<button type="button" onclick="szDayAll(false)">全部外す</button></div>'
        + '<table class="sz-table"><tr><th class="sz-ck"></th><th>名前</th><th>ポジション</th><th>靴（足袋）</th><th>服</th><th>身長</th></tr>'
        + pj.members.map(function (m) {
          return '<tr class="sz-drow" data-shoe="' + szEsc(m.shoe) + '" data-wear="' + szEsc(m.wear) + '">'
            + '<td class="sz-ck"><input type="checkbox" class="sz-dpick" checked onchange="szDaySum()" aria-label="集計に入れる"></td>'
            + '<td class="sz-n"><span class="sz-name">' + szEsc(m.name) + '</span>' + (m.emp ? '<span class="sz-small">社員</span>' : '') + '</td>'
            + '<td>' + szEsc(m.pos) + (m.kari ? '<span class="sz-kari">仮</span>' : '') + '</td>'
            + '<td>' + cell(m.shoe) + '</td><td>' + cell(m.wear) + '</td><td>' + cell(m.height) + '</td></tr>';
        }).join('') + '</table><div class="sz-sum" id="szDaySum"></div>';
      szDaySum();
    } catch (e) { console.error('サイズ一覧 日付から選ぶ', e); }
  }
  function szDayAll(on) { document.querySelectorAll('.sz-dpick').forEach(function (cb) { cb.checked = on; }); szDaySum(); }
  function szDaySum() {
    try {
      const rows = Array.prototype.map.call(document.querySelectorAll('.sz-dpick:checked'), function (cb) { return cb.closest('tr'); });
      const box = document.getElementById('szDaySum');
      if (!box) return;
      box.innerHTML = '<div><b>集計（' + rows.length + '人）</b></div>'
        + (rows.length ? '<div><b>👟 靴（足袋）</b>' + szTally(rows, 'shoe') + '</div><div><b>👕 服</b>' + szTally(rows, 'wear') + '</div>'
          : '<span class="sz-none">チェックした人がいません。</span>');
    } catch (e) { console.error('サイズ一覧 日付の集計', e); }
  }
  szDayToday();
</script>
@endpush
