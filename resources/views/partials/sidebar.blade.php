{{-- 全画面共通のサイドバー（左メニュー）。$active に画面名を渡すと、その項目が選択状態になる。 --}}
{{-- リンク先：Blade化が済んだ画面は「/dashboard」のようなきれいなURL、まだの画面は「/ecs/◯◯.html」（モックのまま動く）。 --}}
{{-- グループ見出し（案件／アサイン等）はクリックで開閉できる。開閉状態はブラウザに記憶（localStorage）。 --}}
<aside class="sidebar">
  <div class="logo">ECS<small>スタッフアサイン管理</small></div>
  <nav>
    <a class="{{ ($active ?? '') === 'dashboard' ? 'active' : '' }}" href="/dashboard"><span class="nav-icon">▣</span> ダッシュボード</a>
    <a class="{{ ($active ?? '') === 'mypage' ? 'active' : '' }}" href="/mypage"><span class="nav-icon">🙍</span> マイページ</a>
    <a class="{{ ($active ?? '') === 'employee_availability' ? 'active' : '' }}" href="/employee-availability"><span class="nav-icon">📅</span> 社員の出勤可能日</a>
    <a class="{{ ($active ?? '') === 'mypage_finance' ? 'active' : '' }}" href="/mypage-finance"><span class="nav-icon">💰</span> 収支入力</a>

    <div class="nav-group" data-group="案件">
      <div class="group-label" onclick="ECStoggleGroup(this)"><span class="caret">▾</span> 案件</div>
      <div class="group-items">
        <a class="{{ ($active ?? '') === 'projects' ? 'active' : '' }}" href="/projects"><span class="nav-icon">▤</span> 案件一覧</a>
        {{-- 年月フォルダ（案件一覧の画面だけJSで中身が入る。他画面では空のまま） --}}
        <div class="ym-tree" id="ymTree"></div>
        <a class="{{ ($active ?? '') === 'project_form' ? 'active' : '' }}" href="/project-form"><span class="nav-icon">＋</span> 案件登録</a>
        <a class="{{ ($active ?? '') === 'assign_publish' ? 'active' : '' }}" href="/assign-publish"><span class="nav-icon">📣</span> スタッフ公開ボード</a>
        <a class="{{ ($active ?? '') === 'count_reminder' ? 'active' : '' }}" href="/count-reminder"><span class="nav-icon">⏰</span> 人数確定リマインド</a>
        <a href="#" onclick="(window.openAggWindow ? openAggWindow() : window.open('/projects-agg','ecs_agg','width=820,height=640')); return false;"><span class="nav-icon">📊</span> 社員・ディレクター集計</a>
      </div>
    </div>

    <div class="nav-group" data-group="アサイン">
      <div class="group-label" onclick="ECStoggleGroup(this)"><span class="caret">▾</span> アサイン</div>
      <div class="group-items">
        <a class="{{ ($active ?? '') === 'assign_sheet' ? 'active' : '' }}" href="/assign-sheet"><span class="nav-icon">🗒️</span> アサイン表</a>
        <a class="{{ ($active ?? '') === 'assign_dashboard' ? 'active' : '' }}" href="/assign-dashboard"><span class="nav-icon">▣</span> アサインダッシュボード</a>
        <a class="{{ ($active ?? '') === 'assign' ? 'active' : '' }}" href="/assign"><span class="nav-icon">▦</span> 日別ボード</a>
        <a class="{{ ($active ?? '') === 'assign_detail' ? 'active' : '' }}" href="/assign-detail"><span class="nav-icon">◎</span> アサイン画面（案件詳細）</a>
        <a class="{{ ($active ?? '') === 'entries' ? 'active' : '' }}" href="/entries"><span class="nav-icon">🙋</span> エントリー一覧</a>
        <a class="{{ ($active ?? '') === 'pickup' ? 'active' : '' }}" href="/pickup"><span class="nav-icon">📌</span> ピックアップ</a>
        <a class="{{ ($active ?? '') === 'assign_director' ? 'active' : '' }}" href="/assign-director"><span class="nav-icon">🎬</span> D決め（ディレクター）</a>
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
        <a class="{{ ($active ?? '') === 'settings' ? 'active' : '' }}" href="/settings"><span class="nav-icon">⚙️</span> 共通設定</a>
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
      <div style="margin-top:8px;">
        <form method="POST" action="/logout" style="margin:0;">
          @csrf
          <button type="submit" style="background:none; border:none; padding:0; color:#a08a73; cursor:pointer; font:inherit; text-decoration:underline;">ログアウト</button>
        </form>
      </div>
    @endauth
  </div>
</aside>
<script>
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
