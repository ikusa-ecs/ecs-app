<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ECS 新しいパスワードの設定</title>
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
    .alt { margin-top: 14px; text-align:center; }
  </style>
</head>
<body>
  <div class="login-card">
    <div class="brand">ECS<small>スタッフアサイン管理</small></div>
    <h1>新しいパスワードの設定</h1>
    <p class="lead">新しいパスワード（8文字以上）を入力してください。</p>

    @if ($errors->any())
      <div style="background:#fdecec; color:#b91c1c; border:1px solid #f3c0c0; border-radius:10px; padding:12px 14px; font-size:13px; margin-bottom:16px;">
        {{ $errors->first() }}
      </div>
    @endif

    <form method="POST" action="{{ route('password.update') }}">
      @csrf
      <input type="hidden" name="token" value="{{ $token }}">

      <div class="form-row">
        <label>メールアドレス</label>
        <input type="email" name="email" value="{{ $email }}" required readonly style="background:#f5efe8;">
      </div>

      <div class="form-row">
        <label>新しいパスワード（8文字以上）</label>
        <input type="password" name="password" placeholder="新しいパスワード" required autofocus>
      </div>

      <div class="form-row">
        <label>新しいパスワード（確認）</label>
        <input type="password" name="password_confirmation" placeholder="もう一度入力" required>
      </div>

      <button class="btn primary" type="submit" style="width:100%; justify-content:center; margin-top:6px;">パスワードを設定する</button>
    </form>

    <div class="alt">
      <a href="/" style="color:var(--muted); font-size:12px; text-decoration:underline;">ログイン画面に戻る</a>
    </div>
  </div>
</body>
</html>
