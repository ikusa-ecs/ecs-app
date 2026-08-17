{{-- 全画面共通のサイドバー（左メニュー）。$active に画面名を渡すと、その項目が選択状態になる。 --}}
{{-- リンク先：Blade化が済んだ画面は「/dashboard」のようなきれいなURL、まだの画面は「/ecs/◯◯.html」（モックのまま動く）。 --}}
{{-- グループ見出し（案件／アサイン等）はクリックで開閉できる。開閉状態はブラウザに記憶（localStorage）。 --}}
<aside class="sidebar">
  <div class="logo">ECS<small>スタッフアサイン管理</small></div>
  <nav>
    <a class="{{ ($active ?? '') === 'dashboard' ? 'active' : '' }}" href="/dashboard"><span class="nav-icon">▣</span> ダッシュボード</a>
    <a class="{{ ($active ?? '') === 'stats' ? 'active' : '' }}" href="/stats"><span class="nav-icon">📈</span> 集計ダッシュボード</a>
    <a class="{{ ($active ?? '') === 'mypage' ? 'active' : '' }}" href="/mypage"><span class="nav-icon">🙍</span> マイページ</a>
    <a class="{{ ($active ?? '') === 'employee_availability' ? 'active' : '' }}" href="/employee-availability"><span class="nav-icon">📅</span> 社員の出勤可能日</a>
    <a class="{{ ($active ?? '') === 'mypage_finance' ? 'active' : '' }}" href="/mypage-finance"><span class="nav-icon">💰</span> 収支入力</a>
    {{-- 収支一覧＝月ごとの売上・経費・利益と「入力済みか」。見るのは社員以上ぜんぶ・直すのは担当と管理者以上。 --}}
    <a class="{{ ($active ?? '') === 'finance_list' ? 'active' : '' }}" href="/finance-list"><span class="nav-icon">📊</span> 収支一覧</a>
    {{-- 使い方ガイド（社内向け）。別タブで開く＝作業を邪魔しない。機能が増えたら guide.blade.php を更新。 --}}
    <a href="/guide" target="_blank" rel="noopener"><span class="nav-icon">📋</span> 使い方ガイド</a>

    <div class="nav-group" data-group="案件">
      <div class="group-label" onclick="ECStoggleGroup(this)"><span class="caret">▾</span> 案件</div>
      <div class="group-items">
        <a class="{{ ($active ?? '') === 'projects' ? 'active' : '' }}" href="/projects"><span class="nav-icon">▤</span> 案件一覧</a>
        {{-- 年月フォルダ（案件一覧の画面だけJSで中身が入る。他画面では空のまま） --}}
        <div class="ym-tree" id="ymTree"></div>
        {{-- アサイン表は「案件の分類」なので案件グループに置く（baba 2026-07-16）。 --}}
        <a class="{{ ($active ?? '') === 'assign_sheet' ? 'active' : '' }}" href="/assign-sheet"><span class="nav-icon">🗒️</span> アサイン表</a>
        <a class="{{ ($active ?? '') === 'project_form' ? 'active' : '' }}" href="/project-form"><span class="nav-icon">＋</span> 案件登録</a>
        <a class="{{ ($active ?? '') === 'assign_publish' ? 'active' : '' }}" href="/assign-publish"><span class="nav-icon">📣</span> スタッフ公開ボード</a>
        <a class="{{ ($active ?? '') === 'count_reminder' ? 'active' : '' }}" href="/count-reminder"><span class="nav-icon">⏰</span> 人数確定リマインド</a>
        {{-- 収支未入力リマインド＝締切(イベント終了後3営業日)を過ぎて未入力の案件をDへタスク化。 --}}
        <a class="{{ ($active ?? '') === 'finance_reminder' ? 'active' : '' }}" href="/finance-reminder"><span class="nav-icon">💸</span> 収支未入力リマインド</a>
        <a href="#" onclick="(window.openAggWindow ? openAggWindow() : window.open('/projects-agg','ecs_agg','width=820,height=640')); return false;"><span class="nav-icon">📊</span> 社員・ディレクター集計</a>
      </div>
    </div>

    <div class="nav-group" data-group="アサイン">
      <div class="group-label" onclick="ECStoggleGroup(this)"><span class="caret">▾</span> アサイン</div>
      <div class="group-items">
        <a class="{{ ($active ?? '') === 'assign_dashboard' ? 'active' : '' }}" href="/assign-dashboard"><span class="nav-icon">▣</span> アサインダッシュボード</a>
        <a class="{{ ($active ?? '') === 'assign' ? 'active' : '' }}" href="/assign"><span class="nav-icon">▦</span> 日別ボード</a>
        <a class="{{ ($active ?? '') === 'assign_detail' ? 'active' : '' }}" href="/assign-detail"><span class="nav-icon">◎</span> 案件別アサイン（案件を選ぶ）</a>
        <a class="{{ ($active ?? '') === 'pickup' ? 'active' : '' }}" href="/pickup"><span class="nav-icon">📌</span> ピックアップ</a>
        <a class="{{ ($active ?? '') === 'assign_director' ? 'active' : '' }}" href="/assign-director"><span class="nav-icon">🎬</span> D決め（ディレクター）</a>
        <a class="{{ ($active ?? '') === 'entries' ? 'active' : '' }}" href="/entries"><span class="nav-icon">🙋</span> エントリー一覧</a>
        <a href="#" onclick="window.open('/assign-wishlist','ecs_wishlist','width=900,height=720'); return false;"><span class="nav-icon">📊</span> スタッフ集計</a>
      </div>
    </div>

    <div class="nav-group" data-group="その他">
      <div class="group-label" onclick="ECStoggleGroup(this)"><span class="caret">▾</span> その他</div>
      <div class="group-items">
        <a class="{{ ($active ?? '') === 'paper_stock' ? 'active' : '' }}" href="/paper-stock"><span class="nav-icon">📄</span> 謎解きの紙 在庫</a>
      </div>
    </div>

    <div class="nav-group" data-group="管理">
      <div class="group-label" onclick="ECStoggleGroup(this)"><span class="caret">▾</span> 管理</div>
      <div class="group-items">
        <a class="{{ ($active ?? '') === 'staff' ? 'active' : '' }}" href="/staff"><span class="nav-icon">☷</span> スタッフ（名簿・稼働状況）</a>
        <a class="{{ ($active ?? '') === 'employees' ? 'active' : '' }}" href="/employees"><span class="nav-icon">🧑‍💼</span> 社員名簿</a>
        @if (in_array(optional(Auth::user())->permission, ['manager', 'admin'], true))
        <a class="{{ ($active ?? '') === 'account_new' ? 'active' : '' }}" href="/account-new"><span class="nav-icon">🔑</span> アカウント発行</a>
        {{-- CSV取込は増やさず1項目に集約。ハブ画面から名簿・コンテンツ・案件の各取込へ。 --}}
        <a class="{{ in_array(($active ?? ''), ['imports', 'person_import', 'content_import', 'project_import'], true) ? 'active' : '' }}" href="/imports"><span class="nav-icon">⬆</span> CSV一括取込</a>
        @endif
        {{-- 共通設定＝マスタ管理・システム設定。MTG日などは社員も変更できるので社員以上に表示（マスタ削除だけAdministrator限定）。 --}}
        <a class="{{ ($active ?? '') === 'settings' ? 'active' : '' }}" href="/settings"><span class="nav-icon">⚙️</span> 共通設定</a>
        {{-- Administrator（全権）専用コンソール。権限変更など「Administratorだけの作業」をここに集約。 --}}
        @if (optional(Auth::user())->permission === 'admin')
        <a class="{{ ($active ?? '') === 'admin_console' ? 'active' : '' }}" href="/admin-console"><span class="nav-icon">🛡</span> Administrator（管理）</a>
        @endif
      </div>
    </div>
  </nav>
  <div class="userbox">
    @auth
      @php
        $permLabels = ['admin' => 'Administrator', 'manager' => '管理者', 'employee' => '社員', 'staff' => 'スタッフ'];
        $u = Auth::user();
      @endphp
      <strong>{{ $u->name }} さん</strong>
      {{ $permLabels[$u->permission] ?? '社員' }}
      <div style="margin-top:8px; display:flex; flex-direction:column; gap:4px; font-size:12px;">
        <a href="/profile" style="color:#a08a73; text-decoration:underline;">マイプロフィール</a>
        <a href="/password" style="color:#a08a73; text-decoration:underline;">パスワード変更</a>
        <form method="POST" action="/logout" style="margin:2px 0 0;">
          @csrf
          <button type="submit" style="background:none; border:none; padding:0; color:#a08a73; cursor:pointer; font:inherit; text-decoration:underline;">ログアウト</button>
        </form>
      </div>
    @endauth

    {{-- 表示の切り替え（困ったとき用の逃げ道）。
         ふだんは画面の幅で自動的にスマホ表示／PC表示が決まるが、
         列の多い画面などは「スマホでもPC表示のまま拡大して見たい」ことがあるため、
         手動で上書きできるようにする。選んだ状態はその端末に記憶する。
         スマホ・タブレット（端末の実寸が狭いもの）でしか意味がないので、
         PCでは JS 側で丸ごと隠している。 --}}
    <button type="button" class="pc-mode-btn" id="pcModeBtn" onclick="ECStogglePcMode()" hidden></button>
  </div>
</aside>
<script>
  // --- 表示の切り替え（スマホ表示 ⇔ PC表示）---
  // 仕組み：PC表示を選ぶと「この画面は1200px幅として扱って」とブラウザに伝えるだけ。
  //         画面を作り分けているわけではないので、直す場所は今までどおり1か所で済む。
  // 切り替えは読み込み直して反映する（表示の指定を途中で変えると、機種によって効かないため）。
  function ECStogglePcMode(){
    var on = document.documentElement.classList.contains('force-pc');
    try { localStorage.setItem('ecs_force_pc', on ? '0' : '1'); } catch (e) {}
    location.reload();
  }
  (function(){
    var btn = document.getElementById('pcModeBtn');
    if (!btn) return;
    var forced = document.documentElement.classList.contains('force-pc');
    // screen.width＝端末そのものの横幅。PC表示に切り替えても変わらないので、
    // 「いまPC表示中でも、元はスマホ」を正しく見分けられる。
    var narrowDevice = (window.screen && window.screen.width <= 900);
    if (!narrowDevice && !forced) return;               // PCでは出さない
    btn.hidden = false;
    btn.textContent = forced ? '📱 スマホ表示に戻す' : '🖥 PC表示に切り替える';
  })();

  // グループ見出しをクリックすると、その下の項目を開閉する。
  function ECStoggleGroup(el){
    var g = el.closest('.nav-group');
    g.classList.toggle('collapsed');
    try {
      var key = 'ecs_nav_collapsed';
      var arr = JSON.parse(localStorage.getItem(key) || '[]');
      var name = g.getAttribute('data-group');
      var i = arr.indexOf(name);
      if (g.classList.contains('collapsed')) { if (i < 0) arr.push(name); }
      else { if (i >= 0) arr.splice(i, 1); }
      localStorage.setItem(key, JSON.stringify(arr));
    } catch (e) {}
  }
  // ページを開いたとき、前回たたんだグループを再現する。
  // ただし「いま開いているページ」が含まれるグループは必ず開く（迷子防止）。
  (function(){
    try {
      var arr = JSON.parse(localStorage.getItem('ecs_nav_collapsed') || '[]');
      document.querySelectorAll('.sidebar .nav-group').forEach(function(g){
        if (arr.indexOf(g.getAttribute('data-group')) >= 0) g.classList.add('collapsed');
        if (g.querySelector('a.active')) g.classList.remove('collapsed');
      });
    } catch (e) {}
  })();
</script>
