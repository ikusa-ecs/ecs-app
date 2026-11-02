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
  .sz-sec { margin: 18px 0 8px; font-size: 15px; font-weight: 800; color: var(--ink); }
  .sz-dept { margin: 12px 0 6px; font-size: 13px; font-weight: 700; color: var(--muted); }
  .sz-table { width: 100%; border-collapse: collapse; background: var(--panel); color: var(--ink); border: 1px solid var(--line); border-radius: 10px; overflow: hidden; }
  .sz-table th, .sz-table td { border-bottom: 1px solid var(--line); padding: 8px 10px; text-align: left; font-size: 13.5px; }
  .sz-table th { font-size: 12px; color: var(--muted); }
  .sz-table td.sz-n { width: 34%; }
  .sz-name { font-weight: 700; }
  .sz-small { font-size: 11px; color: var(--muted); margin-left: 6px; }
  .sz-none { color: var(--muted); }
</style>
@endpush

@section('content')
  <p class="sz-intro">
    在籍中のスタッフと社員の<b>靴（足袋）・服・身長</b>だけを並べた一覧です（衣装の準備用）。サイズは本人がプロフィールで入れたものです。
    スタッフはサイズを登録している人だけ出ます。空の欄は「—」になります。
  </p>
  @include('partials.office_switch')

  {{-- 常勤のスタッフに見せる／見せない（社員だけに出る・押した瞬間に保存・2026-11-02 小沼さん「つど権限を付与する」）。 --}}
  @if ($fulltime !== null)
    <div class="sz-grant">
      <div class="sz-sec" style="margin-top:4px;">👀 この一覧を見せる常勤スタッフ</div>
      <p class="sz-intro" style="margin-bottom:6px;">チェックした人だけ、スタッフ画面からこの一覧を開けます。使い終わったらチェックを外してください。常勤の印はスタッフ名簿の「詳細」→「区分」で付けます。</p>
      @if (count($fulltime) === 0)
        <p class="sz-intro">常勤のスタッフがいません。</p>
      @else
        <div style="display:flex; flex-wrap:wrap; gap:6px 16px;">
          @foreach ($fulltime as $f)
            <label style="font-size:13.5px; cursor:pointer;">
              <input type="checkbox" class="sz-grant-cb" data-id="{{ $f['id'] }}" @checked($f['on']) onchange="szGrant(this)">
              {{ $f['name'] }}@if ($f['office'] !== '')<span class="sz-small">{{ $f['office'] }}</span>@endif
              <span class="sz-small sz-grant-msg"></span>
            </label>
          @endforeach
        </div>
      @endif
    </div>
  @endif
  <div class="sz-bar">
    <input type="text" id="szSearch" placeholder="名前・ふりがなで探す" oninput="szFilter()">
    <select id="szKind" onchange="szFilter()">
      <option value="">スタッフと社員</option>
      <option value="staff">スタッフだけ</option>
      <option value="emp">社員だけ</option>
    </select>
    <span class="cnt" id="szCount">{{ count($staff) + collect($employees)->sum(fn ($r) => count($r)) }}人</span>
  </div>

  {{-- スタッフ --}}
  <div class="sz-part" data-kind="staff">
    <div class="sz-sec">スタッフ（{{ count($staff) }}人）</div>
    @include('partials.sizes_table', ['rows' => $staff])
  </div>

  {{-- 社員（部署ごと・イベプラが先頭） --}}
  <div class="sz-part" data-kind="emp">
    <div class="sz-sec">社員（{{ collect($employees)->sum(fn ($r) => count($r)) }}人）</div>
    @foreach ($employees as $dept => $rows)
      <div class="sz-group">
        <div class="sz-dept">{{ $dept }}（{{ count($rows) }}人）</div>
        @include('partials.sizes_table', ['rows' => $rows])
      </div>
    @endforeach
  </div>
@endsection

@push('scripts')
<script>
  // 常勤のスタッフに見せる／見せない。押した瞬間に保存。失敗したらチェックを戻して赤字（請求書チェックと同じ作り）。
  function szGrant(cb) {
    const msg = cb.parentElement.querySelector('.sz-grant-msg');
    const on = cb.checked;
    msg.textContent = '保存中…'; msg.style.color = '';
    fetch('/sizes/grant', {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: cb.dataset.id, on: on })
    })
    .then(r => r.ok ? r.json() : Promise.reject(r.status))
    .then(() => { msg.textContent = on ? '見せています' : ''; })
    .catch(err => { cb.checked = !on; msg.textContent = '保存できませんでした（' + err + '）'; msg.style.color = '#b91c1c'; });
  }
</script>
<script>
  // 名前・ふりがなで探す／スタッフ・社員で絞る。画面の中だけで絞る（保存はしない）。
  function szFilter() {
    try {
      const q = (document.getElementById('szSearch').value || '').trim().replace(/\s+/g, '');
      const kind = document.getElementById('szKind').value;
      let n = 0;
      document.querySelectorAll('.sz-part').forEach(function (part) {
        const partOn = !kind || part.dataset.kind === kind;
        part.style.display = partOn ? '' : 'none';
        part.querySelectorAll('.sz-row').forEach(function (tr) {
          const hay = ((tr.dataset.name || '') + (tr.dataset.kana || '')).replace(/\s+/g, '');
          const show = partOn && (!q || hay.includes(q));
          tr.style.display = show ? '' : 'none';
          if (show) n++;
        });
      });
      // 名前で探したとき、1人も当たらない部署の見出しは隠す。
      document.querySelectorAll('.sz-group').forEach(function (g) {
        const any = Array.prototype.some.call(g.querySelectorAll('.sz-row'), function (tr) { return tr.style.display !== 'none'; });
        g.style.display = any ? '' : 'none';
      });
      document.getElementById('szCount').textContent = n + '人';
    } catch (e) { console.error('サイズ一覧の絞り込み', e); }
  }
</script>
@endpush
