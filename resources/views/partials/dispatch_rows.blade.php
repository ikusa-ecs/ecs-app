{{--
  派遣依頼の行（2026-09-16 baba要望「派遣で入力したら派遣会社と今の状況を出してほしい」）。

  使い方：@include('partials.dispatch_rows', ['dispatches' => $c['dispatches']])
  中身の正本＝App\Support\DispatchRows（読み方と文字の作り方）。
  見た目の正本＝public/ecs/style.css の .ecs-dsp-*（3つのテーマぜんぶで色が出る）。

  ⚠ ここは見るだけ。直す・消すのは「派遣一覧」（/dispatch-list）の1か所だけ
    ＝どの画面からでも消せると、押し間違いで「頼んだ記録」が消える。
  ⚠ キャンセルも残して薄く出す（頼んだ事実が消えると経緯が追えない）。
--}}
@if (! empty($dispatches))
  <div class="ecs-dsp">
    @foreach ($dispatches as $d)
      <div class="ecs-dsp-row {{ $d['cancelled'] ? 'off' : '' }}" title="{{ $d['tip'] }}">
        <span class="ecs-dsp-mark">派</span>
        <span class="ecs-dsp-agency">{{ $d['agency'] }}</span>
        <span class="ecs-dsp-count">派遣 {{ $d['count'] }}名</span>
        @if ($d['role'] !== '')<span class="ecs-dsp-role">{{ $d['role'] }}</span>@endif
        <span class="ecs-dsp-st {{ $d['cls'] }}">{{ $d['status'] }}</span>
      </div>
    @endforeach
  </div>
@endif
