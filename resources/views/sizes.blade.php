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
            + 'onchange="szPickToggle(\'' + szEsc(pid) + '\', this.checked)"> ' + szEsc(tr.dataset.name || '') + '</label>';
        }).join('') + '</div>';
      box.innerHTML = html;
      if (scroll) box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (e) { console.error('サイズ一覧の集計', e); }
  }
</script>
@endpush
