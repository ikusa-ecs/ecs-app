{{-- SFの商談の確度（確定・Aヨミ・Bヨミ・Cヨミ）の印（2026-11-20 小沼さん「ヨミは出るようにしたい」）。
     マイページと受信箱の両方で、商談名の横に同じ見た目で出す。$yomi が空（読み替えられないフェーズ）なら何も出さない。
     色は文字と面を決め打ち（どの画面の色でも読めるように・確定=緑／A=青／B=黄／C=灰）。 --}}
@if (! empty($yomi))
  @php($__yc = ['確定' => ['#dcfce7', '#166534'], 'Aヨミ' => ['#dbeafe', '#1e40af'], 'Bヨミ' => ['#fef3c7', '#92400e'], 'Cヨミ' => ['#e5e7eb', '#374151']][$yomi] ?? ['#e5e7eb', '#374151'])
  <span style="display:inline-block; padding:1px 9px; border-radius:999px; font-size:12px; font-weight:700; vertical-align:middle; background:{{ $__yc[0] }}; color:{{ $__yc[1] }};">{{ $yomi }}</span>
@endif
