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
  .st-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px; }
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
</style>
@endpush

@section('content')

{{-- 期間の粒度をタブ（シート）で選ぶ。案件一覧の下書き/アーカイブと同じ切替方式。 --}}
<div class="st-tabs">
  <a class="st-tab {{ $span === 'month' ? 'active' : '' }}" href="/stats?span=month">月単位</a>
  <a class="st-tab {{ $span === 'quarter' ? 'active' : '' }}" href="/stats?span=quarter">四半期</a>
  <a class="st-tab {{ $span === 'year' ? 'active' : '' }}" href="/stats?span=year">年単位</a>
</div>

{{-- 選んだ粒度の中で、どの期間かを選ぶ。選ぶとGETで開き直す。 --}}
<form method="GET" action="/stats" class="st-controls" id="stForm">
  <input type="hidden" name="span" value="{{ $span }}">
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
  <span class="now">{{ $selectedLabel !== '' ? $selectedLabel : '—' }} の集計</span>
</form>

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

<div class="st-grid">
  {{-- 拠点（事務所）別イベント数。定番拠点は0でも表示。合計＝全拠点の合計。 --}}
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

  {{-- 部署別 合計出勤数 --}}
  <div class="st-panel">
    <h3>部署別 合計出勤数</h3>
    <div class="st-depts">
      @foreach ($byDept as $d)
        @php $cls = ['イベプラ' => 'plan', 'セールス' => 'sales', 'クリエイティブ' => 'creative'][$d['dept']] ?? ''; @endphp
        <div class="st-dept {{ $cls }}">
          <div class="d-name">{{ $d['dept'] }}</div>
          <div class="d-num">{{ $d['count'] }}</div>
          <div class="d-sub">{{ $d['heads'] }}名が参加</div>
        </div>
      @endforeach
    </div>
    <p class="st-note">部署は社員の所属（イベプラ／セールス／クリエイティブ）で集計。出勤数はその期間に出勤した日数（のべ・複数日はその分カウント）です。</p>
  </div>
</div>

@php
  // 集計の主役は社員（スタッフは出さない）。拠点ごとにブロック分けする（baba 2026-07-24）。
  $emp = $members->where('kind', '社員')->values();
  $officeOrder = ['東京', '名古屋', '大阪', '福岡', '北海道', '東北'];
  $empGrouped = $emp->groupBy(fn ($m) => ($m['office'] ?? '') !== '' ? $m['office'] : '（拠点未設定）');
  // 表示順＝定番拠点の順→それ以外（未設定など）は後ろ。
  $officeKeys = collect($officeOrder)->filter(fn ($o) => $empGrouped->has($o))
      ->merge($empGrouped->keys()->diff($officeOrder))->values();
@endphp

{{-- 社員別 出勤数（拠点ごとのカード・各カード内は多い順） --}}
<div class="st-office-title">社員別 イベント出勤数（拠点ごと・{{ $emp->count() }}名）</div>
<div class="st-office-cards">
  @forelse ($officeKeys as $ok)
    <div class="st-panel">
      <h3>{{ $ok }}（{{ $empGrouped[$ok]->count() }}名）</h3>
      @foreach ($empGrouped[$ok] as $m)
        <div class="st-row">
          <span class="r-name">{{ $m['name'] }}<span class="sub">{{ $m['dept'] !== '' ? $m['dept'] : '社員' }}</span></span>
          <span class="r-num">{{ $m['count'] }}回<small>（うち大型{{ $m['big'] }}件）</small></span>
        </div>
      @endforeach
    </div>
  @empty
    <div class="st-panel"><div class="empty">この期間に出勤した社員がいません。</div></div>
  @endforelse
</div>
<p class="st-note">拠点＝社員の所属オフィス。出勤＝キャンセル以外のアサイン。同じイベントで複数日ある場合は、その日数ぶん数えます。</p>

@endsection
