{{-- マイページ：自分が担当営業の、Salesforceから届いた「取り込み待ち」の商談（2026-11-20 小沼さん）。
     置き場所＝「営業担当の案件」のパネルの中（見出し・説明のすぐ下、一覧の上）。
     ⚠ 月の切替には関係なくいつも出す（営業担当の案件の一覧は月ごとだが、届いた商談は月を変えても見落とさないように）。
     ⚠ どのヨミ（確度）も自動では登録しない。本人が中身を見て「案件として登録」か「見送り」を押したときだけ動く。
     ⚠ 押した先（/mypage/sf-inbox/…）でも本人あてかをサーバー側で確かめ直す（ここでボタンを出すだけでは門にしない）。
     ⚠ 登録の中身は受信箱と同じ App\Support\SfInboxRegister（二重に登録しない）。
     ⚠ 拠点のはじめの値は「押す本人の拠点」（$sfMyOffice・コントローラで決める）。SFの担当拠点は表に参考として出すだけ。
     取り込み待ちが0件で、押したあとの知らせも無いときは、何も出さない（関係ない人の画面を増やさない）。 --}}
@php($__sfRows = $sfMine ?? [])
@php($__myOffice = $sfMyOffice ?? null)
@include('partials.sf_inbox_js', ['scrollKey' => 'ecs_mypage_sf_scroll'])
@php($__sfSkipped = $sfSkipped ?? [])
@if (count($__sfRows) > 0 || count($__sfSkipped) > 0 || session('sf_status') || session('sf_error'))
<style>
  .sfm-box { border: 2px solid var(--brand, #2563eb); border-radius: 12px; margin: 4px 14px 14px; padding: 10px 0 2px; background: var(--brand-soft, transparent); }
  .sfm-box h3 { font-size: 14.5px; margin: 0 14px 4px; color: var(--ink); }
  .sfm-lead { font-size: 12px; color: var(--muted); margin: 0 14px 8px; line-height: 1.7; }
  .sfm-card { border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px; margin: 0 14px 10px; background: var(--panel); color: var(--ink); }
  .sfm-title { font-weight: 700; font-size: 14px; }
  .sfm-sub { font-size: 11.5px; color: var(--muted); }
  .sfm-tbl { width: 100%; border-collapse: collapse; font-size: 12.5px; margin: 6px 0; }
  .sfm-tbl th, .sfm-tbl td { border: 1px solid var(--line); padding: 4px 8px; text-align: left; vertical-align: top; }
  .sfm-tbl th { background: var(--surface-2); width: 110px; white-space: nowrap; font-size: 12px; }
  .sfm-red { color: var(--danger-ink, #b91c1c); font-weight: 700; }
  .sfm-warn { background: var(--warn-soft); color: var(--warn-ink); border-radius: 8px; padding: 6px 10px; font-size: 12px; margin: 6px 0; }
  .sfm-warn ul { margin: 0; padding-left: 18px; }
  .sfm-ops { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; font-size: 13px; }
  .sfm-ops select { padding: 4px 8px; border: 1px solid var(--line); border-radius: 6px; background: var(--panel); color: var(--ink); }
  .sfm-flash { margin: 0 14px 10px; border-radius: 8px; padding: 8px 12px; font-size: 13px; border: 1px solid var(--line); }
  .sfm-flash.ok { background: var(--ok-soft); color: var(--ok-ink); }
  .sfm-flash.err { background: var(--danger-soft); color: var(--danger-ink, #b91c1c); font-weight: 700; }
</style>
<div class="sfm-box" id="sfMine">
  <h3>Salesforceから届いた商談（{{ count($__sfRows) }}件）</h3>
  <p class="sfm-lead">あなたが担当営業の商談です（月の切替に関係なく出ます）。中身を見て、ECSの案件にするなら<b>「案件として登録」</b>、登録しないなら<b>「見送り」</b>を押してください。押すまでECSの案件は変わりません。見送った商談は消えずに下に残り、あとで戻せます。</p>

  @if (session('sf_status'))
    <div class="sfm-flash ok">{{ session('sf_status') }}@if (session('sf_project_id')) <a href="/project-form?project={{ urlencode(session('sf_project_id')) }}" style="font-weight:700; margin-left:6px;">→ 編集画面を開く</a>@endif</div>
  @endif
  @if (session('sf_error'))
    <div class="sfm-flash err">{{ session('sf_error') }}</div>
  @endif

  @foreach ($__sfRows as $row)
    @php($item = $row['item'])
    @php($p = $row['p'])
    <div class="sfm-card">
      <div class="sfm-title">@include('partials.sf_yomi_badge', ['yomi' => $p['yomi'] ?? null]) {{ $p['sf_name'] ?? '（商談名なし）' }}</div>
      <div class="sfm-sub">商談Id {{ $item->sf_opportunity_id }}／受け取り {{ optional($item->received_at)->format('Y-m-d H:i') ?? '—' }}@if ($p && $p['stage'])／フェーズ {{ $p['stage'] }}@endif @if ($p && $p['yomi'])／確度 {{ $p['yomi'] }}@endif</div>

      @if (! $p)
        <p class="sfm-red">この商談の中身が読めませんでした。Salesforceの担当に確認してください。</p>
      @else
        @if ($p['warnings'])
          <div class="sfm-warn"><ul>
            @foreach ($p['warnings'] as $w)<li>{{ $w }}</li>@endforeach
          </ul></div>
        @endif
        <table class="sfm-tbl">
          <tr><th>開催日</th><td>{!! $p['date'] ? e($p['date']) : '<span class="sfm-red">空欄（未定）</span>' !!}@if ($p['days'] && $p['days'] >= 2) <span class="sfm-red">（{{ $p['days'] }}日間）</span>@endif</td></tr>
          <tr><th>お客様名</th><td>{{ $p['client'] ?? '空欄' }}@if ($p['agency'])<span class="sfm-sub">（代理店：{{ $p['agency'] }}）</span>@endif</td></tr>
          <tr><th>拠点</th><td>
            @if ($p['office'])
              {{ $p['office'] }} <span class="sfm-sub">（Salesforceの担当拠点・参考）</span>
            @else
              <span class="sfm-red">要確認</span> <span class="sfm-sub">（Salesforce：{{ $p['office_raw'] ?? '空欄' }}）</span>
            @endif
          </td></tr>
          <tr><th>参加人数</th><td>{{ $p['guest_count'] !== null ? $p['guest_count'].'名' : '空欄' }}</td></tr>
          <tr><th>運営人数</th><td>{{ $p['staff_count'] !== null ? $p['staff_count'].'名' : '空欄（登録すると「人数は仮（未定）」になります）' }}</td></tr>
          <tr><th>コンテンツ</th><td>
            @foreach ($p['contents'] as $c){{ $c['content_name'] }}　@endforeach
            @foreach ($p['unmatched'] as $u)<span class="sfm-red">未対応：{{ $u['name'] }}</span>　@endforeach
            @if (! $p['contents'] && ! $p['unmatched'])なし@endif
          </td></tr>
          <tr><th>会場</th><td>{{ $p['location'] ?? '—' }}</td></tr>
          @include('partials.sf_opp_more', ['p' => $p])
        </table>
      @endif

      <div class="sfm-ops">
        <form method="POST" action="/mypage/sf-inbox/{{ $item->id }}/register" class="sfm-ops" data-sfx-keep-scroll>
          @csrf
          {{-- はじめは押す本人の拠点。選び直せる。本人の拠点が分からないときだけ「選んでください」＋必須。 --}}
          <label>拠点
            <select name="office" @if (! $__myOffice) required @endif>
              @if (! $__myOffice)<option value="">選んでください</option>@endif
              @foreach (($sfOffices ?? []) as $o)
                <option value="{{ $o }}" @selected($__myOffice === $o)>{{ $o }}</option>
              @endforeach
            </select>
          </label>
          <span class="sfx-confirm">
            <button class="btn sm primary" type="submit" data-sfx-ask>案件として登録</button>
            <span class="sfx-ask" hidden>登録しますか？ <button class="btn sm primary" type="submit" data-sfx-yes>はい</button><button class="btn sm" type="button" data-sfx-no>やめる</button></span>
          </span>
        </form>
        <form method="POST" action="/mypage/sf-inbox/{{ $item->id }}/skip" data-sfx-keep-scroll>
          @csrf
          <button class="btn sm" type="submit">見送り</button>
        </form>
      </div>
    </div>
  @endforeach

  {{-- 本人が見送った商談（2026-11-20 小沼さん）。ふだんは畳んでおき、開くと「戻す」が押せる。
       戻すと上の「取り込み待ち」にまた出る。登録ずみは出さない（戻すと案件が2つできるため）。 --}}
  @if (count($__sfSkipped) > 0)
    <details style="margin:0 14px 10px; font-size:12.5px; color:var(--ink);">
      <summary style="cursor:pointer; color:var(--muted);">見送った商談（{{ count($__sfSkipped) }}件）</summary>
      @foreach ($__sfSkipped as $s)
        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; padding:6px 0; border-bottom:1px solid var(--line);">
          <span style="flex:1; min-width:180px;">{{ $s['name'] }}<span class="sfm-sub">　開催日 {{ $s['date'] ?? '未定' }}</span></span>
          <form method="POST" action="/mypage/sf-inbox/{{ $s['item']->id }}/reopen" style="margin:0;">
            @csrf
            <button class="btn sm" type="submit">取り込み待ちに戻す</button>
          </form>
        </div>
      @endforeach
    </details>
  @endif
</div>
@endif
