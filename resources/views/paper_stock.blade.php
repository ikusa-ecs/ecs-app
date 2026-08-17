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
            font-weight: 700; font-family: inherit; background: var(--brand); color: #fff; cursor: pointer; }
  .ps-note { font-size: 11.5px; color: var(--muted); margin-top: 8px; line-height: 1.7; }
  .ps-empty { color: var(--muted); font-size: 13px; padding: 18px 4px; }
  .est { color: #b45309; font-size: 11px; }
  .flash { background: var(--ok-soft); border: 1px solid #bbe3c6; color: #15803d;
           border-radius: 10px; padding: 10px 14px; font-size: 13px; font-weight: 700; margin-bottom: 14px; }
  .tag-future { font-size: 11px; padding: 1px 8px; border-radius: 999px; background: var(--warn-soft); color: #b45309; font-weight: 600; }
  .tag-past { font-size: 11px; padding: 1px 8px; border-radius: 999px; background: #ece3d4; color: #7a6a58; }

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
  対象にするコンテンツは <a href="/masters#contents">マスタ管理</a> の「紙」で切り替えます。チーム数が未入力の案件は、お客様人数÷{{ $teamSize }}で推定します。
</div>

{{-- ── 在庫表（入庫数は手入力） ── --}}
<div class="ps-card">
  <h3>在庫一覧（コンテンツ＝印刷物ごと）</h3>
  <p class="sub">「入庫数」欄に用意した枚数を入れて「保存」を押してください。数字はコンテンツごとに記憶され、集計しても消えません。</p>

  @if(count($stock) === 0)
    <div class="ps-empty">
      「紙が必要」なコンテンツがまだありません。<a href="/masters#contents">マスタ管理</a> でコンテンツの「紙」にチェックを入れてください。
    </div>
  @else
    <form method="post" action="/paper-stock/receipts">
      @csrf
      {{-- 表が画面より広いとき、ページごと横に伸びずに表の中だけ横スクロールさせる入れ物 --}}
      <div class="table-scroll">
      <table class="ps ps-sticky">
        <thead>
          <tr>
            <th class="l">コンテンツ（印刷物）</th>
            <th>必要数(今後)</th>
            <th>入庫数(手入力)</th>
            <th>消費数(自動)</th>
            <th>在庫数</th>
            <th>過不足</th>
          </tr>
        </thead>
        <tbody>
          @foreach($stock as $r)
            <tr>
              <td class="l">{{ $r['name'] }}@if($r['perTeam'] > 1)<span class="est">（{{ $r['perTeam'] }}枚/組）</span>@endif</td>
              <td>{{ $r['future'] }}</td>
              <td><input class="ps-recv" type="number" name="received[{{ $r['id'] }}]" value="{{ $r['received'] }}" min="0" max="99999"></td>
              <td>{{ $r['past'] }}</td>
              <td>{{ $r['zaiko'] }}</td>
              <td class="{{ $r['excess'] < 0 ? 'short' : 'ok' }}">
                {{ $r['excess'] >= 0 ? '+' . $r['excess'] : $r['excess'] }}
                @if($r['excess'] < 0)（{{ $r['short'] }}枚不足）@endif
              </td>
            </tr>
          @endforeach
        </tbody>
        <tfoot>
          <tr>
            <td class="l">合計</td>
            <td>{{ $totals['future'] }}</td>
            <td>{{ $totals['received'] }}</td>
            <td>{{ $totals['past'] }}</td>
            <td>{{ $totals['zaiko'] }}</td>
            <td class="{{ $totals['shortage'] > 0 ? 'short' : 'ok' }}">
              @if($totals['shortage'] > 0)不足 {{ $totals['shortage'] }}枚 @else 不足なし @endif
            </td>
          </tr>
        </tfoot>
      </table>
      </div>
      <div class="ps-actions">
        <button type="submit" class="ps-btn">入庫数を保存</button>
      </div>
      <p class="ps-note">
        ※「過不足」が赤字（マイナス）なら、その枚数だけ追加印刷が必要です。<br>
        ※ 印刷物はコンテンツごとに別物のため、コンテンツをまたいだ合計（在庫の総枚数）には意味がありません。合計行は目安です。
      </p>
    </form>
  @endif
</div>

{{-- ── コンテンツ×月（必要・消費あわせた枚数） ── --}}
@if(count($stock) > 0 && count($months) > 0)
<div class="ps-card">
  <h3>コンテンツ × 月（必要枚数）</h3>
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
@if(count($detail) > 0)
<div class="ps-card">
  <h3>明細（案件ごと・{{ count($detail) }}件）</h3>
  {{-- 列が8つある表なので、表の中だけ横スクロールさせる --}}
  <div class="table-scroll">
  <table class="ps">
    <thead>
      <tr>
        <th class="l">状況</th><th class="l">開催日</th><th class="l">コンテンツ</th><th class="l">クライアント</th>
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
