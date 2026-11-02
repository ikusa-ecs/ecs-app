{{-- サイズ一覧の表1つ（スタッフ／社員の部署ごとで同じ形・2026-11-02）。$rows＝SizeListController の行。 --}}
<table class="sz-table">
  <tr><th>名前</th><th>靴（足袋）</th><th>服</th><th>身長</th></tr>
  @forelse ($rows as $r)
    <tr class="sz-row" data-name="{{ $r['name'] }}" data-kana="{{ $r['kana'] }}">
      <td class="sz-n"><span class="sz-name">{{ $r['name'] }}</span>@if ($r['office'] !== '')<span class="sz-small">{{ $r['office'] }}</span>@endif</td>
      <td>@if ($r['shoe'] !== ''){{ $r['shoe'] }}@else<span class="sz-none">—</span>@endif</td>
      <td>@if ($r['wear'] !== ''){{ $r['wear'] }}@else<span class="sz-none">—</span>@endif</td>
      <td>@if ($r['height'] !== ''){{ $r['height'] }}@else<span class="sz-none">—</span>@endif</td>
    </tr>
  @empty
    <tr><td colspan="4" class="sz-none">いません</td></tr>
  @endforelse
</table>
