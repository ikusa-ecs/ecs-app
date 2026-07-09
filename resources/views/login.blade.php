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

    {{-- 自己登録は廃止（アカウントは管理者が発行する方針）＝「新規登録」導線は置かない。 --}}

    <div class="mock-entry">
      <p class="mock-entry-label">テスト用ログイン（役割ごと・本番前に外します）</p>
      <p style="text-align:center; font-size:11px; color:var(--muted); margin:-2px 0 10px;">役割によって見える画面が変わります。試したい役割を選んでください。</p>

      {{-- 役割ごとのワンクリック（DB接続版＝people に実在。データが入った状態で確認できる）。 --}}
      @php
        $ecsTestRoles = [
          ['スタッフ', 'test-db-staff@example.com', '自分の確定アサインだけ'],
          ['社員', 'test-db-emp@example.com', '業務画面（削除・マスタは不可）'],
          ['管理者', 'test-db-mgr@example.com', '社員＋アカウント発行・名簿取込'],
          ['Administrator', 'test-db@example.com', '全操作（削除・権限・マスタ）'],
        ];
      @endphp
      @foreach ($ecsTestRoles as $r)
        <form method="POST" action="/login" style="margin:0 0 6px;">
          @csrf
          <input type="hidden" name="email" value="{{ $r[1] }}">
          <input type="hidden" name="password" value="test">
          <button class="btn ghost" type="submit" style="width:100%; justify-content:space-between; text-align:left; gap:8px;">
            <span><b>{{ $r[0] }}</b> で入る</span>
            <span style="font-size:10.5px; color:var(--muted); font-weight:400;">{{ $r[2] }}</span>
          </button>
        </form>
      @endforeach

      {{-- DBが無い環境用の予備（折りたたみ）。画面の見た目だけ確認したいとき。 --}}
      <details style="margin-top:8px;">
        <summary style="font-size:11.5px; color:var(--muted); cursor:pointer;">DBが無い環境で見た目だけ確認する場合</summary>
        <div style="margin-top:6px;">
          @php
            $ecsTestRolesNoDb = [
              ['スタッフ', 'test-staff@ecs.local'],
              ['社員', 'test-emp@ecs.local'],
              ['管理者', 'test-mgr@ecs.local'],
              ['Administrator', 'test@ecs.local'],
            ];
          @endphp
          @foreach ($ecsTestRolesNoDb as $r)
            <form method="POST" action="/login" style="margin:0 0 5px;">
              @csrf
              <input type="hidden" name="email" value="{{ $r[1] }}">
              <input type="hidden" name="password" value="test">
              <button class="btn ghost" type="submit" style="width:100%; justify-content:center; font-size:12px;">{{ $r[0] }}（DB不要）</button>
            </form>
          @endforeach
          <p style="font-size:10.5px; color:var(--muted); margin:4px 0 0;">※DB不要版は画面が空で表示されます（中身のデータはDB接続版で見えます）。</p>
        </div>
      </details>
    </div>

    <p class="muted" style="text-align:center; font-size:11px; margin-top:14px;">
      ※テスト用ログインです（パスワードはすべて test）。本番公開前に無効化します（.env の ECS_TEST_LOGIN=false）。
    </p>
  </div>
</body>
</html>
