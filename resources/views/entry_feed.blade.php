@extends('layouts.app')
@section('title', 'エントリー新着')
@section('h1', 'エントリー新着（来た順）')
@php($active = 'entry_feed')

@push('head')
<style>
    .ef-intro { font-size: 12.5px; color: var(--muted, #8a7a6b); margin: 0 0 12px; line-height: 1.7; }
    .ef-filter {
      display: flex; flex-wrap: wrap; gap: 10px; align-items: center;
      background: #fff; border: 1px solid var(--line, #e6d8c8); border-radius: 10px;
      padding: 10px 12px; margin-bottom: 12px;
    }
    .ef-filter a.chip {
      text-decoration: none; font-size: 13px; font-weight: 600; color: var(--ink, #2c2018);
      background: #fff; border: 1px solid #d8c4ae; border-radius: 999px; padding: 5px 13px;
    }
    .ef-filter a.chip.on { background: var(--brand, #8a5a33); color: #fff; border-color: var(--brand, #8a5a33); }
    .ef-filter .lbl { font-size: 12px; font-weight: 700; color: var(--muted, #8a7a6b); }

    .ef-sum { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
    .ef-sum .card {
      background: #fff; border: 1px solid var(--line, #e6d8c8); border-radius: 10px; padding: 8px 14px; min-width: 120px;
    }
    .ef-sum .card .n { font-size: 20px; font-weight: 800; }
    .ef-sum .card .t { font-size: 11.5px; color: var(--muted, #8a7a6b); }

    table.ef { border-collapse: collapse; width: 100%; background: #fff; }
    table.ef th, table.ef td {
      border-bottom: 1px solid var(--line, #e6d8c8); padding: 9px 10px; text-align: left; font-size: 13px; vertical-align: top;
    }
    table.ef th { background: #faf6ee; font-size: 12px; color: var(--muted, #8a7a6b); white-space: nowrap; }
    table.ef td.when { white-space: nowrap; font-variant-numeric: tabular-nums; color: #6e5b49; }
    table.ef td.name strong { font-size: 14px; }
    table.ef tr.is-new td { background: #fffbf0; }
    .tag { display: inline-block; font-size: 10.5px; font-weight: 700; padding: 1px 8px; border-radius: 999px; white-space: nowrap; margin-left: 4px; }
    .tag.new    { background: #fde68a; color: #7a4a00; }
    .tag.extra  { background: #fde8e8; color: #b91c1c; }
    .tag.fix    { background: #e7f6ec; color: #166534; }
    .tag.tmp    { background: #eef0f2; color: #6b7280; }
    .tag.todo   { background: #fdf3e2; color: #8a5a10; }
    .tag.many   { background: #eef2ff; color: #3730a3; }
    .ef-note { color: #6e5b49; font-size: 12px; }
    .ef-empty { color: var(--muted, #8a7a6b); padding: 26px 0; text-align: center; }
    .ef-link { font-size: 12px; white-space: nowrap; }
</style>
@endpush

@section('content')
  @include('partials.office_switch')

  <p class="ef-intro">
    スタッフから届いた<b>エントリー（応募）を、来た順（新しいものが上）</b>に並べています。<br>
    追加案件を出したあとに<b>誰から手が挙がったか</b>、<b>新しく入った方がどの案件に応募してくれたか</b>を見るための画面です。<br>
    ここは<b>見るだけ</b>です。実際に入れるときは右の「アサインへ」から案件のアサイン画面へ進んでください。
  </p>

  <div class="ef-filter">
    <span class="lbl">期間</span>
    @foreach ([7 => '直近7日', 30 => '直近30日', 90 => '直近90日', 0 => 'すべて'] as $d => $label)
      <a class="chip {{ $days === $d ? 'on' : '' }}"
         href="{{ request()->fullUrlWithQuery(['days' => $d]) }}">{{ $label }}</a>
    @endforeach
    <span class="lbl" style="margin-left:8px;">絞り込み</span>
    <a class="chip {{ $onlyExtra ? 'on' : '' }}"
       href="{{ request()->fullUrlWithQuery(['extra' => $onlyExtra ? null : 1]) }}">🔥 追加案件のみ</a>
    <a class="chip {{ $onlyNew ? 'on' : '' }}"
       href="{{ request()->fullUrlWithQuery(['new' => $onlyNew ? null : 1]) }}">🌱 新人のみ</a>
  </div>

  <div class="ef-sum">
    <div class="card"><div class="n">{{ count($rows) }}</div><div class="t">エントリー件数</div></div>
    <div class="card"><div class="n">{{ $newCount }}</div><div class="t">うち新人（入社1年未満）</div></div>
    <div class="card"><div class="n">{{ $todoCount }}</div><div class="t">まだアサインしていない</div></div>
  </div>

  <div class="panel" style="padding:0; overflow-x:auto;">
    <table class="ef">
      <thead>
        <tr>
          <th>届いた日時</th>
          <th>スタッフ</th>
          <th>エントリー先の案件</th>
          <th>本人の一言</th>
          <th>状態</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($rows as $r)
          <tr class="{{ $r['isNew'] ? 'is-new' : '' }}">
            <td class="when">{{ $r['whenLabel'] }}</td>
            <td class="name">
              <strong>{{ $r['staffName'] }}</strong>
              @if ($r['isNew'])<span class="tag new" title="入社1年未満">🌱 新人</span>@endif
              @if ($r['entryCount'] >= 3)<span class="tag many" title="この期間で {{ $r['entryCount'] }} 件エントリーしています">{{ $r['entryCount'] }}件</span>@endif
              <div class="ef-note">{{ $r['staffId'] }}／{{ $r['level'] }}</div>
            </td>
            <td>
              <b>{{ $r['date'] }}（{{ $r['dow'] }}）</b> {{ $r['projectName'] }}
              @if ($r['isExtra'])<span class="tag extra">追加</span>@endif
              @unless ($r['published'])<span class="tag todo" title="スタッフ公開ボードで公開していません">未公開</span>@endunless
              <div class="ef-note">{{ $r['client'] }}@if ($r['office'])／{{ $r['office'] }}@endif</div>
            </td>
            <td class="ef-note">{{ $r['note'] !== '' ? $r['note'] : '—' }}</td>
            <td>
              @if ($r['assignStatus'] === '確定')
                <span class="tag fix">確定</span>
              @elseif ($r['assignStatus'] === '仮')
                <span class="tag tmp">仮</span>
              @else
                <span class="tag todo">未対応</span>
              @endif
            </td>
            <td class="ef-link">
              <a href="/project-assign?project={{ urlencode($r['projectId']) }}">アサインへ →</a>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="ef-empty">この条件に合うエントリーはありません。</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <p class="ef-intro" style="margin-top:12px;">
    ※ 「新人」は<b>入社日から1年未満</b>の方です（入社日が未登録の方は判定できないため付きません）。<br>
    ※ 「◯件」は、この期間にその方が出したエントリーの件数です（3件以上のときに出ます）。<br>
    ※ エントリーは本人が手を挙げた記録なので、<b>他拠点のスタッフからのエントリーも表示します</b>（案件は拠点で絞られます）。
  </p>
@endsection
