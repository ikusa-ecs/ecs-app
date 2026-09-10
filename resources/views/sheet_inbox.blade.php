@extends('layouts.app')
@section('title', 'アサイン表の受信箱')
@section('h1', 'アサイン表の受信箱')
@php($active = 'sheet_inbox')

{{-- 毎朝スプレッドシートから届いたアサイン表を、月ごとに並べる画面（2026-09-10 baba要望）。
     ⚠ 届いた時点ではECSのデータは1文字も変わっていない。反映は「差分を見て取り込む」を押したときだけ。 --}}

@push('head')
<style>
  .sx-wrap { max-width: 980px; }
  .sx-lead { font-size: 13px; color: #6b5c49; line-height: 1.8; margin: 0 0 14px; }
  .sx-lead b { color: var(--ink); }
  .sx-flash { border-radius: 10px; padding: 12px 14px; font-size: 13px; margin-bottom: 14px; line-height: 1.8; }
  .sx-flash.warn { background: #fdf3e2; color: #8a5a10; border: 1px solid #ecd9b6; }
  .sx-flash.ok { background: #eef6f0; color: #166534; border: 1px solid #bfe6d2; }
  .sx-card { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 16px 18px; margin-bottom: 14px; }
  table.sx-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.sx-table th, table.sx-table td { border: 1px solid var(--line); padding: 7px 9px; text-align: left; }
  table.sx-table th { background: #f6f1ea; font-size: 12px; }
  .sx-need { font-weight: 700; color: #8a5a10; }
  .sx-done { color: #9a8f80; }
  .sx-when { font-size: 11.5px; color: #9a8f80; }
  .sx-empty { font-size: 13px; color: #6b5c49; line-height: 1.9; }

  /* 黒ベース（2026-09-09の決まり）。面＝var(--panel)・文字＝var(--ink) に読み替える。 */
  html[data-theme="dark"] .sx-lead,
  html[data-theme="dark"] .sx-empty,
  html[data-theme="dark"] .sx-when { color: var(--muted); }
  html[data-theme="dark"] table.sx-table th { background: var(--surface-2); }
  html[data-theme="dark"] .sx-flash.warn { background: var(--warn-soft); color: var(--warn-ink); border-color: var(--warn-line); }
  html[data-theme="dark"] .sx-flash.ok { background: var(--ok-soft); color: var(--ok-ink); border-color: var(--ok-line); }
  html[data-theme="dark"] .sx-need { color: var(--warn-ink); }
  html[data-theme="dark"] .sx-done { color: var(--muted-dim); }
</style>
@endpush

@section('content')
<div class="sx-wrap">
  <p class="sx-lead">
    毎朝、スプレッドシートのアサイン表が<b>ひとりでにここへ届きます</b>（今月から2027年6月ぶんまで）。<br>
    <b>届いただけでは、ECSは何も変わりません。</b>「差分を見て取り込む」を押して、
    <b>変わったところを確かめてから</b>反映してください。
  </p>

  @if (! $ready)
    <div class="sx-flash warn">
      <b>まだ届きません（合言葉が設定されていません）。</b><br>
      サーバー側の設定ファイル（.env）に <code>ECS_SHEET_SYNC_TOKEN</code> を入れて、
      同じ合言葉をスプレッドシート側の仕掛けにも入れると届き始めます。
      <span class="sx-when">※ 合言葉を決めていないあいだは、外から何が送られてきても受け取りません（安全側に閉じてあります）。</span>
    </div>
  @endif

  <div class="sx-card">
    <h2 style="font-size:14px; margin:0 0 10px;">届いているアサイン表（{{ $office }}）</h2>

    @if ($rows->isEmpty())
      <p class="sx-empty">
        まだ何も届いていません。<br>
        <b>手で入れることもできます</b>：<a href="/past-import">アサイン表の取込</a> から、
        スプレッドシートを「カンマ区切り形式」で落としたCSVを読み込んでください（差分の出方は同じです）。
      </p>
    @else
      <table class="sx-table">
        <thead>
          <tr>
            <th style="width:90px;">何月ぶん</th>
            <th style="width:70px;">案件数</th>
            <th>状態</th>
            <th style="width:150px;">最後に届いた</th>
            <th style="width:130px;"></th>
          </tr>
        </thead>
        <tbody>
          @foreach ($rows as $row)
            <tr>
              <td><b>{{ $row->period }}</b>
                @if ($row->tab)<br><span class="sx-when">タブ {{ $row->tab }}</span>@endif
              </td>
              <td>{{ $row->case_count }} 件</td>
              <td>
                @if ($row->needsAttention())
                  <span class="sx-need">
                    @if ($row->applied_at === null)
                      ⚠ まだ一度も反映していません
                    @else
                      ⚠ 反映したあとにシートが変わりました
                    @endif
                  </span>
                  @if ($row->changed_at)
                    <br><span class="sx-when">シートが変わったのは {{ $row->changed_at->format('n月j日 H:i') }}</span>
                  @endif
                @else
                  <span class="sx-done">✅ 反映済み（そのあとの変更はありません）</span>
                @endif
                @if ($row->applied_at)
                  <br><span class="sx-when">最後に反映したのは {{ $row->applied_at->format('n月j日 H:i') }}</span>
                @endif
              </td>
              <td><span class="sx-when">{{ $row->received_at ? $row->received_at->format('n月j日 H:i') : '—' }}</span></td>
              <td>
                <a class="btn {{ $row->needsAttention() ? 'primary' : '' }}"
                   href="/past-import?sync={{ $row->id }}">差分を見て取り込む</a>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>

      <p class="sx-lead" style="margin:12px 2px 0;">
        ⚠ 「✅ 反映済み」でも、<b>ECS側で誰かが直していれば差分は出ます</b>。
        この印は「<b>届いた中身を反映したあと、シートが変わっていない</b>」という意味です。
      </p>
    @endif
  </div>

  <div class="sx-card">
    <h2 style="font-size:14px; margin:0 0 8px;">拠点</h2>
    <form method="GET" action="/sheet-inbox" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
      <select name="office" style="padding:7px 10px; border:1px solid var(--line); border-radius:8px; font-size:13px;">
        @foreach ($offices as $o)
          <option value="{{ $o }}" @selected($o === $office)>{{ $o }}</option>
        @endforeach
      </select>
      <button class="btn" type="submit">切り替える</button>
      <span class="sx-when">※ 受け取ったシートは拠点ごとに別物です。混ぜて出すと取り違えるので、1拠点ずつ表示します。</span>
    </form>
  </div>
</div>
@endsection
