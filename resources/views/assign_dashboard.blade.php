@extends('layouts.app')
@section('title', 'アサインダッシュボード')
@section('h1', 'アサインダッシュボード')
@php($active = 'assign_dashboard')

@push('head')
<style>
  /* クリックで日別ボードへ飛ぶ行（カーソルと薄い反転で「押せる」と分かるように） */
  .tbl tr.row-link { cursor: pointer; }
  .tbl tr.row-link:hover { background: var(--brand-soft); }

  /* 「アサインが必要な案件」の表＝ノートPC1画面に収まるよう、詰めて・折り返さず・長い時はスクロール */
  .na-scroll { max-height: 300px; overflow-y: auto; border: 1px solid var(--line); border-radius: 8px; }
  table.na-tbl { table-layout: fixed; }
  table.na-tbl th, table.na-tbl td {
    padding: 5px 9px; font-size: 12.5px; white-space: nowrap;   /* 縦書き化（1文字ずつ折り返し）を防ぐ */
  }
  table.na-tbl thead th { position: sticky; top: 0; z-index: 1; }  /* 行が多くても見出しは上に残す */
  /* 案件名だけは長くても…で省略（マウスを乗せると全文がツールチップで出る） */
  table.na-tbl td.na-name { overflow: hidden; text-overflow: ellipsis; }
  /* 決定/必要：決まった人数を色で（満たした=緑／不足=ブランド色／0人=赤）、分母は控えめに */
  table.na-tbl td.na-fill { font-weight: 700; }
  table.na-tbl td.na-fill.ok  { color: #15803d; }
  table.na-tbl td.na-fill.mid { color: var(--brand-dark); }
  table.na-tbl td.na-fill.low { color: var(--danger); }
  table.na-tbl td.na-fill .na-need { color: var(--muted); font-weight: 400; }
</style>
@endpush

@section('content')
      <div class="mock-note">
        これはアサイン担当者向けの「状況まとめ」画面です。数値・一覧はすべて本物の案件・アサインから計算しています。<br>
        ここで全体の状況をつかんでから、<b>「日別ボード」</b>で実際の割り当て作業に進みます。
      </div>

      <h2 style="margin:4px 0 4px; font-size:18px;">アサイン担当の状況</h2>
      <p class="muted" style="font-size:12px; margin:0 0 14px;">アサインを進めるための担当者向けの情報です（全社員向けの情報はダッシュボードにあります）。稼働率の対象月は {{ now()->format('Y年n月') }}です。
        <a class="btn sm" href="/assign" style="margin-left:8px;">▦ 日別ボードで割り当てる →</a>
      </p>

      <!-- 数値サマリ（担当向け・本物のDBから計算） -->
      <div class="grid cols-4">
        <div class="stat">
          <div class="label">募集中の案件</div>
          <div class="value">{{ $recruitCount }}</div>
          <div class="sub">うちアサイン未確定 {{ $recruitUndecided }}件</div>
        </div>
        <div class="stat">
          <div class="label">今週の確定案件</div>
          <div class="value ok">{{ $weekConfirmed }}</div>
          <div class="sub">のべ {{ $weekManDays }} 名アサイン済</div>
        </div>
        <div class="stat">
          <div class="label">希望0件のスタッフ</div>
          <div class="value {{ $zeroPref > 0 ? 'danger' : 'ok' }}">{{ $zeroPref }}</div>
          <div class="sub">{{ $zeroPref > 0 ? '優先的に声掛けが必要' : '全員が希望を提出済み' }}</div>
        </div>
        <div class="stat">
          <div class="label">平均稼働率</div>
          @if ($avgRate === null)
          <div class="value">—</div>
          <div class="sub">希望データがありません</div>
          @else
          <div class="value {{ $avgRate >= 50 ? 'ok' : ($avgRate >= 30 ? 'warn' : 'danger') }}">{{ $avgRate }}<span style="font-size:18px;">%</span></div>
          <div class="sub">対象月（2026/7）の希望充足率の平均</div>
          @endif
        </div>
      </div>

      <div class="grid cols-2" style="margin-top:20px;">

        <!-- アサインが必要な案件（本物の案件・行クリックで日別ボードへジャンプ） -->
        <div class="panel">
          <div class="panel-head">
            <h2>アサインが必要な案件</h2>
            <div class="spacer"></div>
            <a class="btn sm" href="/projects">案件一覧へ</a>
          </div>
          @if (count($needAssign))
          <div class="na-scroll">
          <table class="tbl na-tbl">
            <thead>
              <tr>
                <th>案件名</th>
                <th style="width:58px;">確度</th>
                <th style="width:50px;">日程</th>
                <th class="num" style="width:62px;" title="決まっている人数 ／ 必要人数">決定/必要</th>
                <th style="width:70px;">状況</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($needAssign as $row)
              <tr class="row-link" onclick="location.href='/assign?focus={{ $row['id'] }}'" title="日別ボードでこの案件にジャンプします">
                <td class="na-name" title="{{ $row['name'] }}">{{ $row['name'] }}</td>
                <td>{{ $row['yomi'] }}</td>
                <td>{{ $row['date'] }}</td>
                <td class="num na-fill {{ $row['fillCls'] }}">@if ($row['need']){{ $row['filled'] }}<span class="na-need">/{{ $row['need'] }}</span>@else{{ $row['filled'] }}<span class="na-need">/—</span>@endif</td>
                <td><span class="badge {{ $row['badge'] }}">{{ $row['status'] }}</span></td>
              </tr>
              @endforeach
            </tbody>
          </table>
          </div>
          <p class="muted" style="font-size:11.5px; margin:8px 2px 0;">{{ count($needAssign) }}件。行をクリックすると、日別ボードのその案件にジャンプします。</p>
          @else
          <p class="muted" style="font-size:13px; margin:6px 2px;">いま「未着手・調整中」で、これから先の予定の案件はありません。</p>
          @endif
        </div>

        <!-- 気にかけたい人（稼働状況から移動）＝見落とし防止のためダッシュボードに置く。
             指標は稼働状況と同じ単一ソース（StaffStatusController）から計算。 -->
        <div class="panel">
          <div class="panel-head">
            <h2>⚠ 気にかけたい人</h2>
            <div class="spacer"></div>
            <a class="btn sm" href="/staff">スタッフ画面へ</a>
          </div>
          @php($careAny = count($careZero) || count($careRenkin) || count($careLowRate) || count($carePick) || count($careGobusata))
          @if (count($careZero))
          <div class="alert danger"><span class="ico">⚠</span><div><strong>今月の希望が0件：</strong>{{ implode('、', $careZero) }}。<br>0件回避のため、次のアサインで優先的に検討しましょう。</div></div>
          @endif
          @if (count($careRenkin))
          <div class="alert warn"><span class="ico">⚠</span><div><strong>連勤に注意：</strong>{{ implode('、', array_map(fn ($c) => $c['name'].'（'.$c['renkin'].'連勤）', $careRenkin)) }}。<br>夏場の3連勤・通年5連勤超えは避けたいラインです。</div></div>
          @endif
          @if (count($careLowRate))
          <div class="alert warn"><span class="ico">▲</span><div><strong>稼働率が30%未満：</strong>{{ implode('、', array_map(fn ($c) => $c['name'].'（'.$c['rate'].'%）', $careLowRate)) }}。<br>希望を出してくれているのにアサインが少なめです。</div></div>
          @endif
          @if (count($carePick))
          <div class="alert warn"><span class="ico">▲</span><div><strong>応募が多いのに選ばれた率が低い：</strong>{{ implode('、', array_map(fn ($c) => $c['name'].'（'.$c['rate'].'%・'.$c['picked'].'/'.$c['applied'].'）', $carePick)) }}。<br>エントリーしてくれているのに選ばれていません。新人離脱を防ぐため次のアサインで検討を。</div></div>
          @endif
          @if (count($careGobusata))
          <div class="alert warn"><span class="ico">⌛</span><div><strong>しばらくアサインがない：</strong>{{ implode('、', array_map(fn ($c) => $c['name'].'（'.$c['days'].'日）', $careGobusata)) }}。<br>声をかけて状況を確認したい人です。</div></div>
          @endif
          @unless ($careAny)
          <div class="alert ok"><span class="ico">✓</span><div>特に気にかけるべき人はいません。</div></div>
          @endunless
        </div>

      </div>

      <!-- 直近の確定アサイン（確定したものを案件ごと・確定日時の新しい順） -->
      <div class="panel" style="margin:20px 0;">
        <div class="panel-head">
          <h2>直近の確定アサイン</h2>
          <div class="spacer"></div>
          <a class="btn sm" href="/assign-dashboard/export.csv" title="「アサインが必要な案件」の一覧をCSVでダウンロードします">⬇ CSV出力</a>
        </div>
        @if (count($recentConfirmed))
        <table class="tbl">
          <thead>
            <tr><th>確定日時</th><th>案件名</th><th>日程</th><th class="num">人数</th></tr>
          </thead>
          <tbody>
            @foreach ($recentConfirmed as $r)
            <tr><td>{{ $r['confirmedAt'] }}</td><td>{{ $r['name'] }}</td><td>{{ $r['date'] }}</td><td class="num">{{ $r['headcount'] }}</td></tr>
            @endforeach
          </tbody>
        </table>
        <p class="muted" style="font-size:11.5px; margin:12px 0 0;">確定（status=確定）のアサインを案件ごとにまとめ、確定日時の新しい順に表示しています。CSVはこの画面からダウンロードのみ（外部サービスへの自動書き出しはありません）。</p>
        @else
        <p class="muted" style="font-size:13px; margin:6px 2px;">まだ「確定」のアサインがありません。</p>
        @endif
      </div>
@endsection
