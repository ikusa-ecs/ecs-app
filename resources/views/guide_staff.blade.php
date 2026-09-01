{{-- ECS 使い方ガイド（スタッフ向け）。スタッフ画面のボタンから開く。 --}}
{{-- スタッフがやることだけに絞った内容。機能が増えたらこのファイルを更新する（生きた説明書）。 --}}
{{-- 更新したら、いちばん下の「更新履歴」にも1行足すこと。 --}}
{{-- CSSのメディアクエリ等をBladeに解釈させないため、全体をBladeが解釈しない区間で囲んでいる。 --}}
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

  table{ border-collapse:collapse; width:100%; margin:10px 0; font-size:13px; }
  th,td{ border:1px solid var(--line); padding:7px 9px; text-align:left; vertical-align:top; }
  th{ background:var(--brand-soft); color:var(--brand); font-weight:700; }

  footer{ color:var(--muted); font-size:12px; text-align:center; margin-top:8px; }
</style>
</head>
<body>
<div class="wrap">

  <header class="doc">
    <h1>ECS 使い方ガイド（スタッフ向け）</h1>
    <p>あなたのスマホでできること・やり方をまとめました</p>
  </header>
  <div class="meta">2026年8月20日版</div>

  <div class="tip" style="margin-bottom:22px;">
    <b>ECSとは？</b> 入れる日（稼働希望）を出したり、案件に応募（エントリー）したり、確定した自分の担当を確認したりできる、スタッフ専用のページです。<br>
    画面は下の<b>5つのタブ</b>に分かれています。<span class="key">📋 募集中の案件</span> <span class="key">📅 稼働希望</span> <span class="key">✓ 確定アサイン</span> <span class="key">🔗 リンク集</span> <span class="key">⚙️ 設定</span>
  </div>

  <nav class="toc">
    <b>もくじ</b>
    <ol>
      <li><a href="#login">ログインする</a></li>
      <li><a href="#wish">入れる日（稼働希望）を出す</a></li>
      <li><a href="#entry">案件に応募する（エントリー）</a></li>
      <li><a href="#confirmed">確定した自分の担当を見る</a></li>
      <li><a href="#links">リンク集</a></li>
      <li><a href="#profile">プロフィール・パスワード</a></li>
      <li><a href="#faq">こまったとき</a></li>
      <li><a href="#history">更新履歴</a></li>
    </ol>
  </nav>

  <section id="login">
    <h2>1. ログインする</h2>
    <p>安全のため、パスワードに加えて<b>メールに届く6桁コード</b>でログインします（2段階認証）。</p>
    <ol class="steps">
      <li><b>メールアドレスとパスワードを入れる</b> — アカウントは会社が発行します（自分での新規登録はありません）。</li>
      <li><b>メールに届く6桁コードを入れる</b> — 登録メールに届きます。<span class="pill">10分間有効</span>　<b>5回続けて間違えるとロック</b>がかかります。</li>
      <li><b>初めてのときは初期設定</b> — パスワード（8文字以上）を決めて、<b>ふりがな</b>（必須）と、身長・靴（足袋）のサイズなどのプロフィールを入力します（あとで変えられます）。<b>IKUSAで働き始めた年月</b>もここで入れてください（分かる範囲で・任意）。</li>
    </ol>
    <div class="note">
      コードが届かないときは、入力画面の「<b>コードを再送する</b>」を押してください（迷惑メールフォルダもご確認ください）。<br>
      パスワードを忘れたときは、ログイン画面の「<b>パスワードをお忘れですか</b>」から、メールで再設定できます。<br>
      <b>最初のパスワードを決めるリンクを開いて「この再設定リンクは無効か、期限が切れています」と出たとき</b>も、
      同じ「<b>パスワードをお忘れですか</b>」にメールアドレスを入れれば、新しいリンクが届きます。
      そこで決めたパスワードが、そのまま最初のパスワードになります。
    </div>
  </section>

  <section id="wish">
    <h2>2. 入れる日（稼働希望）を出す</h2>
    <p><span class="key">📅 稼働希望</span> タブのカレンダーで、その月に入れる日を出します。担当者はこれを見てアサインを決めます。</p>
    <ol class="steps">
      <li><b>「稼働希望」タブを開く</b> — <b>今月ぶん</b>が開きます。上に「◯月分の希望を入力中」と出ます。<b>◀ ▶ や月のプルダウン</b>で、半年先まで切り替えられます。</li>
      <li><b>日付をタップして状態を切り替える</b> — タップするたびに <span class="key">終日〇</span> → <span class="key">NG</span> → <span class="key">未定</span> と変わります。「終日〇」はその日は一日じゅう入れる、という意味です。</li>
      <li><b>「この内容で希望を提出する」を押す</b> — これで保存されます。<b>押さないと保存されません。</b></li>
    </ol>
    <div class="tip"><b>★エントリー中</b>／<b>イベント</b>と表示された日は、応募した案件・確定した案件がある日です（この日はタップで変えられません）。</div>
  </section>

  <section id="entry">
    <h2>3. 案件に応募する（エントリー）</h2>
    <p><span class="key">📋 募集中の案件</span> タブで、入りたい案件に応募できます。タブの数字は、いま募集中の件数です。</p>
    <ol class="steps">
      <li><b>「募集中の案件」タブを開く</b> — いちばん上に、担当からの<b>📣 お知らせ</b>が出ます。</li>
      <li><b>案件をさがす</b> — キーワード・エリア・日付でしぼれます。ボタンで <span class="key">📋 募集中のみ</span> <span class="key">★ エントリー中のみ</span> <span class="key">🔥 追加案件のみ</span> にしぼることもできます。</li>
      <li><b>カレンダーで見る</b> — <span class="key">📅 カレンダー</span> に切り替えると、<b>日付ごとに募集案件が並びます</b>。予定と見くらべながら探せます。案件をタップすると<b>すぐ下に中身が開く</b>ので、そのまま応募できます。</li>
      <li><b>入りたい案件の「エントリーする」を押す</b> — 応募が保存されます。案件ごとに<b>エントリー締切</b>と<b>残り人数</b>が出ます。</li>
      <li><b>一言メモを添える</b> — 押すとメモ欄が開くので、担当へ伝えたいこと（例：「都内なら可」）を書けます（任意）。</li>
      <li><b>取り消したいとき</b> — 「エントリーを取り消す」で取り消せます。</li>
    </ol>
    <p class="why">※ 応募すると、稼働希望カレンダーのその日に「★エントリー中」が付きます。<b>応募＝確定ではありません。</b>担当が選んで公開すると、確定アサインに入ります。</p>
  </section>

  <section id="confirmed">
    <h2>4. 確定した自分の担当を見る</h2>
    <p><span class="key">✓ 確定アサイン</span> タブに、担当が<b>公開した</b>あなたの担当案件が並びます。</p>
    <ul>
      <li>日付・集合時間・場所・あなたの担当（役割）が見られます。</li>
      <li><b>タップすると詳細が開きます</b>＝集合場所の詳しい説明・<b>服装</b>・<b>持ち物</b>・<b>当日の注意事項</b>・集合から解散までの時間。<b>現場に行く前に必ず確認してください。</b></li>
      <li>ここに出るのは「公開された・あなたのアサインが<b>確定</b>になった」案件だけです。応募しただけ・調整中はまだ出ません。</li>
      <li>詳細の中に「<b>応募のときに書いた一言</b>」が出ます＝あなたがエントリーのときに書いたコメントです。
        「<b>担当からの連絡</b>」は逆に、担当からあなたへの連絡です。</li>
    </ul>
    <div class="note">当日の連絡や集合の合図（LINEグループ招待など）は、これまでどおり LINE・チャットワークで行います。</div>
  </section>

  <section id="links">
    <h2>5. リンク集</h2>
    <p><span class="key">🔗 リンク集</span> タブに、よく使うページ（マニュアル・アンケートフォームなど）へのリンクがまとまっています。会社側で追加・変更されるので、ときどき見てください。</p>
  </section>

  <section id="profile">
    <h2>6. プロフィール・パスワード</h2>
    <p><span class="key">⚙️ 設定</span> タブから、自分の情報を変えられます。</p>
    <ul>
      <li><b>プロフィール編集</b> — 事務所・身長・靴（足袋）・服のサイズ・最寄り駅・一言アピール・好きなコンテンツ／苦手なコンテンツなど。<b>当日の衣装の準備やメンバー決めの参考になります</b>ので、ぜひ埋めてください。</li>
      <li><b>できるポジション</b> — 自分ができる役割（OP・MC・軍師など）を申告できます。</li>
      <li><b>パスワードの変更</b>・<b>ログアウト</b>もここからできます。</li>
    </ul>
  </section>

  <section id="faq">
    <h2>7. こまったとき</h2>
    <ul>
      <li><b>コードが届かない</b> — 「コードを再送する」を押す。迷惑メールフォルダも確認。それでも届かないときは担当までご連絡ください。</li>
      <li><b>ログインできない・ロックがかかった</b> — 担当までご連絡ください。</li>
      <li><b>希望を出したのに反映されない</b> — カレンダーで日付を選んだあと、必ず「<b>この内容で希望を提出する</b>」を押してください。</li>
      <li><b>応募を間違えた</b> — 「エントリーを取り消す」で取り消せます。</li>
      <li><b>応募したのに確定アサインに出てこない</b> — 応募＝確定ではありません。担当が選んで公開するまで出ません。</li>
      <li><b>その他</b> — 使いにくい点・こうしてほしい点があれば、担当までお知らせください。改善に反映します。</li>
    </ul>
  </section>

  <section id="history">
    <h2>8. 更新履歴</h2>
    <p class="why">画面が変わったときは、この表に1行足していきます。「前と違う」と思ったときは、ここを見てください。</p>
    <table>
      <tr><th style="width:100px;">日付</th><th>変わったこと</th></tr>
      <tr>
        <td>2026-09-01</td>
        <td><b>「募集中の案件」タブを<u>カレンダーでも見られる</u>ようになりました。</b><br>
          絞り込みの下の <b>「📋 リスト／📅 カレンダー」</b>で切り替えます。
          カレンダーは<b>日付のマスに、その日の募集案件</b>が並ぶので、<b>ご自分の予定と見くらべながら</b>探せます。<br>
          ※ 色は <b>濃い緑＝募集中／薄い緑＝エントリー中／薄い茶＝締切・満員</b>です。追加案件は左に赤い線が付きます。
          （色の見本はカレンダーの下にも出ています）<br>
          ※ <b>案件をタップすると、カレンダーはそのままで、すぐ下に中身が開きます。</b>応募（エントリー）もそこからできます。<br>
          ※ 上のしぼり込みは、<b>リストでもカレンダーでも同じように効きます</b>。</td>
      </tr>
      <tr>
        <td>2026-08-31</td>
        <td><b>「設定」タブの<u>自分の情報</u>に、入力できることが増えました。</b><br>
          増えたのは <b>その他話せる言語／チャレンジしたいポジション／日常で使っているオンラインツール／その他備考</b> です。<br>
          ※ <b>チャレンジしたいポジション</b>は、<b>いまできるかどうかは気にせず</b>、やってみたいものを選んでください。
          「次はこの人にお願いしてみよう」と考えるときの参考にします。<br>
          ※ <b>運転</b>と<b>英語</b>は、これまでどおり同じ「設定」タブの<b>「できるポジション・スキル」</b>のところにあります。<br>
          ※ 入れてもらった内容は、メンバーを決めるときの<b>参考</b>にさせてもらいます。
          必ずしも希望どおりのポジションになるとは限りません。</td>
      </tr>
      <tr>
        <td>2026-08-28</td>
        <td><b>【不具合修正】<u>エントリーを押すと「保存に失敗しました（SyntaxError…）」と出る</u>のを直しました。</b><br>
          ⚠ これは<b>ログインの有効期限が切れていた</b>ときに出ていたものです（画面を開いたまま時間がたった／戻るボタンで古い画面を開いた、など）。<br>
          いまは「<b>ログインの有効期限が切れています。画面を読み込み直して、ログインし直してから、もう一度押してください。</b>」と出ます。<br>
          あわせて、<b>うまくいかなかったときのお知らせが、案件のところに赤い文字で残る</b>ようになりました
          （前は消えてしまうので、エントリーできたと思ってしまうことがありました）。</td>
      </tr>
      <tr>
        <td>2026-08-28</td>
        <td><b>確定した案件の詳細で、「<u>応募のときに書いた一言</u>」が見られるようになりました。</b>
          これまでは募集中の間だけしか見返せませんでした。<br>
          ※ 「担当からの連絡」は担当からあなたへの連絡で、別の欄です。</td>
      </tr>
      <tr>
        <td>2026-08-25</td>
        <td><b>アカウント発行のご案内メールのリンクが、7日間そのまま使えるようになりました。</b>
          これまで、受け取ってから時間がたつと<b>「この再設定リンクは無効か、期限が切れています」</b>と出てしまうことがありました。<br>
          もしそう出たときは、ログイン画面の「<b>パスワードをお忘れですか</b>」にメールアドレスを入れてください。
          新しいリンクが届き、そこで決めたパスワードで、そのままログインできます。</td>
      </tr>
      <tr>
        <td>2026-08-20</td>
        <td>このガイドを見直しました。<b>リンク集タブ</b>・<b>絞り込みボタン</b>（募集中のみ／エントリー中のみ／追加案件のみ）・<b>お知らせ</b>・<b>エントリー締切と残り人数</b>の説明を追加し、この更新履歴を作りました。</td>
      </tr>
      <tr>
        <td>2026-08-20</td>
        <td><b>確定アサインをタップすると詳細が開く</b>ようになりました（集合場所の詳しい説明・服装・持ち物・当日の注意事項）。</td>
      </tr>
      <tr>
        <td>2026-08-20</td>
        <td>稼働希望カレンダーが<b>今月ぶん</b>で開くようになりました。確定アサインに出るのは「公開された・確定になった」案件だけに整えました（調整中のものは出ません）。</td>
      </tr>
      <tr>
        <td>2026-08-17</td>
        <td><b>リンク集</b>タブを追加。募集中の案件を「募集中のみ」「追加案件のみ」でしぼれるようにしました。</td>
      </tr>
      <tr>
        <td>2026-07-17</td>
        <td>このガイドの初版を作成。</td>
      </tr>
    </table>
  </section>

  <footer>ECS スタッフアサイン管理システム ／ スタッフ向けガイド ・ 2026年8月20日版</footer>

</div>
</body>
</html>
@endverbatim
