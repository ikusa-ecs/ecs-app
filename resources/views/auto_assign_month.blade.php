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
  .am-month a.on { background:var(--brand-fill); color:#fff; border-color:var(--brand); }
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
  .am-btn.go { background:var(--brand-fill); color:#fff; }
  .am-btn.go:hover { background:var(--brand-fill-hover); }
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

  .am-skip .s-day.s-proj { max-width:280px; }
  .am-skip .s-day.s-proj .dt { font-size:11px; color:var(--muted); margin-right:3px; }
  /* 手で入っている案件＝機械が触らない印（他のチェックと見分けが付くように色を変える）。 */
  .am-skip .s-day.s-hand { border-color:#f0c98a; background:#fff8ec; }
  .am-skip .s-day.s-hand.off { border-color:#e6d6bd; background:#faf6ee; }

  /* 日ごとのまとまり（2026-09-07 baba要望＝並べ方だけ日ごとにする） */
  .am-day { margin:0 0 16px; }
  .am-day-head { display:flex; align-items:baseline; gap:10px; flex-wrap:wrap;
    font-size:14px; font-weight:700; color:var(--ink);
    border-left:4px solid var(--brand); padding:3px 0 3px 9px; margin:0 0 6px; }
  .am-day-head .dw { font-size:12px; color:var(--muted); font-weight:600; }
  .am-day-sum { font-size:12px; color:var(--muted); font-weight:600; }
  /* 何番目に埋めたか。⚠ 並べ方を日ごとにしても「厳しい案件から埋めた」ことが分かるように残す。 */
  .am-ord { display:inline-block; margin-left:6px; min-width:18px; text-align:center;
    background:#eee3d4; color:#6e5b49; border-radius:999px; padding:0 6px;
    font-size:10.5px; font-weight:700; }

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
  /* 入る役割。⚠ その人の主ポジションではなく「枠の役割」。 */
  .am-pick .rl { display:inline-block; background:#fff; color:var(--brand-dark);
    border-radius:5px; padding:0 5px; margin-right:4px; font-size:10.5px; }
  .am-tpl { font-size:11px; color:var(--muted); margin-top:3px; line-height:1.6; }
  .am-slot { display:inline-block; background:#eef2ff; color:#4338ca; border-radius:5px;
    padding:0 6px; margin:0 3px 2px 0; font-size:10.5px; font-weight:700; }
  .am-none { color:var(--muted); }
  .am-rate { font-variant-numeric:tabular-nums; }
  .am-up { color:#15803d; font-weight:700; }

  /* ===== 黒ベース（ダークモード）のときの読みやすさ調整 =====
     白い面・茶色の文字・淡い色の帯を、暗い面＋明るい文字に置き換える。意味の色（緑＝増えた／赤＝戻す）は変えない。 */
  html[data-theme="dark"] .am-month a { background:var(--panel); }
  html[data-theme="dark"] .am-month a:hover { background:var(--surface-3); }
  html[data-theme="dark"] .am-month a.on { background:var(--brand-fill); color:#ffffff; }
  html[data-theme="dark"] .am-flash { background:var(--ok-soft); color:var(--ok-ink); border-color:var(--ok-line); }
  html[data-theme="dark"] .am-lead { background:var(--panel); }
  html[data-theme="dark"] .am-warn { color:var(--warn-ink); }
  html[data-theme="dark"] .am-kpi { background:var(--panel); }
  html[data-theme="dark"] .am-btn.go { color:#ffffff; }
  html[data-theme="dark"] .am-btn.go:disabled { background:var(--chip-bg); color:var(--muted-dim); }
  html[data-theme="dark"] .am-btn.undo { background:var(--panel); color:var(--danger-ink); border-color:var(--danger-line); }
  html[data-theme="dark"] .am-btn.undo:hover { background:var(--danger-soft); }
  html[data-theme="dark"] .am-skip { background:var(--panel); }
  html[data-theme="dark"] .am-skip .s-day { background:var(--surface-2); border-color:var(--field-line); color:var(--ink); }
  html[data-theme="dark"] .am-skip .s-day:hover { background:var(--surface-3); }
  html[data-theme="dark"] .am-skip .s-day.off { background:var(--surface-2); color:var(--muted-dim); border-color:#343a44; }
  html[data-theme="dark"] .am-skip .s-day .cn { background:var(--chip-bg); color:var(--chip-ink); }
  html[data-theme="dark"] .am-skip .s-day.s-hand { border-color:#7a5a1e; background:#2a2418; }
  html[data-theme="dark"] .am-skip .s-day.s-hand.off { border-color:#4a4230; background:var(--surface-2); }
  html[data-theme="dark"] .am-ord { background:var(--chip-bg); color:var(--chip-ink); }
  html[data-theme="dark"] table.am-tbl { background:var(--panel); }
  html[data-theme="dark"] table.am-tbl th, html[data-theme="dark"] table.am-tbl td { border-bottom-color:var(--line); }
  html[data-theme="dark"] table.am-tbl th { background:var(--surface-2); }
  html[data-theme="dark"] table.am-tbl tr.short td { background:var(--warn-soft); }
  html[data-theme="dark"] .am-pick .rl { background:#1a1d23; color:var(--brand-dark); }
  html[data-theme="dark"] .am-slot { background:var(--info-soft); color:var(--info-ink); }
  html[data-theme="dark"] .am-up { color:var(--ok-ink); }
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
  ・<b>「この日はアサインしない」「この案件は入れない」</b>にチェックを入れたものは、自動では入れません（自分で決めたい日・案件に使ってください）。
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
  {{-- ⚠ 案件のチェックも同じフォームで送る。別々のフォームにすると、
       日を変えたときに案件のチェックが消えてしまう。 --}}
  <span class="s-label" style="margin-top:12px;">この案件は入れない（チェックした案件は自動で入れません）：</span>
  <div class="s-days">
    @foreach ($candidateProjects as $cp)
      @php($cd = \Illuminate\Support\Carbon::parse($cp['date']))
      @php($poff = in_array((string) $cp['id'], $skipProjects, true))
      <label class="s-day s-proj {{ $poff ? 'off' : '' }}">
        <input type="checkbox" name="skipProject[]" value="{{ $cp['id'] }}" {{ $poff ? 'checked' : '' }}
               onchange="document.getElementById('skipForm').submit();">
        <span class="dt">{{ $cd->format('n/j') }}</span>{{ $cp['name'] }}<span class="cn">必{{ $cp['need'] }}</span>
      </label>
    @endforeach
  </div>
  {{-- ⚠ 手で入っている案件は既定で対象外（2026-09-08 baba指摘
       「日別ボードで仮埋めしてたのに変わった」「触らないでほしい」）。
       黙って外すと「なぜ下見に出ないのか」が分からないので、ここに理由つきで出す。
       ⚠ 同じフォームで送る（別フォームにすると、日のチェックを変えたときに消える）。 --}}
  @if (count($handMade) > 0)
    <span class="s-label" style="margin-top:12px;">
      🖐 <b>手で入っている案件（機械は触りません）</b>
      … 日別ボードなどで、すでに人が入れてある案件です。<b>チェックを付けた案件だけ</b>、足りないぶんを自動で埋めます。
    </span>
    <div class="s-days">
      @foreach ($handMade as $hm)
        @php($hd = \Illuminate\Support\Carbon::parse($hm['date']))
        <label class="s-day s-proj s-hand {{ $hm['included'] ? '' : 'off' }}"
               title="手で入っている人：{{ implode('、', $hm['people']) }}">
          <input type="checkbox" name="includeHand[]" value="{{ $hm['id'] }}" {{ $hm['included'] ? 'checked' : '' }}
                 onchange="document.getElementById('skipForm').submit();">
          <span class="dt">{{ $hd->format('n/j') }}</span>{{ $hm['name'] }}<span class="cn">手{{ count($hm['people']) }}名</span>
        </label>
      @endforeach
    </div>
  @endif
  {{-- ⚠ まだスタッフに公開していない案件は自動で埋めない（2026-09-09 baba要望）。
       公開していない＝まだ募集を出していない＝エントリー（手を挙げた人）が集まっていないため。
       黙って外すと「なぜ下見に出ないのか」が分からないので、ここに理由つきで出す。 --}}
  @if (count($unpublished) > 0)
    <span class="s-label" style="margin-top:12px;">
      🔒 <b>まだスタッフに公開していない案件（自動では埋めません）</b>
      … 公開すると募集が出て、スタッフのエントリーが集まります。<b>公開ボードで「公開する」を押すと</b>、この一覧から下見に移ります。
    </span>
    <div class="s-days">
      @foreach ($unpublished as $up)
        @php($ud = \Illuminate\Support\Carbon::parse($up['date']))
        <span class="s-day s-proj off" title="{{ $up['client'] }}">
          <span class="dt">{{ $ud->format('n/j') }}</span>{{ $up['name'] }}<span class="cn">必{{ $up['need'] }}</span>
        </span>
      @endforeach
    </div>
  @endif
  @if (count($skipDays) > 0 || count($skipProjects) > 0 || count($includeHandMade) > 0)
    <a class="s-clear" href="?{{ http_build_query(array_filter(['period' => $period, 'office' => $officeScope])) }}">チェックを全部外す（日 {{ count($skipDays) }}・案件 {{ count($skipProjects) }} 除外中）</a>
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
    @foreach ($skipProjects as $pid)
      <input type="hidden" name="skipProject[]" value="{{ $pid }}">
    @endforeach
    {{-- ⚠ 「手で入っているけれど埋めてよい」も実行に持っていく。忘れると
         下見では入るはずの案件が、実行では入らない（逆に、触らないはずの案件が動くこともない）。 --}}
    @foreach ($includeHandMade as $pid)
      <input type="hidden" name="includeHand[]" value="{{ $pid }}">
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

<div class="am-sec">日ごと（この計画で誰が入るか）</div>
<p class="am-note" style="margin:0 0 10px;">
  ⚠ <b>埋める順は今までどおり「取り合いが厳しい案件から」</b>です（考え方は変えていません）。
  見やすいように<b>並べ方だけ日ごと</b>にしました。案件名の右の <span class="am-ord">①</span> が<b>何番目に埋めたか</b>です。
</p>
@foreach ($byDay as $day => $rows)
  @php($d = \Illuminate\Support\Carbon::parse($day))
  <div class="am-day">
    <div class="am-day-head">
      {{ $d->format('n月j日') }}<span class="dw">（{{ ['日','月','火','水','木','金','土'][$d->dayOfWeek] }}）</span>
      <span class="am-day-sum">{{ count($rows) }}件 ／ 入れる {{ collect($rows)->sum(fn ($r) => count($r['picks'])) }}名
        @if (collect($rows)->sum('stillShort') > 0)
          <b class="am-warn">／ まだ足りない {{ collect($rows)->sum('stillShort') }}名</b>
        @endif
      </span>
    </div>
    <table class="am-tbl">
      <thead>
        <tr>
          <th style="width:150px;">案件</th>
          <th class="num" title="運営人数（案件登録の必要人数）">必要</th>
          <th class="num" title="すでに入っている人数">現在</th>
          <th class="num" title="入れられる候補が何人いるか">候補</th>
          <th>入れる人（おすすめ順）</th>
          <th class="num" title="入れてもまだ足りない人数">残り</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($rows as $row)
          <tr class="{{ $row['stillShort'] > 0 ? 'short' : '' }}">
            <td>
              <b>{{ $row['name'] }}</b><span class="am-ord" title="この計画で{{ $row['order'] }}番目に埋めました">{{ $row['order'] }}</span><br>
              <span class="am-none">{{ $row['client'] }}</span>
              {{-- 必要ポジション（コンテンツ×規模）。⚠ 空のときは理由を出す。
                   出さないと「なぜ役割が付かないのか」が分からない。 --}}
              <div class="am-tpl">
                @if (count($row['template']) > 0)
                  必要：@foreach ($row['template'] as $rc => $n)<span class="am-slot">{{ \App\Support\AssignmentRole::label($rc) }}{{ $n }}</span>@endforeach
                @else
                  <span class="am-warn">必要ポジション未設定</span>（コンテンツか規模が入っていません。役割なしで人数だけ入れます）
                @endif
              </div>
            </td>
            <td class="num">{{ $row['need'] }}</td>
            <td class="num">{{ $row['filled'] }}</td>
            <td class="num">{{ $row['candCount'] }}</td>
            <td>
              @forelse ($row['picks'] as $pick)
                <span class="am-pick" title="{{ implode('／', $pick['reasons']) }}{{ $pick['warnings'] ? '　⚠ '.implode('／', $pick['warnings']) : '' }}">
                  <span class="rl">{{ $pick['role'] !== '' ? \App\Support\AssignmentRole::label($pick['role']) : '未定' }}</span>{{ $pick['name'] }}<span class="sc">{{ $pick['score'] }}</span>
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
  </div>
@endforeach
<p class="am-note">
  ※ 名前の右の小さい数字は「おすすめ度」です。マウスを乗せると理由が出ます（本人が希望／今月まだ0件／このコンテンツ経験あり など）。<br>
  ※ 「候補」＝その案件に入れられる人の数です。<b>候補が少ない案件から先に</b>埋めています。<br>
  ※ ⚠ <b>「残り」に数字が出ている案件は、自動では埋まりません。</b>手で名簿・社員・派遣から足してください。<br>
  ※ 名前の前は<b>入る役割</b>です。<b>案件が必要としている枠にだけ</b>入れます（必要ポジションに無い役割は付けません）。<br>
  ※ <b>「未定」</b>＝必要ポジションの合計より運営人数のほうが多いときの余りの枠です。担当はあとで決めてください。<br>
  ※ ⚠ <b>役割の決まった枠には「その役割ができる人」だけ</b>入れます（名簿の「できるポジション」を見ています）。
  できる人がいないと、その枠は空いたままになります（「残り」に数えます）。
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
        <td class="num">
          {{ $s['wishDays'] > 0 ? $s['wishDays'] : '—' }}
          @if ($s['wishDays'] > 0)
            <br><span class="am-none" style="font-size:10.5px;">〇{{ $s['okDays'] }}日・応募{{ $s['entries'] }}件</span>
          @endif
        </td>
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
  ※ 「希望数」＝その月に<b>入れる枠の数</b>。<b>スタッフ一覧（希望まとめ）とまったく同じ数え方</b>です（数え方の置き場所を1つにまとめました）。<br>
  　・<b>〇を出した日</b>＝その日の<b>案件数</b>（案件が無い日は 1）／・<b>〇は無いがエントリーした日</b>＝その<b>エントリー件数</b>。⚠ 同じ日は二重に数えません。<br>
  ※ 「充足率」＝アサイン数 ÷ 希望数。<br>
  ※ ⚠ <b>〇もエントリーも無い人には「充足率」がありません</b>（「—」と出ます）。分母が無いためで、その人は平準化の加点も付きません。
</p>

@endif

@endsection
