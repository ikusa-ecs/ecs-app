@extends('layouts.app')
@section('title', '月まとめ自動アサイン')
@section('h1', '月まとめ自動アサイン')
@php($active = 'auto_assign_month')

@push('head')
@verbatim
<style>
  /* ===== 月まとめ自動アサイン 専用スタイル ===== */
  .am-month { display:flex; align-items:center; gap:8px; margin:0 0 12px; flex-wrap:wrap; }
  .am-month a {
    display:inline-flex; align-items:center; justify-content:center;
    border:1px solid var(--line); background:#fff; border-radius:8px;
    height:30px; min-width:30px; padding:0 9px; font-size:14px; text-decoration:none; color:var(--ink);
  }
  .am-month a:hover { background:#f3ece0; }
  .am-month a.on { background:var(--brand); color:#fff; border-color:var(--brand); }
  .am-month .lbl { font-size:15px; font-weight:700; min-width:104px; text-align:center; }

  .am-flash { background:var(--ok-soft); color:#166534; border:1px solid #bbe3c6;
    border-radius:10px; padding:11px 14px; font-size:13.5px; margin:0 0 14px; line-height:1.7; }

  .am-lead { background:#fff; border:1px solid var(--line); border-radius:12px;
    padding:12px 15px; font-size:13px; line-height:1.8; margin:0 0 14px; }
  .am-lead b { color:var(--ink); }
  .am-warn { color:#b45309; font-weight:700; }

  .am-kpis { display:flex; flex-wrap:wrap; gap:10px; margin:0 0 14px; }
  .am-kpi { background:#fff; border:1px solid var(--line); border-radius:12px; padding:9px 15px; min-width:140px; }
  .am-kpi .k-l { font-size:12px; color:var(--muted); font-weight:600; }
  .am-kpi .k-n { font-size:22px; font-weight:700; font-variant-numeric:tabular-nums; }
  .am-kpi .k-n small { font-size:12.5px; color:var(--muted); font-weight:400; }
  .am-kpi.bad .k-n { color:var(--danger); }

  .am-actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin:0 0 18px; }
  .am-btn { border:none; border-radius:10px; padding:11px 20px; font-family:inherit;
    font-size:14px; font-weight:700; cursor:pointer; }
  .am-btn.go { background:var(--brand); color:#fff; }
  .am-btn.go:hover { background:var(--brand-dark); }
  .am-btn.go:disabled { background:#ddd3c4; color:#8a7a66; cursor:default; }
  .am-btn.undo { background:#fff; color:#b91c1c; border:1px solid #f0b9b9; }
  .am-btn.undo:hover { background:#fdecec; }

  .am-skip { background:#fff; border:1px solid var(--line); border-radius:12px;
    padding:10px 14px; margin:0 0 14px; }
  .am-skip .s-label { font-size:12.5px; font-weight:700; color:var(--muted); display:block; margin-bottom:7px; }
  .am-skip .s-days { display:flex; flex-wrap:wrap; gap:6px; }
  .am-skip .s-day { display:inline-flex; align-items:center; gap:4px; cursor:pointer;
    border:1px solid var(--line-strong,#d8c4ae); border-radius:8px; padding:4px 9px; font-size:12.5px; background:#fff; }
  .am-skip .s-day:hover { background:#f7f1e8; }
  /* 外した日は「使わない」と一目で分かるように、灰色＋取り消し線にする。 */
  .am-skip .s-day.off { background:#f2efea; color:#9b8e80; border-color:#ded4c6; text-decoration:line-through; }
  .am-skip .s-day input { width:15px; height:15px; accent-color:var(--brand); cursor:pointer; }
  .am-skip .s-day .dw { font-size:10.5px; opacity:.8; }
  .am-skip .s-day .cn { font-size:10.5px; font-weight:700; background:#f0e6d8; color:#6e5b49; border-radius:999px; padding:0 5px; }
  .am-skip .s-clear { display:inline-block; margin-top:8px; font-size:12px; color:var(--brand-dark); }

  .am-sec { font-size:15px; font-weight:700; margin:18px 0 8px; }
  .am-note { font-size:12px; color:var(--muted); line-height:1.7; margin:6px 0 0; }

  table.am-tbl { width:100%; border-collapse:collapse; font-size:12.5px; background:#fff;
    border:1px solid var(--line); border-radius:10px; overflow:hidden; }
  table.am-tbl th, table.am-tbl td { padding:6px 9px; text-align:left; border-bottom:1px solid #f0e8dd; vertical-align:top; }
  table.am-tbl th { background:#faf7f1; font-size:11.5px; color:var(--muted); font-weight:700; white-space:nowrap; }
  table.am-tbl td.num, table.am-tbl th.num { text-align:right; font-variant-numeric:tabular-nums; }
  table.am-tbl tr.short td { background:#fdf7f0; }

  .am-pick { display:inline-block; background:var(--brand-soft); color:var(--brand-dark);
    border-radius:999px; padding:1px 9px; margin:1px 3px 1px 0; font-size:11.5px; font-weight:700; }
  .am-pick .sc { font-weight:400; opacity:.75; margin-left:3px; }
  .am-none { color:var(--muted); }
  .am-rate { font-variant-numeric:tabular-nums; }
  .am-up { color:#15803d; font-weight:700; }
</style>
@endverbatim
@endpush

@section('content')

@if (session('status'))
  <div class="am-flash">{{ session('status') }}</div>
@endif

{{-- 月の切替。⚠ 拠点も落とさない（落とすと全社に戻って驚く）。 --}}
<div class="am-month">
  <a href="?{{ http_build_query(array_filter(['period' => $prevPeriod, 'office' => $officeScope])) }}" title="前の月へ">◀</a>
  <span class="lbl">{{ $periodLabel }}</span>
  <a href="?{{ http_build_query(array_filter(['period' => $nextPeriod, 'office' => $officeScope])) }}" title="次の月へ">▶</a>
  <a class="{{ $isThisMonth ? 'on' : '' }}" href="?{{ http_build_query(array_filter(['office' => $officeScope])) }}" title="今月に戻す">今月</a>
</div>

@include('partials.office_switch')

<div class="am-lead">
  <b>{{ $periodLabel }}の「まだ人数が足りない案件」に、まとめて自動アサインします。</b>
  下は<b>これから何が起きるかの下見</b>です。<b class="am-warn">この画面を開いただけでは、何も保存されていません。</b><br>
  ・<b>取り合いが厳しい案件から先に</b>埋めます（希望者が少ない案件が最後に回って埋まらないのを防ぐため）。<br>
  ・1人入れるたびに<b>その人の件数を数え直す</b>ので、自然にならされます。<b>希望を出したのにまだ入れていない人</b>を優先します。<br>
  ・入るのはすべて<b>「仮」</b>です。そのあと手で直せます。<br>
  ・⚠ <b>確定・公開ずみの案件、「🔒 この人数で足りている」で締めた案件には触りません。</b><br>
  ・⚠ <b>その日がNGの人・同じ日に別案件へ入っている人・今月{{ $monthCap }}件に達している人は入れません。</b><br>
  ・⚠ <b>運営人数が未入力（0）の案件は対象外</b>です（何人必要か決まっていないため）。<br>
  ・<b>「この日はアサインしない」</b>にチェックを入れた日は、自動では入れません（自分で決めたい日に使ってください）。
</div>

{{-- 「この日はアサインしない」（2026-09-07 baba要望）。
     ⚠ 外した日の案件は、下の下見にも出さない（出ると「入るもの」と勘違いするため）。
     ⚠ チェックを変えたらその場で下見を作り直す（GETで開き直す）＝
        「チェックしたのに件数が変わらない」を防ぐ。 --}}
@if (count($candidateDays) > 0)
<form method="GET" action="/auto-assign-month" class="am-skip" id="skipForm">
  <input type="hidden" name="period" value="{{ $period }}">
  @if ($officeScope)<input type="hidden" name="office" value="{{ $officeScope }}">@endif
  <span class="s-label">この日はアサインしない（チェックした日は自動で入れません）：</span>
  <div class="s-days">
    @foreach ($candidateDays as $day => $cnt)
      @php($d = \Illuminate\Support\Carbon::parse($day))
      @php($off = in_array($day, $skipDays, true))
      <label class="s-day {{ $off ? 'off' : '' }}">
        <input type="checkbox" name="skip[]" value="{{ $day }}" {{ $off ? 'checked' : '' }}
               onchange="document.getElementById('skipForm').submit();">
        {{ $d->format('n/j') }}<span class="dw">{{ ['日','月','火','水','木','金','土'][$d->dayOfWeek] }}</span><span class="cn">{{ $cnt }}</span>
      </label>
    @endforeach
  </div>
  @if (count($skipDays) > 0)
    <a class="s-clear" href="?{{ http_build_query(array_filter(['period' => $period, 'office' => $officeScope])) }}">チェックを全部外す（{{ count($skipDays) }}日 除外中）</a>
  @endif
</form>
@endif

<div class="am-kpis">
  <div class="am-kpi">
    <div class="k-l">足りない案件</div>
    <div class="k-n">{{ $plan['totals']['projects'] }}<small> 件</small></div>
  </div>
  <div class="am-kpi">
    <div class="k-l">入れられる人数</div>
    <div class="k-n">{{ $plan['totals']['added'] }}<small> 名</small></div>
  </div>
  <div class="am-kpi {{ $plan['totals']['stillShort'] > 0 ? 'bad' : '' }}">
    <div class="k-l">それでも足りない</div>
    <div class="k-n">{{ $plan['totals']['stillShort'] }}<small> 名</small></div>
  </div>
</div>

<div class="am-actions">
  <form method="POST" action="/auto-assign-month/run"
        onsubmit="return confirm('{{ $periodLabel }}の {{ $plan['totals']['projects'] }}件に、{{ $plan['totals']['added'] }}名を「仮」で入れます。よろしいですか？（あとから「この回を取り消す」で戻せます）');">
    @csrf
    <input type="hidden" name="period" value="{{ $period }}">
    <input type="hidden" name="office" value="{{ $officeScope }}">
    {{-- ⚠ 除外した日は実行にも必ず持っていく。ここを忘れると
         「チェックしたのに入ってしまった」になる。 --}}
    @foreach ($skipDays as $d)
      <input type="hidden" name="skip[]" value="{{ $d }}">
    @endforeach
    <button type="submit" class="am-btn go" {{ $plan['totals']['added'] === 0 ? 'disabled' : '' }}>
      ⚡ この計画で {{ $plan['totals']['added'] }}名を入れる（すべて「仮」）
    </button>
  </form>

  @if ($lastRun)
    <form method="POST" action="/auto-assign-month/undo"
          onsubmit="return confirm('直前の自動アサイン（{{ $lastRun->added }}名）を取り消します。よろしいですか？\n※「確定」に上げたものは残ります。');">
      @csrf
      <input type="hidden" name="run_id" value="{{ $lastRun->id }}">
      <button type="submit" class="am-btn undo">↩ 直前の自動アサインを取り消す（{{ $lastRun->added }}名）</button>
    </form>
  @endif
</div>

@if ($plan['totals']['projects'] === 0)
  <div class="am-lead">
    <b>{{ $periodLabel }}に、自動アサインできる案件はありません。</b><br>
    次のどれかです：案件が無い／すでに必要人数を満たしている／確定・公開ずみで締めている／<b>運営人数が未入力</b>。
  </div>
@else

<div class="am-sec">案件ごと（埋める順）</div>
<table class="am-tbl">
  <thead>
    <tr>
      <th>順</th><th>開催日</th><th>案件</th>
      <th class="num" title="運営人数（案件登録の必要人数）">必要</th>
      <th class="num" title="すでに入っている人数">現在</th>
      <th class="num" title="入れられる候補が何人いるか">候補</th>
      <th>入れる人（おすすめ順）</th>
      <th class="num" title="入れてもまだ足りない人数">残り</th>
    </tr>
  </thead>
  <tbody>
    @foreach ($plan['projects'] as $i => $row)
      <tr class="{{ $row['stillShort'] > 0 ? 'short' : '' }}">
        <td class="num">{{ $i + 1 }}</td>
        <td>{{ \Illuminate\Support\Carbon::parse($row['date'])->format('n/j') }}</td>
        <td><b>{{ $row['name'] }}</b><br><span class="am-none">{{ $row['client'] }}</span></td>
        <td class="num">{{ $row['need'] }}</td>
        <td class="num">{{ $row['filled'] }}</td>
        <td class="num">{{ $row['candCount'] }}</td>
        <td>
          @forelse ($row['picks'] as $pick)
            <span class="am-pick" title="{{ implode('／', $pick['reasons']) }}{{ $pick['warnings'] ? '　⚠ '.implode('／', $pick['warnings']) : '' }}">
              {{ $pick['name'] }}<span class="sc">{{ $pick['score'] }}</span>
            </span>
          @empty
            <span class="am-none">入れられる人がいません</span>
          @endforelse
        </td>
        <td class="num">{{ $row['stillShort'] > 0 ? $row['stillShort'] : '—' }}</td>
      </tr>
    @endforeach
  </tbody>
</table>
<p class="am-note">
  ※ 名前の右の小さい数字は「おすすめ度」です。マウスを乗せると理由が出ます（本人が希望／今月まだ0件／このコンテンツ経験あり など）。<br>
  ※ 「候補」＝その案件に入れられる人の数です。<b>候補が少ない案件から先に</b>埋めています。<br>
  ※ ⚠ <b>「残り」に数字が出ている案件は、自動では埋まりません。</b>手で名簿・社員・派遣から足してください。
</p>

<div class="am-sec">人ごと（入れたあとどうなるか）</div>
<table class="am-tbl">
  <thead>
    <tr>
      <th>スタッフ</th>
      <th class="num" title="その月に入れる枠の数（スタッフ一覧と同じ数え方）">希望数</th>
      <th class="num">いま</th>
      <th class="num">追加</th>
      <th class="num">あと</th>
      <th>充足率</th>
    </tr>
  </thead>
  <tbody>
    @foreach ($plan['staff'] as $s)
      <tr>
        <td>{{ $s['name'] }}</td>
        <td class="num">{{ $s['wishDays'] > 0 ? $s['wishDays'] : '—' }}</td>
        <td class="num">{{ $s['before'] }}</td>
        <td class="num am-up">＋{{ $s['add'] }}</td>
        <td class="num">{{ $s['after'] }}</td>
        <td class="am-rate">
          @if ($s['rateBefore'] === null)
            <span class="am-none">—</span>
          @else
            {{ $s['rateBefore'] }}% → <b>{{ $s['rateAfter'] }}%</b>
          @endif
        </td>
      </tr>
    @endforeach
  </tbody>
</table>
<p class="am-note">
  ※ 「希望数」＝その月に入れる枠の数（スタッフ一覧と同じ数え方）。「充足率」＝アサイン数 ÷ 希望数。<br>
  ※ ⚠ <b>〇を1日も出していない人には「充足率」がありません</b>（「—」と出ます）。分母が無いためで、その人は平準化の加点も付きません。
</p>

@endif

@endsection
