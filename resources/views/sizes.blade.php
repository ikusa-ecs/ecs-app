@extends('layouts.app')
@section('title', 'サイズ一覧')
@section('h1', 'サイズ一覧（靴・服・身長）')
@php $active = 'sizes'; @endphp

@push('head')
<style>
  /* 色は共通の変数だけで書く＝3つのテーマぜんぶで読める。形は社員別アサイン一覧と同じ（2026-11-02）。 */
  .sz-intro { font-size: 13px; color: var(--muted); line-height: 1.8; margin: 0 0 12px; }
  .sz-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
  .sz-bar input, .sz-bar select { padding: 7px 10px; border: 1px solid var(--line); border-radius: 8px; font-size: 13.5px; font-family: inherit; background: var(--panel); color: var(--ink); }
  .sz-bar input { min-width: 200px; }
  .sz-bar .cnt { font-size: 12.5px; color: var(--muted); }
  .sz-table { width: 100%; border-collapse: collapse; background: var(--panel); color: var(--ink); border: 1px solid var(--line); border-radius: 10px; overflow: hidden; }
  .sz-table th, .sz-table td { border-bottom: 1px solid var(--line); padding: 8px 10px; text-align: left; font-size: 13.5px; }
  .sz-table th { font-size: 12px; color: var(--muted); }
  .sz-name { font-weight: 700; }
  .sz-small { font-size: 11px; color: var(--muted); margin-left: 6px; }
  .sz-none { color: var(--muted); }
  @media (max-width: 720px) { .sz-table th.hide-sp, .sz-table td.hide-sp { display: none; } }
</style>
@endpush

@section('content')
  <p class="sz-intro">
    在籍中のスタッフと社員の<b>靴（足袋）・服・身長</b>だけを並べた一覧です（衣装の準備用）。サイズは本人がプロフィールで入れたものです。空の人は「—」になります。
  </p>
  @include('partials.office_switch')
  <div class="sz-bar">
    <input type="text" id="szSearch" placeholder="名前・ふりがなで探す" oninput="szFilter()">
    <select id="szKind" onchange="szFilter()">
      <option value="">スタッフと社員</option>
      <option value="スタッフ">スタッフだけ</option>
      <option value="社員">社員だけ</option>
    </select>
    <span class="cnt" id="szCount">{{ count($rows) }}人</span>
  </div>

  <table class="sz-table">
    <tr><th>名前</th><th class="hide-sp">種類</th><th>靴（足袋）</th><th>服</th><th>身長</th></tr>
    @foreach ($rows as $r)
      <tr class="sz-row" data-name="{{ $r['name'] }}" data-kana="{{ $r['kana'] }}" data-kind="{{ $r['kind'] }}">
        <td><span class="sz-name">{{ $r['name'] }}</span>@if ($r['office'] !== '')<span class="sz-small">{{ $r['office'] }}</span>@endif</td>
        <td class="hide-sp">{{ $r['kind'] }}</td>
        <td>@if ($r['shoe'] !== ''){{ $r['shoe'] }}@else<span class="sz-none">—</span>@endif</td>
        <td>@if ($r['wear'] !== ''){{ $r['wear'] }}@else<span class="sz-none">—</span>@endif</td>
        <td>@if ($r['height'] !== ''){{ $r['height'] }}@else<span class="sz-none">—</span>@endif</td>
      </tr>
    @endforeach
  </table>
@endsection

@push('scripts')
<script>
  // 名前・ふりがなで探す／スタッフ・社員で絞る。画面の中だけで絞る（保存はしない）。
  function szFilter() {
    try {
      const q = (document.getElementById('szSearch').value || '').trim().replace(/\s+/g, '');
      const kind = document.getElementById('szKind').value;
      let n = 0;
      document.querySelectorAll('.sz-row').forEach(function (tr) {
        const hay = ((tr.dataset.name || '') + (tr.dataset.kana || '')).replace(/\s+/g, '');
        const show = (!q || hay.includes(q)) && (!kind || tr.dataset.kind === kind);
        tr.style.display = show ? '' : 'none';
        if (show) n++;
      });
      document.getElementById('szCount').textContent = n + '人';
    } catch (e) { console.error('サイズ一覧の絞り込み', e); }
  }
</script>
@endpush
