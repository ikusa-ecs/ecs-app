@extends('layouts.app')
@section('title', '新人')
@section('h1', '新人')
@php($active = 'rookies')

@push('head')
<style>
  .rk-intro { font-size: 13px; color: var(--muted); line-height: 1.8; margin-bottom: 14px; }
  .rk-card { background: var(--panel); color: var(--ink); border: 1px solid var(--line); border-radius: 10px; padding: 14px 16px; margin-bottom: 16px; }
  .rk-card h3 { margin: 0 0 4px; font-size: 14.5px; }
  .rk-card .sub { font-size: 12px; color: var(--muted); margin: 0 0 10px; line-height: 1.7; }
  .rk-wrap { overflow-x: auto; }
  table.rk { width: 100%; border-collapse: collapse; font-size: 12.5px; }
  table.rk th, table.rk td { padding: 7px 8px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
  table.rk th { color: var(--muted); font-weight: 700; white-space: nowrap; }
  .rk-num { white-space: nowrap; font-variant-numeric: tabular-nums; }
  .rk-short { color: var(--warn); font-weight: 700; }
  .rk-okc { color: var(--ok, #15803d); font-weight: 700; }
  .rk-small { font-size: 11.5px; color: var(--muted); line-height: 1.6; }
  .rk-btn { padding: 4px 10px; border: 1px solid var(--line); border-radius: 6px; font-size: 12px; font-family: inherit; background: var(--panel); color: var(--ink); cursor: pointer; }
  .rk-btn.main { background: var(--brand-fill); color: #fff; border-color: var(--brand-fill); }
  .rk-in { padding: 3px 6px; border: 1px solid var(--line); border-radius: 6px; font-size: 12px; background: var(--panel); color: var(--ink); font-family: inherit; }
  .rk-in.n { width: 52px; }
  .rk-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; font-size: 13px; }
  .flash { background: var(--ok-soft); border: 1px solid #bbe3c6; color: #15803d; border-radius: 10px; padding: 10px 14px; font-size: 13px; font-weight: 700; margin-bottom: 14px; }
  .rk-warn { color: var(--warn); font-size: 12px; font-weight: 700; }
  details.rk-more > summary { cursor: pointer; font-weight: 700; font-size: 13.5px; }
  /* 縮める・目次（2026-10-13 baba「全部ちっちゃく縮められるように・Notionみたいにここに飛びたい」）。 */
  .rk-card > h3 { cursor: pointer; user-select: none; }
  .rk-card > h3::before { content: '▾ '; color: var(--muted); }
  .rk-card.rk-folded > h3::before { content: '▸ '; }
  .rk-card.rk-folded > :not(h3) { display: none; }
  .rk-card.rk-folded { padding-bottom: 10px; }
  .rk-toc { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; gap: 6px; align-items: center;
    background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 8px 10px; margin-bottom: 14px; }
  .rk-toc b { font-size: 12px; color: var(--muted); margin-right: 2px; }
  .rk-toc a { font-size: 12.5px; text-decoration: none; color: var(--ink); border: 1px solid var(--line); border-radius: 999px; padding: 2px 10px; white-space: nowrap; }
  .rk-toc a:hover { border-color: var(--brand); }
  .rk-toc .sp { flex: 1; }
  .rk-card { scroll-margin-top: 64px; }
  /* タブ（2026-10-14）。 */
  .rk-tabs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
  .rk-tabs button { padding: 7px 14px; border: 1px solid var(--line); border-radius: 8px 8px 0 0; background: var(--panel); color: var(--ink); font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; }
  .rk-tabs button.on { background: var(--brand-fill); color: #fff; border-color: var(--brand-fill); }
  .rk-card.rk-hide, .rk-toc a.rk-hide { display: none; }
  /* 難易度表と経験（2026-10-17）。 */
  /* 名前（見出しの行）は下にスクロールしても見えるように（2026-10-17 baba）。
     ⚠ 横スクロールの枠の中では position:sticky の top がページに効かない＝枠に高さを決めて枠の中で縦にスクロールさせる。
     コンテンツ名（左端の列）も横にスクロールしても残す。 */
  .rk-mx-wrap { overflow: auto; max-height: 75vh; }
  table.rk-mx th { position: sticky; top: 0; z-index: 2; background: var(--panel); box-shadow: 0 1px 0 var(--line); }
  table.rk-mx td.nm { position: sticky; left: 0; z-index: 1; background: var(--panel); }
  table.rk-mx th.nm { left: 0; z-index: 3; }
  table.rk-mx th.who { min-width: 64px; font-size: 11.5px; }
  table.rk-mx td.c { text-align: center; white-space: nowrap; font-size: 11.5px; }
  table.rk-mx td.c .fc { background: var(--ok-soft); color: var(--ink); font-weight: 700; border-radius: 3px; padding: 0 4px; }
  table.rk-mx td.c .d { background: var(--brand-fill); color: #fff; font-weight: 700; border-radius: 3px; padding: 0 4px; }
  table.rk-mx tr.must td.nm { font-weight: 700; }
  table.rk-mx td.c.todo { background: var(--danger-soft); }
  /* 新人ごとに見る（2026-10-14）。 */
  .rk-who-bar { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
  .rk-who-bar .rk-btn.on { background: var(--brand-fill); color: #fff; border-color: var(--brand-fill); }
  .rk-who-bar .rk-btn.on .rk-small { color: #fff; }
  .rk-who-off { display: none !important; }
  /* 日付で見る（2026-10-14）。1マス＝1日。 */
  table.rk-dist { border-collapse: collapse; font-size: 11px; }
  table.rk-dist th, table.rk-dist td { border: 1px solid var(--line); padding: 2px 3px; text-align: center; min-width: 20px; white-space: nowrap; }
  table.rk-dist th.nm, table.rk-dist td.nm { text-align: left; min-width: 90px; position: sticky; left: 0; background: var(--panel); }
  table.rk-dist .we { color: var(--warn); }
  table.rk-dist td.half { border-left: 2px solid var(--muted); }
  table.rk-dist .d { background: var(--brand-fill); color: #fff; font-weight: 700; border-radius: 3px; padding: 0 3px; }
  table.rk-dist .f { background: var(--ok-soft); color: var(--ink); font-weight: 700; border-radius: 3px; padding: 0 3px; }
  table.rk-dist .p { border: 1px dashed var(--brand); color: var(--brand); font-weight: 700; border-radius: 3px; padding: 0 3px; }
  /* 参加したいイベントの札（2026-10-17） */
  .rk-chip { display: inline-flex; align-items: center; gap: 2px; font-size: 12px; background: var(--ok-soft); color: var(--ink); border-radius: 10px; padding: 1px 4px 1px 8px; }
  .rk-chip-x { border: 0; background: transparent; color: var(--muted); cursor: pointer; font-size: 11px; padding: 0 2px; }
  /* お休み（2026-10-17）＝出勤可能日の×・希望休。D決めの「お休み ◯名」と同じ色。 */
  table.rk-dist .off { background: #fdecd9; color: #b4530a; font-weight: 700; border-radius: 3px; padding: 0 3px; }
  html[data-theme="dark"] table.rk-dist .off { background: var(--warn-soft); color: var(--warn-ink); }
  /* 前半・後半・計を名前の横に（2026-10-17 baba「前後半と合計の数字は名前の横に」） */
  table.rk-dist .cnt { font-size: 10.5px; color: var(--muted); font-weight: 400; margin-left: 4px; }
  table.rk-dist .cnt b { color: var(--ink); }
</style>
@endpush

@section('content')
@include('partials.office_switch')

@if (session('ok'))
  <div class="flash">{{ session('ok') }}</div>
@endif

<p class="rk-intro">
  新人ごとの進み具合と、<b>FCに入れる案</b>を出します（よければ「入れる」でFC・仮のアサインになります）。
  Dは今までどおりD決め画面で決めてください。「D準備OK」＝FCではやったがDはまだのコンテンツです。
</p>

<form class="rk-bar" method="GET" action="/rookies">
  @if ($office)<input type="hidden" name="office" value="{{ $office }}">@endif
  <label>月：<input class="rk-in" type="month" name="month" value="{{ $month->format('Y-m') }}"></label>
  <button class="rk-btn" type="submit">表示</button>
</form>

{{-- タブ（2026-10-14 baba「ページが長くて探しにくい」）。各ブロックの data-tab で出し分ける。選んだタブはこの端末で覚える。 --}}
<div class="rk-tabs" id="rkTabs">
  <button type="button" data-tab="people">👤 新人ごと</button>
  <button type="button" data-tab="fc">👟 FCの案（全員）</button>
  <button type="button" data-tab="matrix">📚 難易度表と経験</button>
  <button type="button" data-tab="settings">⚙ 設定（目標・難易度表・卒業した人）</button>
</div>

{{-- 目次（押すとそのブロックへ飛ぶ・縮めていたら開く）。中身は下の rkToc() が見出しから作る。 --}}
<nav class="rk-toc" id="rkToc" aria-label="目次"></nav>

{{-- OJT担当・チームリーダー欄の候補（全行で1つを使う）。 --}}
<datalist id="rkEmpList">
  @foreach ($employees as $e)<option value="{{ $e->name }}"></option>@endforeach
</datalist>
{{-- 参加したいイベント欄の候補＝コンテンツ台帳（2026-10-17）。 --}}
<datalist id="rkContentList">
  @foreach ($contents as $c)<option value="{{ $c->content_name }}"></option>@endforeach
</datalist>

{{-- 新人ごとに見る（2026-10-14 baba「新人ごとに見られない」）。押した人の行だけを、このタブの全ブロックで出す。 --}}
@if ($rookies)
  <div class="rk-card rk-who-bar" data-tab="people" id="rkWhoBar" style="padding:10px 12px;">
    <span class="rk-small" style="font-weight:700; margin-right:4px;">新人：</span>
    <button type="button" class="rk-btn" data-who="">全員</button>
    @foreach ($rookies as $r)
      <button type="button" class="rk-btn" data-who="{{ $r['id'] }}">{{ $r['name'] }}<span class="rk-small">（FC {{ $r['monthFc'] }}{{ $r['target'] ? '/'.$r['target']['fc'] : '' }}・案{{ count($r['myPicks']) }}）</span></button>
    @endforeach
  </div>
@endif

<div class="rk-card" data-tab="people">
  <h3>🌱 新人の一覧（{{ $month->format('Y年n月') }}）</h3>
  <p class="sub">入社2年以内のイベプラ・セールスの社員と、手で入れた人が出ます。独り立ちしたら「卒業」を押してください（一覧から消えます・下で元に戻せます）。
    「何ヶ月目」は入社日の月を1ヶ月目として数えます。</p>
  <div class="rk-wrap">
  <table class="rk">
    <tr><th>名前</th><th>OJT担当・チームリーダー・メモ</th><th>何ヶ月目</th><th>この月（目標）</th><th>必修</th><th>推奨</th><th>入社日</th><th></th></tr>
    @forelse ($rookies as $r)
      <tr data-rookie="{{ $r['id'] }}">
        <td><b>{{ $r['name'] }}</b><div class="rk-small">{{ $r['dept'] ?: '所属なし' }}・入社 {{ $r['hire'] ?: '未入力' }}</div></td>
        <td>
          @if ($canEdit)
            <form method="POST" action="/rookies/ojt">@csrf<input type="hidden" name="id" value="{{ $r['id'] }}">
              {{-- OJT担当・チームリーダーは名前を打つと候補が出る（2026-10-14 baba）。送るのは隠し欄のID＝名前は rkPickEmp() で引き当てる。 --}}
              <input type="hidden" name="ojt" value="{{ $r['ojt'] }}">
              <input class="rk-in rk-emp" type="text" list="rkEmpList" value="{{ optional($employees->firstWhere('id', $r['ojt']))->name }}" data-for="ojt" placeholder="{{ $r['sales'] ? 'OJT（イベプラ）' : 'OJT担当' }}（名前を打つと候補）" title="{{ $r['sales'] ? 'OJT（イベプラ）' : 'OJT担当' }}" autocomplete="off" style="width:150px;" onchange="rkPickEmp(this)">
              {{-- セールスの新人はOJTが2人＝イベプラ側とセールス側（2026-10-19 baba）。 --}}
              @if ($r['sales'])
                <input type="hidden" name="ojt_sales" value="{{ $r['ojtSales'] }}">
                <input class="rk-in rk-emp" type="text" list="rkEmpList" value="{{ optional($employees->firstWhere('id', $r['ojtSales']))->name }}" data-for="ojt_sales" placeholder="OJT（セールス）（名前を打つと候補）" title="OJT（セールス）" autocomplete="off" style="margin-top:4px; width:150px;" onchange="rkPickEmp(this)">
              @endif
              {{-- チームリーダー（2026-10-13 baba）。 --}}
              <input type="hidden" name="leader" value="{{ $r['leader'] }}">
              <input class="rk-in rk-emp" type="text" list="rkEmpList" value="{{ optional($employees->firstWhere('id', $r['leader']))->name }}" data-for="leader" placeholder="チームリーダー（名前を打つと候補）" title="チームリーダー" autocomplete="off" style="margin-top:4px; width:150px;" onchange="rkPickEmp(this)">
              <input class="rk-in" type="text" name="note" value="{{ $r['note'] }}" maxlength="500" placeholder="メモ" style="margin-top:4px; width:150px;" onchange="rkRemember(); this.form.submit()">
            </form>
          @else
            @if ($r['sales'])
              OJT（イベプラ）：{{ optional($employees->firstWhere('id', $r['ojt']))->name ?? '—' }}
              <div>OJT（セールス）：{{ optional($employees->firstWhere('id', $r['ojtSales']))->name ?? '—' }}</div>
            @else
              OJT：{{ optional($employees->firstWhere('id', $r['ojt']))->name ?? '—' }}
            @endif
            <div class="rk-small">リーダー：{{ optional($employees->firstWhere('id', $r['leader']))->name ?? '—' }}</div>
            @if ($r['note'])<div class="rk-small">{{ $r['note'] }}</div>@endif
          @endif
        </td>
        <td class="rk-num">{{ $r['monthNo'] !== null ? $r['monthNo'].'ヶ月目' : '—' }}</td>
        <td class="rk-num">
          @if ($r['target'])
            FC {{ $r['monthFc'] }}/<b>{{ $r['target']['fc'] }}</b>
            <span class="{{ $r['monthFc'] < $r['target']['fc'] ? 'rk-short' : 'rk-okc' }}">{{ $r['monthFc'] < $r['target']['fc'] ? 'あと'.($r['target']['fc'] - $r['monthFc']) : '達成' }}</span><br>
            D {{ $r['monthD'] }}/<b>{{ $r['target']['dFree'] ? '自由' : $r['target']['d'] }}</b>
            <div class="rk-small" title="{{ $r['target']['dFree'] ? 'この月はD数を決めず、DとFCを合わせて'.$r['target']['events'].'件まで' : '' }}">{{ $r['target']['kind'] }}の表{{ $r['target']['dFree'] ? '・合わせて'.$r['target']['events'].'件まで' : '' }}</div>
          @else
            <span class="rk-small">入社日が未入力（名簿で入社日を入れてください）</span>
          @endif
        </td>
        @foreach (['must', 'recommend'] as $k)
          <td>
            <span class="rk-num">{{ $r['progress'][$k]['done'] }}/{{ $r['progress'][$k]['total'] }}</span>
            @if ($r['progress'][$k]['readyD'])<div class="rk-small">D準備OK：{{ implode('、', $r['progress'][$k]['readyD']) }}</div>@endif
            @if ($r['progress'][$k]['left'])<div class="rk-small">まだ：{{ implode('、', $r['progress'][$k]['left']) }}</div>@endif
          </td>
        @endforeach
        <td class="rk-num">{{ $r['hire'] ?: '未入力' }}</td>
        <td>
          @if ($canEdit)
            <form method="POST" action="/rookies/state" onsubmit="return confirm('{{ $r['name'] }}さんを卒業（独り立ち）にしますか？')">@csrf
              <input type="hidden" name="id" value="{{ $r['id'] }}"><input type="hidden" name="state" value="out">
              <button class="rk-btn" type="submit">🎓 卒業</button></form>
          @endif
        </td>
      </tr>
      <tr data-rookie="{{ $r['id'] }}">
        <td colspan="8" style="border-top:0; padding-top:0;">
          {{-- 参加したいイベント（2026-10-17 baba「希望を優先にアサインできるか」）。FCの案で「まだやっていない」と同じくらい加点する。
               入れ方＝名前を少し打つと台帳の候補が出る（baba「ちょっと入力したら出るように」）→選ぶとすぐ保存。✕で外す。 --}}
          <div class="rk-bar rk-wish" style="margin:4px 0;">
            <b class="rk-small">🙋 参加したいイベント：</b>
            @if ($canEdit)
              <form method="POST" action="/rookies/wishes" style="display:contents;">@csrf<input type="hidden" name="id" value="{{ $r['id'] }}">
                <input type="hidden" name="wishes[]" value="">
                @foreach ($r['wishes'] as $wid)
                  <span class="rk-chip"><input type="hidden" name="wishes[]" value="{{ $wid }}">{{ optional($contents->firstWhere('id', $wid))->content_name ?? $wid }}<button type="button" class="rk-chip-x" title="外す" onclick="rkDropWish(this)">✕</button></span>
                @endforeach
                <input class="rk-in" type="text" list="rkContentList" placeholder="コンテンツ名を少し打つと候補" autocomplete="off" style="width:200px;" onchange="rkAddWish(this)">
              </form>
            @else
              <span class="rk-small">{{ collect($r['wishes'])->map(fn ($w) => optional($contents->firstWhere('id', $w))->content_name ?? $w)->implode('、') ?: 'なし' }}</span>
            @endif
          </div>
          {{-- この月に入っている案件（2026-10-09 baba「新人一覧で案件一覧が出るようにしてほしい」）。 --}}
          <details class="rk-exp">
            <summary class="rk-small" style="cursor:pointer;">📋 {{ $month->format('n月') }}の案件（{{ count($r['cases']) }}件）</summary>
            <div class="rk-bar" style="margin:6px 0;">
              <textarea class="rk-in rk-copy" readonly rows="{{ min(16, substr_count($r['copyText'], "\n") + 1) }}" style="width:100%; max-width:720px; font-size:12px;">{{ $r['copyText'] }}</textarea>
              <button class="rk-btn" type="button" onclick="rkCopy(this)" title="アサインチャットに貼って、OJTに見てもらう文をコピーします">📋 コピー</button><span class="rk-small rk-copied"></span>
            </div>
            @if ($r['cases'])
              <table class="rk" style="max-width:720px;">
                <tr><th>日付</th><th>案件</th><th>役割</th><th>状態</th></tr>
                @foreach ($r['cases'] as $cs)
                  <tr>
                    <td class="rk-num">{{ \Illuminate\Support\Carbon::parse($cs['date'])->format('n/j') }}@if ($cs['dayType'] && $cs['dayType'] !== '本番')<div class="rk-small">{{ $cs['dayType'] }}</div>@endif</td>
                    <td><a href="/project-assign?project={{ $cs['projectId'] }}">{{ $cs['name'] }}</a><div class="rk-small">{{ $cs['client'] }}</div></td>
                    <td>{{ $cs['role'] }}</td>
                    <td>{{ $cs['status'] }}</td>
                  </tr>
                @endforeach
              </table>
            @else
              <p class="rk-small">この月はまだ入っていません。</p>
            @endif
          </details>
          <details class="rk-exp">
            <summary class="rk-small" style="cursor:pointer;">📒 経験を見る・手で直す（{{ count($r['exp']) }}件）</summary>
            <p class="rk-small">「自動」＝アサインから数えた回数（開催済みだけ）。大型で受付だった、など実は経験していないときは「やっていない」、アサインに残っていないが経験したときは「やった」にしてください。</p>
            <form method="POST" action="/rookies/exp">@csrf<input type="hidden" name="id" value="{{ $r['id'] }}">
              <table class="rk" style="max-width:640px;">
                <tr><th>コンテンツ</th><th>FC</th><th>D</th></tr>
                @foreach ($r['exp'] as $e)
                  <tr>
                    <td>{{ $e['name'] }}</td>
                    @foreach (['fc' => 'fcAuto', 'd' => 'dAuto'] as $k => $auto)
                      <td>
                        <select class="rk-in" name="ov[{{ $e['id'] }}][{{ $k }}]">
                          <option value="">自動（{{ $e[$auto] }}回）</option>
                          <option value="done" @selected($e[$k.'Ov'] === 'done')>やった</option>
                          <option value="none" @selected($e[$k.'Ov'] === 'none')>やっていない</option>
                        </select>
                      </td>
                    @endforeach
                  </tr>
                @endforeach
              </table>
              <button class="rk-btn main" type="submit" style="margin-top:6px;">保存</button>
            </form>
          </details>
        </td>
      </tr>
    @empty
      <tr><td colspan="8" class="rk-small">新人はいません。</td></tr>
    @endforelse
  </table>
  </div>
  @if ($canEdit)
    <form class="rk-bar" method="POST" action="/rookies/state" style="margin-top:10px;">@csrf
      <input type="hidden" name="state" value="in">
      <select class="rk-in" name="id">
        @foreach ($others as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach
      </select>
      <button class="rk-btn" type="submit">＋新人に入れる</button>
    </form>
  @endif
</div>

{{-- この人のFCの案（2026-10-14 baba「新人ごとに見られない」）。上の「新人：」で1人選んだときだけ中身が出る。
     入れる・替えるは「👟 FCの案（全員）」のタブと同じ仕組み（rkAssign）。 --}}
<div class="rk-card" data-tab="people">
  <h3>👟 この人のFCの案</h3>
  <p class="sub rk-who-none">上の「新人：」で1人選ぶと、その人のFCの案が出ます。人を替える・案に無い案件に入れるのは「👟 FCの案（全員）」のタブで。</p>
  @foreach ($rookies as $r)
    <div data-rookie="{{ $r['id'] }}" class="rk-who-only">
      @if ($r['myPicks'])
        <div class="rk-wrap">
        <table class="rk" style="max-width:760px;">
          <tr><th>日付</th><th>案件</th><th>必修</th><th>理由</th><th></th></tr>
          @foreach ($r['myPicks'] as $p)
            <tr data-project="{{ $p['projectId'] }}" data-staff="{{ $r['id'] }}">
              <td class="rk-num">{{ \Illuminate\Support\Carbon::parse($p['date'])->format('n/j') }}@if (($p['dayType'] ?? '本番') !== '本番')<div class="rk-small">{{ $p['dayType'] }}</div>@endif</td>
              <td><a href="/project-assign?project={{ $p['projectId'] }}">{{ $p['name'] }}</a><div class="rk-small">{{ $p['client'] }}</div></td>
              <td>{{ $p['mark'] ?: '' }}</td>
              <td class="rk-small">{{ $p['why'] }}</td>
              <td><button class="rk-btn" type="button" onclick="rkAssign([this.closest('tr')])">入れる</button><span class="rk-small rk-done"></span></td>
            </tr>
          @endforeach
        </table>
        </div>
      @else
        <p class="rk-small">{{ $r['name'] }}さんへの案はありません（目標に届いている・空きのある案件が無い、など）。</p>
      @endif
    </div>
  @endforeach
</div>

{{-- Dの一覧（2026-10-13 baba「FCの案と一緒に、ディレクターの一覧（今アサインされている一覧）を見たい」）。 --}}
<div class="rk-card" data-tab="people">
  <h3>🎬 Dの一覧（{{ $month->format('n月') }}・新人がDで入っている案件）</h3>
  <p class="sub">いまアサインに入っている「D」だけを日付順に並べています（仮も確定も）。Dを決めるのは今までどおりD決め画面です。</p>
  @if ($dRows->isEmpty())
    <p class="rk-small">この月、Dで入っている新人はいません。</p>
  @else
    <div class="rk-wrap">
    <table class="rk" style="max-width:760px;">
      <tr><th>日付</th><th>新人</th><th>案件</th><th>状態</th></tr>
      @foreach ($dRows as $c)
        <tr data-rookie="{{ $c['rookieId'] }}">
          <td class="rk-num">{{ \Illuminate\Support\Carbon::parse($c['date'])->format('n/j') }}<div class="rk-small">{{ ['日','月','火','水','木','金','土'][\Illuminate\Support\Carbon::parse($c['date'])->dayOfWeek] }}{{ $c['dayType'] && $c['dayType'] !== '本番' ? '・'.$c['dayType'] : '' }}</div></td>
          <td><b>{{ $c['rookie'] }}</b></td>
          <td><a href="/project-assign?project={{ $c['projectId'] }}">{{ $c['name'] }}</a><div class="rk-small">{{ $c['client'] }}</div></td>
          <td>{{ $c['status'] }}</td>
        </tr>
      @endforeach
    </table>
    </div>
  @endif
</div>

{{-- チャットワークでOJT・本人に確認してもらう文（2026-10-13 baba「テキストですぐコピペできるように」）。
     文はサーバーが作ったもの（RookieFcPlan::copyText）をそのまま使う＝画面で組み立てない。 --}}
<div class="rk-card" data-tab="people">
  <h3>💬 OJT・本人への確認（チャットワーク用の文）</h3>
  <p class="sub">新人ごとに「Dで入っている案件／FC・そのほか／これからFCで入れる案」をまとめた文です。「コピー」を押してチャットワークに貼ってください。
    FCの案を「入れる」と、次に開いたときは「FC・そのほか」に移ります。</p>
  @if ($rookies)
    <div class="rk-bar" style="margin-bottom:8px;">
      <textarea class="rk-copy" readonly style="position:absolute; left:-9999px; width:1px; height:1px;">{{ collect($rookies)->pluck('copyText')->implode("\n\n") }}</textarea>
      <button class="rk-btn main" type="button" onclick="rkCopy(this)">📋 全員分をまとめてコピー</button><span class="rk-small rk-copied"></span>
    </div>
    @foreach ($rookies as $r)
      <details class="rk-exp" data-rookie="{{ $r['id'] }}">
        <summary class="rk-small" style="cursor:pointer;"><b>{{ $r['name'] }}</b>（D {{ count($r['dCases']) }}件・FCの案 {{ count($r['myPicks']) }}件）</summary>
        <div class="rk-bar" style="margin:6px 0; align-items:flex-start;">
          <textarea class="rk-in rk-copy" readonly rows="{{ min(16, substr_count($r['copyText'], "\n") + 1) }}" style="width:100%; max-width:720px; font-size:12px;">{{ $r['copyText'] }}</textarea>
          <button class="rk-btn" type="button" onclick="rkCopy(this)">📋 コピー</button><span class="rk-small rk-copied"></span>
        </div>
      </details>
    @endforeach
  @else
    <p class="rk-small">新人がいません。</p>
  @endif
</div>

<div class="rk-card" data-tab="fc">
  <h3>👟 FCに入れる案（{{ $month->format('n月') }}・今日から月末まで）</h3>
  <p class="sub">運営人数に空きがある案件に、その月のFC目標に届いていない新人を当てた案です。
    1案件に入れる新人は、ふつう1人・運営人数が8名を超える案件は2人・大型で12名以上なら空きの数まで。ARENA場所貸しには入れません。本番とリハ日・前日設営・予備日があるイベントは、出られる日は全部同じ新人を入れます。
    その日すでに入っている人・出勤可能日が×／希望休の人は入れません。まだFCでやっていないコンテンツ（必修・推奨を優先）・難易度が低い・イベプラを優先します。</p>
  @if ($unlinked)
    <p class="rk-warn">⚠ 台帳につながっていない難易度の行が{{ $unlinked }}件あります（下の「難易度表」でつなぐと、難易度・必修が案に効きます）。</p>
  @endif
  {{-- 案のとおりに入れたら何件になるか（2026-10-09 baba）。この月の日だけ数える。 --}}
  {{-- 2026-10-13 baba「新人の誰が何回入るか・1か月でこれをやったら何件増えるか」＝案が0件の新人も出し、合計の行を足した。 --}}
  @if ($picks)
    <div class="rk-small" style="font-weight:700; margin:4px 0;">📊 この案をぜんぶ入れたら（{{ $month->format('n月') }}）＝新人のFCが合計 ＋{{ collect($rookies)->sum('pickInMonth') }}件</div>
    <div class="rk-wrap" style="margin-bottom:12px;">
    <table class="rk" style="max-width:620px;">
      <tr><th>新人</th><th>いまのFC</th><th>案</th><th>入れたら</th><th>FC目標</th></tr>
      @foreach ($rookies as $r)
          <tr>
            <td><b>{{ $r['name'] }}</b></td>
            <td class="rk-num">{{ $r['monthFc'] }}件</td>
            <td class="rk-num">＋{{ $r['pickInMonth'] }}件@if ($r['pickOutside'])<div class="rk-small">（ほかの月にも{{ $r['pickOutside'] }}件）</div>@endif</td>
            <td class="rk-num"><b>{{ $r['afterFc'] }}件</b></td>
            <td class="rk-num">
              @if ($r['target'])
                {{ $r['target']['fc'] }}件
                <span class="{{ $r['afterFc'] < $r['target']['fc'] ? 'rk-short' : 'rk-okc' }}">{{ $r['afterFc'] < $r['target']['fc'] ? 'あと'.($r['target']['fc'] - $r['afterFc']) : '達成' }}</span>
              @else
                —
              @endif
            </td>
          </tr>
      @endforeach
      <tr>
        <td><b>合計</b></td>
        <td class="rk-num">{{ collect($rookies)->sum('monthFc') }}件</td>
        <td class="rk-num"><b>＋{{ collect($rookies)->sum('pickInMonth') }}件</b></td>
        <td class="rk-num"><b>{{ collect($rookies)->sum('afterFc') }}件</b></td>
        <td></td>
      </tr>
    </table>
    </div>
  @endif
  {{-- 日付で見る（2026-10-14 baba「この人が前半に多いから…後半に固まってるからチェンジ…が見られる一覧」）。
       新人×その月の日付。中身はJS（rkDist）が、入っている案件と下の案の表から作る＝新人を替えるとすぐ動く。 --}}
  @if ($rookies)
    <div class="rk-small" style="font-weight:700; margin:8px 0 4px;">🗓 日付で見る（{{ $month->format('n月') }}）　<span style="font-weight:400;">■D／■FC・そのほか（入っている）／□案／休＝出勤可能日が×・希望休　名前の横＝前半（1〜15日）・後半（16日〜）・計の件数</span></div>
    <div class="rk-wrap" id="rkDist" style="margin-bottom:8px;"></div>
  @endif
  {{-- 案に無い案件にも手で入れる（2026-10-14 baba「案の人や案件を変えられない」）。
       日付で見るのすぐ下（baba「下のほうにあると見にくい」＝偏りを見ながらそのまま入れられるように）。 --}}
  @if ($rookies && $openCases)
    <div class="rk-bar" style="margin-bottom:14px; padding-bottom:10px; border-bottom:1px dashed var(--line);">
      <b style="font-size:12.5px;">＋ 案に無い案件に入れる：</b>
      <select class="rk-in" id="rkAddWho" onchange="rkAddCheck()">
        @foreach ($rookies as $r)<option value="{{ $r['id'] }}">{{ $r['name'] }}</option>@endforeach
      </select>
      <select class="rk-in" id="rkAddCase" onchange="rkAddCheck()" style="max-width:420px;">
        @foreach ($openCases as $c)
          <option value="{{ $c['projectId'] }}" data-date="{{ $c['date'] }}">{{ \Illuminate\Support\Carbon::parse($c['date'])->format('n/j') }}{{ $c['dayType'] !== '本番' ? '（'.$c['dayType'].'）' : '' }} {{ $c['name'] }}{{ $c['client'] !== '' ? '／'.$c['client'] : '' }}（空き{{ $c['room'] }}）</option>
        @endforeach
      </select>
      <button class="rk-btn main" type="button" onclick="rkAddOne()">入れる</button>
      <span class="rk-small rk-warn" id="rkAddWarn"></span><span class="rk-small" id="rkAddDone"></span>
    </div>
  @endif
  <div class="rk-wrap">
  <table class="rk">
    <tr><th><input type="checkbox" onclick="rkCheckAll(this)" title="全部えらぶ"></th><th>日付</th><th>案件</th><th>必修</th><th>難易度</th><th>空き</th><th>新人</th><th>理由</th><th>ほかの候補</th><th></th></tr>
    @forelse ($picks as $p)
      <tr class="rk-pick" data-project="{{ $p['projectId'] }}" data-staff="{{ $p['rookieId'] }}">
        <td><input type="checkbox" class="rk-pick-cb"></td>
        <td class="rk-num">{{ \Illuminate\Support\Carbon::parse($p['date'])->format('n/j') }}@if (($p['dayType'] ?? '本番') !== '本番')<div class="rk-small">{{ $p['dayType'] }}</div>@endif</td>
        <td><a href="/project-assign?project={{ $p['projectId'] }}">{{ $p['name'] }}</a><div class="rk-small">{{ $p['client'] }}@if (! empty($p['preStay']))・<b>前泊あり</b>@endif</div></td>
        <td>@if (! empty($p['mark']))<b class="{{ $p['mark'] === '必修' ? 'rk-short' : '' }}">{{ $p['mark'] }}</b>@if (! empty($p['first']))<div class="rk-small">初めて</div>@endif @endif</td>
        <td class="rk-num">{{ $p['difficulty'] ?? '—' }}</td>
        <td class="rk-num">{{ $p['room'] }}名</td>
        {{-- 新人を替えられる（2026-10-14 baba「案の人や案件を変えられない」）。ほかの候補を上に出す。 --}}
        <td>
          <select class="rk-in rk-who" data-date="{{ $p['date'] }}" onchange="rkSwap(this)" title="別の新人に替える">
            <option value="{{ $p['rookieId'] }}" selected>{{ $p['rookie'] }}（案）</option>
            @foreach (collect($rookies)->sortBy(fn ($r) => in_array($r['name'], $p['others'], true) ? 0 : 1) as $r)
              @if ($r['id'] !== $p['rookieId'])<option value="{{ $r['id'] }}">{{ $r['name'] }}{{ in_array($r['name'], $p['others'], true) ? '（ほかの候補）' : '' }}</option>@endif
            @endforeach
          </select>
          <div class="rk-small rk-warn rk-who-warn"></div>
        </td>
        <td class="rk-small">{{ $p['why'] }}</td>
        <td class="rk-small">{{ implode('、', $p['others']) }}</td>
        <td style="white-space:nowrap;"><button class="rk-btn" type="button" onclick="rkAssign([this.closest('tr')])" title="この新人を、この案件にFC（仮）で入れます">入れる</button>
          <button class="rk-btn" type="button" onclick="this.closest('tr').remove(); window.rkDist()" title="この案を表から外します（開き直すと案は作り直されます）">外す</button><span class="rk-small rk-done"></span></td>
      </tr>
    @empty
      <tr><td colspan="10" class="rk-small">案はありません（空きのある案件が無い、または新人がこの月の目標に届いています）。</td></tr>
    @endforelse
  </table>
  </div>
  @if ($picks)
    <div class="rk-bar" style="margin-top:10px;">
      <button class="rk-btn main" type="button" onclick="rkAssign(Array.from(document.querySelectorAll('tr.rk-pick')).filter(function (tr) { return tr.querySelector('.rk-pick-cb').checked; }))">チェックしたものを入れる</button>
      <span class="rk-small">FC・<b>仮</b>で入ります（確定は日別ボードやアサイン画面で）。入れたあと、この表を作り直します。</span>
    </div>
  @endif
</div>

{{-- 難易度表と経験（2026-10-17 baba「難易度表プラス誰がどれを経験したかがわかるところがほしい」）。
     行＝難易度表・列＝新人。経験は手で直したぶんも入れた回数（RookieFcPlan の fcDone/dDone＝FCの案と同じ数え方）。
     必修・推奨なのにまだの所は薄い赤。台帳につながっていない行は数えられないので「—」。 --}}
<div class="rk-card" data-tab="matrix">
  <h3>📚 難易度表と経験</h3>
  <p class="sub"><span style="background:var(--brand-fill); color:#fff; border-radius:3px; padding:0 4px; font-weight:700;">D</span>＝Dでやった
    <span style="background:var(--ok-soft); border-radius:3px; padding:0 4px; font-weight:700;">FC</span>＝FCでやった（数字は回数）
    <span style="background:var(--danger-soft); padding:0 6px;">　</span>＝必修・推奨なのにまだ　—＝台帳につながっていない（⚙ 設定の難易度表でつなぐと出ます）。
    数えるのは開催済みのアサインと、各人の「経験を見る・手で直す」・ECSより前の経験の取込です。</p>
  @if ($difficulties->isEmpty())
    <p class="rk-small">難易度表をまだ取り込んでいません（⚙ 設定から取り込めます）。</p>
  @elseif (! $rookies)
    <p class="rk-small">新人はいません。</p>
  @else
    <div class="rk-mx-wrap">
    <table class="rk rk-mx">
      <tr><th class="nm">コンテンツ</th><th>種類</th><th>分類</th><th>難易度</th><th>必修</th><th>推奨</th>
        @foreach ($rookies as $r)
          <th class="who">{{ $r['name'] }}<div class="rk-small" title="必修の済み／全部">必修 {{ $r['progress']['must']['done'] }}/{{ $r['progress']['must']['total'] }}</div></th>
        @endforeach
      </tr>
      @foreach ($difficulties as $d)
        <tr class="{{ $d->must ? 'must' : '' }}">
          <td class="nm">{{ $d->sheet_name }}</td>
          <td class="rk-small">{{ $d->kind }}</td>
          <td class="rk-small">{{ $d->category }}</td>
          <td class="rk-num">{{ $d->difficulty ?? '—' }}</td>
          <td>{{ $d->must ? ($d->one_of ? '◻︎' : '●') : '' }}</td>
          <td>{{ $d->recommend ? ($d->one_of ? '◻︎' : '●') : '' }}</td>
          @foreach ($rookies as $r)
            @if (! $d->content_id)
              <td class="c rk-small">—</td>
            @else
              <td class="c {{ empty($r['fcDone'][$d->content_id]) && empty($r['dDone'][$d->content_id]) && ($d->must || $d->recommend) ? 'todo' : '' }}">
                @if (! empty($r['dDone'][$d->content_id]))<span class="d">D{{ $r['dDone'][$d->content_id] > 1 ? $r['dDone'][$d->content_id] : '' }}</span>@endif
                @if (! empty($r['fcDone'][$d->content_id]))<span class="fc">FC{{ $r['fcDone'][$d->content_id] > 1 ? $r['fcDone'][$d->content_id] : '' }}</span>@endif
              </td>
            @endif
          @endforeach
        </tr>
      @endforeach
    </table>
    </div>
  @endif
</div>

<details class="rk-card rk-more" data-tab="settings">
  <summary>📋 月ごとの目標（何ヶ月目 → イベント数・うちD数）</summary>
  <p class="sub">シート「セールス新人イベントおよびD数」「イベプラ新人イベントおよびD数」の値です。所属がセールスの人はセールスの表、イベプラ（だけ）の人はイベプラの表を使います。
    FCの目標＝イベント数 − D数。<b>D数を空にした月</b>は「D数は決めない（DとFCを合わせてイベント数まで）」＝FCの目標はイベント数 − その月にやったD数です。12ヶ月目より先は12ヶ月目の数を使います。</p>
  <div style="display:flex; gap:24px; flex-wrap:wrap; align-items:flex-start;">
  {{-- 2026-10-13 baba「イベプラの新人の目標もある」＝表を2つ。保存は表ごと。 --}}
  @foreach ($targetSets as $kind => $set)
    <form method="POST" action="/rookies/targets">@csrf
      <input type="hidden" name="kind" value="{{ $kind }}">
      <div style="font-weight:700; margin-bottom:4px;">{{ $kind }}新人</div>
      <table class="rk" style="max-width:360px;">
        <tr><th>何ヶ月目</th><th>イベント数</th><th>うちD数</th></tr>
        @foreach ($set as $m => $t)
          <tr><td>{{ $m }}ヶ月目</td>
            <td><input class="rk-in n" type="number" min="0" name="t[{{ $m }}][events]" value="{{ $t[0] }}" @disabled(! $canEdit)></td>
            <td><input class="rk-in n" type="number" min="0" name="t[{{ $m }}][d]" value="{{ $t[1] }}" placeholder="自由" @disabled(! $canEdit)></td></tr>
        @endforeach
      </table>
      @if ($canEdit)<button class="rk-btn main" type="submit" style="margin-top:8px;">{{ $kind }}の表を保存</button>@endif
    </form>
  @endforeach
  </div>
</details>

<details class="rk-card rk-more" data-tab="settings" @if ($unlinked) open @endif>
  <summary>📚 難易度表（コンテンツ難易度シート）・ECSより前の経験の取込</summary>
  <p class="sub">スプレッドシート「コンテンツ難易度」のリアル・オンラインをそれぞれCSVで取り込みます（入れ直すと上書き）。
    名前が台帳と合わない行は、右のプルダウンで台帳のコンテンツを選んでつないでください。つながっていない行は使いません。</p>
  @if ($canEdit)
    <form class="rk-bar" method="POST" action="/rookies/import" enctype="multipart/form-data">@csrf
      <select class="rk-in" name="kind"><option>リアル</option><option>オンライン</option></select>
      <input type="file" name="file" accept=".csv,text/csv">
      <button class="rk-btn main" type="submit">取り込む</button>
    </form>
    {{-- ECSより前の経験（2026-10-13 baba）。チェックが入っているところだけ「やった」にする。空欄は触らない。 --}}
    <p class="sub" style="margin-top:12px;">🗂 <b>ECSより前の経験</b>：「新人社員管理シート」の「新人経験値」タブをCSVでダウンロードして取り込みます。
      チェックが入っている「経験あり」「D経験あり」だけを、各人の「経験を見る・手で直す」に「やった」で入れます（空欄は触りません＝入れ直すと増えたぶんだけ足されます）。
      <b>リアルもオンラインもこの1枚でまとめて入ります</b>（上半分＝リアル、「【オンライン】」の行から下＝オンライン）。コンテンツは上の難易度表でつないだもの（リアル／オンラインそれぞれ）を使います。</p>
    <form class="rk-bar" method="POST" action="/rookies/import-exp" enctype="multipart/form-data">@csrf
      <input type="file" name="file" accept=".csv,text/csv">
      <button class="rk-btn main" type="submit">経験を取り込む</button>
    </form>
  @endif
  <div class="rk-wrap">
  <table class="rk">
    <tr><th>種類</th><th>分類</th><th>シートの名前</th><th>難易度</th><th>必修</th><th>推奨</th><th>台帳のコンテンツ</th></tr>
    @forelse ($difficulties as $d)
      <tr>
        <td>{{ $d->kind }}</td>
        <td class="rk-small">{{ $d->category }}</td>
        <td>{{ $d->sheet_name }}</td>
        <td class="rk-num">{{ $d->difficulty ?? '—' }}</td>
        <td>{{ $d->must ? ($d->one_of ? '◻︎' : '●') : '' }}</td>
        <td>{{ $d->recommend ? ($d->one_of ? '◻︎' : '●') : '' }}</td>
        <td>
          @if ($canEdit)
            <form method="POST" action="/rookies/link">@csrf<input type="hidden" name="id" value="{{ $d->id }}">
              <select class="rk-in" name="content_id" onchange="rkLink(this)">
                <option value="">（つながっていない）</option>
                @foreach ($contents as $c)<option value="{{ $c->id }}" @selected($c->id === $d->content_id)>{{ $c->content_name }}</option>@endforeach
              </select><span class="rk-small rk-saved"></span></form>
          @else
            {{ optional($contents->firstWhere('id', $d->content_id))->content_name ?? '（つながっていない）' }}
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="7" class="rk-small">まだ取り込んでいません。</td></tr>
    @endforelse
  </table>
  </div>
</details>

@if ($canEdit && $graduated->isNotEmpty())
<details class="rk-card rk-more" data-tab="settings">
  <summary>🎓 卒業した人（{{ $graduated->count() }}名）</summary>
  @foreach ($graduated as $g)
    <form class="rk-bar" method="POST" action="/rookies/state">@csrf
      <input type="hidden" name="id" value="{{ $g->id }}"><input type="hidden" name="state" value="in">
      {{ $g->name }} <button class="rk-btn" type="submit">新人に戻す</button>
    </form>
  @endforeach
</details>
@endif

<script>
  // 難易度表のつなぎは、画面を読み込み直さずにその場で保存する（2026-10-09 baba「つないだら上に行っちゃうのめんどい」）。
  // 失敗したらふつうの送信に切り替える（保存されないまま黙らない）。
  function rkLink(sel) {
    var form = sel.form;
    var mark = form.querySelector('.rk-saved');
    mark.textContent = ' 保存中…';
    fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw r.status; mark.textContent = ' ✓ 保存しました'; })
      .catch(function () { form.submit(); });
  }
  // FCの案を実際にアサインする（2026-10-09 baba「案件でOKってなったらアサイン入るようにしたい」）。
  // ⚠ 入口は日別ボードなどと同じ /entries/assign（誰がアサインされているかの正本は assignments ひとつ）。
  //   FC・仮で入れる。1件ずつ順に送り、終わったら読み込み直して表を作り直す（入れた人は案から消える）。
  // 新人の案件一覧をコピー（アサインチャットに貼ってOJTに見てもらう・2026-10-09 baba）。
  // 文はサーバーが作ったもの（RookieFcPlan::copyText）をそのまま使う＝画面で組み立てない。
  function rkCopy(btn) {
    var box = btn.parentNode.querySelector('.rk-copy');
    var mark = btn.parentNode.querySelector('.rk-copied');
    var done = function () { if (mark) mark.textContent = ' ✓ コピーしました'; };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(box.value).then(done, function () { box.select(); document.execCommand('copy'); done(); });
    } else {
      box.select(); document.execCommand('copy'); done();
    }
  }
  function rkCheckAll(box) {
    document.querySelectorAll('.rk-pick-cb').forEach(function (cb) { cb.checked = box.checked; });
  }
  // 新人ごとの、もう入っている日（2026-10-14）。案の新人を替えたとき・手で足すときに知らせる。
  var RK_BUSY = @json((object) $busy);
  var RK_NAMES = @json(collect($rookies)->pluck('name', 'id'));
  function rkBusyOn(staff, date) { return (RK_BUSY[staff] || []).indexOf(date) !== -1; }

  // 日付で見る（2026-10-14 baba「前半に多い・後半に固まってる、を見てチェンジしたい」）。
  // 入っている案件（サーバー）＋下の案の表（いまの新人）から作る。新人を替えたり外したりしたら作り直す。
  var RK_CASES = @json(collect($rookies)->mapWithKeys(fn ($r) => [$r['id'] => collect($r['cases'])->map(fn ($c) => ['date' => $c['date'], 'role' => $c['role'], 'name' => $c['name']])->values()]));
  var RK_MONTH = '{{ $month->format('Y-m') }}';
  var RK_DAYOFF = @json((object) $dayOff->all());  // 新人のお休み {社員ID:{"Y-m-d":true}}
  window.rkDist = function () {
    var box = document.getElementById('rkDist');
    if (!box) return;
    var y = +RK_MONTH.slice(0, 4), m = +RK_MONTH.slice(5, 7);
    var last = new Date(y, m, 0).getDate();
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    var esc = function (s) { return String(s).replace(/[&<>"]/g, function (ch) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]; }); };
    var wd = ['日', '月', '火', '水', '木', '金', '土'];
    // 新人 → 日付 → [{k:'d'|'f'|'p', name}]
    var cell = {};
    var add = function (sid, date, k, name) {
      if (date.slice(0, 7) !== RK_MONTH) return;
      ((cell[sid] = cell[sid] || {})[date] = cell[sid][date] || []).push({ k: k, name: name });
    };
    Object.keys(RK_CASES).forEach(function (sid) {
      (RK_CASES[sid] || []).forEach(function (c) { add(sid, c.date, c.role === 'D' ? 'd' : 'f', c.name + '（' + c.role + '）'); });
    });
    document.querySelectorAll('tr.rk-pick').forEach(function (tr) {
      var who = tr.querySelector('.rk-who');
      var link = tr.querySelector('a');
      add(tr.dataset.staff, who ? who.dataset.date : '', 'p', (link ? link.textContent : '') + '（案）');
    });
    var head = '<tr><th class="nm">新人</th>';
    for (var d = 1; d <= last; d++) {
      var w = new Date(y, m - 1, d).getDay();
      head += '<th class="' + (w === 0 || w === 6 ? 'we' : '') + (d === 16 ? ' half' : '') + '">' + d + '<br>' + wd[w] + '</th>';
    }
    head += '</tr>';
    var body = '';
    Object.keys(RK_NAMES).forEach(function (sid) {
      var row = '';
      var first = 0, second = 0;
      for (var d = 1; d <= last; d++) {
        var key = RK_MONTH + '-' + pad(d);
        var items = ((cell[sid] || {})[key]) || [];
        // お休み（出勤可能日の×・希望休・2026-10-17）。入っている日でも出す＝休みの日に入れてしまったのに気づけるように。
        var off = !!(RK_DAYOFF[sid] && RK_DAYOFF[sid][key]);
        if (items.length) { if (d <= 15) first++; else second++; }
        var mark = '';
        if (items.length) {
          var k = items.some(function (i) { return i.k === 'd'; }) ? 'd' : (items.some(function (i) { return i.k === 'f'; }) ? 'f' : 'p');
          mark = '<span class="' + k + '" title="' + esc(items.map(function (i) { return i.name; }).join(' / ')) + '">'
            + (k === 'd' ? 'D' : (k === 'f' ? 'F' : '案')) + (items.length > 1 ? items.length : '') + '</span>';
        }
        if (off) mark += '<span class="off" title="出勤可能日が×・希望休">休</span>';
        row += '<td' + (d === 16 ? ' class="half"' : '') + '>' + mark + '</td>';
      }
      // 前半・後半・計は名前の横（2026-10-17 baba）。右端だと横にスクロールしないと見えないため。
      body += '<tr><td class="nm"><b>' + esc(RK_NAMES[sid]) + '</b><span class="cnt">前' + first + '・後' + second + '・計<b>' + (first + second) + '</b></span></td>'
        + row + '</tr>';
    });
    box.innerHTML = '<table class="rk-dist">' + head + body + '</table>';
  };
  window.rkDist();

  // 案の新人を替える（2026-10-14 baba「案の人や案件を変えられない」）。「入れる」はこの行の新人で入れる。
  function rkSwap(sel) {
    var tr = sel.closest('tr');
    tr.dataset.staff = sel.value;
    var warn = tr.querySelector('.rk-who-warn');
    // ⚠ 同じ表で、替えた先の新人がもう同じ日に案で入っていないか（替えるとかぶることがある）。
    var dup = Array.from(document.querySelectorAll('tr.rk-pick')).some(function (o) {
      return o !== tr && o.dataset.staff === sel.value && o.querySelector('.rk-who').dataset.date === sel.dataset.date;
    });
    warn.textContent = rkBusyOn(sel.value, sel.dataset.date) ? '⚠ この日はもう別の案件に入っています'
      : (dup ? '⚠ この日、ほかの案でも入っています' : '');
    if (window.rkDist) window.rkDist();
  }

  // 案に無い案件に手で入れる。
  function rkAddCheck() {
    var who = document.getElementById('rkAddWho'), cs = document.getElementById('rkAddCase');
    var date = cs.options[cs.selectedIndex].dataset.date;
    document.getElementById('rkAddWarn').textContent = rkBusyOn(who.value, date) ? '⚠ この日はもう別の案件に入っています' : '';
  }
  function rkAddOne() {
    var who = document.getElementById('rkAddWho'), cs = document.getElementById('rkAddCase');
    var done = document.getElementById('rkAddDone');
    rkAssign([{ dataset: { project: cs.value, staff: who.value }, querySelector: function () { return done; } }]);
  }

  function rkAssign(rows) {
    if (!rows.length) { alert('入れる案件にチェックを付けてください。'); return; }
    if (!confirm(rows.length + '件を、FC（仮）で入れます。よろしいですか？')) return;
    var token = '{{ csrf_token() }}';
    var ng = [];
    var chain = Promise.resolve();
    rows.forEach(function (tr) {
      chain = chain.then(function () {
        var mark = tr.querySelector('.rk-done');
        if (mark) mark.textContent = ' …';
        return fetch('/entries/assign', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
          body: JSON.stringify({ project_id: tr.dataset.project, staff_id: tr.dataset.staff, action: 'assign', role: 'FC', status: '仮' })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok && j && j.ok !== false, j: j }; }); })
          .then(function (res) {
            if (mark) mark.textContent = res.ok ? ' ✓' : ' ✗';
            if (!res.ok) ng.push((res.j && res.j.message) || '保存できませんでした');
          })
          .catch(function () { if (mark) mark.textContent = ' ✗'; ng.push('通信に失敗しました'); });
      });
    });
    chain.then(function () {
      if (ng.length) alert('入れられなかったものがあります：\n' + ng.join('\n'));
      rkRemember();
      location.reload();
    });
  }

  // ほかのボタン（OJT・経験など）は読み込み直すので、開いていた枠と元の位置に戻す。
  // ===== 縮める・目次（2026-10-13 baba「全部ちっちゃく縮められるように」「Notionみたいにここに飛びたい」）=====
  // ・見出し（h3）を押すとそのブロックを縮める／開く。縮めた状態はこの端末で覚える（localStorage）。
  // ・下のほうの「月ごとの目標」などはもともと開け閉めできる（details）。目次から飛ぶと開く。
  // ・覚える鍵は見出しの文字の「（」より前＝月が変わっても同じブロックとして覚える。
  (function rkToc() {
    var KEY = 'rk_folded';
    var folded = {};
    try { folded = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { folded = {}; }
    var save = function () { try { localStorage.setItem(KEY, JSON.stringify(folded)); } catch (e) {} };
    var toc = document.getElementById('rkToc');
    if (!toc) return;
    var cards = Array.prototype.slice.call(document.querySelectorAll('.rk-card'));
    var title = function (el) { return (el.textContent || '').trim().replace(/\s+/g, ' '); };
    toc.innerHTML = '<b>目次</b>';
    cards.forEach(function (card, i) {
      var head = card.querySelector(':scope > h3, :scope > summary');
      if (!head) return;
      var text = title(head);
      var key = text.split('（')[0];
      card.id = card.id || ('rk-sec-' + i);
      var isDetails = card.tagName === 'DETAILS';
      if (!isDetails) {
        if (folded[key]) card.classList.add('rk-folded');
        head.title = '押すと縮める／開く';
        head.addEventListener('click', function () {
          card.classList.toggle('rk-folded');
          if (card.classList.contains('rk-folded')) folded[key] = 1; else delete folded[key];
          save();
        });
      }
      var a = document.createElement('a');
      a.href = '#' + card.id;
      a.textContent = key;
      a.addEventListener('click', function (ev) {
        ev.preventDefault();
        if (isDetails) card.open = true;
        else if (card.classList.contains('rk-folded')) { card.classList.remove('rk-folded'); delete folded[key]; save(); }
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
      a.dataset.tab = card.dataset.tab || '';
      toc.appendChild(a);
    });
    var sp = document.createElement('span'); sp.className = 'sp'; toc.appendChild(sp);
    var mk = function (label, fold) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'rk-btn'; b.textContent = label;
      b.addEventListener('click', function () {
        cards.forEach(function (card) {
          var head = card.querySelector(':scope > h3, :scope > summary');
          if (!head) return;
          var key = title(head).split('（')[0];
          if (card.tagName === 'DETAILS') { card.open = !fold; return; }
          card.classList.toggle('rk-folded', fold);
          if (fold) folded[key] = 1; else delete folded[key];
        });
        save();
      });
      toc.appendChild(b);
    };
    mk('すべて縮める', true);
    mk('すべて開く', false);
  })();

  // タブ（2026-10-14 baba「ページが長くて探しにくい」）。目次もいまのタブのブロックだけにする。
  (function rkTabs() {
    var KEY = 'rk_tab';
    var bar = document.getElementById('rkTabs');
    if (!bar) return;
    var show = function (tab) {
      bar.querySelectorAll('button').forEach(function (b) { b.classList.toggle('on', b.dataset.tab === tab); });
      document.querySelectorAll('.rk-card[data-tab], .rk-toc a[data-tab]').forEach(function (el) {
        el.classList.toggle('rk-hide', el.dataset.tab !== '' && el.dataset.tab !== tab);
      });
      try { localStorage.setItem(KEY, tab); } catch (e) {}
    };
    bar.querySelectorAll('button').forEach(function (b) { b.addEventListener('click', function () { show(b.dataset.tab); }); });
    var saved = 'people';
    try { saved = localStorage.getItem(KEY) || 'people'; } catch (e) {}
    show(bar.querySelector('button[data-tab="' + saved + '"]') ? saved : 'people');
  })();

  // 新人ごとに見る（2026-10-14 baba「新人ごとに見られない」）。「👤 新人ごと」タブの中だけ、選んだ人の行に絞る。
  (function rkWho() {
    var KEY = 'rk_who';
    var bar = document.getElementById('rkWhoBar');
    if (!bar) return;
    var pick = function (who) {
      if (who && !bar.querySelector('button[data-who="' + who + '"]')) who = '';   // 卒業などでいなくなった人
      bar.querySelectorAll('button').forEach(function (b) { b.classList.toggle('on', b.dataset.who === who); });
      document.querySelectorAll('.rk-card[data-tab="people"] [data-rookie]').forEach(function (el) {
        var only = el.classList.contains('rk-who-only');
        el.classList.toggle('rk-who-off', only ? el.dataset.rookie !== who : (who !== '' && el.dataset.rookie !== who));
      });
      document.querySelectorAll('.rk-who-none').forEach(function (el) { el.classList.toggle('rk-who-off', who !== ''); });
      try { localStorage.setItem(KEY, who); } catch (e) {}
    };
    bar.querySelectorAll('button').forEach(function (b) { b.addEventListener('click', function () { pick(b.dataset.who); }); });
    var saved = '';
    try { saved = localStorage.getItem(KEY) || ''; } catch (e) {}
    pick(saved);
  })();

  // OJT担当・チームリーダー：打った名前を社員のIDに引き当てて保存する（2026-10-14 baba）。
  // 空にしたら「なし」。名簿に無い名前なら保存せず元の名前に戻す。
  var RK_EMP = @json($employees->map(fn ($e) => ['id' => $e->id, 'name' => $e->name])->values());
  function rkPickEmp(input) {
    var hidden = input.form.querySelector('input[type=hidden][name="' + input.dataset.for + '"]');
    var name = input.value.replace(/[\s　]+/g, '');
    var id = '';
    if (name !== '') {
      var hit = RK_EMP.filter(function (e) { return (e.name || '').replace(/[\s　]+/g, '') === name; })[0];
      if (!hit) {
        var cur = RK_EMP.filter(function (e) { return e.id === hidden.value; })[0];
        input.value = cur ? cur.name : '';
        alert('「' + name + '」は社員名簿に見つかりません。候補から選んでください。');
        return;
      }
      id = hit.id;
      input.value = hit.name;
    }
    if (id === hidden.value) return;
    hidden.value = id;
    rkRemember();
    input.form.submit();
  }

  // 参加したいイベント（2026-10-17 baba）：打った名前を台帳のIDに引き当てて足す・✕で外す。どちらもすぐ保存。
  var RK_CONTENTS = @json($contents->map(fn ($c) => ['id' => (string) $c->id, 'name' => $c->content_name])->values());
  function rkAddWish(input) {
    var name = input.value.replace(/[\s　]+/g, '');
    if (name === '') return;
    var hit = RK_CONTENTS.filter(function (c) { return (c.name || '').replace(/[\s　]+/g, '') === name; })[0];
    if (!hit) {
      alert('「' + input.value + '」はコンテンツ台帳に見つかりません。候補から選んでください。');
      input.value = '';
      return;
    }
    var have = Array.from(input.form.querySelectorAll('input[name="wishes[]"]')).some(function (h) { return h.value === hit.id; });
    if (have) { input.value = ''; return; }
    var h = document.createElement('input');
    h.type = 'hidden'; h.name = 'wishes[]'; h.value = hit.id;
    input.form.appendChild(h);
    rkRemember();
    input.form.submit();
  }
  function rkDropWish(btn) {
    var form = btn.closest('form');
    btn.closest('.rk-chip').remove();
    rkRemember();
    form.submit();
  }

  function rkRemember() {
    try {
      var open = [];
      document.querySelectorAll('details').forEach(function (d, i) { if (d.open) open.push(i); });
      sessionStorage.setItem('rk_scroll', JSON.stringify({ y: window.scrollY, open: open }));
    } catch (e) {}
  }
  try {
    var saved = JSON.parse(sessionStorage.getItem('rk_scroll') || 'null');
    if (saved) {
      sessionStorage.removeItem('rk_scroll');
      var all = document.querySelectorAll('details');
      (saved.open || []).forEach(function (i) { if (all[i]) all[i].open = true; });
      window.scrollTo(0, parseInt(saved.y, 10) || 0);
    }
    document.querySelectorAll('form[method="POST"]').forEach(function (f) { f.addEventListener('submit', rkRemember); });
  } catch (e) {}
</script>
@endsection
