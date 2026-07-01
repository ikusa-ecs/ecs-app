@extends('layouts.app')
@section('title', '人数確定リマインド')
@section('h1', '人数確定リマインド')
@php($active = 'count_reminder')

@push('head')
<style>
  .cr-intro { font-size: 13px; color: var(--muted); line-height: 1.8; margin-bottom: 14px; }
  .cr-card { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 14px 16px; margin-bottom: 16px; }
  .cr-card h3 { margin: 0 0 8px; font-size: 14px; }

  .cr-status { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; font-size: 13px; }
  .cr-badge { font-size: 12px; padding: 2px 10px; border-radius: 999px; font-weight: 600; white-space: nowrap; }
  .cr-badge.ok   { background: var(--ok-soft); color: #15803d; }
  .cr-badge.warn { background: var(--warn-soft); color: #b45309; }
  .cr-badge.bad  { background: var(--danger-soft); color: #b91c1c; }

  .cr-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 4px; }
  .cr-actions form { margin: 0; }
  .cr-btn { padding: 9px 16px; border: 1px solid var(--line); border-radius: 8px; font-size: 13.5px; font-weight: 600;
            font-family: inherit; background: #fff; cursor: pointer; }
  .cr-btn.dry  { background: #f6f2ea; }
  .cr-btn.test { background: var(--brand-soft); color: var(--brand-dark); border-color: var(--brand-soft); }
  .cr-btn.live { background: #b91c1c; color: #fff; border-color: #b91c1c; }
  .cr-btn:disabled { opacity: .5; cursor: not-allowed; }

  table.cr { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.cr th, table.cr td { padding: 8px 10px; border-bottom: 1px solid var(--line); text-align: left; white-space: nowrap; }
  table.cr th { background: #faf7f1; font-weight: 600; color: #6b5d4d; }
  table.cr td.cwid-miss { color: var(--danger); }
  .days-left { font-size: 12px; font-weight: 600; color: #6b5d4d; }
  .days-left.urgent { color: #b91c1c; font-weight: 700; }
  .sent-tag { font-size: 11px; padding: 1px 8px; border-radius: 999px; background: #ece3d4; color: #7a6a58; }
  .todo-tag { font-size: 11px; padding: 1px 8px; border-radius: 999px; background: var(--warn-soft); color: #b45309; font-weight: 600; }
  .cr-empty { color: var(--muted); font-size: 13px; padding: 18px 4px; }

  .cr-result { border-left: 4px solid var(--brand); background: #faf7f1; padding: 12px 14px; border-radius: 8px;
               white-space: pre-wrap; font-size: 13px; line-height: 1.7; margin-bottom: 16px; }
  .cr-result.err { border-left-color: #b91c1c; background: #fdf2f2; }
</style>
@endpush

@section('content')

@php($result = $result ?? null)
@if($result)
  <div class="cr-result {{ empty($result['ok']) ? 'err' : '' }}">
<strong>{{ $result['title'] ?? '' }}</strong>
@if(($result['mode'] ?? '') === 'dry')
→ 実際には送信していません。送信すると、Dに「期限{{ $result['limitLabel'] ?? '' }}」のタスクを付けます。
@else
送信先ルーム: {{ $result['room'] ?? '' }}（{{ !empty($result['isTest']) ? 'テスト' : '本番' }}）
@endif
対象（未送信）: {{ $result['hit'] ?? 0 }}件@if(($result['skipSent'] ?? 0) > 0)（送信済みでスキップ {{ $result['skipSent'] }}件）@endif
@if(!empty($result['memberError']))
⚠️ {{ $result['memberError'] }}
@endif
@if(($result['mode'] ?? '') !== 'dry')
@if(($result['hit'] ?? 0) === 0)送る案件はありませんでした@else✅ 送信: {{ $result['sent'] ?? 0 }}通@if(!empty($result['sendErr'])) / ❌ 送信失敗: {{ $result['sendErr'] }}@endif
✅ Dへタスク: {{ $result['taskCount'] ?? 0 }}件（期限{{ $result['limitLabel'] ?? '' }}）@if(($result['taskErr'] ?? 0) > 0) / ❌{{ $result['taskErr'] }}件失敗@endif
@endif
@endif
@if(!empty($result['unknownNames']))
⚠️ CWIDが取れず@できなかった人: {{ implode('、', $result['unknownNames']) }}
　→ その人がチャットワークの送信先ルームに入っているか確認してください。
@endif
  </div>
@endif

<div class="cr-intro">
  イベント開催の <strong>{{ $daysBefore }}日前（2週間前）</strong> を迎えた案件を拾い、参加人数の確定締め切りを
  <strong>営業＋D（ディレクター）</strong> へチャットワークで1通にまとめて知らせます。Dには期限つきタスクも付きます。<br>
  一度送った案件は二度送りません（送信済みは下の表で「送信済み」と表示）。開催日・営業・Dは ECS の案件データ（案件一覧・D決め）から読みます。
</div>

{{-- 設定・操作 --}}
<div class="cr-card">
  <h3>送信の設定と操作</h3>
  <div class="cr-status">
    @if($hasToken)
      <span class="cr-badge ok">チャットワーク鍵：設定済み</span>
    @else
      <span class="cr-badge bad">チャットワーク鍵：未設定</span>
      <span style="color:var(--muted);">→ <code>.env</code> の <code>CHATWORK_TOKEN</code> にトークンを入れると送信できます（件数確認は鍵なしでも可）。</span>
    @endif
    <span class="cr-badge warn">本番ルーム: {{ $room }}</span>
    <span class="cr-badge warn">テストルーム: {{ $testRoom }}</span>
  </div>

  <div class="cr-actions">
    <form method="post" action="/count-reminder/send">
      @csrf
      <input type="hidden" name="mode" value="dry">
      <button type="submit" class="cr-btn dry">③ 件数だけ確認（送らない）</button>
    </form>
    <form method="post" action="/count-reminder/send">
      @csrf
      <input type="hidden" name="mode" value="test">
      <button type="submit" class="cr-btn test" {{ $hasToken ? '' : 'disabled' }}
        onclick="return confirm('テスト用ルームへ実際に送ります。よろしいですか？');">② テスト送信</button>
    </form>
    <form method="post" action="/count-reminder/send">
      @csrf
      <input type="hidden" name="mode" value="live">
      <button type="submit" class="cr-btn live" {{ $hasToken ? '' : 'disabled' }}
        onclick="return confirm('⚠️ 本番ルームへ実際に送ります。よろしいですか？\n※ いまのECSは見本データです。本番の実運用データが入るまでは、実送信にご注意ください。');">④ 本番送信</button>
    </form>
  </div>
</div>

{{-- 対象案件の一覧 --}}
<div class="cr-card">
  <h3>対象案件（開催まで{{ $daysBefore }}日以内・{{ count($cases) }}件）</h3>
  @if(count($cases) === 0)
    <div class="cr-empty">いまは対象の案件がありません（開催まで{{ $daysBefore }}日以内の本番案件がありません）。</div>
  @else
    <table class="cr">
      <thead>
        <tr>
          <th>開催日</th><th>開催まで</th><th>コンテンツ</th><th>クライアント</th><th>営業</th><th>D（ディレクター）</th><th>状態</th>
        </tr>
      </thead>
      <tbody>
        @foreach($cases as $c)
          <tr>
            <td>{{ $c['dateStr'] }}</td>
            <td>
              @if(($c['daysLeft'] ?? 0) <= 0)
                <span class="days-left urgent">本日</span>
              @elseif(($c['daysLeft'] ?? 0) <= 3)
                <span class="days-left urgent">あと{{ $c['daysLeft'] }}日</span>
              @else
                <span class="days-left">あと{{ $c['daysLeft'] }}日</span>
              @endif
            </td>
            <td>{{ $c['content'] !== '' ? $c['content'] : '(未記入)' }}</td>
            <td>{{ $c['client'] !== '' ? $c['client'] : '—' }}</td>
            <td class="{{ $c['sales'] === '' ? 'cwid-miss' : '' }}">{{ $c['sales'] !== '' ? $c['sales'] : '(未記入)' }}</td>
            <td class="{{ $c['director'] === '' ? 'cwid-miss' : '' }}">{{ $c['director'] !== '' ? $c['director'] : '(D未定)' }}</td>
            <td>
              @if($c['alreadySent'])
                <span class="sent-tag">送信済み</span>
              @else
                <span class="todo-tag">未送信</span>
              @endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>

@endsection
