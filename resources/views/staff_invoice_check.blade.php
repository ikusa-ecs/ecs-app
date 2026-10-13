@extends('layouts.app')
@section('title', 'スタッフ別アサイン（請求書チェック）')
@section('h1', 'スタッフ別アサイン（請求書チェック）')
@php $active = 'staff_invoice_check'; @endphp

@push('head')
<style>
  /* 色は共通の変数だけで書く＝3つのテーマぜんぶで読める（2026-10-13） */
  .si-intro { font-size: 13px; color: var(--muted); line-height: 1.8; margin: 0 0 12px; }
  .si-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
  .si-bar .ym { font-size: 16px; font-weight: 800; color: var(--ink); min-width: 110px; text-align: center; }
  .si-bar a.nav { text-decoration: none; border: 1px solid var(--line); border-radius: 8px; padding: 4px 10px; color: var(--ink); background: var(--panel); }
  .si-bar .cnt { font-size: 12.5px; color: var(--muted); }
  .si-bar label { font-size: 12.5px; color: var(--ink); display: flex; align-items: center; gap: 4px; cursor: pointer; }
  .si-bar .sp { flex: 1; }
  .si-table { width: 100%; border-collapse: collapse; background: var(--panel); color: var(--ink); border: 1px solid var(--line); border-radius: 10px; overflow: hidden; }
  .si-table th, .si-table td { border-bottom: 1px solid var(--line); padding: 8px 10px; text-align: left; vertical-align: top; font-size: 13px; }
  .si-table th { background: var(--surface-2, var(--panel)); font-size: 12px; color: var(--muted); }
  .si-table td.num { text-align: right; white-space: nowrap; }
  .si-table td.box { text-align: center; white-space: nowrap; width: 110px; }
  .si-table input[type=checkbox] { width: 20px; height: 20px; cursor: pointer; }
  .si-who { display: block; font-size: 10.5px; color: var(--muted); margin-top: 2px; }
  .si-row.done td { opacity: .55; }
  .si-name { font-weight: 700; }
  .si-detail { margin-top: 6px; }
  .si-detail summary { cursor: pointer; font-size: 12px; color: var(--muted); }
  .si-cases { margin: 6px 0 0; padding: 0; list-style: none; font-size: 12.5px; line-height: 1.8; }
  .si-cases .st { font-size: 11px; color: var(--muted); }
  .si-cases .cx { font-size: 11px; color: #b91c1c; font-weight: 700; }
  .si-copy { font-size: 11.5px; border: 1px solid var(--line); background: var(--panel); color: var(--ink); border-radius: 6px; padding: 2px 8px; cursor: pointer; margin-left: 6px; }
  .si-saving { font-size: 10.5px; color: var(--muted); }
  .si-ng { font-size: 10.5px; color: #b91c1c; font-weight: 700; }
  @media (max-width: 720px) {
    .si-table td.box { width: auto; }
    .si-table th.hide-sp, .si-table td.hide-sp { display: none; }
  }
</style>
@endpush

@section('content')

<p class="si-intro">
  月を選ぶと、その月にアサイン（キャンセル以外）があるスタッフが並びます。スタッフから請求書が届いたら<b>📨</b>、中身を明細と見比べて合っていたら<b>✅</b>にチェックしてください。<b>押した瞬間に保存</b>されます（誰がいつ付けたかも残ります）。<br>
  名前の行の「明細」を開くと、その人の日付・案件・役割が並びます。※ アサイン表の取込で名簿と名前が合わなかった人は、ECSにアサインが入っていないのでここにも出ません。
</p>

@include('partials.office_switch')

<div class="si-bar">
  <a class="nav" href="{{ $prevUrl }}">‹ 前の月</a>
  <span class="ym">{{ $month->format('Y年n月') }}</span>
  <a class="nav" href="{{ $nextUrl }}">次の月 ›</a>
  <span class="cnt">{{ $counts['all'] }}人／📨 {{ $counts['received'] }}人／✅ {{ $counts['checked'] }}人</span>
  <span class="sp"></span>
  <label><input type="checkbox" id="siOnlyOpen" onchange="siFilter()"> ✅がまだの人だけ</label>
</div>

@if (! $list)
  <div class="panel"><p class="si-intro" style="margin:0;">この月にアサインのあるスタッフはいません。</p></div>
@else
<table class="si-table">
  <tr>
    <th>スタッフ</th>
    <th class="hide-sp">件数</th>
    <th style="text-align:center;">📨 請求書</th>
    <th style="text-align:center;">✅ チェック済</th>
  </tr>
  @foreach ($list as $r)
    <tr class="si-row {{ $r['checked'] ? 'done' : '' }}" data-id="{{ $r['id'] }}">
      <td>
        <span class="si-name">{{ $r['name'] }}</span>
        @if ($r['office'] !== '')<span class="si-who" style="display:inline; margin-left:6px;">{{ $r['office'] }}</span>@endif
        <details class="si-detail">
          <summary>明細 {{ count($r['cases']) }}件</summary>
          <textarea class="si-copytext" readonly style="position:absolute; left:-9999px; width:1px; height:1px;">{{ $r['copyText'] }}</textarea>
          <button type="button" class="si-copy" onclick="siCopy(this)">📋 コピー</button><span class="si-saving"></span>
          <ul class="si-cases">
            @foreach ($r['cases'] as $c)
              <li>{{ $c['date'] }}　<a href="/project-assign?project={{ urlencode($c['projectId']) }}">{{ $c['name'] }}</a>{{ $c['client'] !== '' ? '／'.$c['client'] : '' }}{{ $c['dayType'] !== '' && $c['dayType'] !== '本番' ? '［'.$c['dayType'].'］' : '' }}　{{ $c['role'] }}
                @if ($c['status'] !== '確定')<span class="st">（{{ $c['status'] }}）</span>@endif
                @if ($c['cancelled'])<span class="cx">案件中止</span>@endif
              </li>
            @endforeach
          </ul>
        </details>
      </td>
      <td class="num hide-sp">{{ count($r['cases']) }}件<span class="si-who">うち確定 {{ $r['confirmed'] }}</span></td>
      @foreach (['received' => '請求書が届いた', 'checked' => '明細と見比べて合っていた'] as $field => $title)
        <td class="box">
          <input type="checkbox" title="{{ $title }}" data-field="{{ $field }}" @checked($r[$field]) onchange="siMark(this)">
          <span class="si-who">{{ $r[$field] ? $r[$field]['at'].' '.$r[$field]['by'] : '' }}</span>
        </td>
      @endforeach
    </tr>
  @endforeach
</table>
@endif

<script>
  // 押した瞬間に保存（2026-10-13）。⚠ 失敗をだまって流さない＝チェックを元に戻して赤字で知らせる。
  var SI_YM = @json($ym);
  var SI_CSRF = @json(csrf_token());
  var siBusy = 0;
  function siMark(box) {
    var tr = box.closest('tr');
    var who = box.parentNode.querySelector('.si-who');
    var on = box.checked;
    who.textContent = '保存中…'; who.className = 'si-who';
    siBusy++;
    fetch('/staff-invoice-check/mark', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': SI_CSRF },
      body: JSON.stringify({ staff_id: tr.dataset.id, ym: SI_YM, field: box.dataset.field, on: on })
    })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('通信エラー ' + r.status)); })
      .then(function (j) {
        if (!j || !j.ok) throw new Error('保存できませんでした');
        who.textContent = on ? (j.at + ' ' + j.by) : '';
        if (box.dataset.field === 'checked') tr.classList.toggle('done', on);
        siFilter();
      })
      .catch(function (e) {
        box.checked = !on;   // 保存できていないので元に戻す
        who.textContent = '⚠ 保存できませんでした。もう一度押してください';
        who.className = 'si-who si-ng';
      })
      .then(function () { siBusy--; });
  }
  function siFilter() {
    var only = document.getElementById('siOnlyOpen');
    document.querySelectorAll('tr.si-row').forEach(function (tr) {
      tr.style.display = (only && only.checked && tr.classList.contains('done')) ? 'none' : '';
    });
  }
  function siCopy(btn) {
    var box = btn.parentNode.querySelector('.si-copytext');
    var mark = btn.nextElementSibling;
    var done = function () { if (mark) mark.textContent = ' ✓ コピーしました'; };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(box.value).then(done, function () { box.select(); document.execCommand('copy'); done(); });
    } else {
      box.select(); document.execCommand('copy'); done();
    }
  }
  // 保存の途中で閉じようとしたら引き止める。
  window.addEventListener('beforeunload', function (ev) { if (siBusy > 0) { ev.preventDefault(); ev.returnValue = ''; } });
</script>

@endsection
