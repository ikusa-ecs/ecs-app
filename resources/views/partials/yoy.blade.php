{{-- 昨対比（前年同期との比較）の1行。集計ダッシュボードの数字の下に添える。

     使い方＝@include('partials.yoy', ['y' => $yoyTotal])／単位を変えるときは 'unit' => '回'
     $y の中身＝StatsController::yoy() が作る ['has'=>bool,'prev'=>int,'diff'=>int,'pct'=>?int]

     ⚠ has=false は「前年のデータが無い」＝0件とは違う。ECSは2026年から使い始めたので、
       前年に案件が1件も入っていない期間がある。そこを0件として「-100%」と出すと、
       画面全体の数字が信用されなくなる。
     ⚠ pct=null は「前年が0件」＝割り算ができないので率は出さない（0件→1件は「+100%」ではない）。
     ⚠ 計算はここでしない（コントローラの1か所で作って渡す）。画面で計算を書き足さないこと。 --}}
<div class="k-yoy">
  @if (! $y['has'])
    <span class="flat">前年のデータなし</span>
  @else
    <span class="prev">前年 {{ $y['prev'] }}{{ $unit ?? '件' }}</span>
    @if ($y['diff'] > 0)
      <span class="up">＋{{ $y['diff'] }}@if ($y['pct'] !== null)（＋{{ $y['pct'] }}%）@endif</span>
    @elseif ($y['diff'] < 0)
      <span class="down">{{ $y['diff'] }}@if ($y['pct'] !== null)（{{ $y['pct'] }}%）@endif</span>
    @else
      <span class="flat">前年と同じ</span>
    @endif
  @endif
</div>
