@extends('layouts.app')
@section('title', '繁忙期ボーナス')
@section('h1', '繁忙期ボーナス')
@php($active = 'active_bonus')

{{-- 繁忙期ボーナス（/active-bonus）。2026-09-17 baba要望。
     「あと1回出てもらえればボーナスが付く＝タイミーを頼むより安い」を一目で分かるようにする。
     ⚠ 計算はすべて App\Support\ActiveBonus で済ませてある。この画面では計算しない
       （画面ごとに数え直すと、スタッフ画面と数字が食い違う）。
     ⚠ 色は必ず変数（var(--panel) など）で書く。黒ベースのテーマでも読めるようにするため。 --}}

@push('head')
<style>
  .ab-intro { font-size: 13px; color: var(--muted); line-height: 1.8; margin-bottom: 14px; }
  .ab-intro b { color: var(--ink); }

  /* 上の操作バー（月を選ぶ） */
  .ab-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 14px;
    background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 10px 14px; }
  .ab-bar .lbl { font-size: 12.5px; color: var(--muted); font-weight: 700; }
  .ab-bar select { padding: 7px 10px; border: 1px solid var(--field-line); border-radius: 8px;
    font-size: 14px; font-family: inherit; background: var(--surface-3); color: var(--ink); font-weight: 700; }
  .ab-bar button { padding: 7px 16px; border: 1px solid var(--brand-dark); border-radius: 8px;
    font-size: 13px; font-weight: 700; font-family: inherit; background: var(--brand-fill); color: #fff; cursor: pointer; }
  .ab-bar .spacer { flex: 1; }
  .ab-bar .off { font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 999px;
    background: var(--chip-bg); color: var(--chip-ink); }

  /* KPIカード */
  .ab-kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 14px; }
  .ab-kpi { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px; box-shadow: var(--shadow); }
  .ab-kpi .k-label { font-size: 12px; color: var(--muted); font-weight: 700; }
  .ab-kpi .k-num { font-size: 28px; font-weight: 800; color: var(--ink); line-height: 1.15; margin-top: 4px; }
  .ab-kpi .k-num small { font-size: 14px; font-weight: 700; color: var(--muted-dim); margin-left: 3px; }
  .ab-kpi .k-note { font-size: 11.5px; color: var(--muted-dim); margin-top: 4px; }
  .ab-kpi.hot { border-top: 3px solid var(--warn); }
  .ab-kpi.cost { border-top: 3px solid var(--brand); }
  .ab-kpi.save { border-top: 3px solid var(--ok); }
  .ab-kpi.save .k-num { color: var(--ok-ink); }

  /* 決まりの設定（2026-09-18 に共通設定からここへ移した） */
  .ab-flash { background: var(--ok-soft); color: var(--ok-ink); border: 1px solid var(--ok-line);
    border-radius: 8px; padding: 8px 12px; font-size: 12.5px; font-weight: 700; margin-bottom: 10px; }
  table.ab-set { border-collapse: collapse; font-size: 13px; margin: 6px 0 12px; }
  table.ab-set th { text-align: left; padding: 4px 12px 4px 0; font-size: 11.5px; color: var(--muted); font-weight: 700; }
  table.ab-set td { padding: 3px 12px 3px 0; color: var(--ink); }
  table.ab-set input, .ab-setrow input { padding: 6px 8px; border: 1px solid var(--field-line); border-radius: 8px;
    font-size: 13px; font-family: inherit; background: var(--surface-3); color: var(--ink); }
  table.ab-set input { width: 100px; }
  .ab-setrow { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;
    padding: 10px 0; border-top: 1px solid var(--line); }
  .ab-setrow .s-label { display: block; font-size: 13px; font-weight: 700; color: var(--ink); }
  .ab-setrow .s-note { display: block; font-size: 11.5px; color: var(--muted); line-height: 1.7; margin-top: 2px; }
  .ab-check { font-size: 13px; font-weight: 700; color: var(--ink); cursor: pointer; white-space: nowrap; }
  .ab-setfoot { padding: 12px 0 2px; border-top: 1px solid var(--line); }
  .ab-setfoot button { padding: 8px 18px; border: 1px solid var(--brand-dark); border-radius: 8px;
    font-size: 13px; font-weight: 700; font-family: inherit; background: var(--brand-fill); color: #fff; cursor: pointer; }

  /* 進捗バー */
  .ab-prog { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px;
    margin-bottom: 14px; box-shadow: var(--shadow); }
  .ab-prog h3 { font-size: 14px; font-weight: 800; color: var(--ink); margin: 0 0 8px; }
  .ab-prog .p-txt { font-size: 13px; color: var(--ink); line-height: 1.8; }
  .ab-prog .p-txt .hint { color: var(--muted); }
  .ab-prog .p-bar { height: 10px; border-radius: 999px; background: var(--chip-bg); margin-top: 10px; overflow: hidden; }
  .ab-prog .p-bar span { display: block; height: 100%; border-radius: 999px; background: var(--brand-fill); }

  /* パネル3枚 */
  .ab-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 14px; margin-bottom: 16px; }
  .ab-panel { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px; box-shadow: var(--shadow); }
  .ab-panel h3 { font-size: 14px; font-weight: 800; color: var(--ink); margin: 0 0 10px; }
  .ab-panel .empty { color: var(--muted-dim); font-size: 13px; padding: 10px 0; }

  table.ab { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.ab th, table.ab td { padding: 7px 8px; border-bottom: 1px solid var(--line); text-align: left; white-space: nowrap; }
  table.ab th { background: var(--surface-2); font-weight: 600; color: var(--muted); font-size: 11.5px; }
  table.ab tr:last-child td { border-bottom: none; }
  table.ab td.num { text-align: right; font-variant-numeric: tabular-nums; }
  table.ab .name { font-weight: 700; color: var(--ink); white-space: normal; }
  table.ab .yen { font-weight: 700; color: var(--ink); }
  table.ab .yen.zero { color: var(--muted-dim); font-weight: 600; }
  .ab-rank { display: inline-block; width: 22px; text-align: right; font-weight: 800; color: var(--muted-dim); }
  .ab-rank.top { color: var(--warn-ink); }
  .ab-spot { display: inline-block; font-size: 10.5px; font-weight: 700; padding: 1px 7px; border-radius: 999px;
    background: var(--chip-bg); color: var(--chip-ink); margin-left: 5px; }
  .ab-up { font-weight: 700; color: var(--ok-ink); }
  .ab-one { font-weight: 800; color: var(--warn-ink); }

  /* 設定のおさらい */
  .ab-rule { font-size: 12px; color: var(--muted); line-height: 1.9; margin-top: 4px; }
  .ab-rule b { color: var(--ink); }

  @media (max-width: 720px) {
    .ab-kpis { grid-template-columns: repeat(2, 1fr); }
    table.ab th, table.ab td { padding: 6px 5px; font-size: 12px; }
  }
</style>
@endpush

@section('content')

  @include('partials.office_switch')

  <p class="ab-intro">
    繁忙期に<b>自社スタッフにもう1回出てもらう</b>ための画面です。
    アサインの回数が階層に届くと時給が上がり、その月の<b>全部の回にさかのぼって</b>ボーナスが付きます。<br>
    足りないぶんをタイミーで埋めるより安く済むので、<b>「あと1回」の人に優先して声をかけると、いちばん得</b>になります。
  </p>

  <form class="ab-bar" method="get" action="/active-bonus">
    <span class="lbl">月</span>
    <select name="month">
      @foreach ($months as $m)
        <option value="{{ $m['value'] }}" @selected($m['value'] === $month)>{{ $m['label'] }}</option>
      @endforeach
    </select>
    {{-- 拠点の選択を月と一緒に持ち回る（選び直すと自拠点に戻ってしまうのを防ぐ）。 --}}
    <input type="hidden" name="office" value="{{ request()->query('office', '') }}">
    <button type="submit">表示</button>
    <span class="spacer"></span>
    @unless ($enabled)
      <span class="off">いまは「実施していない」設定です</span>
    @endunless
  </form>

  {{-- ── KPI ── --}}
  <div class="ab-kpis">
    <div class="ab-kpi hot">
      <div class="k-label">🔥 あと1回で達成する人</div>
      <div class="k-num">{{ $oneMoreCount }}<small>名</small></div>
      <div class="k-note">この人たちを優先すると削減額がいちばん増えます</div>
    </div>
    <div class="ab-kpi cost">
      <div class="k-label">💰 IKUSA追加コスト（ボーナス合計）</div>
      <div class="k-num">¥{{ number_format($bonusTotal) }}</div>
      <div class="k-note">達成した {{ $achievedCount }}名に払うぶん</div>
    </div>
    <div class="ab-kpi save">
      <div class="k-label">🏆 削減見込額</div>
      <div class="k-num">¥{{ number_format($saving) }}</div>
      <div class="k-note">タイミー利用時との差</div>
    </div>
    <div class="ab-kpi">
      <div class="k-label">🎯 達成人数</div>
      <div class="k-num">{{ $achievedCount }}<small>/ {{ $targetCount }}名</small></div>
      <div class="k-note">対象＝この月に1回以上入ったスタッフ</div>
    </div>
    <div class="ab-kpi">
      <div class="k-label">🧾 タイミー利用時コスト</div>
      <div class="k-num">¥{{ number_format($spotTotal) }}</div>
      <div class="k-note">のべ {{ $totalCount }}回 × ¥{{ number_format($spotCost) }}</div>
    </div>
    <div class="ab-kpi">
      <div class="k-label">📈 達成率</div>
      <div class="k-num">{{ $achieveRate }}<small>%</small></div>
      <div class="k-note">1回あたり {{ $hours }}時間で計算</div>
    </div>
  </div>

  {{-- ── 今月の進捗 ── --}}
  <div class="ab-prog">
    <h3>🎯 {{ $monthLabel }}の進捗</h3>
    <div class="p-txt">
      対象：{{ $targetCount }}名　達成：{{ $achievedCount }}名（{{ $achieveRate }}%）<br>
      @if ($targetCount === 0)
        <span class="hint">この月は、まだ確定のアサインがありません。</span>
      @elseif ($oneMoreCount > 0)
        残り<b>{{ $oneMoreCount }}名</b>へ優先アサインすると削減見込額の最大化につながります
      @else
        <span class="hint">いま「あと1回」の人はいません。</span>
      @endif
    </div>
    <div class="p-bar"><span style="width: {{ $achieveRate }}%"></span></div>
  </div>

  {{-- ── 3つの表 ── --}}
  <div class="ab-grid">

    <div class="ab-panel">
      <h3>🔥 あと1回でボーナス！</h3>
      @if ($oneMore->isEmpty())
        <div class="empty">いません。</div>
      @else
        <table class="ab">
          <tr><th>スタッフ名</th><th class="num">現在回数</th><th>あと</th></tr>
          @foreach ($oneMore as $r)
            <tr>
              <td class="name">{{ $r['name'] }}@if ($r['isSpot'])<span class="ab-spot">臨時</span>@endif</td>
              <td class="num">{{ $r['count'] }}回</td>
              <td><span class="ab-one">あと1回</span></td>
            </tr>
          @endforeach
        </table>
      @endif
    </div>

    <div class="ab-panel">
      <h3>🎁 次回達成ボーナス内訳</h3>
      @if ($nextRows->isEmpty())
        <div class="empty">次に届く階層がある人はいません。</div>
      @else
        <table class="ab">
          <tr><th>スタッフ名</th><th class="num">現在回数</th><th>次の階層</th><th class="num">次のボーナス</th></tr>
          @foreach ($nextRows as $r)
            <tr>
              <td class="name">{{ $r['name'] }}</td>
              <td class="num">{{ $r['count'] }}回</td>
              <td>{{ $r['next']['count'] }}回で達成</td>
              <td class="num ab-up">+{{ number_format($r['next']['rate']) }}円/h</td>
            </tr>
          @endforeach
        </table>
      @endif
    </div>

    <div class="ab-panel">
      <h3>🏆 アサイン回数ランキング</h3>
      @if ($ranking->isEmpty())
        <div class="empty">この月の確定アサインがありません。</div>
      @else
        <table class="ab">
          <tr><th>#</th><th>スタッフ名</th><th class="num">回数</th><th class="num">累計ボーナス</th></tr>
          @foreach ($ranking as $i => $r)
            <tr>
              <td><span class="ab-rank {{ $i < 3 ? 'top' : '' }}">{{ $i + 1 }}</span></td>
              <td class="name">{{ $r['name'] }}@if ($r['isSpot'])<span class="ab-spot">臨時</span>@endif</td>
              <td class="num">{{ $r['count'] }}回</td>
              <td class="num yen {{ $r['bonus'] === 0 ? 'zero' : '' }}">¥{{ number_format($r['bonus']) }}</td>
            </tr>
          @endforeach
        </table>
      @endif
    </div>

  </div>

  {{-- ── 決まりの設定（2026-09-18 baba要望で共通設定からこの画面に移した） ──
       ⚠ 保存先は今までと同じ（App\Support\ActiveBonus）。共通設定に置いていたときの値はそのまま使える。
       ⚠ このコメントにBladeの命令名（＠から始まる語）を書かないこと。 --}}
  <div class="ab-panel">
    <h3>⚙ 決まりの設定</h3>

    @if (session('active_bonus_status'))
      <div class="ab-flash">{{ session('active_bonus_status') }}</div>
    @endif

    <form method="POST" action="/active-bonus/settings">
      @csrf
      {{-- 保存したあと、いま見ている月と拠点のまま戻るために持ち回る。 --}}
      <input type="hidden" name="month" value="{{ $month }}">
      <input type="hidden" name="office" value="{{ request()->query('office', '') }}">

      <table class="ab-set">
        <tr>
          <th>達成する回数</th>
          <th>上がる時給（円/h）</th>
        </tr>
        {{-- いまの階層＋空き2行。行を消したいときは、その行の両方を空にして保存します。 --}}
        @foreach (array_pad($tiers, count($tiers) + 2, null) as $t)
          <tr>
            <td><input type="number" name="counts[]" min="1" max="999" value="{{ $t['count'] ?? '' }}"> 回</td>
            <td>＋<input type="number" name="rates[]" min="1" max="100000" value="{{ $t['rate'] ?? '' }}"> 円/h</td>
          </tr>
        @endforeach
      </table>

      <div class="ab-setrow">
        <div>
          <span class="s-label">1回あたりの時間</span>
          <span class="s-note">ボーナス額の計算に使います（回数 × この時間 × 上がる時給）</span>
        </div>
        <div><input type="number" name="hours" min="1" max="24" value="{{ $hours }}" required> 時間</div>
      </div>

      <div class="ab-setrow">
        <div>
          <span class="s-label">タイミー1回あたりの費用</span>
          <span class="s-note">手数料込み。削減見込額＝この金額 × のべ回数 − ボーナス合計。0にすると比較は0円になります</span>
        </div>
        <div>¥<input type="number" name="spot_cost" min="0" max="1000000" value="{{ $spotCost }}" required></div>
      </div>

      <div class="ab-setrow">
        <div>
          <span class="s-label">スタッフに見せる</span>
          <span class="s-note">
            ONにすると、スタッフ画面に「今月のアサイン◯回／あと◯回」が出ます。<br>
            ⚠ <b>この画面（社員側）は、OFFでもいつでも見られます。</b>
            繁忙期をやっていないときにスタッフへ「あと1回でボーナス」と出さないための切替です。
          </span>
        </div>
        <div>
          <label class="ab-check">
            <input type="checkbox" name="show_to_staff" value="1" @checked($enabled)> スタッフに見せる
          </label>
        </div>
      </div>

      <div class="ab-setfoot">
        <button type="submit">この決まりにする</button>
      </div>
    </form>

    <div class="ab-rule" style="margin-top:12px;">
      数え方：<b>「確定」のアサインだけ</b>を数えます（仮置き・キャンセルは数えません）。<br>
      対象：<b>その月に1回以上入ったスタッフ</b>（社員は入りません）。拠点は<b>スタッフの所属拠点</b>で分けます。<br>
      ⚠ 集計ダッシュボードの出勤数は「キャンセル以外」なので、数が一致しないことがあります。
    </div>
  </div>

@endsection
