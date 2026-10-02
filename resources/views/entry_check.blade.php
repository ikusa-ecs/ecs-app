@extends('layouts.app')
@section('title', 'エントリーの点検')
@section('h1', 'エントリーの点検')
@php($active = 'settings')

@push('head')
<style>
  .ec-intro { font-size: 13px; color: var(--muted); line-height: 1.8; margin-bottom: 14px; }
  .ec-form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 6px; font-size: 13px; }
  .ec-h { font-size: 15px; font-weight: 800; margin: 18px 0 6px; color: var(--ink); }
  .ec-count { font-size: 12px; color: var(--muted); font-weight: 600; margin-left: 6px; }
  .ec-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .ec-table th, .ec-table td { padding: 7px 9px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
  .ec-table th { font-size: 11.5px; color: var(--muted); font-weight: 700; }
  .ec-note { font-size: 11.5px; color: var(--muted); }
  .ec-old { text-decoration: line-through; color: var(--muted); }
  .ec-new { font-weight: 700; color: var(--warn-ink); }
  .ec-none { font-size: 13px; color: var(--ok-ink); }
  .ec-scroll { overflow-x: auto; }
</style>
@endpush

@section('content')
{{-- 取込でIDがずれた件（9/30ごろ）の点検。判定の正本＝App\Support\EntryCheck。見るだけでデータは変えない。 --}}
<div class="panel">
  <p class="ec-intro">
    スタッフのエントリーが、<b>本人が押したときとは別の案件</b>を指してしまっていないかを探します。<b>見るだけ</b>で、データは変えません。<br>
    ここに出た人だけ、本人に「この案件で合っていますか？」と確かめれば足ります。
    エントリーしたあとに本人が押し直していれば、変わったあとの中身を見て押しているので出てきません。
  </p>
  <form method="GET" action="/entry-check" class="ec-form">
    <label>この日より後の変更を見る：<input type="date" name="from" value="{{ $from }}"></label>
    <button type="submit" class="btn">表示</button>
  </form>

  <div class="ec-h">① エントリーしたあとに、案件の中身が書き換わった<span class="ec-count">{{ count($changed) }}件</span></div>
  <p class="ec-note">開催日・案件名・クライアント・コンテンツのどれかが、エントリーより後に変わったもの。</p>
  @if (count($changed) === 0)
    <p class="ec-none">ありません。</p>
  @else
    <div class="ec-scroll"><table class="ec-table">
      <thead><tr><th>スタッフ</th><th>いまの案件</th><th>エントリーした日時</th><th>そのあと変わったところ</th></tr></thead>
      <tbody>
        @foreach ($changed as $r)
          <tr>
            <td>{{ $r['staff_name'] }} <span class="ec-note">（{{ $r['staff_id'] }}）</span></td>
            <td>{{ $r['start_date'] }} {{ $r['project_name'] }}<div class="ec-note">{{ $r['project_id'] }}</div></td>
            <td>{{ $r['applied_at'] }}</td>
            <td>
              @foreach ($r['diffs'] as $d)
                <div>{{ $d['label'] }}：<span class="ec-old">{{ $d['old'] }}</span> → <span class="ec-new">{{ $d['new'] }}</span>
                  <span class="ec-note">（{{ $d['at'] }}・{{ $d['by'] }}）</span></div>
              @endforeach
            </td>
          </tr>
        @endforeach
      </tbody>
    </table></div>
  @endif

  <div class="ec-h">② 同じ日に、あとから別の番号で同じ案件ができた<span class="ec-count">{{ count($twins) }}件</span></div>
  <p class="ec-note">本人は古い方にエントリーしたまま。新しい方にはエントリーしていない。</p>
  @if (count($twins) === 0)
    <p class="ec-none">ありません。</p>
  @else
    <div class="ec-scroll"><table class="ec-table">
      <thead><tr><th>スタッフ</th><th>エントリーしている案件</th><th>エントリーした日時</th><th>あとからできた案件</th></tr></thead>
      <tbody>
        @foreach ($twins as $r)
          <tr>
            <td>{{ $r['staff_name'] }} <span class="ec-note">（{{ $r['staff_id'] }}）</span></td>
            <td>{{ $r['start_date'] }} {{ $r['project_name'] }}<div class="ec-note">{{ $r['project_id'] }}</div></td>
            <td>{{ $r['applied_at'] }}</td>
            <td>{{ $r['twin_name'] }}<div class="ec-note">{{ $r['twin_id'] }}（{{ $r['twin_created'] }} にできた）</div></td>
          </tr>
        @endforeach
      </tbody>
    </table></div>
  @endif

  <div class="ec-h">③ エントリー先の案件が、もう無い<span class="ec-count">{{ count($missing) }}件</span></div>
  @if (count($missing) === 0)
    <p class="ec-none">ありません。</p>
  @else
    <div class="ec-scroll"><table class="ec-table">
      <thead><tr><th>スタッフ</th><th>消えた案件</th><th>エントリーした日時</th></tr></thead>
      <tbody>
        @foreach ($missing as $r)
          <tr>
            <td>{{ $r['staff_name'] }} <span class="ec-note">（{{ $r['staff_id'] }}）</span></td>
            <td>{{ $r['project_name'] }}<div class="ec-note">{{ $r['project_id'] }}</div></td>
            <td>{{ $r['applied_at'] }}</td>
          </tr>
        @endforeach
      </tbody>
    </table></div>
  @endif
</div>
@endsection
