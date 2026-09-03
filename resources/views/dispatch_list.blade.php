@extends('layouts.app')
@section('title', '派遣一覧')
@section('h1', '派遣一覧')
@php($active = 'dispatch_list')

@push('head')
<style>
  .dl-intro { font-size: 13px; color: var(--muted); line-height: 1.8; margin-bottom: 14px; }
  .dl-bar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
  .dl-bar select, .dl-bar input { padding: 6px 9px; border: 1px solid var(--line); border-radius: 8px;
    font-size: 13px; font-family: inherit; background: #fff; }
  .dl-bar .lbl { font-size: 12px; color: var(--muted); font-weight: 600; }
  .dl-btn { padding: 6px 14px; border: 1px solid var(--brand-dark); border-radius: 8px; font-size: 13px;
    font-weight: 700; font-family: inherit; background: var(--brand); color: #fff; cursor: pointer; text-decoration: none; }
  .dl-btn.ghost { background: #fff; color: var(--brand-dark); }

  .dl-sum { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
  .dl-sum .card { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 8px 16px; min-width: 110px; }
  .dl-sum .num { font-size: 20px; font-weight: 800; color: var(--brand-dark); }
  .dl-sum .lbl { font-size: 11.5px; color: var(--muted); }

  table.dl { width: 100%; border-collapse: collapse; font-size: 13px; background: #fff; }
  table.dl th, table.dl td { padding: 7px 9px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
  table.dl th { background: #faf7f1; font-weight: 600; color: #6b5d4d; font-size: 11.5px; white-space: nowrap; }
  table.dl td.nw { white-space: nowrap; }
  table.dl tr.cancelled td { opacity: .55; }
  table.dl tr.cancelled .agency { text-decoration: line-through; }
  .dl-day { font-weight: 700; color: #6e5b49; }
  .dl-sub { font-size: 11px; color: var(--muted); }
  .st { display: inline-block; font-size: 10.5px; font-weight: 700; padding: 1px 8px; border-radius: 999px; white-space: nowrap; }
  .st.asked { background: var(--warn-soft); color: #8a5a10; }
  .st.fixed { background: var(--ok-soft); color: #166534; }
  .st.cancelled { background: #ece3d4; color: #7a6a58; }
  .fill { font-weight: 700; }
  .fill.short { color: #b91c1c; }
  .fill.full { color: #15803d; }
  .dl-in { width: 100%; min-width: 60px; padding: 4px 6px; border: 1px solid var(--line); border-radius: 6px;
    font-size: 12.5px; font-family: inherit; background: #fff; }
  .dl-in.n { width: 52px; text-align: right; }
  .dl-x { border: none; background: none; color: #c99; font-weight: 700; cursor: pointer; font-size: 14px; }
  .dl-empty { color: var(--muted); font-size: 13px; padding: 22px 4px; background: #fff;
    border: 1px solid var(--line); border-radius: 10px; }
  .dl-saved { color: #15803d; font-size: 11px; font-weight: 700; display: none; }
  .flash { background: var(--ok-soft); border: 1px solid #bbe3c6; color: #15803d;
    border-radius: 10px; padding: 10px 14px; font-size: 13px; font-weight: 700; margin-bottom: 14px; }

  @media (max-width: 720px) {
    .dl-wrap { overflow-x: auto; }
    table.dl { min-width: 860px; }
  }
</style>
@endpush

@section('content')
  @if (session('status'))
    <div class="flash">{{ session('status') }}</div>
  @endif

  <p class="dl-intro">
    <b>派遣を頼んだ案件の一覧</b>です。開催日の早い順に並びます。<br>
    入れる場所は <b><a href="/assign">日別ボード</a></b> の案件カードにある <b>「＋派遣」</b>です。
    ここでは<b>人数・役割・状態・備考</b>を直せます。
    @if ($officeScope)
      <br>※ いまは <b>{{ $officeScope }}</b> の案件だけを出しています。
    @endif
  </p>

  <form method="GET" action="/dispatch-list" class="dl-bar">
    <span class="lbl">状態：</span>
    <select name="status" onchange="this.form.submit()">
      <option value="">すべて</option>
      @foreach ($statuses as $st)
        <option value="{{ $st }}" @selected($statusFilter === $st)>{{ $st }}</option>
      @endforeach
    </select>
    <span class="lbl">期間：</span>
    {{-- ⚠ href の中にインラインで @if を書かないこと。文字のすぐ後ろ（…list@if）だと
         Blade が命令と見なさず「@if(」がそのまま画面に出て、その画面のJSが死ぬ。
         URLはコントローラで作って渡す。 --}}
    @if ($withPast)
      <span class="lbl">過去も全部</span>
      <a class="dl-btn ghost" href="{{ $urlFuture }}">これから（{{ $days }}日先まで）だけにする</a>
    @else
      <span class="lbl">これから（{{ $days }}日先まで）</span>
      <a class="dl-btn ghost" href="{{ $urlPast }}">過去も出す</a>
    @endif
  </form>

  {{-- ⚠ 数え上げは @php ブロックで書かない。展開されずに画面にそのまま出ることがある
       （この画面でも一度やった）。数はコントローラで作って渡す。 --}}
  <div class="dl-sum">
    <div class="card"><div class="num">{{ $sumRows }}</div><div class="lbl">依頼の件数</div></div>
    <div class="card"><div class="num">{{ $sumPeople }}</div><div class="lbl">頼んでいる人数（のべ）</div></div>
    <div class="card"><div class="num">{{ $sumProjects }}</div><div class="lbl">対象の案件</div></div>
    <div class="card"><div class="num">{{ $sumAsked }}</div><div class="lbl">まだ「依頼中」</div></div>
  </div>

  @if ($rows->isEmpty())
    <div class="dl-empty">
      この期間に、派遣を頼んだ記録はありません。<br>
      <b>入れ方</b>＝<a href="/assign">日別ボード</a>で案件カードの「<b>＋派遣</b>」を押し、派遣先の名前を入れてください。<br>
      ※ 過去の記録を見たいときは、上の「過去も出す」を押してください。
    </div>
  @else
    <div class="dl-wrap">
      <table class="dl">
        <thead>
          <tr>
            <th>開催日</th>
            <th>案件</th>
            <th>会場</th>
            <th>派遣先</th>
            <th>人数</th>
            <th>役割</th>
            <th>状態</th>
            <th>依頼日</th>
            <th>備考</th>
            <th>アサイン</th>
            @if ($canEdit)<th></th>@endif
          </tr>
        </thead>
        <tbody>
          @foreach ($rows as $r)
            <tr class="{{ $r['statusCls'] }}" data-id="{{ $r['id'] }}">
              <td class="nw"><span class="dl-day">{{ $r['dayLabel'] }}</span>
                @if ($r['dow'])<span class="dl-sub">（{{ $r['dow'] }}）</span>@endif
              </td>
              <td>
                <a href="/project-assign?project={{ urlencode($r['projectId']) }}">{{ $r['projectName'] }}</a>
                <div class="dl-sub">{{ $r['client'] }}@if ($r['contents'])／{{ $r['contents'] }}@endif</div>
              </td>
              <td>
                {{ $r['place'] !== '' ? $r['place'] : '—' }}
                @if ($r['assembly'])<div class="dl-sub">集合：{{ $r['assembly'] }}</div>@endif
              </td>
              <td class="agency">
                @if ($canEdit)
                  <input class="dl-in" value="{{ $r['agency'] }}" data-f="agency" onchange="dlSave(this)">
                @else
                  {{ $r['agency'] }}
                @endif
              </td>
              <td class="nw">
                @if ($canEdit)
                  <input class="dl-in n" type="number" min="1" max="99" value="{{ $r['count'] }}" data-f="count" onchange="dlSave(this)">
                @else
                  {{ $r['count'] }}名
                @endif
              </td>
              <td class="nw">
                @if ($canEdit)
                  <input class="dl-in" style="width:80px;" value="{{ $r['role'] }}" data-f="role" placeholder="例）受付" onchange="dlSave(this)">
                @else
                  {{ $r['role'] !== '' ? $r['role'] : '—' }}
                @endif
              </td>
              <td class="nw">
                @if ($canEdit)
                  <select class="dl-in" style="width:92px;" data-f="status" onchange="dlSave(this)">
                    @foreach ($statuses as $st)
                      <option value="{{ $st }}" @selected($r['status'] === $st)>{{ $st }}</option>
                    @endforeach
                  </select>
                @else
                  <span class="st {{ $r['statusCls'] }}">{{ $r['status'] }}</span>
                @endif
              </td>
              <td class="nw">
                @if ($canEdit)
                  <input class="dl-in" type="date" style="width:130px;" value="{{ $r['requestedOn'] }}" data-f="requested_on" onchange="dlSave(this)">
                @else
                  {{ $r['requestedOn'] !== '' ? $r['requestedOn'] : '—' }}
                @endif
              </td>
              <td>
                @if ($canEdit)
                  <input class="dl-in" value="{{ $r['note'] }}" data-f="note" placeholder="例）返事待ち" onchange="dlSave(this)">
                @else
                  {{ $r['note'] !== '' ? $r['note'] : '—' }}
                @endif
              </td>
              {{-- 案件の埋まり具合。⚠ 派遣を頼んでいるのに社員・スタッフも満員、が見えるようにする。 --}}
              <td class="nw">
                <span class="fill {{ $r['filled'] < $r['need'] ? 'short' : 'full' }}">{{ $r['filled'] }}/{{ $r['need'] ?: '—' }}</span>
              </td>
              @if ($canEdit)
                <td class="nw">
                  <span class="dl-saved">✓</span>
                  <button type="button" class="dl-x" title="この行を消す（頼んだ事実を残したいときは、状態を「キャンセル」にしてください）" onclick="dlDelete(this)">×</button>
                </td>
              @endif
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif

  <p class="dl-sub" style="margin-top:14px; line-height:1.9;">
    ※ <b>状態</b>＝<b>依頼中</b>（返事待ち）／<b>確定</b>（来てもらえる）／<b>キャンセル</b>。<br>
    ※ ⚠ <b>断られた・なくなったときは「キャンセル」にしてください。</b>「×」で消すと<b>頼んだ事実そのものが消える</b>ので、打ち間違いのときだけ使ってください。<br>
    ※ <b>アサイン</b>の列＝その案件の社員・スタッフの埋まり具合（<b>いま何人／運営人数</b>）。赤字はまだ足りていない案件です。
  </p>
@endsection

@push('scripts')
<script>
  window.ECS_CSRF = '{{ csrf_token() }}';
</script>
@verbatim
<script>
  // 派遣一覧の1マスぶんの保存（2026-09-03）。押した欄だけを送る。
  // ⚠ 送っていない欄まで空で上書きしないこと（他の画面で入れた内容が消える）。
  function dlRow(el){ return el.closest('tr'); }

  function dlSave(el){
    const tr = dlRow(el);
    if (!tr) return;
    const body = new URLSearchParams();
    body.append(el.dataset.f, el.value);
    fetch('/dispatches/' + encodeURIComponent(tr.dataset.id), {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF },
      body: body.toString()
    })
    .then(r => r.json().then(j => ({ ok: r.ok, j })))
    .then(({ ok, j }) => {
      // ⚠ 失敗をだまって流さない。「保存したつもりで消えている」がいちばん困る。
      if (!ok) { alert((j && j.message) || '保存できませんでした。'); return; }
      const mark = tr.querySelector('.dl-saved');
      if (mark) { mark.style.display = 'inline'; setTimeout(() => { mark.style.display = 'none'; }, 1500); }
      // 状態を変えたら、行の見た目（取り消し線）もその場でそろえる。
      if (el.dataset.f === 'status') {
        tr.className = (el.value === 'キャンセル') ? 'cancelled' : (el.value === '確定' ? 'fixed' : 'asked');
      }
    })
    .catch(() => alert('保存に失敗しました。通信を確認して、もう一度お試しください。'));
  }

  function dlDelete(btn){
    const tr = dlRow(btn);
    if (!tr) return;
    if (!confirm('この派遣依頼の記録を消します。\n\n断られた・なくなったときは、消すのではなく状態を「キャンセル」にしてください（頼んだ経緯が残ります）。\n\n打ち間違いのときだけ「OK」を押してください。')) return;
    fetch('/dispatches/' + encodeURIComponent(tr.dataset.id), {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF },
      body: '_method=DELETE'
    })
    .then(r => r.json().then(j => ({ ok: r.ok, j })))
    .then(({ ok, j }) => {
      if (!ok) { alert((j && j.message) || '消せませんでした。'); return; }
      location.reload();
    })
    .catch(() => alert('通信に失敗しました。もう一度お試しください。'));
  }
</script>
@endverbatim
@endpush
