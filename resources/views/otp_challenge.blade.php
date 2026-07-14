<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ECS 2段階認証</title>
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
    .code-input { text-align:center; letter-spacing: 8px; font-size: 22px; font-family: monospace; }
    .alt { margin-top: 14px; text-align:center; }
  </style>
</head>
<body>
  <div class="login-card">
    <div class="brand">ECS<small>スタッフアサイン管理</small></div>
    <h1>2段階認証</h1>
    <p class="lead">
      登録メールアドレス宛に確認コードを送りました。<br>
      メールに届いた6桁のコードを入力してください。
    </p>

    @if (session('status') === 'confirmation-code-sent')
      <div style="background:var(--ok-soft, #e7f6ec); color:#166534; border:1px solid #b7e0c2; border-radius:10px; padding:12px 14px; font-size:13px; margin-bottom:16px;">
        新しい確認コードをメールで送りました。
      </div>
    @endif

    @if ($errors->any())
      <div style="background:#fdecec; color:#b91c1c; border:1px solid #f3c0c0; border-radius:10px; padding:12px 14px; font-size:13px; margin-bottom:16px;">
        {{ $errors->first() }}
      </div>
    @endif

    <form method="POST" action="/otp">
      @csrf
      <div class="form-row">
        <label>確認コード（6桁）</label>
        <input class="code-input" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="______" required autofocus>
      </div>
      <button class="btn primary" type="submit" style="width:100%; justify-content:center; margin-top:6px;">ログイン</button>
    </form>

    <div class="alt">
      <form method="POST" action="/otp/resend" style="margin:0;">
        @csrf
        <button type="submit" style="background:none; border:none; padding:0; color:var(--muted); font-size:12px; cursor:pointer; text-decoration:underline;">コードが届かない場合は再送する</button>
      </form>
    </div>

    <div class="alt" style="margin-top:10px;">
      <form method="POST" action="/logout" style="margin:0;">
        @csrf
        <button type="submit" style="background:none; border:none; padding:0; color:var(--muted); font-size:12px; cursor:pointer; text-decoration:underline;">ログインし直す</button>
      </form>
    </div>

    <p class="muted" style="text-align:center; font-size:11px; margin-top:16px; color:var(--muted);">
      送信先：{{ $email }}
    </p>
  </div>
</body>
</html>
