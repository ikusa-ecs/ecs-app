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
</style>
@endpush

@section('content')
@include('partials.office_switch')

@if (session('ok'))
  <div class="flash">{{ session('ok') }}</div>
@endif

<p class="rk-intro">
  新人ごとの進み具合と、<b>FCに入れる案</b>を出します（案は見るだけ・ここからは保存しません。入れるときは日別ボードやアサイン画面で）。
  Dは今までどおりD決め画面で決めてください。「D準備OK」＝FCではやったがDはまだのコンテンツです。
</p>

<form class="rk-bar" method="GET" action="/rookies">
  @if ($office)<input type="hidden" name="office" value="{{ $office }}">@endif
  <label>月：<input class="rk-in" type="month" name="month" value="{{ $month->format('Y-m') }}"></label>
  <button class="rk-btn" type="submit">表示</button>
</form>

<div class="rk-card">
  <h3>🌱 新人の一覧（{{ $month->format('Y年n月') }}）</h3>
  <p class="sub">入社2年以内のイベプラ・セールスの社員と、手で入れた人が出ます。独り立ちしたら「卒業」を押してください（一覧から消えます・下で元に戻せます）。
    「何ヶ月目」は入社日の月を1ヶ月目として数えます。</p>
  <div class="rk-wrap">
  <table class="rk">
    <tr><th>名前</th><th>OJT担当・メモ</th><th>何ヶ月目</th><th>この月（目標）</th><th>必修</th><th>推奨</th><th>入社日</th><th></th></tr>
    @forelse ($rookies as $r)
      <tr>
        <td><b>{{ $r['name'] }}</b><div class="rk-small">{{ $r['dept'] ?: '所属なし' }}・入社 {{ $r['hire'] ?: '未入力' }}</div></td>
        <td>
          @if ($canEdit)
            <form method="POST" action="/rookies/ojt">@csrf<input type="hidden" name="id" value="{{ $r['id'] }}">
              <select class="rk-in" name="ojt" onchange="rkRemember(); this.form.submit()" title="OJT担当">
                <option value="">（OJT担当なし）</option>
                @foreach ($employees as $e)<option value="{{ $e->id }}" @selected($e->id === $r['ojt'])>{{ $e->name }}</option>@endforeach
              </select>
              <input class="rk-in" type="text" name="note" value="{{ $r['note'] }}" maxlength="500" placeholder="メモ" style="margin-top:4px; width:150px;" onchange="rkRemember(); this.form.submit()">
            </form>
          @else
            {{ optional($employees->firstWhere('id', $r['ojt']))->name ?? '—' }}
            @if ($r['note'])<div class="rk-small">{{ $r['note'] }}</div>@endif
          @endif
        </td>
        <td class="rk-num">{{ $r['monthNo'] !== null ? $r['monthNo'].'ヶ月目' : '—' }}</td>
        <td class="rk-num">
          @if ($r['target'])
            FC {{ $r['monthFc'] }}/<b>{{ $r['target']['fc'] }}</b>
            <span class="{{ $r['monthFc'] < $r['target']['fc'] ? 'rk-short' : 'rk-okc' }}">{{ $r['monthFc'] < $r['target']['fc'] ? 'あと'.($r['target']['fc'] - $r['monthFc']) : '達成' }}</span><br>
            D {{ $r['monthD'] }}/<b>{{ $r['target']['d'] }}</b>
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
      <tr>
        <td colspan="8" style="border-top:0; padding-top:0;">
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

<div class="rk-card">
  <h3>👟 FCに入れる案（{{ $month->format('n月') }}・今日から月末まで）</h3>
  <p class="sub">運営人数に空きがある案件に、その月のFC目標に届いていない新人を当てた案です。
    1案件に入れる新人は、ふつう1人・運営人数が8名を超える案件は2人・大型で12名以上なら空きの数まで。
    その日すでに入っている人・出勤可能日が×／希望休の人は入れません。まだFCでやっていないコンテンツ（必修・推奨を優先）・難易度が低い・イベプラを優先します。</p>
  @if ($unlinked)
    <p class="rk-warn">⚠ 台帳につながっていない難易度の行が{{ $unlinked }}件あります（下の「難易度表」でつなぐと、難易度・必修が案に効きます）。</p>
  @endif
  <div class="rk-wrap">
  <table class="rk">
    <tr><th>日付</th><th>案件</th><th>難易度</th><th>空き</th><th>新人</th><th>理由</th><th>ほかの候補</th></tr>
    @forelse ($picks as $p)
      <tr>
        <td class="rk-num">{{ \Illuminate\Support\Carbon::parse($p['date'])->format('n/j') }}</td>
        <td><a href="/project-assign?project={{ $p['projectId'] }}">{{ $p['name'] }}</a><div class="rk-small">{{ $p['client'] }}</div></td>
        <td class="rk-num">{{ $p['difficulty'] ?? '—' }}</td>
        <td class="rk-num">{{ $p['room'] }}名</td>
        <td><b>{{ $p['rookie'] }}</b></td>
        <td class="rk-small">{{ $p['why'] }}</td>
        <td class="rk-small">{{ implode('、', $p['others']) }}</td>
      </tr>
    @empty
      <tr><td colspan="7" class="rk-small">案はありません（空きのある案件が無い、または新人がこの月の目標に届いています）。</td></tr>
    @endforelse
  </table>
  </div>
</div>

<details class="rk-card rk-more">
  <summary>📋 月ごとの目標（何ヶ月目 → イベント数・うちD数）</summary>
  <p class="sub">シート「セールス新人イベントおよびD数」の値です。FCの目標＝イベント数 − D数。12ヶ月目より先は12ヶ月目の数を使います。</p>
  <form method="POST" action="/rookies/targets">@csrf
    <table class="rk" style="max-width:420px;">
      <tr><th>何ヶ月目</th><th>イベント数</th><th>うちD数</th></tr>
      @foreach ($targets as $m => $t)
        <tr><td>{{ $m }}ヶ月目</td>
          <td><input class="rk-in n" type="number" min="0" name="t[{{ $m }}][events]" value="{{ $t[0] }}" @disabled(! $canEdit)></td>
          <td><input class="rk-in n" type="number" min="0" name="t[{{ $m }}][d]" value="{{ $t[1] }}" @disabled(! $canEdit)></td></tr>
      @endforeach
    </table>
    @if ($canEdit)<button class="rk-btn main" type="submit" style="margin-top:8px;">保存</button>@endif
  </form>
</details>

<details class="rk-card rk-more" @if ($unlinked) open @endif>
  <summary>📚 難易度表（コンテンツ難易度シート）</summary>
  <p class="sub">スプレッドシート「コンテンツ難易度」のリアル・オンラインをそれぞれCSVで取り込みます（入れ直すと上書き）。
    名前が台帳と合わない行は、右のプルダウンで台帳のコンテンツを選んでつないでください。つながっていない行は使いません。</p>
  @if ($canEdit)
    <form class="rk-bar" method="POST" action="/rookies/import" enctype="multipart/form-data">@csrf
      <select class="rk-in" name="kind"><option>リアル</option><option>オンライン</option></select>
      <input type="file" name="file" accept=".csv,text/csv">
      <button class="rk-btn main" type="submit">取り込む</button>
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
<details class="rk-card rk-more">
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
  // ほかのボタン（OJT・経験など）は読み込み直すので、開いていた枠と元の位置に戻す。
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
