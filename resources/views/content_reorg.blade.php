@extends('layouts.app')
@section('title', 'コンテンツ台帳の整理')
@section('h1', 'コンテンツ台帳の整理')
@php($active = 'settings')

@push('head')
<style>
  .cr-intro { font-size: 13px; color: var(--muted); line-height: 1.8; margin-bottom: 14px; }
  .cr-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
  .cr-table th, .cr-table td { padding: 6px 8px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
  .cr-table th { font-size: 11.5px; color: var(--muted); font-weight: 700; }
  .cr-ng { color: var(--warn); font-weight: 700; }
  .cr-ok { color: var(--ok, #15803d); font-weight: 700; }
  .cr-flash { padding: 10px 12px; border-radius: 8px; background: var(--ok-soft); color: var(--ok-ink); margin-bottom: 12px; font-size: 13px; }
  .cr-btn { padding: 9px 18px; border: 1px solid var(--brand-fill); border-radius: 8px; font-size: 13.5px; font-weight: 700; font-family: inherit; background: var(--brand-fill); color: #fff; cursor: pointer; }
</style>
@endpush

@section('content')
{{-- コンテンツ台帳の整理（2026-10-09）。案の中身と直し方の正本＝App\Support\ContentReorg（ここで判定し直さない）。 --}}
@if (session('status'))
  <div class="cr-flash">{{ session('status') }}</div>
@endif

<div class="panel">
  <p class="cr-intro">
    「コンテンツ台帳の整理案（2026-10-09）」で決めたとおりに、台帳と案件のつなぎを一気に直します。<br>
    案件は消えません（コンテンツのつながりが変わるだけ・編集履歴に残ります）。必要人数・経験・難易度表のつなぎも一緒に付け替えます。<br>
    「✗」の行は直しません（理由を見てください）。<b>実行できるのは {{ $okCount }}件</b>です。
  </p>
  <table class="cr-table">
    <tr><th>やること</th><th>ID</th><th>いまの名前</th><th>つなぎ先</th><th>案件</th><th></th></tr>
    @foreach ($rows as $r)
      <tr>
        <td>{{ $r['kind'] }}</td>
        <td>{{ $r['id'] }}</td>
        <td>{{ $r['name'] }}</td>
        <td>{{ $r['to'] }}</td>
        <td>{{ $r['projects'] }}件</td>
        <td>@if ($r['ok'])<span class="cr-ok">○</span>@else<span class="cr-ng">✗ {{ $r['why'] }}</span>@endif</td>
      </tr>
    @endforeach
  </table>
  @if ($okCount > 0)
    <form method="POST" action="/masters/content-reorg" style="margin-top:14px;"
          onsubmit="return confirm('○の {{ $okCount }}件を直します。よろしいですか？（元に戻すボタンはありません）')">
      @csrf
      <button class="cr-btn" type="submit">○の{{ $okCount }}件を直す</button>
    </form>
  @endif
</div>
@endsection
