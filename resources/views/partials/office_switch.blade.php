{{-- 拠点の表示切替スイッチ（全拠点運用・設計書19.2）。
     管理者・Administrator にだけ表示。一般社員・スタッフには出さない（自拠点固定）。
     選んだ拠点は ?office= を付けて開き直し、サーバー側で案件を絞る。 --}}
@php
    $__osCanSwitch = \App\Support\OfficeScope::canSeeAll();
    // osNoAll＝「全拠点」を出さない画面（公開ボードのように、まとめて操作すると事故になるもの）。
    // その画面では osActive に「いま見ている拠点」を渡してもらい、それを選択中として光らせる。
    $__osNoAll     = $osNoAll ?? false;
    $__osSelected  = $__osNoAll
        ? (string) ($osActive ?? \App\Support\OfficeScope::DEFAULT_OFFICE)
        : \App\Support\OfficeScope::selected(request());
    $__osOptions   = \App\Support\OfficeScope::options();
@endphp
@if ($__osCanSwitch)
<style>
  .office-switch {
    display: flex; flex-wrap: wrap; align-items: center; gap: 8px;
    margin: 0 0 14px; padding: 10px 12px;
    background: #fbf6ef; border: 1px solid var(--line, #e6d8c8); border-radius: 10px;
  }
  .office-switch .os-label { font-size: 12px; font-weight: 700; color: var(--muted, #8a7a6b); margin-right: 2px; }
  .office-switch .os-chip {
    text-decoration: none; font-size: 13px; font-weight: 600;
    color: var(--ink, #2c2018); background: #fff;
    border: 1px solid var(--line-strong, #d8c4ae); border-radius: 999px; padding: 5px 13px;
    transition: all .15s;
  }
  .office-switch .os-chip:hover { border-color: var(--brand, #8a5a33); }
  .office-switch .os-chip.active { background: var(--brand, #8a5a33); color: #fff; border-color: var(--brand, #8a5a33); }
</style>
<div class="office-switch">
  <span class="os-label">表示する拠点</span>
  @if ($__osNoAll)
    <span style="font-size:11.5px;color:var(--muted,#8a7a6b);">（この画面は<b>1拠点ずつ</b>。まとめて公開する事故を防ぐため「全拠点」はありません）</span>
  @endif
  @unless ($__osNoAll)
    <a class="os-chip {{ $__osSelected === '' ? 'active' : '' }}"
       href="{{ request()->fullUrlWithQuery(['office' => '']) }}">全拠点</a>
  @endunless
  @foreach ($__osOptions as $__of)
    <a class="os-chip {{ $__osSelected === $__of ? 'active' : '' }}"
       href="{{ request()->fullUrlWithQuery(['office' => $__of]) }}">{{ $__of }}</a>
  @endforeach
</div>
@endif
