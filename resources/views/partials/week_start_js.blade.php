{{-- カレンダーの「週のはじまり」を画面で使うための小さな部品（2026-09-15 baba要望）。

     ⚠ カレンダーを描く画面は、必ずこの2つの関数だけを使うこと。
       画面ごとに (firstDow + 6) % 7 のような計算を書くと、片方だけ直して食い違う
       （実際、社員の出勤可能日だけ月曜はじまり・ほかは日曜はじまり、とバラバラだった）。
     ⚠ 値の正本は App\Support\WeekStart。ここには「並べ替え方」しか書かない。 --}}
@verbatim
<script>
  // 曜日見出しの並び（左から順）。値は 0=日 … 6=土。
  function ECS_WEEK_ORDER(){
    return (window.ECS_WEEK_START === 'mon') ? [1,2,3,4,5,6,0] : [0,1,2,3,4,5,6];
  }
  // 月初に入れる空きマスの数。firstDow＝その月の1日の曜日（0=日…6=土）。
  function ECS_WEEK_LEAD(firstDow){
    return (window.ECS_WEEK_START === 'mon') ? ((firstDow + 6) % 7) : firstDow;
  }
</script>
@endverbatim
