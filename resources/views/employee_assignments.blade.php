@extends('layouts.app')
@section('title', '社員別アサイン一覧')
@section('h1', '社員別アサイン一覧')
@php $active = 'employee_assignments'; @endphp

@push('head')
<style>
  /* 色は共通の変数だけで書く＝3つのテーマぜんぶで読める（2026-10-13）。形は請求書チェックと同じ。 */
  .ea-intro { font-size: 13px; color: var(--muted); line-height: 1.8; margin: 0 0 12px; }
  .ea-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
  .ea-bar .ym { font-size: 16px; font-weight: 800; color: var(--ink); min-width: 110px; text-align: center; }
  .ea-bar a.nav { text-decoration: none; border: 1px solid var(--line); border-radius: 8px; padding: 4px 10px; color: var(--ink); background: var(--panel); }
  .ea-bar .cnt { font-size: 12.5px; color: var(--muted); }
  .ea-table { width: 100%; border-collapse: collapse; background: var(--panel); color: var(--ink); border: 1px solid var(--line); border-radius: 10px; overflow: hidden; }
  .ea-table th, .ea-table td { border-bottom: 1px solid var(--line); padding: 8px 10px; text-align: left; vertical-align: top; font-size: 13px; }
  .ea-table th { font-size: 12px; color: var(--muted); }
  .ea-table td.num { text-align: right; white-space: nowrap; }
  .ea-name { font-weight: 700; }
  .ea-small { font-size: 11px; color: var(--muted); margin-left: 6px; }
  .ea-detail summary { cursor: pointer; font-size: 12px; color: var(--muted); margin-top: 4px; }
  .ea-cases { margin: 6px 0 0; padding: 0; list-style: none; font-size: 12.5px; line-height: 1.8; }
  .ea-cases li { margin-bottom: 4px; }
  .ea-cases .st { font-size: 11px; color: var(--muted); }
  .ea-cases .cx { font-size: 11px; color: #b91c1c; font-weight: 700; }
  .ea-sub { font-size: 11.5px; color: var(--muted); line-height: 1.6; padding-left: 1em; display: flex; flex-wrap: wrap; gap: 4px 12px; }
  .ea-fmt { font-weight: 700; color: var(--ink); border: 1px solid var(--line); border-radius: 999px; padding: 0 7px; }
  .ea-copy { font-size: 11.5px; border: 1px solid var(--line); background: var(--panel); color: var(--ink); border-radius: 6px; padding: 2px 8px; cursor: pointer; margin-left: 6px; }
  .ea-none { font-size: 12px; color: var(--muted); margin-top: 10px; line-height: 1.8; }
  @media (max-width: 720px) { .ea-table th.hide-sp, .ea-table td.hide-sp { display: none; } }
</style>
@endpush

@section('content')

<p class="ea-intro">
  月を選ぶと、その月にアサイン（キャンセル以外）がある社員が社歴順に並びます。「明細」を開くと日付・案件・役割・集合／解散・会場住所が出ます。見るだけの画面です（アサインを直すのは今までどおりD決め・日別ボードなど）。
</p>

@include('partials.office_switch')

<div class="ea-bar">
  <a class="nav" href="{{ $prevUrl }}">‹ 前の月</a>
  <span class="ym">{{ $month->format('Y年n月') }}</span>
  <a class="nav" href="{{ $nextUrl }}">次の月 ›</a>
  <span class="cnt">{{ count($list) }}人・のべ {{ $total }}件</span>
</div>

@if (! $list)
  <div class="panel"><p class="ea-intro" style="margin:0;">この月にアサインのある社員はいません。</p></div>
@else
<table class="ea-table">
  <tr><th>社員</th><th class="hide-sp">件数</th><th class="hide-sp">うちD／SD</th></tr>
  @foreach ($list as $r)
    <tr>
      <td>
        <span class="ea-name">{{ $r['name'] }}</span>
        <span class="ea-small">{{ $r['dept'] }}{{ $r['dept'] !== '' && $r['office'] !== '' ? '・' : '' }}{{ $r['office'] }}</span>
        <details class="ea-detail">
          <summary>明細 {{ $r['liveCount'] }}件{{ $r['tentative'] ? '（うち仮 '.$r['tentative'].'）' : '' }}{{ count($r['cases']) - $r['liveCount'] ? '・中止 '.(count($r['cases']) - $r['liveCount']) : '' }}</summary>
          <textarea class="ea-copytext" readonly style="position:absolute; left:-9999px; width:1px; height:1px;">{{ $r['copyText'] }}</textarea>
          <button type="button" class="ea-copy" onclick="eaCopy(this)">📋 コピー</button><span class="ea-small"></span>
          <ul class="ea-cases">
            @foreach ($r['cases'] as $c)
              <li>{{ $c['date'] }}　<a href="/project-assign?project={{ urlencode($c['projectId']) }}">{{ $c['name'] }}</a>{{ $c['client'] !== '' ? '／'.$c['client'] : '' }}{{ $c['dayType'] !== '' && $c['dayType'] !== '本番' ? '［'.$c['dayType'].'］' : '' }}　<b>{{ $c['role'] }}</b>
                @if ($c['status'] !== '確定')<span class="st">（{{ $c['status'] }}）</span>@endif
                @if ($c['cancelled'])<span class="cx">案件中止</span>@endif
                @if ($c['format'] !== '' || $c['meet'] !== '' || $c['leave'] !== '' || $c['place'] !== '')
                  <div class="ea-sub">
                    @if ($c['format'] !== '')<span class="ea-fmt">{{ $c['format'] }}</span>@endif
                    @if ($c['meet'] !== '' || $c['leave'] !== '')<span>{{ $c['meet'] !== '' ? '集合 '.$c['meet'] : '' }}{{ $c['meet'] !== '' && $c['leave'] !== '' ? '・' : '' }}{{ $c['leave'] !== '' ? '解散 '.$c['leave'] : '' }}</span>@endif
                    @if ($c['place'] !== '')<span>📍 {{ $c['place'] }}</span>@endif
                  </div>
                @endif
              </li>
            @endforeach
          </ul>
        </details>
      </td>
      <td class="num hide-sp">{{ $r['liveCount'] }}件</td>
      <td class="num hide-sp">D {{ $r['d'] }}／SD {{ $r['sd'] }}</td>
    </tr>
  @endforeach
</table>
@endif

@if ($none)
  <p class="ea-none">この月にアサインの無い社員：{{ implode('・', $none) }}</p>
@endif

<script>
  function eaCopy(btn) {
    var box = btn.parentNode.querySelector('.ea-copytext');
    var mark = btn.nextElementSibling;
    var done = function () { if (mark) mark.textContent = ' ✓ コピーしました'; };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(box.value).then(done, function () { box.select(); document.execCommand('copy'); done(); });
    } else {
      box.select(); document.execCommand('copy'); done();
    }
  }
</script>

@endsection
