<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ECS ログイン</title>
  <link rel="stylesheet" href="/ecs/style.css">
  <style>
    body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background: #3a2d20; }
    .login-card {
      background: #fff; width: 380px; max-width: 92vw; border-radius: 16px;
      padding: 36px 34px; box-shadow: 0 20px 50px rgba(0,0,0,0.35);
    }
    .login-card .brand { font-size: 30px; font-weight: 800; letter-spacing: 2px; color: var(--brand); text-align: center; }
    .login-card .brand small { display:block; font-size: 12px; font-weight: 400; color: var(--muted); letter-spacing: 0; margin-top: 4px; }
    .login-card h1 { font-size: 17px; text-align: center; margin: 22px 0 6px; }
    .login-card p.lead { text-align: center; color: var(--muted); font-size: 13px; margin: 0 0 22px; }
    .sent { display:none; background: var(--ok-soft); color:#166534; border-radius:10px; padding:14px; font-size:13px; margin-bottom:16px; }
    .mock-entry { margin-top: 18px; padding-top: 18px; border-top: 1px dashed var(--line, #e6cdb8); display: flex; flex-direction: column; gap: 10px; }
    .mock-entry-label { text-align:center; color: var(--muted); font-size: 11.5px; margin: 0 0 2px; }
    .mock-entry .btn { width: 100%; justify-content: center; font-size: 13px; }
  </style>
</head>
<body>
  <div class="login-card">
    <div class="brand">ECS<small>スタッフアサイン管理</small></div>
    <h1>ログイン</h1>
    <p class="lead">登録済みのメールアドレスとパスワードでログインしてください。</p>

    @if ($errors->any())
      <div style="background:#fdecec; color:#b91c1c; border:1px solid #f3c0c0; border-radius:10px; padding:12px 14px; font-size:13px; margin-bottom:16px;">
        {{ $errors->first() }}
      </div>
    @endif

    <form method="POST" action="/login">
      @csrf

      <div class="form-row">
        <label>メールアドレス</label>
        {{-- 開発中は既定値を入れておく（本番前に消す）。入力し直した値はエラー時も保持。 --}}
        <input type="email" name="email" placeholder="you@example.com" value="{{ old('email', 'e-007@example.com') }}" required autofocus>
      </div>

      <div class="form-row">
        <label>パスワード</label>
        {{-- 開発中の仮パスワードを既定で入れておく（本番前に消す）。 --}}
        <input type="password" name="password" placeholder="パスワード" value="password" required>
      </div>

      <label style="display:flex; align-items:center; gap:6px; font-size:12px; color:var(--muted); margin:2px 0 16px;">
        <input type="checkbox" name="remember" value="1"> ログイン状態を保持する
      </label>

      <button class="btn primary" type="submit" style="width:100%; justify-content:center;">ログイン</button>
    </form>

    <div style="margin-top:14px; padding-top:14px; border-top:1px solid var(--line, #e6cdb8); text-align:center;">
      <p class="muted" style="font-size:12px; margin:0 0 8px;">まだ登録していない方（スタッフ・社員）は</p>
      <a class="btn ghost" style="width:100%; justify-content:center;" href="/register">＋ 新規登録</a>
    </div>

    <div class="mock-entry">
      <p class="mock-entry-label">開発用：ワンクリックで入る（自動ログイン・本番前に外します）</p>
      {{-- 以前の「直接入れる」ボタンの復活版。今は全画面が保護されているので、
           ただのリンクではなく裏で本物のログイン(POST /login)をしてから入る。 --}}
      <form method="POST" action="/login" style="margin:0;">
        @csrf
        <input type="hidden" name="email" value="e-007@example.com">
        <input type="hidden" name="password" value="password">
        <button class="btn primary" type="submit" style="width:100%; justify-content:center;">🧑‍💼 社員として入る（baba）</button>
      </form>
      <form method="POST" action="/login" style="margin:0;">
        @csrf
        <input type="hidden" name="email" value="s-001@example.com">
        <input type="hidden" name="password" value="password">
        <button class="btn ghost" type="submit" style="width:100%; justify-content:center;">🙋 スタッフとして入る</button>
      </form>
    </div>

    <p class="muted" style="text-align:center; font-size:11.5px; margin-top:16px;">
      ※開発中：見本アカウントの仮パスワードは「password」です（本番前に必ず入れ替え、上の自動ログインも外します）。
    </p>
  </div>
</body>
</html>
