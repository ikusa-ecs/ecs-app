@extends('layouts.app')
@section('title', 'アサインする案件を選ぶ')
@section('h1', 'アサイン（案件を選ぶ）')
@php($active = 'assign_detail')

@push('head')
@verbatim
<style>
  .pick-intro {
    background: var(--panel); border: 1px solid var(--line); border-radius: 12px;
    box-shadow: var(--shadow); padding: 14px 18px; margin-bottom: 16px;
    color: var(--muted); font-size: 13px; line-height: 1.7;
  }
  .pick-intro b { color: var(--ink); }

  .pick-bar {
    display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
    background: var(--panel); border: 1px solid var(--line); border-radius: 12px;
    box-shadow: var(--shadow); padding: 12px 16px; margin-bottom: 16px;
  }
  .pick-bar label { font-size: 13px; color: var(--muted); display: inline-flex; align-items: center; gap: 6px; }
  .pick-bar select, .pick-bar input[type="date"] {
    padding: 6px 10px; border: 1px solid var(--line); border-radius: 8px; font: inherit; background: #fff;
  }
  .pick-bar .chk { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
  .pick-bar .spacer { flex: 1; }
  .pick-bar .count { font-size: 13px; color: var(--muted); }

  .pick-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 14px; }
  .pcard {
    background: var(--panel); border: 1px solid var(--line); border-radius: 12px;
    box-shadow: var(--shadow); padding: 14px 16px; display: flex; flex-direction: column; gap: 8px;
    text-decoration: none; color: inherit; transition: box-shadow .12s, transform .12s, border-color .12s;
  }
  .pcard:hover { box-shadow: 0 6px 18px rgba(0,0,0,.10); transform: translateY(-1px); border-color: var(--brand); }
  .pcard .row1 { display: flex; align-items: baseline; gap: 8px; }
  .pcard .date { font-weight: 700; font-size: 15px; color: var(--ink); }
  .pcard .dow { font-size: 12px; color: var(--muted); }
  .pcard .off { margin-left: auto; font-size: 12px; color: var(--muted); }
  .pcard .pname { font-weight: 600; font-size: 14px; }
  .pcard .client { font-size: 12px; color: var(--muted); }
  .pcard .meta { display: flex; gap: 12px; flex-wrap: wrap; font-size: 12px; color: var(--muted); }
  .pcard .meta b { color: var(--ink); font-weight: 600; }

  .fill { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; }
  .fill .bar { width: 70px; height: 7px; border-radius: 4px; background: #eee; overflow: hidden; }
  .fill .bar > i { display: block; height: 100%; background: #7bb37b; }
  .fill.short .bar > i { background: #e0a04a; }
  .fill.none .bar > i { background: #d98b8b; }
  .fill .num { font-weight: 600; color: var(--ink); }

  .pcard .go { margin-top: 4px; align-self: flex-start; font-size: 12px; color: var(--brand); font-weight: 600; }
  .badge-cat { font-size: 11px; padding: 1px 8px; border-radius: 999px; background: var(--brand-soft, #f0e9e0); color: #7a5a3a; }

  .pick-empty { padding: 40px; text-align: center; color: var(--muted); }
</style>
@endverbatim
@endpush

@section('content')
<div class="pick-intro">
  ここは <b>アサインする案件を選ぶ</b>入口です。日付でしぼり込むか、まだ人が足りていない案件を選んでください。
  カードをクリックすると、その案件の<b>本物のアサイン画面</b>（担当・巡回・役割を入力して保存できる画面）へ進みます。
</div>

<div class="pick-bar">
  <label>日付でしぼる
    <select id="pickDate"><option value="">すべての開催日</option></select>
  </label>
  <label class="chk"><input type="checkbox" id="pickShort"> 人が足りていない案件だけ</label>
  <span class="spacer"></span>
  <span class="count" id="pickCount"></span>
</div>

<div class="pick-grid" id="pickGrid"></div>
<div class="pick-empty" id="pickEmpty" style="display:none;">条件に合う案件がありません。</div>

<script>
  // 画面が使う案件データ（本物・DB由来）。下のロジックより先に置く。
  window.ECS_PICK_CASES = @json($cases);
</script>
@verbatim
<script>
  const CASES = window.ECS_PICK_CASES || [];

  // 日付プルダウンを作る（重複なし・昇順）。
  (function(){
    const sel = document.getElementById('pickDate');
    const seen = new Set();
    CASES.forEach(c => {
      if (c.date && !seen.has(c.date)) {
        seen.add(c.date);
        const o = document.createElement('option');
        o.value = c.date;
        o.textContent = c.date + '（' + c.dow + '）';
        sel.appendChild(o);
      }
    });
  })();

  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m])); }

  function fillClass(done, need){
    if (need <= 0) return { cls: '', pct: done > 0 ? 100 : 0 };
    if (done <= 0) return { cls: 'none', pct: 0 };
    if (done < need) return { cls: 'short', pct: Math.round(done / need * 100) };
    return { cls: '', pct: 100 };
  }

  function render(){
    const d = document.getElementById('pickDate').value;
    const onlyShort = document.getElementById('pickShort').checked;
    const grid = document.getElementById('pickGrid');
    const empty = document.getElementById('pickEmpty');

    let list = CASES.slice();
    if (d) list = list.filter(c => c.date === d);
    if (onlyShort) list = list.filter(c => (c.need || 0) <= 0 ? false : (c.done || 0) < c.need);

    grid.innerHTML = '';
    document.getElementById('pickCount').textContent = list.length + '件';
    empty.style.display = list.length ? 'none' : 'block';

    list.forEach(c => {
      const f = fillClass(c.done || 0, c.need || 0);
      const offTxt = c.off === 0 ? '本日' : (c.off > 0 ? 'あと' + c.off + '日' : (-c.off) + '日前');
      const a = document.createElement('a');
      a.className = 'pcard';
      a.href = '/project-assign?project=' + encodeURIComponent(c.id);
      a.innerHTML =
        '<div class="row1"><span class="date">' + esc(c.date) + '</span>' +
        '<span class="dow">(' + esc(c.dow) + ')</span>' +
        '<span class="off">' + esc(offTxt) + '</span></div>' +
        '<div class="pname">' + esc(c.name) + '</div>' +
        (c.client ? '<div class="client">' + esc(c.client) + '</div>' : '') +
        '<div class="meta">' +
          '<span class="badge-cat">' + esc(c.cat) + '</span>' +
          (c.dir ? '<span>D：<b>' + esc(c.dir) + '</b></span>' : '') +
          (c.place ? '<span>' + esc(c.place) + '</span>' : '') +
        '</div>' +
        '<div class="fill ' + f.cls + '">アサイン <span class="num">' + (c.done||0) + ' / ' + (c.need||0) + '名</span>' +
          '<span class="bar"><i style="width:' + f.pct + '%"></i></span></div>' +
        '<div class="go">この案件をアサイン →</div>';
      grid.appendChild(a);
    });
  }

  document.getElementById('pickDate').addEventListener('change', render);
  document.getElementById('pickShort').addEventListener('change', render);
  render();
</script>
@endverbatim
@endsection
