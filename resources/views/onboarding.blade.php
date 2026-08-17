<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ECS はじめてのログイン（初期設定）</title>
  <link rel="stylesheet" href="/ecs/style.css?v={{ \App\Support\Asset::ver('ecs/style.css') }}">
  @verbatim
  <style>
    body { display: flex; align-items: flex-start; justify-content: center; min-height: 100vh; background: #3a2d20; padding: 32px 12px; }
    .ob-card {
      background: #fff; width: 560px; max-width: 94vw; border-radius: 16px;
      padding: 30px 34px 34px; box-shadow: 0 20px 50px rgba(0,0,0,0.35);
    }
    .ob-card .brand { font-size: 26px; font-weight: 800; letter-spacing: 2px; color: var(--brand); text-align: center; }
    .ob-card .brand small { display:block; font-size: 12px; font-weight: 400; color: var(--muted); letter-spacing: 0; margin-top: 4px; }
    .ob-hello { text-align:center; font-size: 18px; font-weight: 700; margin: 18px 0 2px; }
    .ob-lead { text-align:center; color: var(--muted); font-size: 13px; margin: 0 0 8px; line-height:1.7; }
    .ob-steps { display:flex; gap:8px; justify-content:center; margin: 12px 0 20px; flex-wrap:wrap; }
    .ob-steps .st { font-size:12px; color:var(--brand-dark, #8a5a33); background: var(--brand-soft, #f6e9dd); border:1px solid #ecd6c2; border-radius:999px; padding:4px 12px; font-weight:600; }

    .sec { border: 1px solid var(--line); border-radius: 10px; padding: 16px 16px 6px; margin-bottom: 18px; background: #fbf8f3; }
    .sec h3 { font-size: 14px; margin: 0 0 4px; display:flex; align-items:center; gap:8px; }
    .sec h3 .no { display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; background:var(--brand,#b5673a); color:#fff; font-size:13px; }
    .sec .sub { font-size: 12px; color: var(--muted); margin: 0 0 12px; }

    .field { margin-bottom: 12px; }
    .field label { display: block; font-size: 12.5px; font-weight: 600; margin-bottom: 4px; }
    .field .req { color: var(--danger); font-size: 11px; margin-left: 4px; }
    .field input, .field select, .field textarea {
      width: 100%; padding: 9px 11px; border: 1px solid var(--line); border-radius: 8px;
      font-size: 13.5px; font-family: inherit; background: #fff; box-sizing: border-box;
    }
    .field textarea { min-height: 56px; resize: vertical; }
    .field .hint { display:block; font-size: 11.5px; color: var(--muted); margin-top: 4px; }
    .field-row3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
    .field-row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    @media (max-width: 520px){ .field-row2, .field-row3 { grid-template-columns: 1fr; } }

    .err { background: #fdecec; color: var(--danger); border-radius: 8px; padding: 10px 12px; font-size: 12.5px; margin-bottom: 14px; border:1px solid #f3c0c0; }
  </style>
  @endverbatim
</head>
<body>
  <div class="ob-card">
    <div class="brand">ECS<small>スタッフアサイン管理</small></div>

    <div class="ob-hello">ようこそ、{{ $me->name }} さん</div>
    <p class="ob-lead">
      はじめてのログインです。安全のため、まず<b>パスワードの設定</b>と<b>あなたの情報の登録</b>をお願いします。<br>
      （入力した内容は、あとから「マイプロフィール」でいつでも直せます）
    </p>
    <div class="ob-steps">
      <span class="st">① パスワードを決める</span>
      <span class="st">② プロフィールを入れる</span>
    </div>

    @if ($errors->any())
      <div class="err">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="/onboarding">
      @csrf

      {{-- ① パスワードの設定（初回なので現在のパスワードは不要） --}}
      <div class="sec">
        <h3><span class="no">1</span> パスワードを決める</h3>
        <p class="sub">これからのログインで使う、あなただけのパスワードを決めてください。</p>

        <div class="field">
          <label>新しいパスワード<span class="req">必須</span></label>
          <input type="password" name="password" required placeholder="8文字以上">
          <span class="hint">8文字以上で入力してください。</span>
        </div>
        <div class="field">
          <label>新しいパスワード（確認）<span class="req">必須</span></label>
          <input type="password" name="password_confirmation" required placeholder="確認のためもう一度">
        </div>
      </div>

      {{-- ② 基本情報（旧・新規登録で聞いていた項目） --}}
      <div class="sec">
        <h3><span class="no">2</span> あなたの情報</h3>
        <p class="sub">当日のユニフォーム・衣装の準備などの参考にします（氏名以外は任意・あとで変更できます）。</p>

        <div class="field">
          <label>氏名<span class="req">必須</span></label>
          <input type="text" name="name" value="{{ old('name', $me->name) }}" required placeholder="例）山田 太郎">
        </div>

        <div class="field">
          <label>メールアドレス</label>
          <input type="email" name="email" value="{{ old('email', $me->email) }}" placeholder="you@example.com">
          <span class="hint">ログインにも使うアドレスです。</span>
        </div>

        <div class="field-row3">
          <div class="field">
            <label>身長</label>
            <input type="text" name="height" value="{{ old('height', $me->height) }}" placeholder="例）170（cm）">
          </div>
          <div class="field">
            <label>靴のサイズ</label>
            <input type="text" name="shoe_size" value="{{ old('shoe_size', $me->shoe_size) }}" placeholder="例）26.5cm">
          </div>
          <div class="field">
            <label>服（衣装）のサイズ</label>
            <select name="shirt_size">
              <option value="">選択</option>
              @foreach (['SS','S','M','L','LL','3L'] as $opt)
                <option value="{{ $opt }}" @selected(old('shirt_size', $me->shirt_size) === $opt)>{{ $opt }}</option>
              @endforeach
            </select>
          </div>
        </div>

        <div class="field-row2">
          <div class="field">
            <label>都道府県</label>
            <input type="text" name="prefecture" value="{{ old('prefecture', $me->prefecture) }}" placeholder="例）千葉県">
          </div>
          <div class="field">
            <label>最寄り駅</label>
            <input type="text" name="nearest_station" value="{{ old('nearest_station', $me->nearest_station) }}" placeholder="例）JR千葉駅">
          </div>
        </div>

        <div class="field">
          <label>事務所</label>
          <select name="office">
            <option value="">選択</option>
            @foreach (['東京','大阪','名古屋','福岡','東北','北海道'] as $opt)
              <option value="{{ $opt }}" @selected(old('office', $me->office) === $opt)>{{ $opt }}</option>
            @endforeach
          </select>
        </div>

        @if ($me->role === 'staff')
          <div class="field">
            <label>一言アピール</label>
            <textarea name="appeal" placeholder="例）元気な進行が得意です！">{{ old('appeal', $me->appeal) }}</textarea>
          </div>
        @endif
      </div>

      <button type="submit" class="btn primary" style="width:100%; justify-content:center;">設定を完了して始める</button>
    </form>

    <form method="POST" action="/logout" style="margin-top:14px; text-align:center;">
      @csrf
      <button type="submit" style="background:none; border:none; color:var(--muted); font-size:12px; cursor:pointer; text-decoration:underline;">別のアカウントでログインし直す</button>
    </form>
  </div>
</body>
</html>
