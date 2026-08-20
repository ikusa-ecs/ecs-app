{{-- ECS 使い方ガイド（スタッフ向け）。スタッフ画面のボタンから開く。 --}}
{{-- スタッフがやることだけに絞った内容。機能が増えたらこのファイルを更新する（生きた説明書）。 --}}
{{-- CSSのメディアクエリ等をBladeに解釈させないため、全体をverbatimブロックで囲んでいる。 --}}
@verbatim
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ECS 使い方ガイド（スタッフ向け）</title>
<style>
  :root{
    --brand:#a15c2e; --brand-soft:#f6ede4; --ink:#2f2a24; --muted:#7a6f63;
    --line:#e6d8c8; --ok:#166534; --ok-soft:#e7f6ec; --warn:#8a5a10; --warn-soft:#fdf3e2;
  }
  *{ box-sizing:border-box; }
  html{ scroll-behavior:smooth; }
  body{
    margin:0; color:var(--ink); background:#fbf8f4;
    font-family:"Segoe UI","Hiragino Kaku Gothic ProN","Yu Gothic",Meiryo,sans-serif;
    line-height:1.75; font-size:15px;
  }
  .wrap{ max-width:620px; margin:0 auto; padding:24px 16px 56px; }
  header.doc{
    background:linear-gradient(120deg,#a15c2e,#c07a44); color:#fff;
    border-radius:14px; padding:22px 22px; margin-bottom:8px;
  }
  header.doc h1{ margin:0 0 6px; font-size:22px; letter-spacing:.5px; }
  header.doc p{ margin:0; opacity:.92; font-size:13px; }
  .meta{ font-size:12px; color:var(--muted); margin:10px 2px 22px; }

  nav.toc{ background:#fff; border:1px solid var(--line); border-radius:12px; padding:12px 16px; margin-bottom:24px; }
  nav.toc b{ font-size:13px; color:var(--muted); display:block; margin-bottom:6px; }
  nav.toc a{ color:var(--brand); text-decoration:none; font-size:14px; }
  nav.toc a:hover{ text-decoration:underline; }
  nav.toc ol{ margin:0; padding-left:20px; }
  nav.toc li{ margin:3px 0; }

  section{ background:#fff; border:1px solid var(--line); border-radius:12px; padding:18px 20px; margin-bottom:18px; }
  h2{ font-size:17px; margin:0 0 10px; padding-bottom:8px; border-bottom:2px solid var(--brand-soft); color:var(--brand); }
  h3{ font-size:14.5px; margin:16px 0 6px; }
  p{ margin:8px 0; }
  ul,ol{ margin:8px 0; padding-left:22px; }
  li{ margin:5px 0; }
  .lead{ font-size:15.5px; }
  .why{ color:var(--muted); font-size:13px; }

  .steps{ counter-reset:s; list-style:none; padding-left:0; }
  .steps>li{ counter-increment:s; position:relative; padding:8px 0 8px 40px; border-bottom:1px dashed var(--line); }
  .steps>li:last-child{ border-bottom:none; }
  .steps>li::before{
    content:counter(s); position:absolute; left:0; top:8px;
    width:26px; height:26px; border-radius:50%; background:var(--brand); color:#fff;
    text-align:center; line-height:26px; font-weight:700; font-size:13px;
  }
  .steps b{ color:var(--brand); }

  .note{ background:var(--warn-soft); border:1px solid #ecd9b6; border-radius:10px; padding:11px 13px; font-size:13px; }
  .tip{ background:var(--ok-soft); border:1px solid #b7e0c2; border-radius:10px; padding:11px 13px; font-size:13px; }
  .pill{ display:inline-block; background:var(--brand-soft); color:var(--brand); border-radius:20px; padding:1px 10px; font-size:12px; font-weight:700; }
  .key{ display:inline-block; background:var(--brand-soft); color:var(--brand); border:1px solid var(--line); border-radius:6px; padding:0 7px; font-weight:700; font-size:13px; }

  footer{ color:var(--muted); font-size:12px; text-align:center; margin-top:8px; }
</style>
</head>
<body>
<div class="wrap">

  <header class="doc">
    <h1>ECS 使い方ガイド（スタッフ向け）</h1>
    <p>あなたのスマホでできること・やり方をまとめました</p>
  </header>
  <div class="meta">2026年7月17日版 ・ 開発中のため、画面は今後変わることがあります</div>

  <div class="tip" style="margin-bottom:22px;">
    <b>ECSとは？</b> あなたの「入れる日（希望）」を出したり、案件に応募（エントリー）したり、確定した自分の担当を確認したりできる、スタッフ専用のページです。
  </div>

  <nav class="toc">
    <b>もくじ</b>
    <ol>
      <li><a href="#login">ログインする</a></li>
      <li><a href="#wish">入れる日（稼働希望）を出す</a></li>
      <li><a href="#entry">案件に応募する（エントリー）</a></li>
      <li><a href="#confirmed">確定した自分の担当を見る</a></li>
      <li><a href="#profile">プロフィール・パスワード</a></li>
      <li><a href="#faq">こまったとき</a></li>
    </ol>
  </nav>

  <section id="login">
    <h2>1. ログインする</h2>
    <p>安全のため、パスワードに加えて<b>メールに届く6桁コード</b>でログインします（2段階認証）。</p>
    <ol class="steps">
      <li><b>メールアドレスとパスワードを入れる</b> — アカウントは会社が発行します（自分での新規登録はありません）。</li>
      <li><b>メールに届く6桁コードを入れる</b> — 登録メールに届きます。<span class="pill">10分間有効</span></li>
      <li><b>初めてのときは初期設定</b> — パスワードを決めて、身長・靴のサイズなどのプロフィールを入力します（あとで変えられます）。</li>
    </ol>
    <div class="note">コードが届かないときは、入力画面の「<b>コードを再送する</b>」を押してください。</div>
  </section>

  <section id="wish">
    <h2>2. 入れる日（稼働希望）を出す</h2>
    <p>「稼働希望」タブのカレンダーで、その月に入れる日を出します。担当者はこれを見てアサインを決めます。</p>
    <ol class="steps">
      <li><b>「稼働希望」タブを開く</b></li>
      <li><b>日付をタップして状態を切り替える</b> — タップするたびに <span class="key">終日〇</span> → <span class="key">NG</span> → <span class="key">未定</span> と変わります。「終日〇」はその日は一日じゅう入れる、という意味です。</li>
      <li><b>「この内容で希望を提出する」を押す</b> — これで保存されます。</li>
    </ol>
    <div class="tip"><b>★エントリー中</b>／<b>イベント</b>と表示された日は、応募した案件・確定した案件がある日です（この日はタップで変えられません）。</div>
  </section>

  <section id="entry">
    <h2>3. 案件に応募する（エントリー）</h2>
    <p>「募集中の案件」タブで、入りたい案件に応募できます。</p>
    <ol class="steps">
      <li><b>「募集中の案件」タブを開く</b> — キーワード・エリア・日付でしぼれます。</li>
      <li><b>入りたい案件の「エントリーする」を押す</b> — 応募が保存されます。</li>
      <li><b>一言メモを添える</b> — 押すとメモ欄が開くので、担当へ伝えたいこと（例：「都内なら可」）を書けます（任意）。</li>
      <li><b>取り消したいとき</b> — 「エントリーを取り消す」で取り消せます。</li>
    </ol>
    <p class="why">※ 応募すると、稼働希望カレンダーのその日に「★エントリー中」が付きます。応募＝確定ではありません。担当が選んで「公開」すると確定になります。</p>
  </section>

  <section id="confirmed">
    <h2>4. 確定した自分の担当を見る</h2>
    <p>「確定アサイン」タブに、担当が<b>公開した</b>あなたの担当案件が並びます。</p>
    <ul>
      <li>日付・集合時間・場所・あなたの担当（役割）が見られます。<b>タップすると、持ち物・服装・当日の注意事項・集合場所の詳しい説明</b>が開きます。</li>
      <li>ここに出るのは「公開された・あなたのアサインが<b>確定</b>になった」案件だけです。応募しただけ・調整中はまだ出ません。</li>
    </ul>
    <div class="note">当日の連絡や集合の合図（LINEグループ招待など）は、これまでどおり LINE・チャットワークで行います。</div>
  </section>

  <section id="profile">
    <h2>5. プロフィール・パスワード</h2>
    <p>「設定」タブから、自分の情報を変えられます。</p>
    <ul>
      <li><b>プロフィール編集</b> — 事務所・身長・靴・服のサイズ・最寄り駅・一言アピールなど。当日の衣装準備やメンバー決めの参考になります。</li>
      <li><b>できるポジション</b> — 自分ができる役割（OP・MC・軍師など）を申告できます。</li>
      <li><b>パスワードの変更</b>・<b>ログアウト</b>もここからできます。</li>
    </ul>
  </section>

  <section id="faq">
    <h2>6. こまったとき</h2>
    <ul>
      <li><b>コードが届かない</b> — 「コードを再送する」を押す。迷惑メールも確認。</li>
      <li><b>希望を出したのに反映されない</b> — カレンダーで日付を選んだあと、必ず「<b>この内容で希望を提出する</b>」を押してください。</li>
      <li><b>応募を間違えた</b> — 「エントリーを取り消す」で取り消せます。</li>
      <li><b>その他</b> — 使いにくい点・こうしてほしい点があれば、担当までお知らせください。改善に反映します。</li>
    </ul>
  </section>

  <footer>ECS スタッフアサイン管理システム ／ スタッフ向けガイド ・ 2026年7月</footer>

</div>
</body>
</html>
@endverbatim
