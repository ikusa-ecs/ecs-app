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
      <p class="mock-entry-label">テスト用：ワンクリックで入る（DB不要・本番前に外します）</p>
      {{-- DBを使わないテスト用アカウントで入る（App\Support\TestAccounts）。
           DBが無い／未接続のテスト環境でも入れる。裏で本物のログイン(POST /login)をしてから入る。 --}}
      <form method="POST" action="/login" style="margin:0;">
        @csrf
        <input type="hidden" name="email" value="test@ecs.local">
        <input type="hidden" name="password" value="test">
        <button class="btn primary" type="submit" style="width:100%; justify-content:center;">🧑‍💼 社員として入る（テスト・DB不要）</button>
      </form>
      <form method="POST" action="/login" style="margin:0;">
        @csrf
        <input type="hidden" name="email" value="test-staff@ecs.local">
        <input type="hidden" name="password" value="test">
        <button class="btn ghost" type="submit" style="width:100%; justify-content:center;">🙋 スタッフとして入る（テスト・DB不要）</button>
      </form>

      {{-- DB接続版：people テーブルに実在するテストアカウント（TestLoginSeeder で投入）。
           DBがつながっている環境で、名簿・アサイン等の関連データも含めて確認したいとき用。 --}}
      <p class="mock-entry-label" style="margin-top:6px;">DB接続版（DBがつながっている環境用）</p>
      <form method="POST" action="/login" style="margin:0;">
        @csrf
        <input type="hidden" name="email" value="test-db@example.com">
        <input type="hidden" name="password" value="test">
        <button class="btn ghost" type="submit" style="width:100%; justify-content:center;">🧑‍💼 社員として入る（テスト・DB接続）</button>
      </form>
      <form method="POST" action="/login" style="margin:0;">
        @csrf
        <input type="hidden" name="email" value="test-db-staff@example.com">
        <input type="hidden" name="password" value="test">
        <button class="btn ghost" type="submit" style="width:100%; justify-content:center;">🙋 スタッフとして入る（テスト・DB接続）</button>
      </form>
    </div>

    <p class="muted" style="text-align:center; font-size:11.5px; margin-top:16px;">
      ※テスト用：上のボタンはDBを使わず入れます（メール test@ecs.local ／ パスワード test）。本番前に無効化します（.env の ECS_TEST_LOGIN=false）。
    </p>
  </div>
</body>
</html>
