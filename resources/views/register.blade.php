<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ECS 新規登録</title>
  <link rel="stylesheet" href="/ecs/style.css?v={{ \App\Support\Asset::ver('ecs/style.css') }}">
  @verbatim
  <style>
    body { display: flex; align-items: flex-start; justify-content: center; min-height: 100vh; background: #3a2d20; padding: 32px 12px; }
    .reg-card {
      background: #fff; width: 560px; max-width: 94vw; border-radius: 16px;
      padding: 32px 34px 36px; box-shadow: 0 20px 50px rgba(0,0,0,0.35);
    }
    .reg-card .brand { font-size: 28px; font-weight: 800; letter-spacing: 2px; color: var(--brand); text-align: center; }
    .reg-card .brand small { display:block; font-size: 12px; font-weight: 400; color: var(--muted); letter-spacing: 0; margin-top: 4px; }
    .reg-card h1 { font-size: 18px; text-align: center; margin: 20px 0 4px; }
    .reg-card p.lead { text-align: center; color: var(--muted); font-size: 13px; margin: 0 0 22px; }

    .sec { border: 1px solid var(--line); border-radius: 10px; padding: 16px 16px 6px; margin-bottom: 18px; background: #fbf8f3; }
    .sec h3 { font-size: 14px; margin: 0 0 4px; }
    .sec .sub { font-size: 12px; color: var(--muted); margin: 0 0 12px; }

    .field { margin-bottom: 12px; }
    .field label { display: block; font-size: 12.5px; font-weight: 600; margin-bottom: 4px; }
    .field .req { color: var(--danger); font-size: 11px; margin-left: 4px; }
    .field input, .field select, .field textarea {
      width: 100%; padding: 9px 11px; border: 1px solid var(--line); border-radius: 8px;
      font-size: 13.5px; font-family: inherit; background: #fff; box-sizing: border-box;
    }
    .field textarea { min-height: 56px; resize: vertical; }
    .field-row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .field-row3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
    @media (max-width: 520px){ .field-row2, .field-row3 { grid-template-columns: 1fr; } }

    /* 種別の選択（スタッフ／社員） */
    .role-pick { display: flex; gap: 10px; }
    .role-pick label {
      flex: 1; display: flex; align-items: center; gap: 8px; cursor: pointer;
      border: 1.5px solid var(--line); border-radius: 10px; padding: 12px 14px; font-size: 13.5px; font-weight: 600; background:#fff;
    }
    .role-pick label.on { border-color: var(--brand); background: var(--brand-soft); color: var(--brand-dark); }
    .role-pick input { width: 16px; height: 16px; accent-color: var(--brand); }

    .pos-list { display: flex; flex-wrap: wrap; gap: 8px 16px; }
    .pos-list label { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 500; }
    .pos-list input { width: 16px; height: 16px; accent-color: var(--brand); }

    .err { display:none; background: #fde8e8; color: var(--danger); border-radius: 8px; padding: 10px 12px; font-size: 12.5px; margin-bottom: 14px; }
    .done { display:none; background: var(--ok-soft); color:#166534; border-radius:10px; padding:16px; font-size:13.5px; line-height:1.8; margin-bottom:16px; text-align:center; }
    .back-line { text-align:center; font-size: 12.5px; margin-top: 18px; }
    .back-line a { color: var(--brand); }
    .note { font-size: 12px; color: var(--muted); background:#f8f3ea; border:1px dashed var(--line); border-radius:8px; padding:9px 12px; margin: 6px 0 0; line-height:1.7; }
    .hidden { display: none; }
  </style>
  @endverbatim
</head>
<body>
  <div class="reg-card">
    <div class="brand">ECS<small>スタッフアサイン管理</small></div>
    <h1>新規登録</h1>
    <p class="lead">ログインに必要な情報と、当日の準備の参考になる情報を登録してください。</p>

    <div class="err" id="err"></div>

    <div class="done" id="done">
      ✅ 登録を受け付けました（モックのため実際には送信されません）。<br>
      ご登録のメールアドレスにログイン用リンクをお送りする想定です。<br>
      <a class="btn primary" style="margin-top:12px; display:inline-flex;" href="/">ログイン画面へ</a>
    </div>

    <form id="regForm">
      <!-- ① アカウント情報（必須） -->
      <div class="sec">
        <h3>① アカウント情報</h3>
        <p class="sub">ログインに使う情報です。ここは必ず入力してください。</p>

        <div class="field">
          <label>種別<span class="req">必須</span></label>
          <div class="role-pick">
            <label id="roleStaffLbl"><input type="radio" name="role" value="staff" onchange="onRole()"> スタッフ</label>
            <label id="roleEmpLbl"><input type="radio" name="role" value="emp" onchange="onRole()"> 社員</label>
          </div>
        </div>

        <div class="field">
          <label>氏名<span class="req">必須</span></label>
          <input type="text" id="acName" placeholder="例）山田 太郎">
        </div>
        <div class="field">
          <label>メールアドレス<span class="req">必須</span></label>
          <input type="email" id="acEmail" placeholder="you@example.com">
        </div>
        <p class="note">💡 このメールアドレス宛に、ログイン用のリンクをお送りします（パスワードは不要です）。</p>
      </div>

      <!-- ② 基本情報（個人情報・共通） -->
      <div class="sec">
        <h3>② 基本情報</h3>
        <p class="sub">当日のユニフォーム準備などの参考にします（任意・あとから変更できます）。</p>
        <div class="field-row3">
          <div class="field"><label>身長</label><input type="number" inputmode="numeric" id="pfHeight" placeholder="cm"></div>
          <div class="field"><label>靴のサイズ</label><input type="number" inputmode="decimal" id="pfShoe" placeholder="cm"></div>
          <div class="field"><label>服のサイズ</label>
            <select id="pfWear">
              <option value="">選択</option>
              <option>SS</option><option>S</option><option>M</option><option>L</option><option>LL</option><option>3L</option>
            </select>
          </div>
        </div>
        <div class="field-row2">
          <div class="field"><label>都道府県</label>
            <select id="pfPref">
              <option value="">選択</option>
              <option>北海道</option><option>青森県</option><option>岩手県</option><option>宮城県</option><option>秋田県</option><option>山形県</option><option>福島県</option>
              <option>茨城県</option><option>栃木県</option><option>群馬県</option><option>埼玉県</option><option>千葉県</option><option>東京都</option><option>神奈川県</option>
              <option>新潟県</option><option>富山県</option><option>石川県</option><option>福井県</option><option>山梨県</option><option>長野県</option>
              <option>岐阜県</option><option>静岡県</option><option>愛知県</option><option>三重県</option>
              <option>滋賀県</option><option>京都府</option><option>大阪府</option><option>兵庫県</option><option>奈良県</option><option>和歌山県</option>
              <option>鳥取県</option><option>島根県</option><option>岡山県</option><option>広島県</option><option>山口県</option>
              <option>徳島県</option><option>香川県</option><option>愛媛県</option><option>高知県</option>
              <option>福岡県</option><option>佐賀県</option><option>長崎県</option><option>熊本県</option><option>大分県</option><option>宮崎県</option><option>鹿児島県</option><option>沖縄県</option>
            </select>
          </div>
          <div class="field"><label>最寄り駅</label><input type="text" id="pfStation" placeholder="例）JR千葉駅"></div>
        </div>
        <div class="field"><label>事務所</label>
          <select id="pfOffice">
            <option value="">選択</option>
            <option>東京</option><option>大阪</option><option>名古屋</option><option>福岡</option><option>東北</option><option>北海道</option>
          </select>
        </div>
      </div>

      <!-- ③スタッフ専用：プロフィール・できるポジション -->
      <div class="sec hidden" id="staffOnly">
        <h3>③ プロフィール（スタッフ）</h3>
        <p class="sub">メンバー決めの参考にします（任意）。</p>
        <div class="field"><label>一言アピール</label><textarea id="pfAppeal" placeholder="例）元気な進行が得意です！"></textarea></div>
        <div class="field"><label>好きなコンテンツ</label><input type="text" id="pfLike" placeholder="例）運動会・水合戦"></div>
        <div class="field"><label>苦手なコンテンツ</label><input type="text" id="pfDislike" placeholder="例）オンライン配信"></div>
        <div class="field"><label>得意なポジション</label><textarea id="pfStrongPosFree" placeholder="例）盛り上げ役が好きです。／裏方の段取りが得意です。"></textarea></div>
        <div class="field"><label>苦手なポジション</label><textarea id="pfWeakPosFree" placeholder="例）細かい受付業務はやや苦手です。"></textarea></div>
        <div class="field" style="margin-top:6px;">
          <label>できるポジション</label>
          <div class="pos-list" id="posList"></div>
        </div>
      </div>

      <!-- ③社員専用：所属（担当部署） -->
      <div class="sec hidden" id="empOnly">
        <h3>③ 所属（社員）</h3>
        <p class="sub">あなたの主な担当を選んでください（任意）。</p>
        <div class="field">
          <label>所属</label>
          <select id="empKind">
            <option value="">選択</option>
            <option>イベプラ</option>
            <option>セールス</option>
            <option>クリエイティブ</option>
          </select>
        </div>
      </div>

      <button type="button" class="btn primary" style="width:100%; justify-content:center;" onclick="submitReg()">この内容で登録する</button>
    </form>

    <div class="back-line">すでにアカウントをお持ちの方は <a href="/">ログインはこちら</a></div>

    <p class="note" style="margin-top:18px;">
      これは見た目確認用のモックです。入力しても保存・送信はされません。<br>
      ※ログイン方式（メールのリンク方式など）は検討中の仮実装です。
    </p>
  </div>

  @verbatim
  <script>
    // できるポジションの一覧（スタッフ管理・スタッフ設定と同じ並び）
    // スタッフが「できる」として選ぶのは OP / MC / 軍師 の3つに限定。
    // （D はできるスタッフがほぼいない・FC/CK/受付 は誰でもやる前提なので選択対象外）
    const POS = [
      { k:'OP',  label:'OP（音響）' },
      { k:'MC',  label:'MC（司会進行）' },
      { k:'SP', label:'軍師・サポーター' },
    ];
    document.getElementById('posList').innerHTML = POS.map(p =>
      `<label><input type="checkbox" value="${p.k}"> ${p.label}</label>`).join('');

    // 種別を選んだら、スタッフ用／社員用の欄を出し分ける
    function onRole(){
      const role = (document.querySelector('input[name="role"]:checked') || {}).value;
      document.getElementById('staffOnly').classList.toggle('hidden', role !== 'staff');
      document.getElementById('empOnly').classList.toggle('hidden', role !== 'emp');
      // 選んだボタンを強調
      document.getElementById('roleStaffLbl').classList.toggle('on', role === 'staff');
      document.getElementById('roleEmpLbl').classList.toggle('on', role === 'emp');
    }

    function submitReg(){
      const role  = (document.querySelector('input[name="role"]:checked') || {}).value;
      const name  = document.getElementById('acName').value.trim();
      const email = document.getElementById('acEmail').value.trim();
      const err   = document.getElementById('err');

      // 必須チェック（種別・氏名・メールのみ）
      const miss = [];
      if (!role)  miss.push('種別（スタッフ／社員）');
      if (!name)  miss.push('氏名');
      if (!email) miss.push('メールアドレス');
      const emailOk = /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email);
      if (email && !emailOk) miss.push('正しい形式のメールアドレス');

      if (miss.length){
        err.textContent = '次の項目を確認してください：' + miss.join('、');
        err.style.display = 'block';
        window.scrollTo({ top: 0, behavior: 'smooth' });
        return;
      }
      err.style.display = 'none';
      document.getElementById('regForm').style.display = 'none';
      document.getElementById('done').style.display = 'block';
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  </script>
  @endverbatim
</body>
</html>
