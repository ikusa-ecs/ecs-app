@extends('layouts.app')
@section('title', '配信・中継案件一覧')
@section('h1', '配信・中継案件一覧')
@php($active = 'broadcast_list')

@push('head')
{{-- 保存に使う合言葉。⚠ この画面のJSから読む（layouts.app に meta タグは無い）。 --}}
<script>window.ECS_CSRF = '{{ csrf_token() }}';</script>
<style>
  .bc-intro { font-size: 13px; color: var(--muted); line-height: 1.8; margin-bottom: 14px; }
  .bc-sum { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
  .bc-sum .card { background: var(--panel, #fff); border: 1px solid var(--line); border-radius: 10px; padding: 8px 16px; min-width: 130px; }
  .bc-sum .num { font-size: 20px; font-weight: 800; color: var(--brand-dark); }
  .bc-sum .num.warn { color: var(--danger-ink, #b91c1c); }
  .bc-sum .lbl { font-size: 11.5px; color: var(--muted); }

  .bc-bar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
  .bc-bar .lbl { font-size: 12px; color: var(--muted); font-weight: 600; }
  .bc-btn { padding: 6px 14px; border: 1px solid var(--brand-dark); border-radius: 8px; font-size: 13px;
    font-weight: 700; font-family: inherit; background: var(--panel, #fff); color: var(--brand-dark);
    cursor: pointer; text-decoration: none; }

  .bc-wrap { overflow-x: auto; }
  table.bc { width: 100%; border-collapse: collapse; font-size: 13px; background: var(--panel, #fff); }
  table.bc th, table.bc td { padding: 7px 9px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
  table.bc th { background: var(--th-bg, #faf7f1); font-weight: 600; color: var(--muted); font-size: 11.5px; white-space: nowrap; }
  table.bc td.nw { white-space: nowrap; }
  table.bc tr.cancelled td { opacity: .55; }
  .bc-day { font-weight: 700; color: var(--ink); }
  .bc-sub { font-size: 11px; color: var(--muted); }

  .kind { display: inline-block; font-size: 10.5px; font-weight: 800; padding: 1px 8px; border-radius: 999px; white-space: nowrap; }
  .kind.haishin { background: #e3edf7; color: #2c6ca0; }
  .kind.chukei  { background: #efe6f6; color: #6d28d9; }
  .kind.arena   { background: #fdf3e3; color: #92600a; }

  .bc-owner select, .bc-owner input { padding: 5px 8px; border: 1px solid var(--line); border-radius: 8px;
    font-size: 12.5px; font-family: inherit; background: var(--panel, #fff); color: var(--ink); }
  .bc-owner select { max-width: 190px; }
  .bc-owner input { max-width: 170px; }
  .bc-owner .row2 { margin-top: 4px; }
  .bc-none { display: inline-block; font-size: 10.5px; font-weight: 800; padding: 1px 8px; border-radius: 999px;
    background: #fde8e8; color: #b91c1c; }
  html[data-theme="dark"] .bc-none { background: var(--danger-soft); color: var(--danger-ink); }
  .bc-saved { font-size: 11px; color: #166534; font-weight: 700; margin-left: 6px; }
  .bc-failed { font-size: 11px; color: #b91c1c; font-weight: 700; margin-left: 6px; }

  .bc-empty { background: var(--panel, #fff); border: 1px dashed var(--line); border-radius: 10px;
    padding: 20px; font-size: 13px; color: var(--muted); line-height: 1.9; }
</style>
@endpush

@section('content')
  <p class="bc-intro">
    <b>配信・中継のある案件だけ</b>を、開催日の早い順に並べています。<br>
    出るのは、案件登録の<b>「配信種別」が「配信」か「中継」</b>の案件と、
    <b>ARENA場所貸しで「配信・中継＝あり」</b>にした案件です。<br>
    <b>配信担当</b>はこの画面で決められます（<b>選んだその場で保存</b>されます。保存ボタンはありません）。
    社員は<b>全拠点</b>から選べます。名簿にいない相手は<b>外部業者</b>の欄に名前を書いてください。
    @if ($officeScope)
      <br>※ いまは <b>{{ $officeScope }}</b> の案件だけを出しています。
    @endif
  </p>

  @include('partials.office_switch')

  <div class="bc-sum">
    <div class="card"><div class="num">{{ $sumRows }}</div><div class="lbl">配信・中継の案件</div></div>
    <div class="card"><div class="num {{ $sumNoOwner > 0 ? 'warn' : '' }}">{{ $sumNoOwner }}</div><div class="lbl">配信担当がまだ未定</div></div>
  </div>

  {{-- ⚠ href の中にインラインで @if を書かないこと。文字のすぐ後ろだと Blade が命令と見なさず
       「@if(」がそのまま画面に出て、その画面のJSが死ぬ。URLはコントローラで作って渡している。 --}}
  <div class="bc-bar">
    @if ($withPast)
      <span class="lbl">過去も全部</span>
      <a class="bc-btn" href="{{ $urlFuture }}">これから（{{ $days }}日先まで）だけにする</a>
    @else
      <span class="lbl">これから（{{ $days }}日先まで）</span>
      <a class="bc-btn" href="{{ $urlPast }}">過去も出す</a>
    @endif
  </div>

  @if ($rows->isEmpty())
    <div class="bc-empty">
      この期間に、配信・中継のある案件はありません。<br>
      <b>出し方</b>＝<a href="/project-form">案件登録</a>（または案件の編集）で
      <b>「配信種別」を「配信」か「中継」</b>にしてください。
      ARENA場所貸しの案件は<b>「配信・中継」を「あり」</b>にします。<br>
      ※ 終わった案件を見たいときは、上の「過去も出す」を押してください。
    </div>
  @else
    <div class="bc-wrap">
      <table class="bc">
        <thead>
          <tr>
            <th>開催日</th>
            <th>種別</th>
            <th>案件</th>
            <th>会場</th>
            <th>時間</th>
            <th>D</th>
            <th>配信担当</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($rows as $r)
            <tr class="{{ $r['cancelled'] ? 'cancelled' : '' }}">
              <td class="nw">
                <span class="bc-day">{{ $r['dayLabel'] }}</span>
                <span class="bc-sub">{{ $r['dowLabel'] }}</span>
                @if ($r['draft'])<div class="bc-sub">下書き</div>@endif
                @if ($r['cancelled'])<div class="bc-sub">キャンセル</div>@endif
              </td>
              <td class="nw">
                @if ($r['kind'] === '配信')
                  <span class="kind haishin">配信</span>
                @elseif ($r['kind'] === '中継')
                  <span class="kind chukei">中継</span>
                @else
                  <span class="kind arena">配信・中継</span>
                @endif
                @if ($r['isArena'])<div class="bc-sub">ARENA場所貸し</div>@endif
                @if ($r['tool'] !== '')<div class="bc-sub">{{ $r['tool'] }}</div>@endif
              </td>
              <td>
                <a href="/project-assign?project={{ urlencode($r['id']) }}">{{ $r['content'] }}</a>
                @if ($r['client'] !== '')<div class="bc-sub">{{ $r['client'] }}</div>@endif
                @if ($officeScope === null && $r['office'] !== '')<div class="bc-sub">{{ $r['office'] }}</div>@endif
              </td>
              <td>{{ $r['place'] !== '' ? $r['place'] : '—' }}</td>
              <td class="nw">
                {{ $r['meet'] !== '' ? $r['meet'] : '—' }}〜{{ $r['leave'] !== '' ? $r['leave'] : '—' }}
                @if ($r['evStart'] !== '' || $r['evEnd'] !== '')
                  <div class="bc-sub">本番 {{ $r['evStart'] !== '' ? $r['evStart'] : '—' }}〜{{ $r['evEnd'] !== '' ? $r['evEnd'] : '—' }}</div>
                @endif
              </td>
              <td class="nw">{{ $r['director'] !== '' ? $r['director'] : '未定' }}</td>
              <td class="bc-owner">
                @if ($canEdit)
                  <select onchange="ecsBcSave('{{ $r['id'] }}', 'broadcast_owner_id', this.value, this)">
                    <option value="">（社員：未定）</option>
                    @foreach ($employees as $e)
                      <option value="{{ $e['id'] }}" @selected($r['ownerId'] === $e['id'])>{{ $e['label'] }}</option>
                    @endforeach
                  </select>
                  <div class="row2">
                    <input type="text" value="{{ $r['outside'] }}" placeholder="外部業者（任意）"
                           onchange="ecsBcSave('{{ $r['id'] }}', 'broadcast_owner_name', this.value, this)">
                  </div>
                  @if ($r['ownerId'] === '' && $r['outside'] === '')
                    <div class="row2"><span class="bc-none">担当 未定</span></div>
                  @endif
                @else
                  {{ $r['ownerName'] !== '' ? $r['ownerName'] : '' }}
                  @if ($r['outside'] !== '')<div class="bc-sub">{{ $r['outside'] }}</div>@endif
                  @if ($r['ownerName'] === '' && $r['outside'] === '')<span class="bc-none">担当 未定</span>@endif
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif
@endsection

@push('scripts')
<script>
  // 配信担当の保存（2026-09-18・FB No.21）。
  // ⚠ 保存の入口は案件一覧と同じ POST /projects/cells の1か所（拠点チェックもそこで通る）。
  // ⚠ 押した（選んだ）その場で保存する＝保存ボタンは無い。D決めと同じ考え方
  //    （押し忘れで決めた担当が消える事故を2回やっているため）。
  function ecsBcSave(projectId, field, value, el) {
    const body = { id: projectId };
    body[field] = value;

    fetch('/projects/cells', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': window.ECS_CSRF,
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify(body)
    })
      .then(function (res) { return res.ok ? res.json() : Promise.reject(res.status); })
      .then(function (json) {
        if (!json || !json.ok) { return Promise.reject('ng'); }
        ecsBcFlash(el, '保存しました', 'bc-saved');
      })
      .catch(function () {
        // ⚠ 失敗を黙らない。黙ると「保存したつもり」で担当が空のまま当日を迎える。
        ecsBcFlash(el, '保存できていません（もう一度選び直してください）', 'bc-failed');
      });
  }

  function ecsBcFlash(el, text, cls) {
    const cell = el.closest('td');
    if (!cell) { return; }
    const old = cell.querySelector('.bc-saved, .bc-failed');
    if (old) { old.remove(); }
    const tag = document.createElement('span');
    tag.className = cls;
    tag.textContent = text;
    cell.appendChild(tag);
    if (cls === 'bc-saved') {
      setTimeout(function () { tag.remove(); }, 2500);
    }
  }
</script>
@endpush
