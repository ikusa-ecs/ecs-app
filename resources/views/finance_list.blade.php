@extends('layouts.app')
@section('title', '収支一覧')
@section('h1', '収支一覧')
@php($active = 'finance_list')

@push('head')
@verbatim
<style>
  .fl-intro { font-size: 13px; color: var(--muted); line-height: 1.8; margin-bottom: 14px; }
  .fl-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
  .fl-bar select {
    padding: 8px 11px; border: 1px solid var(--line); border-radius: 8px;
    font-size: 13.5px; font-family: inherit; background: #fff;
  }
  .fl-bar .spacer { flex: 1; }

  /* 上部のまとめ（売上・経費・利益・未入力） */
  .fl-kpis { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
  .fl-kpi {
    background: #fff; border: 1px solid var(--line); border-radius: 10px;
    padding: 11px 16px; min-width: 132px;
  }
  .fl-kpi .lbl { font-size: 11.5px; color: var(--muted); font-weight: 700; }
  .fl-kpi .num { font-size: 19px; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--ink); }
  .fl-kpi.profit .num { color: #15803d; }
  .fl-kpi.profit .num.minus { color: #b91c1c; }
  .fl-kpi.todo .num { color: #b45309; }
  .fl-kpi .sub { font-size: 11px; color: var(--muted); }

  table.fl { width: 100%; border-collapse: collapse; font-size: 13px; background: #fff; }
  table.fl th, table.fl td { padding: 8px 10px; border-bottom: 1px solid var(--line); white-space: nowrap; }
  table.fl th { background: #faf7f1; font-weight: 600; color: #6b5d4d; text-align: left; font-size: 12px; }
  table.fl th.num, table.fl td.num { text-align: right; font-variant-numeric: tabular-nums; }
  table.fl td.nm { font-weight: 600; max-width: 260px; overflow: hidden; text-overflow: ellipsis; }
  table.fl tfoot td { font-weight: 800; background: var(--brand-soft); color: var(--brand-dark); border-top: 2px solid #e3d3b6; }
  table.fl tr.unfilled td { background: #fffaf2; }
  table.fl td.minus { color: #b91c1c; }

  .pill { display: inline-block; font-size: 11px; font-weight: 700; padding: 1px 8px; border-radius: 999px; }
  .pill.ok { background: var(--ok-soft, #e7f6e9); color: #15803d; }
  .pill.todo { background: var(--warn-soft, #fdf0dc); color: #b45309; }
  .pill.late { background: var(--danger-soft, #fdecec); color: #b91c1c; }
  .pill.day { background: #f0ece3; color: #7a6a58; }

  .fl-edit { font-size: 12px; font-weight: 700; color: var(--brand-dark); text-decoration: none; }
  .fl-edit:hover { color: var(--brand); text-decoration: underline; }
  .fl-noedit { font-size: 11.5px; color: var(--muted); }
  .fl-empty { color: var(--muted); font-size: 13px; padding: 20px 4px; }
  .fl-note { font-size: 11.5px; color: var(--muted); line-height: 1.8; margin-top: 12px; }
</style>
@endverbatim
@endpush

@section('content')
      {{-- 拠点の切替（管理者以上だけ表示。一般社員は自拠点固定＝スイッチは出ない） --}}
      @include('partials.office_switch')

      <p class="fl-intro">
        月ごとに、案件の<b>売上・経費・利益</b>と<b>収支が入力済みかどうか</b>を並べた表です。
        金額は収支入力（マイページ）で入れたものをそのまま集計しています。<br>
        <b>見られるのは社員以上の全員</b>ですが、<b>入力・修正ができるのは担当のディレクター／営業担当と管理者以上</b>だけです。
        入力の締切は<b>イベント終了後 {{ $deadlineBizDays }}営業日</b>で、過ぎても未入力の案件は「遅れ」と出ます。
        @if ($officeScope)
          <br><b>{{ $officeScope }}</b>の案件だけを表示しています。
        @endif
      </p>

      <form class="fl-bar" method="GET" action="/finance-list">
        @if (request()->query('office'))
          <input type="hidden" name="office" value="{{ request()->query('office') }}">
        @endif
        <label for="month" style="font-size:13px; font-weight:700;">月：</label>
        <select name="month" id="month" onchange="this.form.submit()">
          @forelse ($months as $m)
            <option value="{{ $m['value'] }}" @selected($m['value'] === $selectedMonth)>{{ $m['label'] }}</option>
          @empty
            <option value="">（案件がありません）</option>
          @endforelse
        </select>
        <div class="spacer"></div>
        <a class="btn" href="/finance-list/export.csv?{{ http_build_query(array_filter(['month' => $selectedMonth, 'office' => request()->query('office')])) }}">⬇ この月をCSVで書き出す</a>
      </form>

      <div class="fl-kpis">
        <div class="fl-kpi"><div class="lbl">売上 合計</div><div class="num">¥{{ number_format($summary['revenue']) }}</div></div>
        <div class="fl-kpi"><div class="lbl">経費 合計</div><div class="num">¥{{ number_format($summary['cost']) }}</div></div>
        <div class="fl-kpi profit">
          <div class="lbl">利益（粗利）</div>
          <div class="num {{ $summary['profit'] < 0 ? 'minus' : '' }}">¥{{ number_format($summary['profit']) }}</div>
          <div class="sub">{{ $summary['margin'] !== null ? '粗利率 ' . $summary['margin'] . '%' : '—' }}</div>
        </div>
        <div class="fl-kpi todo">
          <div class="lbl">未入力</div>
          <div class="num">{{ $summary['unfilled'] }}</div>
          <div class="sub">
            全{{ $summary['count'] }}件中
            @if ($summary['overdue'] > 0)／うち締切超え {{ $summary['overdue'] }}件 @endif
          </div>
        </div>
      </div>

      @if ($rows->isEmpty())
        <div class="panel"><p class="fl-empty">この月に対象の案件がありません。</p></div>
      @else
      <div class="panel" style="padding:0; overflow-x:auto;">
        <table class="fl">
          <thead>
            <tr>
              <th>開催日</th>
              <th>案件名</th>
              <th>クライアント</th>
              <th>D</th>
              <th>営業</th>
              <th class="num">売上</th>
              <th class="num">経費</th>
              <th class="num">利益</th>
              <th>入力</th>
              <th>締切</th>
              <th>最終入力</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            @foreach ($rows as $r)
              <tr class="{{ $r['filled'] ? '' : 'unfilled' }}">
                <td>
                  {{ $r['dateLabel'] }}
                  @if ($r['dayType'] !== '本番')<span class="pill day">{{ $r['dayType'] }}</span>@endif
                </td>
                <td class="nm" title="{{ $r['projectName'] }}">{{ $r['name'] }}</td>
                <td>{{ $r['client'] !== '' ? $r['client'] : '—' }}</td>
                <td>{{ $r['director'] !== '' ? $r['director'] : '—' }}</td>
                <td>{{ $r['sales'] !== '' ? $r['sales'] : '—' }}</td>
                <td class="num">{{ $r['hasRevenue'] ? '¥' . number_format($r['revenue']) : '—' }}</td>
                <td class="num">{{ $r['cost'] > 0 ? '¥' . number_format($r['cost']) : '—' }}</td>
                <td class="num {{ $r['profit'] < 0 ? 'minus' : '' }}">{{ $r['filled'] ? '¥' . number_format($r['profit']) : '—' }}</td>
                <td>
                  @if ($r['filled'])
                    <span class="pill ok">入力済み</span>
                  @elseif ($r['overdue'])
                    <span class="pill late">遅れ</span>
                  @else
                    <span class="pill todo">未入力</span>
                  @endif
                </td>
                <td>{{ $r['deadlineLabel'] }}</td>
                <td>{{ $r['updatedBy'] !== '' ? $r['updatedBy'] : '—' }}</td>
                <td>
                  @if ($r['canEdit'])
                    <a class="fl-edit" href="/mypage-finance?case={{ urlencode($r['id']) }}">✏ 入力する</a>
                  @else
                    <span class="fl-noedit">担当のみ</span>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
          <tfoot>
            <tr>
              <td colspan="5">{{ $selectedMonth }} 合計（{{ $summary['count'] }}件・入力済み {{ $summary['filled'] }}件）</td>
              <td class="num">¥{{ number_format($summary['revenue']) }}</td>
              <td class="num">¥{{ number_format($summary['cost']) }}</td>
              <td class="num {{ $summary['profit'] < 0 ? 'minus' : '' }}">¥{{ number_format($summary['profit']) }}</td>
              <td colspan="4"></td>
            </tr>
          </tfoot>
        </table>
      </div>
      @endif

      <p class="fl-note">
        ※ 「利益」＝売上 − 経費合計。経費のうち「実費」の費目は<b>1,000円単位に切り上げ</b>て合計します（収支入力画面と同じ計算です）。<br>
        ※ 「入力済み」＝売上か経費のどちらかが入っている案件です。売上だけ・経費だけでも入力済みになります。<br>
        ※ 下書きの案件は出しません。予備日・リハの行は種別バッジを付けて表示します。
      </p>
@endsection
