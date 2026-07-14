@extends('layouts.app')
@section('title', '2段階認証')
@section('h1', '2段階認証')
@php $active = 'two_factor'; @endphp

@section('content')
      {{-- なぜ／使い方：パスワードに加えて、スマホの認証アプリが出す6桁コードを
           ログイン時に求める設定です。パスワードが漏れても、スマホが無いと入れないので安全です。 --}}
      <div class="mock-note">
        パスワードに加えて「スマホの認証アプリが出す6桁のコード」でログインする設定です。オンにすると、ログインのたびにコードの入力が必要になり、安全性が上がります。
      </div>

      @php
        $statusLabels = [
          'two-factor-authentication-enabled'   => '2段階認証を開始しました。下のQRコードを認証アプリで読み取り、表示された6桁コードで確認してください。',
          'two-factor-authentication-confirmed' => '2段階認証を有効にしました。次回ログインからコードの入力が必要になります。',
          'two-factor-authentication-disabled'  => '2段階認証をオフにしました。',
          'recovery-codes-generated'            => 'リカバリコードを再発行しました。',
        ];
      @endphp

      @if (session('status') && isset($statusLabels[session('status')]))
        <div style="background:var(--ok-soft, #e7f6ec); color:#166534; border:1px solid #b7e0c2; border-radius:10px; padding:12px 14px; font-size:13px; margin-bottom:16px; max-width:560px;">
          {{ $statusLabels[session('status')] }}
        </div>
      @endif

      @if ($errors->any())
        <div style="background:#fdecec; color:#b91c1c; border:1px solid #f3c0c0; border-radius:10px; padding:12px 14px; font-size:13px; margin-bottom:16px; max-width:560px;">
          {{ $errors->first() }}
        </div>
      @endif

      @if ($isTest)
        {{-- テスト用ログインはDBに保存しないため 2FA を設定できない。 --}}
        <div class="panel" style="max-width:560px;">
          <p style="margin:0; font-size:14px;">これは<b>テスト用ログイン</b>のため、2段階認証の設定はできません。</p>
          <p style="margin:8px 0 0; font-size:13px; color:var(--muted);">DBに登録された実在アカウントでログインすると設定できます。</p>
        </div>

      @elseif (! $enabled)
        {{-- まだオフ：有効化ボタン --}}
        <div class="panel" style="max-width:560px;">
          <p style="margin:0 0 6px; font-size:15px;"><b>現在：オフ</b></p>
          <p style="margin:0 0 16px; font-size:13px; color:var(--muted);">「2段階認証を始める」を押すと、QRコードが表示されます。スマホの認証アプリ（Google Authenticator など）で読み取ってください。</p>
          <form method="POST" action="/user/two-factor-authentication">
            @csrf
            <button class="btn primary" type="submit">2段階認証を始める</button>
          </form>
        </div>

      @else
        {{-- 有効化の手続き中 or 有効済み --}}
        <div class="panel" style="max-width:560px;">
          @if ($confirmed)
            <p style="margin:0 0 6px; font-size:15px; color:#166534;"><b>現在：オン（有効）</b></p>
            <p style="margin:0 0 16px; font-size:13px; color:var(--muted);">次回ログインから、パスワードのあとに認証アプリの6桁コードを聞かれます。</p>
          @else
            <p style="margin:0 0 6px; font-size:15px;"><b>あと1歩：コードで確認してください</b></p>
            <p style="margin:0 0 12px; font-size:13px; color:var(--muted);">下のQRコードを認証アプリで読み取り、表示された6桁コードを入力して「確認して有効化」を押すと、オンになります。</p>

            <div style="display:flex; gap:20px; flex-wrap:wrap; align-items:flex-start; margin-bottom:16px;">
              <div style="background:#2d3748; padding:10px; border-radius:10px;">{!! $qrSvg !!}</div>
              <form method="POST" action="/user/confirmed-two-factor-authentication" style="min-width:220px;">
                @csrf
                <div class="form-row">
                  <label>認証アプリの6桁コード</label>
                  <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" required autofocus>
                </div>
                <div style="margin-top:12px;">
                  <button class="btn primary" type="submit">確認して有効化</button>
                </div>
              </form>
            </div>
          @endif

          {{-- リカバリコード：スマホを無くしたとき、コードの代わりに1回だけ使える緊急コード。 --}}
          <div style="border-top:1px dashed var(--line, #e6cdb8); margin-top:8px; padding-top:16px;">
            <p style="margin:0 0 6px; font-size:14px;"><b>リカバリコード</b></p>
            <p style="margin:0 0 10px; font-size:12px; color:var(--muted);">スマホを無くしたとき用の緊急コードです。安全な場所に控えてください（各コードは1回だけ使えます）。</p>
            <div style="display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:6px 18px; font-family:monospace; font-size:13px; background:var(--brand-soft,#f6e9dd); padding:12px 14px; border-radius:8px;">
              @foreach ($recoveryCodes as $code)
                <div>{{ $code }}</div>
              @endforeach
            </div>
            <form method="POST" action="/user/two-factor-recovery-codes" style="margin-top:10px;">
              @csrf
              <button class="btn ghost" type="submit">リカバリコードを再発行</button>
            </form>
          </div>

          {{-- 無効化（オフに戻す） --}}
          <div style="border-top:1px dashed var(--line, #e6cdb8); margin-top:16px; padding-top:16px;">
            <form method="POST" action="/user/two-factor-authentication" onsubmit="return confirm('2段階認証をオフにしますか？');">
              @csrf
              @method('DELETE')
              <button class="btn" type="submit" style="background:#fdecec; border:1px solid #f3c0c0; color:#b91c1c;">2段階認証をオフにする</button>
            </form>
          </div>
        </div>
      @endif
@endsection
