{{-- エントリー新着（来た順）の中身。
     2026-09-10：サイドメニューが長くなったので、独立画面 `/entry-feed` から
     **エントリー一覧（/entries）の4つ目のタブ**へ引っ越した（baba要望）。
     中身の作り方（数え方・並び）の正本は `App\Support\EntryFeed`。ここは見た目だけ。
     ⚠ 期間・絞り込みのリンクには必ず `view=feed` を付ける（付けないと押した先で
        「案件ごと」のタブに戻ってしまう）。 --}}
@push('head')
<style>
    .ef-intro { font-size: 12.5px; color: var(--muted, #8a7a6b); margin: 0 0 12px; line-height: 1.7; }
    .ef-filter {
      display: flex; flex-wrap: wrap; gap: 10px; align-items: center;
      background: var(--panel, #fff); border: 1px solid var(--line, #e6d8c8); border-radius: 10px;
      padding: 10px 12px; margin-bottom: 12px;
    }
    .ef-filter a.chip {
      text-decoration: none; font-size: 13px; font-weight: 600; color: var(--ink, #2c2018);
      background: var(--panel, #fff); border: 1px solid #d8c4ae; border-radius: 999px; padding: 5px 13px;
    }
    .ef-filter a.chip.on { background: var(--brand, #8a5a33); color: #fff; border-color: var(--brand, #8a5a33); }
    .ef-filter .lbl { font-size: 12px; font-weight: 700; color: var(--muted, #8a7a6b); }

    .ef-sum { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
    .ef-sum .card {
      background: var(--panel, #fff); border: 1px solid var(--line, #e6d8c8); border-radius: 10px; padding: 8px 14px; min-width: 120px;
    }
    .ef-sum .card .n { font-size: 20px; font-weight: 800; }
    .ef-sum .card .t { font-size: 11.5px; color: var(--muted, #8a7a6b); }

    table.ef { border-collapse: collapse; width: 100%; background: var(--panel, #fff); }
    table.ef th, table.ef td {
      border-bottom: 1px solid var(--line, #e6d8c8); padding: 9px 10px; text-align: left; font-size: 13px; vertical-align: top;
    }
    table.ef th { background: #faf6ee; font-size: 12px; color: var(--muted, #8a7a6b); white-space: nowrap; }
    table.ef td.when { white-space: nowrap; font-variant-numeric: tabular-nums; color: #6e5b49; }
    table.ef td.name strong { font-size: 14px; }
    table.ef tr.is-new td { background: #fffbf0; }
    .ef .tag { display: inline-block; font-size: 10.5px; font-weight: 700; padding: 1px 8px; border-radius: 999px; white-space: nowrap; margin-left: 4px; }
    .ef .tag.new    { background: #fde68a; color: #7a4a00; }
    .ef .tag.extra  { background: #fde8e8; color: #b91c1c; }
    .ef .tag.fix    { background: #e7f6ec; color: #166534; }
    .ef .tag.tmp    { background: #eef0f2; color: #6b7280; }
    .ef .tag.todo   { background: #fdf3e2; color: #8a5a10; }
    .ef .tag.many   { background: #eef2ff; color: #3730a3; }
    /* その日の稼働希望（2026-09-03 baba要望）。⚠ NG は食い違いなので赤く目立たせる。 */
    .ef .tag.ok     { background: #e7f6ec; color: #166534; }
    .ef .tag.ng     { background: #fdecec; color: #b91c1c; }
    .ef .tag.none   { background: transparent; color: #b9b0a4; font-weight: 400; }
    .ef .wish   { white-space: nowrap; }
    .ef-note { color: #6e5b49; font-size: 12px; }
    .ef-empty { color: var(--muted, #8a7a6b); padding: 26px 0; text-align: center; }
    .ef-link { font-size: 12px; white-space: nowrap; }

    /* ===== 黒ベース（ダークモード）のときの読みやすさ調整 =====
       白い面と茶色の文字が黒地に残ると読めないので、ここで暗い面＋明るい文字に置き換える。
       色の意味（緑＝OK／赤＝NG など）は変えない。 */
    html[data-theme="dark"] .ef-filter a.chip { border-color: var(--field-line); }
    html[data-theme="dark"] .ef-filter a.chip.on { background: var(--brand-fill); color: #ffffff; border-color: var(--brand); }
    html[data-theme="dark"] table.ef th { background: var(--surface-2); }
    html[data-theme="dark"] table.ef td.when { color: var(--muted); }
    html[data-theme="dark"] table.ef tr.is-new td { background: #2a2418; }
    html[data-theme="dark"] .ef .tag.new   { background: var(--warn-soft); color: var(--warn-ink); }
    html[data-theme="dark"] .ef .tag.extra { background: var(--danger-soft); color: var(--danger-ink); }
    html[data-theme="dark"] .ef .tag.fix   { background: var(--ok-soft); color: var(--ok-ink); }
    html[data-theme="dark"] .ef .tag.tmp   { background: var(--chip-bg); color: var(--chip-ink); }
    html[data-theme="dark"] .ef .tag.todo  { background: var(--warn-soft); color: var(--warn-ink); }
    html[data-theme="dark"] .ef .tag.many  { background: var(--info-soft); color: var(--info-ink); }
    html[data-theme="dark"] .ef .tag.ok    { background: var(--ok-soft); color: var(--ok-ink); }
    html[data-theme="dark"] .ef .tag.ng    { background: var(--danger-soft); color: var(--danger-ink); }
    html[data-theme="dark"] .ef .tag.none  { color: var(--muted-dim); }
    html[data-theme="dark"] .ef-note { color: var(--muted); }
</style>
@endpush

<p class="ef-intro">
  スタッフから届いた<b>エントリー（応募）を、来た順（新しいものが上）</b>に並べています。<br>
  追加案件を出したあとに<b>誰から手が挙がったか</b>、<b>新しく入った方がどの案件に応募してくれたか</b>が分かります。<br>
  ここは<b>見るだけ</b>です。実際に入れるときは右の「アサインへ」から案件のアサイン画面へ進んでください。
</p>

<div class="ef-filter">
  <span class="lbl">期間</span>
  @foreach (\App\Support\EntryFeed::DAY_OPTIONS as $d => $label)
    <a class="chip {{ $feedDays === $d ? 'on' : '' }}"
       href="{{ request()->fullUrlWithQuery(['days' => $d, 'view' => 'feed']) }}">{{ $label }}</a>
  @endforeach
  <span class="lbl" style="margin-left:8px;">絞り込み</span>
  <a class="chip {{ $feedOnlyExtra ? 'on' : '' }}"
     href="{{ request()->fullUrlWithQuery(['extra' => $feedOnlyExtra ? null : 1, 'view' => 'feed']) }}">🔥 追加案件のみ</a>
  <a class="chip {{ $feedOnlyNew ? 'on' : '' }}"
     href="{{ request()->fullUrlWithQuery(['new' => $feedOnlyNew ? null : 1, 'view' => 'feed']) }}">🌱 新人のみ</a>
</div>

<div class="ef-sum">
  <div class="card"><div class="n">{{ count($feedRows) }}</div><div class="t">エントリー件数</div></div>
  <div class="card"><div class="n">{{ $feedNewCount }}</div><div class="t">うち新人（入社1年未満）</div></div>
  <div class="card"><div class="n">{{ $feedTodoCount }}</div><div class="t">まだアサインしていない</div></div>
</div>

<div class="panel" style="padding:0; overflow-x:auto;">
  <table class="ef">
    <thead>
      <tr>
        <th>届いた日時</th>
        <th>スタッフ</th>
        <th>その日の希望</th>
        <th>エントリー先の案件</th>
        <th>本人の一言</th>
        <th>状態</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      @forelse ($feedRows as $r)
        <tr class="{{ $r['isNew'] ? 'is-new' : '' }}">
          <td class="when">{{ $r['whenLabel'] }}</td>
          <td class="name">
            <strong>{{ $r['staffName'] }}</strong>
            @if ($r['isNew'])<span class="tag new" title="入社1年未満">🌱 新人</span>@endif
            @if ($r['entryCount'] >= 3)<span class="tag many" title="この期間で {{ $r['entryCount'] }} 件エントリーしています">{{ $r['entryCount'] }}件</span>@endif
            <div class="ef-note">{{ $r['staffId'] }}／{{ $r['level'] }}</div>
          </td>
          {{-- その日の稼働希望カレンダー（2026-09-03 baba要望）。
               ⚠ エントリー（応募）と稼働希望は**別の入力**。両方見ないと、
                 手は挙げたのにカレンダーはNG、という食い違いに気づけない。 --}}
          <td class="wish">
            @if ($r['wish'] === 'ok')
              <span class="tag ok" title="稼働希望カレンダーで、この日を終日〇にしています">終日〇</span>
            @elseif ($r['wish'] === 'ng')
              <span class="tag ng" title="⚠ エントリーはありますが、稼働希望カレンダーではこの日をNG（または希望休）にしています">NG</span>
            @else
              <span class="tag none" title="この日の稼働希望を出していません（未定・未提出）">—</span>
            @endif
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
        <tr><td colspan="7" class="ef-empty">この条件に合うエントリーはありません。</td></tr>
      @endforelse
    </tbody>
  </table>
</div>

<p class="ef-intro" style="margin-top:12px;">
  ※ 「新人」は<b>入社日から1年未満</b>の方です（入社日が未登録の方は判定できないため付きません）。<br>
  ※ 「◯件」は、この期間にその方が出したエントリーの件数です（3件以上のときに出ます）。<br>
  ※ エントリーは本人が手を挙げた記録なので、<b>他拠点のスタッフからのエントリーも表示します</b>（案件は拠点で絞られます）。
</p>
