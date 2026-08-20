@extends('layouts.app')
@section('title', 'スタッフへのお知らせ送信')
@section('h1', 'スタッフへのお知らせ送信')
@php($active = 'staff_notify')

@push('head')
<style>
  .sn-intro { font-size: 13px; color: var(--muted); line-height: 1.8; margin-bottom: 14px; }
  .sn-card { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 14px 16px; margin-bottom: 16px; }
  .sn-card h3 { margin: 0 0 8px; font-size: 14px; }

  .sn-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
  .sn-tab { padding: 8px 14px; border: 1px solid var(--line); border-radius: 999px; font-size: 13px; font-weight: 600;
            background: #fff; color: var(--ink); }
  .sn-tab:hover { text-decoration: none; background: #faf7f1; }
  .sn-tab.on { background: var(--brand-soft); color: var(--brand-dark); border-color: var(--brand-soft); }

  .sn-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 4px; align-items: center; }
  .sn-actions form { margin: 0; }
  .sn-btn { padding: 9px 16px; border: 1px solid var(--line); border-radius: 8px; font-size: 13.5px; font-weight: 600;
            font-family: inherit; background: #fff; cursor: pointer; }
  .sn-btn.dry  { background: #f6f2ea; }
  .sn-btn.test { background: var(--brand-soft); color: var(--brand-dark); border-color: var(--brand-soft); }
  .sn-btn.live { background: #b91c1c; color: #fff; border-color: #b91c1c; }
  .sn-btn:disabled { opacity: .5; cursor: not-allowed; }

  table.sn { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.sn th, table.sn td { padding: 8px 10px; border-bottom: 1px solid var(--line); text-align: left; }
  table.sn th { background: #faf7f1; font-weight: 600; color: #6b5d4d; white-space: nowrap; }
  table.sn td.nowrap { white-space: nowrap; }
  .sn-skip { font-size: 11.5px; padding: 1px 8px; border-radius: 999px; background: var(--warn-soft); color: #b45309; font-weight: 600; }
  .sn-ok { font-size: 11.5px; padding: 1px 8px; border-radius: 999px; background: var(--ok-soft); color: #15803d; font-weight: 600; }
  .sn-empty { color: var(--muted); font-size: 13px; padding: 18px 4px; }

  .sn-result { border-left: 4px solid var(--brand); background: #faf7f1; padding: 12px 14px; border-radius: 8px;
               white-space: pre-wrap; font-size: 13px; line-height: 1.7; margin-bottom: 16px; }
  .sn-preview { background: #faf7f1; border: 1px solid var(--line); border-radius: 8px; padding: 12px 14px;
                font-size: 12.5px; line-height: 1.8; white-space: pre-wrap; font-family: inherit; }
  .sn-note { font-size: 12px; color: var(--muted); line-height: 1.7; }

  @media (max-width: 720px) {
    table.sn th, table.sn td { white-space: normal; }
    .sn-btn { width: 100%; }
    .sn-actions form { width: 100%; }
  }
</style>
@endpush

@section('content')

<p class="sn-intro">
  スタッフ本人へ「<b>アサインが確定しました</b>」「<b>募集が出ました</b>」をメールで知らせる画面です。<br>
  <b>自動では送りません。</b>ここで相手と文面を確かめてから「送信」を押してください
  （いまDBのメールアドレスは見本データが多いため、誤送信を防ぐためにこの形にしています）。
  同じ知らせは二度送りません。
</p>

<div class="sn-tabs">
  <a class="sn-tab {{ $isConfirmed ? 'on' : '' }}" href="/assign-notify?kind=assign_confirmed">✅ アサイン確定のお知らせ</a>
  <a class="sn-tab {{ $isConfirmed ? '' : 'on' }}" href="/assign-notify?kind=project_published">📣 募集開始のお知らせ</a>
</div>

@if ($result)
  <div class="sn-result">@foreach ($result['messages'] as $m){{ $m }}
@endforeach</div>
@endif

<div class="sn-card">
  <h3>送る</h3>
  <p class="sn-note">
    対象 <b>{{ count($cases) }}</b> 件（うち実際に送れるのは <b>{{ $sendableCount }}</b> 件）。<br>
    メールの送り方＝<b>{{ $mailer }}</b>@if ($mailer === 'log')（＝いまは実際には送らず、ログに書き出すだけです）@endif
  </p>
  <div class="sn-actions">
    <form method="POST" action="/assign-notify/send">
      @csrf
      <input type="hidden" name="kind" value="{{ $kind }}">
      <input type="hidden" name="mode" value="dry">
      <button type="submit" class="sn-btn dry">件数を数えるだけ</button>
    </form>
    <form method="POST" action="/assign-notify/send">
      @csrf
      <input type="hidden" name="kind" value="{{ $kind }}">
      <input type="hidden" name="mode" value="test">
      <button type="submit" class="sn-btn test" {{ count($cases) ? '' : 'disabled' }}>自分に1件だけ送る（文面確認）</button>
    </form>
    <form method="POST" action="/assign-notify/send"
          onsubmit="return confirm('{{ $sendableCount }}名のスタッフへ実際にメールを送ります。よろしいですか？');">
      @csrf
      <input type="hidden" name="kind" value="{{ $kind }}">
      <input type="hidden" name="mode" value="live">
      <button type="submit" class="sn-btn live" {{ $sendableCount ? '' : 'disabled' }}>スタッフへ送信する</button>
    </form>
  </div>
</div>

@if ($preview)
<div class="sn-card">
  <h3>文面（1件目の例）</h3>
  <div class="sn-preview">件名： {{ $preview['subject'] }}

{{ $preview['staff_name'] }} 様

{{ $preview['headline'] }}
@foreach ($preview['lines'] as $label => $value)@if (trim((string) $value) !== '')
・{{ $label }}： {{ $value }}
@endif
@endforeach

詳しい内容は ECS の「スタッフ画面」でご確認ください。
@if (trim($preview['footer']) !== '')

{{ $preview['footer'] }}
@endif</div>
</div>
@endif

<div class="sn-card">
  <h3>送る相手（{{ count($cases) }}件）</h3>
  @if (! count($cases))
    <div class="sn-empty">
      いま送る相手はいません。
      @if ($isConfirmed)
        （公開ONの案件で、これからの日に「確定」でアサインされている人が対象です。まだ送っていない人だけ並びます）
      @else
        （公開ONで募集中の案件について、その拠点のスタッフが対象です。まだ送っていない人だけ並びます）
      @endif
    </div>
  @else
    <table class="sn">
      <thead>
        <tr>
          <th>日付</th><th>案件</th><th>スタッフ</th><th>宛先</th><th>状態</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($cases as $c)
          <tr>
            <td class="nowrap">{{ $c['date'] }}</td>
            <td>{{ $c['project'] }}</td>
            <td class="nowrap">{{ $c['staff_name'] }}</td>
            <td>{{ $c['to'] !== '' ? $c['to'] : '（未登録）' }}</td>
            <td class="nowrap">
              @if ($c['skipReason'] === null)
                <span class="sn-ok">送れます</span>
              @else
                <span class="sn-skip">送りません：{{ $c['skipReason'] }}</span>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>

@if ($recent->count())
<div class="sn-card">
  <h3>送信の記録（新しい順・最大30件）</h3>
  <table class="sn">
    <thead>
      <tr><th>いつ</th><th>種類</th><th>案件</th><th>宛先</th><th>結果</th></tr>
    </thead>
    <tbody>
      @foreach ($recent as $r)
        <tr>
          <td class="nowrap">{{ optional($r->sent_at)->format('n/j H:i') }}</td>
          <td class="nowrap">{{ $r->kind === 'assign_confirmed' ? 'アサイン確定' : '募集開始' }}</td>
          <td>{{ $r->project_id }}</td>
          <td>{{ $r->to ?? '—' }}</td>
          <td class="nowrap">
            @if ($r->status === 'sent')
              <span class="sn-ok">送信</span>
            @else
              <span class="sn-skip">{{ $r->status === 'skipped' ? '送らず' : '失敗' }}{{ $r->note ? '：'.$r->note : '' }}</span>
            @endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
</div>
@endif

@endsection
