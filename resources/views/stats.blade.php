@extends('layouts.app')
@section('title', '集計ダッシュボード')
@section('h1', '集計ダッシュボード')
@php $active = 'stats'; @endphp

@push('head')
<style>
  /* ===== 集計ダッシュボード（/stats）専用スタイル ===== */

  /* 期間の粒度を選ぶタブ（案件一覧の下書き/アーカイブと同じ見た目のシート切替） */
  .st-tabs { display: flex; gap: 6px; margin: 0; }
  .st-tab {
    padding: 9px 18px; border: 1px solid var(--line); border-bottom: none; border-radius: 8px 8px 0 0;
    background: #fff; color: var(--muted); font-size: 13.5px; font-weight: 600; cursor: pointer; text-decoration: none;
  }
  .st-tab:hover { background: #f3ece0; text-decoration: none; }
  .st-tab.active { background: var(--brand); border-color: var(--brand); color: #fff; }

  /* 表示範囲（全拠点／各拠点）を選ぶボタン列 */
  .st-scope { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; margin: 0 0 14px; }
  .st-scope .sc-label { font-size: 12.5px; color: #7a6a58; font-weight: 700; margin-right: 2px; }
  .st-scope a {
    padding: 6px 15px; border: 1px solid var(--line); border-radius: 999px;
    background: #fff; color: var(--muted); font-size: 12.5px; font-weight: 700; text-decoration: none;
  }
  .st-scope a:hover { background: #f3ece0; }
  .st-scope a.active { background: var(--brand); border-color: var(--brand); color: #fff; }
  .st-scope a.all { border-style: dashed; }

  /* 上部の操作バー（選んだ粒度の中で期間を選ぶ）。タブの下にくっつける。 */
  .st-controls {
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    background: var(--panel); border: 1px solid var(--line); border-radius: 0 12px 12px 12px;
    padding: 10px 14px; margin-bottom: 14px;
  }
  .st-controls select {
    padding: 7px 10px; border: 1px solid var(--line); border-radius: 8px;
    font-size: 14px; font-family: inherit; background: #fff; color: var(--ink); font-weight: 700;
  }
  .st-controls .lbl { font-size: 12.5px; color: #7a6a58; }
  .st-controls .spacer { flex: 1; }
  .st-controls .now { font-size: 15px; font-weight: 800; color: var(--ink); }

  /* KPIカード（大きな数字） */
  .st-kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px; }
  .st-kpi {
    background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px;
    box-shadow: 0 1px 2px rgba(60,45,30,.06);
  }
  .st-kpi .k-label { font-size: 12px; color: #8a7a66; font-weight: 700; }
  .st-kpi .k-num { font-size: 30px; font-weight: 800; color: var(--ink); line-height: 1.1; margin-top: 4px; }
  .st-kpi .k-num small { font-size: 14px; font-weight: 700; color: #a89680; margin-left: 3px; }
  .st-kpi.accent-real   { border-top: 3px solid #2f6fb3; }
  .st-kpi.accent-online { border-top: 3px solid #1f9d74; }
  .st-kpi.accent-total  { border-top: 3px solid #d9822b; }
  .st-kpi.accent-att    { border-top: 3px solid #7a52c9; }

  /* パネル（見出し付きの箱） */
  .st-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 14px; margin-bottom: 16px; }
  .st-panel {
    background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px;
    box-shadow: 0 1px 2px rgba(60,45,30,.06);
  }
  .st-panel h3 { font-size: 14px; font-weight: 800; color: var(--ink); margin: 0 0 12px; }
  .st-panel .empty { color: #a08a73; font-size: 13px; padding: 10px 0; }

  /* 一覧（拠点別・社員別）＝名前＋数字だけ（棒グラフは使わない・baba 2026-07-24） */
  .st-row { display: flex; align-items: baseline; justify-content: space-between; gap: 10px;
    padding: 6px 2px; border-bottom: 1px dotted var(--line); font-size: 13px; }
  .st-row:last-child { border-bottom: none; }
  .st-row .r-name { color: var(--ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .st-row .r-name .sub { font-size: 11px; color: #a89680; margin-left: 6px; }
  .st-row .r-num { font-weight: 800; color: var(--ink); white-space: nowrap; }
  .st-row .r-num small { font-size: 11px; font-weight: 700; color: #a89680; margin-left: 2px; }
  .st-row.zero .r-name, .st-row.zero .r-num { color: #c4b8a6; }

  /* 社員別を拠点ごとの「カード」に分ける（baba 2026-07-24） */
  .st-office-title { font-size: 14px; font-weight: 800; color: var(--ink); margin: 0 0 10px; }
  .st-office-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 14px; margin-bottom: 8px; }
  .st-office-cards .st-panel h3 { border-bottom: 2px solid var(--line); padding-bottom: 6px; }

  /* CSV出力ボタン */
  .st-csv {
    padding: 7px 14px; border: 1px solid var(--brand); border-radius: 8px;
    background: var(--brand); color: #fff; font-size: 12.5px; font-weight: 700;
    text-decoration: none; white-space: nowrap;
  }
  .st-csv:hover { opacity: .9; text-decoration: none; }

  /* 社員別のディレクター内訳テーブル（社員・ディレクター集計と同じ列） */
  .st-emp-block { margin-bottom: 14px; }
  .st-emp-scroll { overflow-x: auto; }
  .st-emp-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
  .st-emp-table th, .st-emp-table td {
    padding: 5px 8px; text-align: right; border-bottom: 1px solid var(--line);
    white-space: nowrap; font-variant-numeric: tabular-nums;
  }
  .st-emp-table th { color: #8a7a66; font-weight: 700; font-size: 11px; }
  .st-emp-table th.l, .st-emp-table td.l { text-align: left; }
  .st-emp-table td.l { color: var(--ink); font-weight: 600; }
  .st-emp-table td.l .sub { font-size: 10.5px; color: #a89680; font-weight: 400; margin-left: 5px; }
  .st-emp-table tbody tr:hover { background: #faf6f0; }

  /* 部署別の合計カード */
  .st-depts { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
  .st-dept { border: 1px solid var(--line); border-radius: 10px; padding: 12px; text-align: center; }
  .st-dept.plan     { border-top: 3px solid #d97706; }
  .st-dept.sales    { border-top: 3px solid #3b5ba5; }
  .st-dept.creative { border-top: 3px solid #2e9e6b; }
  .st-dept .d-name { font-size: 12.5px; font-weight: 800; color: var(--ink); }
  .st-dept .d-num { font-size: 26px; font-weight: 800; color: var(--ink); line-height: 1.15; margin-top: 2px; }
  .st-dept .d-sub { font-size: 11px; color: #a89680; }

  /* メンバー別ランキング（縦スクロール） */
  .st-members { max-height: 460px; overflow: auto; padding-right: 4px; }
  .st-note { font-size: 11.5px; color: #a08a73; margin-top: 6px; }

  @media (max-width: 900px) {
    .st-kpis { grid-template-columns: repeat(2, 1fr); }
    .st-grid { grid-template-columns: 1fr; }
  }
  /* 数えなかった案件の注記（先-2）。既存の .st-note とは別のクラスにする（見た目を変えないため） */
  .st-excluded { font-size: 12.5px; color: var(--muted); line-height: 1.8; background: #faf7f1;
                 border: 1px solid var(--line); border-radius: 8px; padding: 10px 12px; margin: 0 0 16px; }
  .st-excluded-list { list-style: none; margin: -10px 0 16px; padding: 0 12px 10px; background: #faf7f1;
                      border: 1px solid var(--line); border-top: none; border-radius: 0 0 8px 8px; font-size: 12.5px; }
  .st-excluded-list li { padding: 3px 0; border-top: 1px dashed var(--line); color: var(--ink); }
  .st-excluded-list .why { color: var(--muted); font-size: 11.5px; }
</style>
@endpush

@section('content')

{{-- 期間の粒度をタブ（シート）で選ぶ。案件一覧の下書き/アーカイブと同じ切替方式。拠点(office)は引き継ぐ。 --}}
<div class="st-tabs">
  <a class="st-tab {{ $span === 'month' ? 'active' : '' }}" href="/stats?span=month&office={{ urlencode($scopeOffice) }}">月単位</a>
  <a class="st-tab {{ $span === 'quarter' ? 'active' : '' }}" href="/stats?span=quarter&office={{ urlencode($scopeOffice) }}">四半期</a>
  <a class="st-tab {{ $span === 'year' ? 'active' : '' }}" href="/stats?span=year&office={{ urlencode($scopeOffice) }}">年単位</a>
</div>

{{-- 選んだ粒度の中で、どの期間かを選ぶ。選ぶとGETで開き直す（拠点も引き継ぐ）。 --}}
<form method="GET" action="/stats" class="st-controls" id="stForm">
  <input type="hidden" name="span" value="{{ $span }}">
  <input type="hidden" name="office" value="{{ $scopeOffice }}">
  <span class="lbl">
    @switch($span) @case('quarter') 四半期 @break @case('year') 年 @break @default 月 @endswitch
    を選ぶ
  </span>
  <select name="period" onchange="document.getElementById('stForm').submit()">
    @forelse ($spanOptions as $o)
      <option value="{{ $o['value'] }}" @selected($o['value'] === $selected)>{{ $o['label'] }}</option>
    @empty
      <option value="">案件なし</option>
    @endforelse
  </select>

  <span class="spacer"></span>
  <span class="now">{{ $selectedLabel !== '' ? $selectedLabel : '—' }}・{{ $scopeOffice !== '' ? $scopeOffice : '全拠点' }} の集計</span>
  <a class="st-csv" href="/stats/export.csv?span={{ $span }}&period={{ urlencode($selected) }}&office={{ urlencode($scopeOffice) }}">⬇ CSVで出力</a>
</form>

{{-- 表示範囲＝全拠点／各拠点。選ぶと下の集計すべてがその拠点に切り替わる（baba 2026-07-27）。 --}}
<div class="st-scope">
  <span class="sc-label">表示範囲</span>
  <a class="all {{ $scopeOffice === '' ? 'active' : '' }}" href="/stats?span={{ $span }}&period={{ urlencode($selected) }}&office=">全拠点</a>
  @foreach ($offices as $o)
    <a class="{{ $scopeOffice === $o ? 'active' : '' }}" href="/stats?span={{ $span }}&period={{ urlencode($selected) }}&office={{ urlencode($o) }}">{{ $o }}</a>
  @endforeach
</div>

{{-- KPI：イベント数（合計・リアル・オンライン）と のべ出勤数 --}}
<div class="st-kpis">
  <div class="st-kpi accent-total">
    <div class="k-label">イベント数（合計）</div>
    <div class="k-num">{{ $totalEvents }}<small>件</small></div>
  </div>
  <div class="st-kpi accent-real">
    <div class="k-label">リアル</div>
    <div class="k-num">{{ $realEvents }}<small>件</small></div>
  </div>
  <div class="st-kpi accent-online">
    <div class="k-label">オンライン</div>
    <div class="k-num">{{ $onlineEvents }}<small>件</small></div>
  </div>
  <div class="st-kpi accent-att">
    <div class="k-label">のべ出勤数（全員）</div>
    <div class="k-num">{{ $totalAttendance }}<small>回</small></div>
  </div>
</div>

{{-- 数えなかった案件の注記（先-2）。社内の数え方＝体験会・EXPOはイベント数に入れない。
     「案件はあるのに件数が少ない」理由がこの画面で分かるようにする。 --}}
@if ($excludedCount > 0)
  <p class="st-excluded">
    この期間には、<b>イベント数に数えていない案件が {{ $excludedCount }} 件</b>あります（内訳：@foreach ($excludedReasons as $reason => $n){{ $reason }} {{ $n }}件@if (! $loop->last) ／ @endif @endforeach）。<br>
    社内の数え方にあわせて<b>体験会・EXPO は数えません</b>。案件ごとに変えたいときは、案件登録の画面で「イベント数に数える」を <b>数える／数えない</b> に切り替えてください。
  </p>
  <ul class="st-excluded-list">
    @foreach ($excludedList as $x)
      <li>{{ $x['date'] }}　{{ $x['name'] }}　<span class="why">{{ $x['why'] }}</span></li>
    @endforeach
    @if ($excludedCount > $excludedList->count())
      <li>…ほか {{ $excludedCount - $excludedList->count() }} 件</li>
    @endif
  </ul>
@endif

<div class="st-grid">
  {{-- 拠点（事務所）別イベント数。全拠点モードのみ表示（特定の拠点を選んだら他拠点は隠す・baba 2026-07-27）。 --}}
  @if ($scopeOffice === '')
  <div class="st-panel">
    <h3>拠点別 イベント数</h3>
    @forelse ($byOffice as $b)
      <div class="st-row {{ $b['count'] === 0 ? 'zero' : '' }}">
        <span class="r-name">{{ $b['office'] }}</span>
        <span class="r-num">{{ $b['count'] }}件<small>（うち大型{{ $b['big'] }}件）</small></span>
      </div>
    @empty
      <div class="empty">この期間のイベントがありません。</div>
    @endforelse
    <p class="st-note">※ 現在は東京のみ運営のため、ほとんどが東京になります（他拠点は今後対応）。</p>
  </div>
  @endif

  {{-- 規模別イベント数（大型／中型／小型） --}}
  <div class="st-panel">
    <h3>規模別 イベント数</h3>
    @foreach ($byScale as $s)
      <div class="st-row {{ $s['count'] === 0 ? 'zero' : '' }}">
        <span class="r-name">{{ $s['scale'] }}</span>
        <span class="r-num">{{ $s['count'] }}<small>件</small></span>
      </div>
    @endforeach
  </div>
</div>

<div class="st-grid">
  {{-- 他拠点依頼数（東→他拠点／他拠点→東／ヘルプを別々に） --}}
  <div class="st-panel">
    <h3>他拠点依頼数</h3>
    @foreach ($otherBase as $o)
      <div class="st-row {{ $o['count'] === 0 ? 'zero' : '' }}">
        <span class="r-name">{{ $o['label'] }}</span>
        <span class="r-num">{{ $o['count'] }}<small>件</small></span>
      </div>
    @endforeach
    <p class="st-note">実施形態から判定。東京のみ運営の間は少なめです。</p>
  </div>

  {{-- 部署別 合計出勤・ディレクター＋1人あたり平均 --}}
  <div class="st-panel">
    <h3>部署別 出勤・ディレクター</h3>
    <div class="st-depts">
      @foreach ($byDept as $d)
        @php $cls = ['イベプラ' => 'plan', 'セールス' => 'sales', 'クリエイティブ' => 'creative'][$d['dept']] ?? ''; @endphp
        <div class="st-dept {{ $cls }}">
          <div class="d-name">{{ $d['dept'] }}</div>
          <div class="d-num">{{ $d['count'] }}</div>
          <div class="d-sub">のべ出勤・{{ $d['active'] }}名参加</div>
          <div class="d-sub">ディレクター {{ $d['director'] }}件</div>
          <div class="d-sub avg">1人平均：出勤{{ $d['avgEvents'] }}／D{{ $d['avgDirector'] }}</div>
        </div>
      @endforeach
    </div>
    <p class="st-note">平均＝部署の社員数で割った1人あたり（出勤＝のべ日数／D＝ディレクター担当案件数）。</p>
  </div>
</div>

@php
  // 集計の主役は社員（スタッフは出さない）。
  // 全拠点モード＝拠点ごとにカード分け／特定の拠点を選んだとき＝部署ごとにカード分け（baba 2026-07-27）。
  $emp = $members->where('kind', '社員')->values();
  if ($scopeOffice !== '') {
    $groupBy = '部署';
    $groupOrder = ['イベプラ', 'セールス', 'クリエイティブ'];
    $empGrouped = $emp->groupBy(fn ($m) => ($m['dept'] ?? '') !== '' ? $m['dept'] : '（部署未設定）');
  } else {
    $groupBy = '拠点';
    $groupOrder = ['東京', '名古屋', '大阪', '福岡', '北海道', '東北'];
    $empGrouped = $emp->groupBy(fn ($m) => ($m['office'] ?? '') !== '' ? $m['office'] : '（拠点未設定）');
  }
  // 表示順＝定番の順→それ以外（未設定など）は後ろ。
  $groupKeys = collect($groupOrder)->filter(fn ($k) => $empGrouped->has($k))
      ->merge($empGrouped->keys()->diff($groupOrder))->values();
@endphp

{{-- 社員別 出勤数＋ディレクター内訳（全拠点=拠点ごと／拠点別=部署ごと・各グループ内は出勤の多い順）。
     列は「社員・ディレクター集計」と同じ：出勤／D＋SD合計／D／リアルD／大型D／大型SD／オンラインD。 --}}
<div class="st-office-title">社員別 イベント出勤・ディレクター内訳（{{ $groupBy }}ごと・{{ $emp->count() }}名）</div>
@forelse ($groupKeys as $gk)
  <div class="st-panel st-emp-block">
    <h3>{{ $gk }}（{{ $empGrouped[$gk]->count() }}名）</h3>
    <div class="st-emp-scroll">
      <table class="st-emp-table">
        <thead>
          <tr>
            <th class="l">氏名</th>
            <th>イベント出勤</th>
            <th>D＋SD合計</th>
            <th>D</th>
            <th>リアルD</th>
            <th>大型D</th>
            <th>大型SD</th>
            <th>オンラインD</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($empGrouped[$gk] as $m)
            <tr>
              <td class="l">{{ $m['name'] }}@if ($scopeOffice !== '' && $m['office'] !== '')<span class="sub">{{ $m['office'] }}</span>@endif</td>
              <td>{{ $m['count'] }}</td>
              <td>{{ $m['dTotal'] }}</td>
              <td>{{ $m['d'] }}</td>
              <td>{{ $m['realD'] }}</td>
              <td>{{ $m['bigD'] }}</td>
              <td>{{ $m['bigSD'] }}</td>
              <td>{{ $m['onlineD'] }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
@empty
  <div class="st-panel"><div class="empty">この期間に出勤した社員がいません。</div></div>
@endforelse
<p class="st-note">全拠点＝拠点ごと／拠点を選ぶと部署ごとに表示。出勤＝出勤日数。D＋SD合計〜オンラインDは「社員・ディレクター集計」と同じ数え方（同じ案件は1回）。</p>

{{-- スタッフ別 出勤数（上長要望で追加・baba 2026-07-27） --}}
@php $stf = $members->where('kind', 'スタッフ')->sortByDesc('count')->values(); @endphp
<div class="st-panel" style="margin-top:16px;">
  <h3>スタッフ別 イベント出勤数（{{ $stf->count() }}名）</h3>
  <div class="st-members">
    @forelse ($stf as $m)
      <div class="st-row">
        <span class="r-name">{{ $m['name'] }}</span>
        <span class="r-num">{{ $m['count'] }}回<small>（うち大型{{ $m['big'] }}件）</small></span>
      </div>
    @empty
      <div class="empty">この期間に出勤したスタッフがいません。</div>
    @endforelse
  </div>
  <p class="st-note">出勤＝キャンセル以外のアサイン。同じイベントで複数日ある場合は、その日数ぶん数えます。</p>
</div>

@endsection
