@extends('layouts.app')
@section('title', 'エントリー新着')
@section('h1', 'エントリー新着（来た順）')
@php($active = 'entries')

{{-- ⚠ 2026-09-10：この画面は **エントリー一覧（/entries）の「🆕 新着（来た順）」タブ**へ引っ越しました。
     ふだんこのファイルは使いません（`/entry-feed` を開くと `/entries?view=feed` へ転送されます）。
     中身は `partials/entry_feed_panel.blade.php` の1か所に置いてあるので、
     もし将来ここを画面として復活させるときも、見た目を書き写す必要はありません。 --}}
@section('content')
  @include('partials.office_switch')
  @include('partials.entry_feed_panel')
@endsection
