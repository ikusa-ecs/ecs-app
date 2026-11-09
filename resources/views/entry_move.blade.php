@extends('layouts.app')
@section('title', 'エントリーの付け替え')
@section('h1', 'エントリーの付け替え')
@php($active = 'settings')

@push('head')
<style>
  .em-intro { font-size: 13px; color: var(--muted); line-height: 1.8; margin-bottom: 14px; }
  .em-form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 10px; font-size: 13px; }
  .em-form input[type=text] { width: 9.5em; }
  .em-proj { font-size: 13px; margin: 4px 0; color: var(--ink); }
  .em-proj b { font-weight: 800; }
  .em-miss { color: var(--warn-ink); font-weight: 700; font-size: 13px; }
  .em-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px; }
  .em-table th, .em-table td { padding: 7px 9px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
  .em-table th { font-size: 11.5px; color: var(--muted); font-weight: 700; }
  .em-note { font-size: 11.5px; color: var(--muted); }
  .em-done { padding: 8px 10px; border-radius: 8px; background: var(--ok-soft); color: var(--ok-ink); font-size: 13px; margin-bottom: 10px; }
  .em-err { padding: 8px 10px; border-radius: 8px; background: var(--warn-soft); color: var(--warn-ink); font-size: 13px; margin-bottom: 10px; }
  .em-scroll { overflow-x: auto; }
</style>
@endpush

@section('content')
{{-- エントリーを別の案件へ移す（2026-11-09）。正本＝App\Http\Controllers\EntryMoveController。 --}}
<div class="panel">
  @if (session('status'))
    <div class="em-done">{{ session('status') }}</div>
  @endif
  @if ($errors->any())
    <div class="em-err">{{ $errors->first() }}</div>
  @endif

  <p class="em-intro">
    取込で案件の中身が別の案件に入れ替わったとき、<b>スタッフが押したときに選んだ案件</b>へエントリーを移します。<br>
    動くのは<b>エントリーだけ</b>です（希望／可・一言・エントリーした日時はそのまま）。アサインは動きません。
    いつ中身が変わったかは「案件の編集履歴」で見られるので、その時刻より前にエントリーした人を選んでください。
  </p>

  <form method="GET" action="/entry-move" class="em-form">
    <label>移す元の案件ID <input type="text" name="from" value="{{ $fromId }}" placeholder="P-2026-0000"></label>
    <label>移す先の案件ID <input type="text" name="to" value="{{ $toId }}" placeholder="P-2026-0000"></label>
    <button type="submit" class="btn">表示</button>
  </form>

  @if ($fromId !== '')
    @if ($from)
      <div class="em-proj">移す元：<b>{{ $from->id }}</b>　{{ $fromLabel }}　<a href="/project-history?project={{ urlencode($from->id) }}" target="_blank">編集履歴</a></div>
    @else
      <div class="em-miss">移す元の案件 {{ $fromId }} が見つかりません。</div>
    @endif
  @endif
  @if ($toId !== '')
    @if ($to)
      <div class="em-proj">移す先：<b>{{ $to->id }}</b>　{{ $toLabel }}　<a href="/project-history?project={{ urlencode($to->id) }}" target="_blank">編集履歴</a></div>
    @else
      <div class="em-miss">移す先の案件 {{ $toId }} が見つかりません。</div>
    @endif
  @endif

  @if ($from)
    @if (count($rows) === 0)
      <p class="em-note">この案件にエントリーしている人はいません。</p>
    @else
      <form method="POST" action="/entry-move" onsubmit="return confirm('選んだ人のエントリーを移します。よろしいですか？');">
        @csrf
        <input type="hidden" name="from" value="{{ $from->id }}">
        <input type="hidden" name="to" value="{{ $to->id ?? '' }}">
        <div class="em-scroll">
        <table class="em-table">
          <tr><th></th><th>名前</th><th>エントリー</th><th>エントリーした日時</th><th>メモ</th></tr>
          @foreach ($rows as $r)
            <tr>
              <td><input type="checkbox" name="staff[]" value="{{ $r['staff_id'] }}" {{ $r['already'] ? 'disabled' : '' }}></td>
              <td>{{ $r['name'] }}</td>
              <td>{{ $r['intent'] }}@if ($r['note'])<div class="em-note">{{ $r['note'] }}</div>@endif</td>
              <td>{{ $r['at'] }}</td>
              <td class="em-note">
                @if ($r['already'])移す先にもうエントリーがあります@endif
                @if ($r['assigned'])移す元の案件に{{ $r['assigned'] }}でアサイン中（アサインは動きません）@endif
              </td>
            </tr>
          @endforeach
        </table>
        </div>
        @if ($to)
          <p style="margin-top:10px;"><button type="submit" class="btn primary">選んだ人を {{ $to->id }} へ移す</button></p>
        @else
          <p class="em-note" style="margin-top:10px;">移す先の案件IDを入れて「表示」を押すと、移せるようになります。</p>
        @endif
      </form>
    @endif
  @endif
</div>
@endsection
