@extends('layouts.app')
@section('title', 'パスワード変更')
@section('h1', 'パスワード変更')
@php($active = 'password')

@section('content')
      {{-- なぜ／使い方：ログイン中の本人が、自分のログインパスワードを変える画面です。 --}}
      <div class="mock-note">
        ログイン中のあなた自身のパスワードを変更できます。安全のため、まず「現在のパスワード」を確認してから新しいパスワードに切り替えます。
      </div>

      @if (session('status'))
        <div style="background:var(--ok-soft, #e7f6ec); color:#166534; border:1px solid #b7e0c2; border-radius:10px; padding:12px 14px; font-size:13px; margin-bottom:16px; max-width:480px;">
          {{ session('status') }}
        </div>
      @endif

      @if ($errors->any())
        <div style="background:#fdecec; color:#b91c1c; border:1px solid #f3c0c0; border-radius:10px; padding:12px 14px; font-size:13px; margin-bottom:16px; max-width:480px;">
          {{ $errors->first() }}
        </div>
      @endif

      <div class="panel" style="max-width:480px;">
        <form method="POST" action="/password">
          @csrf

          <div class="form-row">
            <label>現在のパスワード</label>
            <input type="password" name="current_password" required autofocus>
          </div>

          <div class="form-row">
            <label>新しいパスワード</label>
            <input type="password" name="password" required>
            <span class="set-note" style="display:block; font-size:12px; color:var(--muted); margin-top:4px;">8文字以上で入力してください。</span>
          </div>

          <div class="form-row">
            <label>新しいパスワード（確認）</label>
            <input type="password" name="password_confirmation" required>
            <span class="set-note" style="display:block; font-size:12px; color:var(--muted); margin-top:4px;">確認のため、もう一度同じものを入力してください。</span>
          </div>

          <div style="margin-top:16px;">
            <button class="btn primary" type="submit">パスワードを変更</button>
          </div>
        </form>
      </div>
@endsection
