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
    .alt { margin-top: 16px; text-align:center; }
    .alt a { color: var(--muted); font-size: 12px; cursor: pointer; text-decoration: underline; }
    .recovery { display: none; }
  </style>
</head>
<body>
  <div class="login-card">
    <div class="brand">ECS<small>スタッフアサイン管理</small></div>
    <h1>2段階認証</h1>
    <p class="lead">スマホの認証アプリに表示されている6桁のコードを入力してください。</p>

    @if ($errors->any())
      <div style="background:#fdecec; color:#b91c1c; border:1px solid #f3c0c0; border-radius:10px; padding:12px 14px; font-size:13px; margin-bottom:16px;">
        {{ $errors->first() }}
      </div>
    @endif

    {{-- 通常＝認証アプリの6桁コード。スマホが無いときは下の「リカバリコード」に切り替える。 --}}
    <form method="POST" action="/two-factor-challenge">
      @csrf

      <div id="codeBlock">
        <div class="form-row">
          <label>認証アプリの6桁コード</label>
          <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" autofocus>
        </div>
      </div>

      <div id="recoveryBlock" class="recovery">
        <div class="form-row">
          <label>リカバリコード</label>
          <input type="text" name="recovery_code" placeholder="xxxxxxxx-xxxxxxxx">
        </div>
      </div>

      <button class="btn primary" type="submit" style="width:100%; justify-content:center; margin-top:6px;">ログイン</button>
    </form>

    <div class="alt">
      <a id="toRecovery" onclick="ECSshowRecovery(true)">スマホが使えない（リカバリコードを使う）</a>
      <a id="toCode" onclick="ECSshowRecovery(false)" style="display:none;">認証アプリのコードに戻す</a>
    </div>

    <div class="alt" style="margin-top:10px;">
      <form method="POST" action="/logout" style="margin:0;">
        @csrf
        <button type="submit" style="background:none; border:none; padding:0; color:var(--muted); font-size:12px; cursor:pointer; text-decoration:underline;">ログインし直す</button>
      </form>
    </div>
  </div>

  <script>
    // 「認証アプリのコード」と「リカバリコード」の入力欄を切り替える。
    function ECSshowRecovery(useRecovery){
      document.getElementById('codeBlock').style.display     = useRecovery ? 'none' : '';
      document.getElementById('recoveryBlock').style.display = useRecovery ? 'block' : 'none';
      document.getElementById('toRecovery').style.display    = useRecovery ? 'none' : '';
      document.getElementById('toCode').style.display        = useRecovery ? '' : 'none';
    }
  </script>
</body>
</html>
