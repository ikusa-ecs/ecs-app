@extends('layouts.app')
@section('title', '謎解きの紙 在庫')
@section('h1', '謎解きの紙 在庫')
@php($active = 'paper_stock')

@push('head')
<style>
  .ps-intro { font-size: 13px; color: var(--muted); line-height: 1.8; margin-bottom: 14px; }
  .ps-card { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 14px 16px; margin-bottom: 16px; }
  .ps-card h3 { margin: 0 0 4px; font-size: 14px; }
  .ps-card .sub { font-size: 12px; color: var(--muted); margin: 0 0 10px; }

  table.ps { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.ps th, table.ps td { padding: 8px 10px; border-bottom: 1px solid var(--line); text-align: right; white-space: nowrap; }
  table.ps th { background: #faf7f1; font-weight: 600; color: #6b5d4d; text-align: right; }
  table.ps th.l, table.ps td.l { text-align: left; }
  table.ps tfoot td { font-weight: 700; background: #faf7f1; border-top: 2px solid #e3d3b6; }
  .ps-recv { width: 84px; padding: 6px 8px; border: 1px solid var(--line); border-radius: 7px;
             font-size: 13px; font-family: inherit; text-align: right; background: #fff9ec; }
  td.short { color: #b91c1c; font-weight: 700; }
  td.ok { color: #15803d; }
  .ps-actions { margin-top: 12px; display: flex; gap: 10px; align-items: center; }
  .ps-btn { padding: 9px 18px; border: 1px solid var(--brand-dark); border-radius: 8px; font-size: 13.5px;
            font-weight: 700; font-family: inherit; background: var(--brand-fill); color: #fff; cursor: pointer; }
  .ps-note { font-size: 11.5px; color: var(--muted); margin-top: 8px; line-height: 1.7; }
  .ps-empty { color: var(--muted); font-size: 13px; padding: 18px 4px; }
  .est { color: #b45309; font-size: 11px; }
  .flash { background: var(--ok-soft); border: 1px solid #bbe3c6; color: #15803d;
           border-radius: 10px; padding: 10px 14px; font-size: 13px; font-weight: 700; margin-bottom: 14px; }
  .tag-future { font-size: 11px; padding: 1px 8px; border-radius: 999px; background: var(--warn-soft); color: #b45309; font-weight: 600; }
  .tag-past { font-size: 11px; padding: 1px 8px; border-radius: 999px; background: #ece3d4; color: #7a6a58; }
  /* 120部を切った在庫（2026-10-15）。チャットワークに知らせるのと同じ基準。 */
  .tag-low { font-size: 11px; padding: 1px 8px; border-radius: 999px; background: #fde2e2; color: #b91c1c; font-weight: 700; margin-left: 4px; }
  td.none { color: var(--muted); }
  .ps-filter { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 0 0 10px; font-size: 13px; }
  .ps-filter select { padding: 5px 8px; border: 1px solid var(--line); border-radius: 7px; font-family: inherit; font-size: 13px; }

  /* =====================================================================
     スマホ幅（720px以下）だけに効く指定。PCの見た目は一切変わりません。
     この画面の表は table.ps で、共通CSSの表対応（table.tbl）が効かないため
     ここで面倒を見ます。
     ===================================================================== */
  @media (max-width: 720px) {
    /* カードの余白を詰めて、狭い画面でも表に幅を回す */
    .ps-card { padding: 12px; border-radius: 10px; }
    .ps-intro { font-size: 12.5px; }

    /* 文字と余白を少し小さくして、横スクロールの距離を短くする */
    table.ps { font-size: 12.5px; }
    table.ps th, table.ps td { padding: 7px 8px; }

    /* 数字の列は折り返すと桁がバラけて読めなくなるので nowrap のまま。
       そのぶん表は画面より横に長くなるが、表を囲った .table-scroll のおかげで
       ページ全体ではなく「表の中だけ」を指でなぞって見る形になる。 */

    /* コンテンツ名（1列目）だけは折り返してよい。
       名前は長く、nowrap のままだと横スクロールの距離が極端に伸びるため。
       幅の下限・上限を決めて、1文字ずつ縦に割れるのを防ぐ。 */
    table.ps.ps-sticky th.l:first-child,
    table.ps.ps-sticky td.l:first-child {
      white-space: normal;
      min-width: 8.5em;
      max-width: 12em;
      line-height: 1.5;
    }

    /* 罫線の引き方を「セルごと」に切り替える。
       まとめ引き（border-collapse: collapse）のままだと、次で列を left:0 に貼り付けたとき
       罫線だけが取り残されて消えるブラウザがあるため。見た目は変わらない。 */
    table.ps.ps-sticky { border-collapse: separate; border-spacing: 0; }
    /* セルごとに引くと、合計行のある表だけ「最終行の下線」と「合計行の上線」が二重に出る。
       下線側を消して1本にする（合計行が無い表は下線を残したいので対象外）。 */
    table.ps.ps-sticky tbody:has(+ tfoot) tr:last-child td { border-bottom: 0; }

    /* 横になぞっている間も「今どの行を見ているか」が分かるよう、
       コンテンツ名の列は左端に貼り付けたまま残す。
       貼り付ける列は、下を流れる列が透けないよう背景色を必ず指定する。
       右の区切り線は box-shadow で描く（罫線だと表側に持っていかれることがあるため）。 */
    table.ps.ps-sticky th.l:first-child,
    table.ps.ps-sticky td.l:first-child {
      position: sticky;
      left: 0;
      z-index: 2;
      background: #fff;
      box-shadow: 1px 0 0 var(--line);
    }
    table.ps.ps-sticky thead th.l:first-child,
    table.ps.ps-sticky tfoot td.l:first-child { background: #faf7f1; }

    /* 入庫数の入力欄。16px未満だと iPhone が入力時に勝手に画面を拡大するため 16px。
       高さも指で押せる大きさにする。 */
    .ps-recv {
      width: 5.5em;
      min-height: 40px;
      padding: 8px 9px;
      font-size: 16px;
    }

    /* 保存ボタンは横並びが入りきらないので折り返し、押しやすいよう幅いっぱいにする */
    .ps-actions { flex-wrap: wrap; gap: 8px; }
    .ps-btn { width: 100%; padding: 12px 18px; font-size: 14px; }
  }

  /* ここから下は「黒ベース（ダークモード）」のときだけ効く上書き。
     上の色は白地むけに直接書いてあるので、黒地だと白いカード・薄茶の表見出し・
     生成りの入力欄が読みにくい。意味の色（不足＝赤／足りている＝緑／推定＝橙）は残す。 */
  html[data-theme="dark"] .ps-card { background: var(--panel); }
  html[data-theme="dark"] table.ps th { background: var(--surface-2); color: var(--muted); }
  html[data-theme="dark"] table.ps tfoot td { background: var(--surface-2); border-top-color: var(--line); }
  html[data-theme="dark"] .ps-recv { background: var(--surface-2); color: var(--ink); border-color: var(--field-line); }
  html[data-theme="dark"] td.short { color: var(--danger-ink); }
  html[data-theme="dark"] td.ok { color: var(--ok-ink); }
  html[data-theme="dark"] .est { color: var(--warn-ink); }
  html[data-theme="dark"] .flash { background: var(--ok-soft); border-color: var(--ok-line); color: var(--ok-ink); }
  html[data-theme="dark"] .tag-future { color: var(--warn-ink); }
  html[data-theme="dark"] .tag-past { background: var(--chip-bg); color: var(--chip-ink); }
  /* スマホで左に貼り付く1列目（下の行が透けないよう背景色が要る） */
  html[data-theme="dark"] table.ps.ps-sticky th.l:first-child,
  html[data-theme="dark"] table.ps.ps-sticky td.l:first-child { background: var(--panel); }
  html[data-theme="dark"] table.ps.ps-sticky thead th.l:first-child,
  html[data-theme="dark"] table.ps.ps-sticky tfoot td.l:first-child { background: var(--surface-2); }
</style>
@endpush

@section('content')

@if (session('status'))
  <div class="flash">✓ {{ session('status') }}</div>
@endif

<div class="ps-intro">
  謎解き系コンテンツの <strong>紙（謎解きシート）</strong> の在庫を、案件データから自動で集計します。<br>
  <strong>必要数(今後)</strong>＝これから開催する分・<strong>消費数</strong>＝開催済みの分（どちらも自動）／
  <strong>入庫数</strong>＝印刷して用意した枚数（手入力）。<strong>在庫＝入庫−消費</strong>、<strong>過不足＝在庫−必要数(今後)</strong>。<br>
  対象にするコンテンツは <a href="/masters#contents">マスタ管理</a> の「紙」で切り替えます。チーム数が未入力の案件は、お客様人数÷{{ $teamSize }}で推定します。<br>
  <strong>在庫は拠点ごと</strong>です。巻き取られた案件は巻き取った拠点の紙、ヘルプの案件は登録した拠点の紙として数えます。
  在庫が <strong>{{ $alertBelow }}部を切ると、毎朝チャットワークに1回だけ</strong>知らせます（入庫数を入れたところだけ）。
</div>

{{-- 拠点の切替（2026-09-15 baba要望）。 --}}
@include('partials.office_switch')

@if(count($stock) === 0)
  <div class="ps-card">
    <div class="ps-empty">
      「紙が必要」なコンテンツがまだありません。<a href="/masters#contents">マスタ管理</a> でコンテンツの「紙」にチェックを入れてください。
    </div>
  </div>
@elseif (! $showStockCols)
{{-- ── 全拠点：コンテンツ×拠点の在庫（見るだけ）。入庫数は拠点を選んで直す。 ── --}}
<div class="ps-card">
  <h3>在庫一覧（コンテンツ × 拠点）</h3>
  <p class="sub">いま手元にある数（入庫−消費）と、( )内はこれからの予定をぜんぶやった後の残りです。
    「—」＝入庫数をまだ入れていない拠点。<b>入庫数を直すときは、上で拠点を選んでください。</b></p>
  <div class="table-scroll">
  <table class="ps ps-sticky">
    <thead>
      <tr>
        <th class="l">コンテンツ（印刷物）</th>
        @foreach($offices as $o)<th>{{ $o }}</th>@endforeach
      </tr>
    </thead>
    <tbody>
      @foreach($stock as $r)
        <tr>
          <td class="l">{{ $r['name'] }}@if($r['perTeam'] > 1)<span class="est">（{{ $r['perTeam'] }}枚/組）</span>@endif</td>
          @foreach($offices as $o)
            @php($c = $byOffice[$o][$r['id']] ?? null)
            @if ($c === null || (! $c['hasReceived'] && $c['future'] === 0))
              <td class="none">—</td>
            @elseif (! $c['hasReceived'])
              <td class="none">— <span class="est">（今後{{ $c['future'] }}部使う）</span></td>
            @else
              <td class="{{ $c['excess'] < 0 ? 'short' : '' }}">
                {{ $c['zaiko'] }}@if($c['low'])<span class="tag-low">{{ $alertBelow }}部切れ</span>@endif
                <span class="est">（{{ $c['excess'] >= 0 ? '+'.$c['excess'] : $c['excess'] }}）</span>
                @if($c['ordered'] > 0)<span class="tag-future" title="発注中（まだ届いていない）">📦{{ $c['ordered'] }}</span>@endif
              </td>
            @endif
          @endforeach
        </tr>
      @endforeach
    </tbody>
  </table>
  </div>
</div>

{{-- ── 発注中（全拠点・見るだけ）。「届いた」は拠点を選んで押す。 ── --}}
@if(count($pendingOrders) > 0 || count($arrivedOrders) > 0)
<div class="ps-card">
  <h3>📦 追加発注（全拠点）</h3>
  <p class="sub">発注を記録する・「届いた」を押すときは、上で拠点を選んでください。</p>
  @include('partials.paper_orders', ['pendingOrders' => $pendingOrders, 'arrivedOrders' => $arrivedOrders, 'canEdit' => false])
</div>
@endif

{{-- ── 拠点×月（必要枚数） ── --}}
@if(count($months) > 0)
<div class="ps-card">
  <h3>拠点 × 月（使う枚数）</h3>
  <p class="sub">開催月ごとに、どの拠点で何枚使うか（今後・開催済みの合計・全コンテンツの合計）。</p>
  <div class="table-scroll">
  <table class="ps ps-sticky">
    <thead>
      <tr>
        <th class="l">拠点</th>
        @foreach($months as $m)
          <th>{{ $m === '未定' ? '未定' : \Illuminate\Support\Str::of($m)->replace('-', '/') }}</th>
        @endforeach
        <th>合計</th>
      </tr>
    </thead>
    <tbody>
      @foreach($offices as $o)
        @php($perMonth = $byOfficeMonth[$o] ?? [])
        <tr>
          <td class="l"><a href="/paper-stock?office={{ urlencode($o) }}">{{ $o }}</a></td>
          @foreach($months as $m)
            <td>{{ $perMonth[$m] ?? 0 }}</td>
          @endforeach
          <td><strong>{{ array_sum($perMonth) }}</strong></td>
        </tr>
      @endforeach
    </tbody>
  </table>
  </div>
</div>
@endif
@else
{{-- ── 追加発注（2026-10-15 baba要望）。発注を記録→届いたら「届いた」で入庫数に足す。 ── --}}
<div class="ps-card">
  <h3>📦 追加発注（{{ $officeScope }}）</h3>
  <p class="sub">印刷を発注したら、ここに記録してください。<b>届いたら「届いた」を押すと、{{ $officeScope }}の入庫数に部数が足されます</b>（入庫数を自分で計算し直さなくてよい）。発注中のものは、{{ $alertBelow }}部の知らせにも「発注中」と添えます。</p>
  <form method="post" action="/paper-stock/orders" class="ps-filter">
    @csrf
    <input type="hidden" name="office" value="{{ $officeScope }}">
    <select name="content_id" required>
      <option value="">コンテンツを選ぶ</option>
      @foreach($names as $cid => $nm)
        <option value="{{ $cid }}" @selected(old('content_id') === (string) $cid)>{{ $nm }}</option>
      @endforeach
    </select>
    <label><input class="ps-recv" type="number" name="count" min="1" max="99999" required value="{{ old('count') }}" placeholder="部数"> 部</label>
    <label>届く予定日 <input type="date" name="expected_on" value="{{ old('expected_on') }}" class="ps-recv" style="width:150px;"></label>
    <input type="text" name="note" maxlength="200" value="{{ old('note') }}" placeholder="メモ（印刷会社・第◯刷など）" class="ps-recv" style="width:220px; text-align:left;">
    <button type="submit" class="ps-btn">発注を記録</button>
  </form>
  @if ($errors->any())
    <p class="ps-note" style="color:#b91c1c;">⚠ {{ $errors->first() }}</p>
  @endif

  @include('partials.paper_orders', ['pendingOrders' => $pendingOrders, 'arrivedOrders' => $arrivedOrders, 'canEdit' => true])
</div>

{{-- ── 拠点を選んでいるとき：その拠点の在庫表（入庫数は手入力） ── --}}
<div class="ps-card">
  <h3>{{ $officeScope }}の在庫（コンテンツ＝印刷物ごと）</h3>
  <p class="sub">「入庫数」欄に{{ $officeScope }}で用意した枚数を入れて「保存」を押してください。数字は拠点×コンテンツごとに記憶され、集計しても消えません。空欄＝まだ在庫を管理していない（知らせも出ません）。</p>

    <form method="post" action="/paper-stock/receipts">
      @csrf
      <input type="hidden" name="office" value="{{ $officeScope }}">
      {{-- 表が画面より広いとき、ページごと横に伸びずに表の中だけ横スクロールさせる入れ物 --}}
      <div class="table-scroll">
      <table class="ps ps-sticky">
        <thead>
          <tr>
            <th class="l">コンテンツ（印刷物）</th>
            <th>必要数(今後)</th>
            @if ($showStockCols)<th>入庫数(手入力)</th>@endif
            <th>消費数(自動)</th>
            @if ($showStockCols)<th>在庫数</th><th>過不足</th>@endif
          </tr>
        </thead>
        <tbody>
          @foreach($stock as $r)
            <tr>
              <td class="l">{{ $r['name'] }}@if($r['perTeam'] > 1)<span class="est">（{{ $r['perTeam'] }}枚/組）</span>@endif</td>
              <td>{{ $r['future'] }}</td>
              @if ($showStockCols)<td><input class="ps-recv" type="number" name="received[{{ $r['id'] }}]" value="{{ $r['hasReceived'] ? $r['received'] : '' }}" placeholder="未入力" min="0" max="99999"></td>@endif
              <td>{{ $r['past'] }}</td>
              @if ($showStockCols)
                <td>{{ $r['zaiko'] }}@if($r['low'])<span class="tag-low">{{ $alertBelow }}部切れ</span>@endif
                  @if($r['ordered'] > 0)<span class="tag-future" title="発注中（まだ届いていない）">📦発注中{{ $r['ordered'] }}</span>@endif</td>
                <td class="{{ $r['excess'] < 0 ? 'short' : 'ok' }}">
                  {{ $r['excess'] >= 0 ? '+' . $r['excess'] : $r['excess'] }}
                  @if($r['excess'] < 0)（{{ $r['short'] }}枚不足）@endif
                </td>
              @endif
            </tr>
          @endforeach
        </tbody>
        <tfoot>
          <tr>
            <td class="l">合計</td>
            <td>{{ $totals['future'] }}</td>
            @if ($showStockCols)<td>{{ $totals['received'] }}</td>@endif
            <td>{{ $totals['past'] }}</td>
            @if ($showStockCols)
              <td>{{ $totals['zaiko'] }}</td>
              <td class="{{ $totals['shortage'] > 0 ? 'short' : 'ok' }}">
                @if($totals['shortage'] > 0)不足 {{ $totals['shortage'] }}枚 @else 不足なし @endif
              </td>
            @endif
          </tr>
        </tfoot>
      </table>
      </div>
      @if ($showStockCols)
        <div class="ps-actions">
          <button type="submit" class="ps-btn">入庫数を保存</button>
        </div>
      @endif
      <p class="ps-note">
        ※「過不足」が赤字（マイナス）なら、その枚数だけ追加印刷が必要です。<br>
        ※ 印刷物はコンテンツごとに別物のため、コンテンツをまたいだ合計（在庫の総枚数）には意味がありません。合計行は目安です。
      </p>
    </form>
</div>
@endif

{{-- ── コンテンツ×月（必要・消費あわせた枚数） ── --}}
@if(count($stock) > 0 && count($months) > 0)
<div class="ps-card">
  <h3>コンテンツ × 月（必要枚数）@if($officeScope !== null)<span class="est">　{{ $officeScope }}の紙だけ</span>@endif</h3>
  <p class="sub">開催月ごとの枚数です（今後・開催済みの合計）。次にいつ・何枚要るかの見通しに使えます。</p>
  {{-- 月が増えるほど横に長くなる表なので、表の中だけ横スクロールさせる --}}
  <div class="table-scroll">
  <table class="ps ps-sticky">
    <thead>
      <tr>
        <th class="l">コンテンツ</th>
        @foreach($months as $m)
          <th>{{ $m === '未定' ? '未定' : \Illuminate\Support\Str::of($m)->replace('-', '/') }}</th>
        @endforeach
        <th>合計</th>
      </tr>
    </thead>
    <tbody>
      @foreach($byContentMonth as $cid => $perMonth)
        @php($rowTotal = array_sum($perMonth))
        <tr>
          <td class="l">{{ $names[$cid] ?? $cid }}</td>
          @foreach($months as $m)
            <td>{{ $perMonth[$m] ?? 0 }}</td>
          @endforeach
          <td><strong>{{ $rowTotal }}</strong></td>
        </tr>
      @endforeach
    </tbody>
  </table>
  </div>
</div>
@endif

{{-- ── 明細（案件ごと） ── --}}
@if($detailAll > 0)
<div class="ps-card">
  <h3>明細（案件ごと・{{ count($detail) }}件@if(count($detail) !== $detailAll)／全{{ $detailAll }}件@endif）</h3>
  {{-- コンテンツ・月で絞る（2026-10-15 baba要望）。拠点は上の切替で絞る。 --}}
  <form method="get" action="/paper-stock" class="ps-filter">
    <input type="hidden" name="office" value="{{ \App\Support\OfficeScope::param($officeScope) }}">
    <select name="content" onchange="this.form.submit()">
      <option value="">コンテンツ：すべて</option>
      @foreach($names as $cid => $nm)
        <option value="{{ $cid }}" @selected($fContent === (string) $cid)>{{ $nm }}</option>
      @endforeach
    </select>
    <select name="month" onchange="this.form.submit()">
      <option value="">月：すべて</option>
      @foreach($months as $m)
        <option value="{{ $m }}" @selected($fMonth === $m)>{{ $m === '未定' ? '未定' : \Illuminate\Support\Str::of($m)->replace('-', '/') }}</option>
      @endforeach
    </select>
    @if($fContent !== '' || $fMonth !== '')<a href="/paper-stock?office={{ urlencode(\App\Support\OfficeScope::param($officeScope)) }}">絞り込みを外す</a>@endif
  </form>
  {{-- 列が9つある表なので、表の中だけ横スクロールさせる --}}
  <div class="table-scroll">
  <table class="ps">
    <thead>
      <tr>
        <th class="l">状況</th><th class="l">開催日</th><th class="l">紙の拠点</th><th class="l">コンテンツ</th><th class="l">クライアント</th>
        <th>お客様人数</th><th>チーム数</th><th>必要枚数</th><th class="l">備考</th>
      </tr>
    </thead>
    <tbody>
      @foreach($detail as $d)
        <tr>
          <td class="l">
            @if($d['status'] === '今後')<span class="tag-future">今後</span>@else<span class="tag-past">開催済み</span>@endif
          </td>
          <td class="l">{{ $d['dateStr'] }}</td>
          <td class="l">{{ $d['office'] }}</td>
          <td class="l">{{ $d['content'] }}</td>
          <td class="l">{{ $d['client'] !== '' ? $d['client'] : '—' }}</td>
          <td>{{ $d['guest'] !== null ? $d['guest'] : '—' }}</td>
          <td>{{ $d['teamsCalc'] !== null ? $d['teamsCalc'] : '—' }}</td>
          <td>{{ $d['sheets'] }}</td>
          <td class="l">@if($d['estimated'])<span class="est">★人数から推定（1組{{ $teamSize }}人）</span>@endif</td>
        </tr>
      @endforeach
    </tbody>
  </table>
  </div>
</div>
@endif

@endsection
