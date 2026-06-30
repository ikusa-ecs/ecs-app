@extends('layouts.app')
@section('title', 'マイページ')
@section('h1', 'マイページ')
@php($active = 'mypage')

@push('head')
@verbatim
<style>
    .mp-wrap { max-width: 960px; }

    /* プロフィールの簡易カード */
    .prof-card { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
    .prof-avatar {
      width: 52px; height: 52px; border-radius: 50%; background: var(--brand-soft);
      color: var(--brand-dark); display: flex; align-items: center; justify-content: center;
      font-size: 22px; font-weight: 800; flex-shrink: 0;
    }
    .prof-main { flex: 1; min-width: 180px; }
    .prof-name { font-size: 17px; font-weight: 800; color: var(--ink); }
    .prof-sub { font-size: 12.5px; color: var(--muted); margin-top: 2px; }
    .dept { display: inline-block; padding: 1px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 700; }
    .dept.plan { background: #e3edff; color: #2456b8; }
    .dept.sales { background: #e1f3e4; color: #2e7d32; }
    .dept.creative { background: #efe6fb; color: #7a3fb8; }

    /* 月絞り込みバー */
    .mp-filter { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 4px; }
    .mp-filter label { font-size: 13px; font-weight: 700; color: var(--ink); }
    .mp-filter select {
      padding: 8px 11px; border: 1px solid var(--line); border-radius: 8px;
      font-size: 13.5px; font-family: inherit; background: #fff;
    }
    .mp-filter .note { font-size: 12px; color: var(--muted); }

    /* 案件テーブル */
    .role-tag {
      display: inline-block; padding: 1px 9px; border-radius: 6px; font-size: 11.5px; font-weight: 700;
      background: #e0f2fe; color: #0369a1; margin: 1px 2px 1px 0; white-space: nowrap;
    }
    .role-tag.sd { background: #ede9fe; color: #6d28d9; }
    .role-tag.mc { background: #fde9d9; color: #b4530a; }
    .sub-loc { font-size: 11.5px; color: var(--muted); }
    .cal-btn {
      background: #fff; color: var(--brand-dark); border: 1px solid var(--line);
      border-radius: 8px; padding: 5px 10px; font-size: 12px; font-weight: 700; cursor: pointer;
      font-family: inherit; white-space: nowrap;
    }
    .cal-btn:hover { background: #f3ece0; }

    .sec-count { font-size: 12.5px; color: var(--muted); margin: 0 0 10px; }
    .empty-note { color: var(--muted); font-size: 12.5px; margin: 8px 2px; }

    /* リスト／カレンダー 切替トグル */
    .view-tabs { display: flex; gap: 8px; margin: 0 0 12px; flex-wrap: wrap; }
    .view-tab {
      padding: 7px 16px; border: 1px solid var(--line); border-radius: 999px;
      background: #fff; cursor: pointer; font-size: 13px; color: #6b5544; font-weight: 700;
      font-family: inherit;
    }
    .view-tab.active { background: var(--brand); border-color: var(--brand-dark); color: #fff; }

    /* カレンダー表示（出勤可能日画面の作りを踏襲） */
    .mp-cal { display: none; }
    .mp-cal.show { display: block; }
    .mp-cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; }
    .mp-cal-grid .dow { text-align: center; font-size: 12px; color: var(--muted); padding-bottom: 4px; font-weight: 600; }
    .mp-cal-grid .dow.sat { color: var(--brand); }
    .mp-cal-grid .dow.sun { color: var(--danger); }
    .mp-cell {
      min-height: 74px; border-radius: 10px; border: 1px solid var(--line);
      padding: 4px 4px 5px; font-size: 13px; background: #fff; display: flex; flex-direction: column; gap: 3px;
    }
    .mp-cell.empty { border: none; background: none; }
    .mp-cell.has { background: var(--brand-soft); border-color: #e3d3b6; }
    .mp-cell .dnum { font-size: 12px; color: #8a7a66; font-weight: 600; }
    .mp-cell.sat .dnum { color: var(--brand); }
    .mp-cell.sun .dnum { color: var(--danger); }
    .mp-ev {
      background: #fff; border: 1px solid var(--line); border-radius: 6px;
      padding: 3px 4px; font-size: 10.5px; line-height: 1.25; cursor: pointer; text-align: left;
    }
    .mp-ev:hover { background: #f3ece0; }
    .mp-ev .ev-name { font-weight: 700; color: var(--ink); display: block; overflow-wrap: anywhere; }
    .mp-ev .ev-pos { color: var(--muted); }
    .mp-cal-empty { color: var(--muted); font-size: 12.5px; margin: 8px 2px; }

    /* アーカイブ折りたたみ */
    .arch-toggle {
      margin-top: 16px; background: none; border: none; cursor: pointer; font-family: inherit;
      font-size: 13px; font-weight: 700; color: var(--brand-dark); padding: 4px 0;
    }
    .arch-toggle:hover { color: var(--brand); }
    .arch-body { display: none; margin-top: 8px; }
    .arch-body.open { display: block; }
    .arch-body tr td { color: var(--muted); }

    /* アカウント欄 */
    .set-row {
      display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;
      padding: 14px 2px; border-bottom: 1px solid var(--line);
    }
    .set-row:last-child { border-bottom: none; }
    .set-row .set-label { font-size: 14px; font-weight: 700; color: var(--ink); }
    .set-row .set-note { display: block; font-size: 12px; font-weight: 400; color: var(--muted); margin-top: 3px; }
    .acct-value { font-size: 14px; color: var(--ink); font-weight: 600; overflow-wrap: anywhere; }
    .line-btn {
      background: #fff; color: var(--brand-dark); border: 1px solid var(--line);
      border-radius: 10px; padding: 11px 16px; font-size: 14px; font-weight: 700; cursor: pointer;
      font-family: inherit;
    }
    .line-btn:hover { background: #f3ece0; }
    .line-btn.danger { color: var(--danger); border-color: #f0b9b9; }
    .line-btn.danger:hover { background: var(--danger-soft); }

    /* カレンダー一括登録モーダル */
    .cal-modal-bg {
      position: fixed; inset: 0; background: rgba(0,0,0,.4);
      display: none; align-items: center; justify-content: center; z-index: 100; padding: 16px;
    }
    .cal-modal-bg.show { display: flex; }
    .cal-modal {
      background: #fff; border-radius: 12px; max-width: 560px; width: 100%;
      max-height: 80vh; display: flex; flex-direction: column; box-shadow: 0 10px 40px rgba(0,0,0,.2);
    }
    .cal-modal-head {
      display: flex; align-items: center; gap: 8px; padding: 16px 18px; border-bottom: 1px solid var(--line);
    }
    .cal-modal-head h2 { font-size: 16px; margin: 0; }
    .cal-modal-head .spacer { flex: 1; }
    .cal-modal-close {
      background: none; border: none; font-size: 20px; cursor: pointer; color: var(--muted); line-height: 1;
    }
    .cal-modal-body { padding: 12px 18px; overflow-y: auto; }
    .cal-modal-note { font-size: 12px; color: var(--muted); margin: 0 0 10px; }
    .bulk-row {
      display: flex; align-items: center; gap: 12px; padding: 10px 2px; border-bottom: 1px solid var(--line);
    }
    .bulk-row:last-child { border-bottom: none; }
    .bulk-date { font-size: 12.5px; font-weight: 700; color: var(--ink); white-space: nowrap; min-width: 64px; }
    .bulk-name { flex: 1; font-size: 13px; color: var(--ink); }
  </style>
@endverbatim
@endpush

@section('content')
@verbatim
      <div class="mock-note">これは見た目確認用のモックです。「baba さん」でログイン中という想定で、自分の案件を表示しています（データは仮の見本です）。</div>

      <!-- プロフィール -->
      <div class="panel mp-wrap">
        <div class="prof-card">
          <div class="prof-avatar">馬</div>
          <div class="prof-main">
            <div class="prof-name">baba さん <span class="dept plan">イベプラ</span></div>
            <div class="prof-sub">所属：イベント東（東京）　／　baba@ikusa.co.jp</div>
          </div>
          <button class="line-btn" onclick="location.href='/mypage-finance'">💰 収支を入力する</button>
          <button class="line-btn" onclick="alert('プロフィール編集の画面を開きます（モックのためダミーです）。氏名・拠点・区分は社員名簿と連動する想定です。')">プロフィールを編集</button>
        </div>
      </div>

      <!-- 月の絞り込み -->
      <div class="panel mp-wrap" style="margin-top:20px;">
        <div class="mp-filter">
          <label for="monthFilter">月で絞り込み：</label>
          <select id="monthFilter" onchange="render()"></select>
          <span class="note">「アサインされた案件」「営業担当の案件」の両方に効きます</span>
        </div>
      </div>

      <!-- ① アサインされた案件 -->
      <div class="panel mp-wrap" style="margin-top:20px;">
        <div class="panel-head">
          <h2>アサインされた案件</h2>
          <div class="spacer"></div>
          <button class="btn primary sm" onclick="openBulkCal()">📅 今後をまとめて登録</button>
        </div>
        <p class="sec-count">自分が現場メンバー（ディレクター等）としてアサインされた案件です。<span id="asgCount"></span></p>

        <!-- リスト／カレンダー 切替 -->
        <div class="view-tabs">
          <button class="view-tab active" id="asgTabList" onclick="switchAsgView('list')">📋 リスト表示</button>
          <button class="view-tab" id="asgTabCal" onclick="switchAsgView('cal')">📅 カレンダー表示</button>
        </div>

        <!-- リスト表示 -->
        <div id="asgListView">
          <table class="tbl">
            <thead>
              <tr><th>日程</th><th>案件名</th><th>自分のポジション</th><th>集合・解散</th><th>会場</th><th>状況</th><th>操作</th></tr>
            </thead>
            <tbody id="asgBody"></tbody>
          </table>
          <p class="empty-note" id="asgEmpty" style="display:none;">この月にアサインされた案件はありません。</p>
        </div>

        <!-- カレンダー表示 -->
        <div class="mp-cal" id="asgCalView">
          <div class="mp-cal-grid" id="asgCalGrid"></div>
          <p class="mp-cal-empty" id="asgCalEmpty" style="display:none;">この月にアサインされた案件はありません。</p>
        </div>

        <button class="arch-toggle" id="asgArchBtn" onclick="toggleArch('asg')">▸ アーカイブ（終了した案件） <span id="asgArchCnt"></span></button>
        <div class="arch-body" id="asgArch">
          <table class="tbl">
            <thead>
              <tr><th>日程</th><th>案件名</th><th>自分のポジション</th><th>集合・解散</th><th>会場</th><th>状況</th></tr>
            </thead>
            <tbody id="asgArchBody"></tbody>
          </table>
          <p class="empty-note" id="asgArchEmpty" style="display:none;">アーカイブはありません。</p>
        </div>
      </div>

      <!-- ② 営業担当の案件 -->
      <div class="panel mp-wrap" style="margin-top:20px;">
        <div class="panel-head"><h2>営業担当の案件</h2></div>
        <p class="sec-count">自分が営業担当（セールス）として登録した案件です。<span id="salCount"></span></p>

        <table class="tbl">
          <thead>
            <tr><th>日程</th><th>案件名</th><th>ディレクター</th><th>集合・解散</th><th>会場</th><th>状況</th></tr>
          </thead>
          <tbody id="salBody"></tbody>
        </table>
        <p class="empty-note" id="salEmpty" style="display:none;">この月に営業担当の案件はありません。</p>

        <button class="arch-toggle" id="salArchBtn" onclick="toggleArch('sal')">▸ アーカイブ（終了した案件） <span id="salArchCnt"></span></button>
        <div class="arch-body" id="salArch">
          <table class="tbl">
            <thead>
              <tr><th>日程</th><th>案件名</th><th>ディレクター</th><th>集合・解散</th><th>会場</th><th>状況</th></tr>
            </thead>
            <tbody id="salArchBody"></tbody>
          </table>
          <p class="empty-note" id="salArchEmpty" style="display:none;">アーカイブはありません。</p>
        </div>
      </div>

      <p class="muted" style="font-size:11.5px; margin:0 0 4px; max-width:960px;">
        ※ 「アサインされた案件」は、本番ではログイン社員ごとの実際のアサインから自動表示します（モックでは baba さんの担当ぶんを仮に設定しています）。「営業担当」は案件の営業担当から自動で抽出しています。
      </p>

      <!-- アカウント -->
      <div class="panel mp-wrap" style="margin-top:20px;">
        <div class="panel-head"><h2>アカウント</h2></div>

        <div class="set-row">
          <div>
            <span class="set-label">ログイン中のメールアドレス</span>
            <span class="set-note">ログインに使うメールアドレスです。</span>
          </div>
          <div><span class="acct-value">baba@ikusa.co.jp</span></div>
        </div>

        <div class="set-row">
          <div>
            <span class="set-label">パスワード</span>
            <span class="set-note">定期的な変更をおすすめします。</span>
          </div>
          <div><button class="line-btn" onclick="alert('パスワード変更の画面を開きます（モックのためダミーです）。')">パスワードを変更する</button></div>
        </div>

        <div class="set-row">
          <div>
            <span class="set-label">ログアウト</span>
            <span class="set-note">この端末からサインアウトします。</span>
          </div>
          <div><button class="line-btn danger" onclick="doLogout()">ログアウト</button></div>
        </div>
      </div>

      <!-- カレンダー一括登録モーダル -->
      <div class="cal-modal-bg" id="bulkBg">
        <div class="cal-modal">
          <div class="cal-modal-head">
            <h2>📅 今後の案件をカレンダーに登録</h2>
            <div class="spacer"></div>
            <button class="cal-modal-close" onclick="closeBulkCal()" aria-label="閉じる">×</button>
          </div>
          <div class="cal-modal-body">
            <p class="cal-modal-note">各案件の「登録」を押すと、Googleカレンダーの予定追加画面が入力済みで開きます。内容を確認して「保存」を押してください（カレンダーの時間は移動ぶん前後1時間を含みます）。</p>
            <div id="bulkList"></div>
          </div>
        </div>
      </div>
@endverbatim
@endsection

@push('scripts')
<script src="/ecs/data/cases.js"></script>
<script>
  // DB（MyPageController）から渡したデータ。空でなければ見本（cases.js / 下の MY_ASSIGN_MOCK）より優先する。
  // ・ECS_ME      … ログイン中の社員（名前・メール・部署）
  // ・MY_ASSIGN_DB … 自分のアサイン（案件ID→ポジション）。assignments 由来
  // ・ECS_CASES_DB … 全案件（projects 由来。cases.js と同じ形）
  window.ECS_ME = @json($me);
  window.MY_ASSIGN_DB = @json($myAssign);
  window.ECS_CASES_DB = @json($cases);
  if (window.ECS_CASES_DB && window.ECS_CASES_DB.length) { window.ECS_CASES = window.ECS_CASES_DB; }
</script>
@verbatim
<script>
  // ===== ログイン中の社員（認証はMTG後。今は MyPageController が固定した「自分」を使う）=====
  // DB（window.ECS_ME）があればその名前、無ければ 'baba'。
  const ME = (window.ECS_ME && window.ECS_ME.name) ? window.ECS_ME.name : 'baba';
  const WK = ['日','月','火','水','木','金','土'];

  // 見本：DB にアサインが無いときのフォールバック（案件ID→自分のポジション）。
  const MY_ASSIGN_MOCK = {
    'past_fes':  'ディレクター',   // 終了（アーカイブ確認用）
    'undo_d1':   'ディレクター',
    'undo_d2':   'ディレクター',
    'undo_d3':   'ディレクター',
    'mizu':      'ディレクター',
    'shinkan':   'ディレクター',
    'konshin':   'SD',
    'enni1':     'MC',
    'bousai':    'ディレクター',   // 翌月（月絞り確認用）
    'fes_setup': 'ディレクター'    // さらに先（月絞り確認用）
  };
  // DB の自分のアサイン（MyPageController が assignments から作る）。空なら見本を使う。
  const MY_ASSIGN = (window.MY_ASSIGN_DB && Object.keys(window.MY_ASSIGN_DB).length)
    ? window.MY_ASSIGN_DB : MY_ASSIGN_MOCK;

  // off → Date
  function dateOf(off) {
    return (window.ECS_caseDate ? window.ECS_caseDate(off)
      : (function(){ var d=new Date(); d.setHours(0,0,0,0); d.setDate(d.getDate()+off); return d; })());
  }
  // off → 「7/20(日)」
  function fmtDate(off) {
    const d = dateOf(off);
    return (d.getMonth() + 1) + '/' + d.getDate() + '(' + WK[d.getDay()] + ')';
  }
  // off → 月キー「2026-07」
  function monthKey(off) {
    const d = dateOf(off);
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
  }
  // 月キー → 「2026年7月」
  function monthLabel(key) {
    const [y, m] = key.split('-');
    return y + '年' + Number(m) + '月';
  }

  function statusBadge(status) {
    const cls = status === '確定' ? 'ok' : (status === '調整中' ? 'blue' : 'amber');
    return '<span class="badge ' + cls + '">' + (status || '—') + '</span>';
  }
  function posTag(pos) {
    const cls = pos === 'SD' ? ' sd' : (pos === 'MC' ? ' mc' : '');
    return '<span class="role-tag' + cls + '">' + pos + '</span>';
  }
  function byId(id) { return (window.ECS_CASES || []).find(c => c.id === id); }

  // データの組み立て
  function assignedCases() {
    return Object.keys(MY_ASSIGN)
      .map(id => { const c = byId(id); return c ? Object.assign({ myPos: MY_ASSIGN[id] }, c) : null; })
      .filter(c => c && !c.draft);
  }
  function salesCases() {
    return (window.ECS_CASES || []).filter(c => c.sales === ME && !c.draft);
  }

  // 月セレクトの選択肢を作る（両セクションの案件に出てくる月）
  function buildMonthOptions() {
    const keys = new Set();
    assignedCases().forEach(c => keys.add(monthKey(c.off)));
    salesCases().forEach(c => keys.add(monthKey(c.off)));
    const sorted = Array.from(keys).sort();
    const sel = document.getElementById('monthFilter');
    sel.innerHTML = '<option value="">すべての月</option>' +
      sorted.map(k => '<option value="' + k + '">' + monthLabel(k) + '</option>').join('');
  }

  // 行HTML（アサイン用：自分のポジション列あり）
  function asgRow(c, isArch) {
    const cal = isArch ? ''
      : '<td><button class="cal-btn" onclick="addCal(\'' + c.id + '\')">📅 登録</button></td>';
    return '<tr>' +
      '<td>' + fmtDate(c.off) + '</td>' +
      '<td><strong>' + c.name + '</strong><br><span class="sub-loc">' + c.client + '</span></td>' +
      '<td>' + posTag(c.myPos) + '</td>' +
      '<td>' + (c.meet || '—') + '〜' + (c.leave || '—') + '</td>' +
      '<td>' + (c.placeShort || '—') + '</td>' +
      '<td>' + statusBadge(c.status) + '</td>' +
      (isArch ? '' : cal) +
    '</tr>';
  }
  // 行HTML（営業用：ディレクター列）
  function salRow(c) {
    return '<tr>' +
      '<td>' + fmtDate(c.off) + '</td>' +
      '<td><strong>' + c.name + '</strong><br><span class="sub-loc">' + c.client + '</span></td>' +
      '<td>' + (c.dir || '—') + '</td>' +
      '<td>' + (c.meet || '—') + '〜' + (c.leave || '—') + '</td>' +
      '<td>' + (c.placeShort || '—') + '</td>' +
      '<td>' + statusBadge(c.status) + '</td>' +
    '</tr>';
  }

  // ===== E-1（A）：自分のアサイン案件 → Googleカレンダー（予定追加リンク方式・OAuth不要） =====
  // ボタンを押すと Googleカレンダーの「予定追加」画面がタイトル・日時・場所・メモ入りで開く。
  // 社員は内容を確認して「保存」を押すだけ（半自動）。クリック不要の完全自動版は認証連携後（MTG後）。

  function myCaseById(id) { return assignedCases().find(c => c.id === id); }

  function pad2(n) { return String(n).padStart(2, '0'); }
  // Date → "20260720T073000"（Googleカレンダーの時刻形式・ローカル時刻）
  function calStamp(d) {
    return d.getFullYear() + pad2(d.getMonth() + 1) + pad2(d.getDate()) +
      'T' + pad2(d.getHours()) + pad2(d.getMinutes()) + '00';
  }
  function calYmd(d) { return '' + d.getFullYear() + pad2(d.getMonth() + 1) + pad2(d.getDate()); }

  // 案件1件 → Googleカレンダー予定追加URL
  function gcalUrl(c) {
    const d = dateOf(c.off);
    const title = c.content + '（' + c.client + '）｜' + c.myPos;
    const loc = (c.place && c.place !== '（未定）') ? c.place : (c.placeShort || '');
    const hasTime = c.meet && c.leave && c.meet !== '—' && c.leave !== '—';

    let dates;
    if (hasTime) {
      const [mh, mm] = c.meet.split(':').map(Number);
      const [lh, lm] = c.leave.split(':').map(Number);
      const s = new Date(d); s.setHours(mh - 1, mm, 0, 0);   // 集合 −1時間（移動枠）
      const e = new Date(d); e.setHours(lh + 1, lm, 0, 0);   // 解散 ＋1時間（移動枠）
      dates = calStamp(s) + '/' + calStamp(e);
    } else {
      const e = new Date(d); e.setDate(e.getDate() + 1);
      dates = calYmd(d) + '/' + calYmd(e);                   // 時間未定は終日予定
    }

    const lines = [
      '顧客名：' + c.client,
      '営業担当：' + (c.sales || '—'),
      'ディレクター：' + (c.dir || '—'),
      '自分の役割：' + c.myPos,
      '集合〜解散：' + (c.meet || '—') + '〜' + (c.leave || '—') +
        (hasTime ? '（カレンダーの時間は前後1時間の移動枠を含みます）' : '（時間未定のため終日で登録）')
    ];
    if (c.note) lines.push('メモ：' + c.note);

    const q = new URLSearchParams({
      action: 'TEMPLATE', text: title, dates: dates,
      details: lines.join('\n'), location: loc, ctz: 'Asia/Tokyo'
    });
    return 'https://calendar.google.com/calendar/render?' + q.toString();
  }

  // 案件ごとの「📅 登録」
  function addCal(id) {
    const c = myCaseById(id);
    if (c) window.open(gcalUrl(c), '_blank', 'noopener');
  }

  // 「今後をまとめて登録」→ 一覧の小窓を出し、1件ずつリンク
  function openBulkCal() {
    const list = assignedCases().filter(c => !c.archived).sort((a, b) => a.off - b.off);
    document.getElementById('bulkList').innerHTML = list.length
      ? list.map(c =>
          '<div class="bulk-row">' +
            '<span class="bulk-date">' + fmtDate(c.off) + '</span>' +
            '<span class="bulk-name"><strong>' + c.content + '</strong>（' + c.client + '）' +
              '<br><span class="sub-loc">' + c.myPos + ' / ' + (c.placeShort || '—') + '</span></span>' +
            '<a class="cal-btn" href="' + gcalUrl(c).replace(/&/g, '&amp;') + '" target="_blank" rel="noopener">📅 登録</a>' +
          '</div>'
        ).join('')
      : '<p class="empty-note">今後のアサイン案件はありません。</p>';
    document.getElementById('bulkBg').classList.add('show');
  }
  function closeBulkCal() { document.getElementById('bulkBg').classList.remove('show'); }

  // ===== カレンダー表示（アサイン案件をマス目に置く。出勤可能日画面の作りを踏襲）=====
  // 現在のリスト／カレンダーの表示状態。既定はリスト。
  let asgView = 'list';

  // 表示する月を決める：月セレクト選択中はその月、未選択なら直近（今日以降で一番早い）アサイン月、無ければ今月。
  function asgCalMonth() {
    const mf = document.getElementById('monthFilter').value;
    if (mf) { const [y, m] = mf.split('-').map(Number); return { y, m }; }
    // 未選択：今日以降のアサインのうち一番早い月。無ければ過去含め一番近い月。今も無ければ今月。
    const list = assignedCases();
    const upcoming = list.filter(c => c.off >= 0).sort((a, b) => a.off - b.off);
    const pick = upcoming.length ? upcoming[0] : (list.slice().sort((a, b) => a.off - b.off)[0] || null);
    if (pick) { const d = dateOf(pick.off); return { y: d.getFullYear(), m: d.getMonth() + 1 }; }
    const t = new Date(); return { y: t.getFullYear(), m: t.getMonth() + 1 };
  }

  // その月のアサイン案件を「日 → 案件配列」にまとめる
  function asgByDay(y, m) {
    const map = {};
    assignedCases().forEach(c => {
      const d = dateOf(c.off);
      if (d.getFullYear() === y && d.getMonth() + 1 === m) {
        (map[d.getDate()] = map[d.getDate()] || []).push(c);
      }
    });
    return map;
  }

  function renderAsgCalendar() {
    const { y, m } = asgCalMonth();
    const grid = document.getElementById('asgCalGrid');
    const dayMap = asgByDay(y, m);
    const hasAny = Object.keys(dayMap).length > 0;
    document.getElementById('asgCalEmpty').style.display = hasAny ? 'none' : 'block';

    let html = '';
    // 曜日見出し（日曜始まり）
    WK.forEach((w, i) => {
      html += '<div class="dow' + (i === 6 ? ' sat' : '') + (i === 0 ? ' sun' : '') + '">' + w + '</div>';
    });
    // 月初の空きセル（日曜始まり）
    const firstDow = new Date(y, m - 1, 1).getDay(); // 0=日..6=土
    for (let i = 0; i < firstDow; i++) html += '<div class="mp-cell empty"></div>';

    const days = new Date(y, m, 0).getDate();
    for (let d = 1; d <= days; d++) {
      const dow = new Date(y, m - 1, d).getDay();
      const evs = dayMap[d] || [];
      const dowCls = (dow === 6 ? ' sat' : (dow === 0 ? ' sun' : ''));
      html += '<div class="mp-cell' + (evs.length ? ' has' : '') + dowCls + '">' +
        '<div class="dnum">' + d + '</div>' +
        evs.map(c =>
          '<button class="mp-ev" onclick="addCal(\'' + c.id + '\')" title="クリックでGoogleカレンダー登録">' +
            '<span class="ev-name">' + (c.content || c.name) + '</span>' +
            '<span class="ev-pos">' + c.myPos + (c.placeShort ? ' / ' + c.placeShort : '') + '</span>' +
          '</button>'
        ).join('') +
      '</div>';
    }
    grid.innerHTML = html;
  }

  // リスト／カレンダーの表示切替
  function switchAsgView(view) {
    asgView = view;
    document.getElementById('asgTabList').classList.toggle('active', view === 'list');
    document.getElementById('asgTabCal').classList.toggle('active', view === 'cal');
    document.getElementById('asgListView').style.display = view === 'list' ? 'block' : 'none';
    document.getElementById('asgCalView').classList.toggle('show', view === 'cal');
    if (view === 'cal') renderAsgCalendar();
  }

  function render() {
    const mf = document.getElementById('monthFilter').value;
    const inMonth = c => !mf || monthKey(c.off) === mf;

    // ① アサイン
    const asg = assignedCases().filter(inMonth);
    const asgUp  = asg.filter(c => !c.archived).sort((a, b) => a.off - b.off);
    const asgArc = asg.filter(c => c.archived).sort((a, b) => b.off - a.off);
    document.getElementById('asgBody').innerHTML = asgUp.map(c => asgRow(c, false)).join('');
    document.getElementById('asgEmpty').style.display = asgUp.length ? 'none' : 'block';
    document.getElementById('asgArchBody').innerHTML = asgArc.map(c => asgRow(c, true)).join('');
    document.getElementById('asgArchEmpty').style.display = asgArc.length ? 'none' : 'block';
    document.getElementById('asgArchCnt').textContent = '（' + asgArc.length + '件）';
    document.getElementById('asgCount').textContent = '表示中 ' + asgUp.length + ' 件';

    // ② 営業担当
    const sal = salesCases().filter(inMonth);
    const salUp  = sal.filter(c => !c.archived).sort((a, b) => a.off - b.off);
    const salArc = sal.filter(c => c.archived).sort((a, b) => b.off - a.off);
    document.getElementById('salBody').innerHTML = salUp.map(salRow).join('');
    document.getElementById('salEmpty').style.display = salUp.length ? 'none' : 'block';
    document.getElementById('salArchBody').innerHTML = salArc.map(salRow).join('');
    document.getElementById('salArchEmpty').style.display = salArc.length ? 'none' : 'block';
    document.getElementById('salArchCnt').textContent = '（' + salArc.length + '件）';
    document.getElementById('salCount').textContent = '表示中 ' + salUp.length + ' 件';

    // カレンダー表示も月セレクトに連動して更新（表示中のときだけ描けば十分だが、常に最新にしておく）
    renderAsgCalendar();
  }

  function toggleArch(key) {
    const body = document.getElementById(key + 'Arch');
    const btn  = document.getElementById(key + 'ArchBtn');
    const open = body.classList.toggle('open');
    btn.innerHTML = (open ? '▾' : '▸') + ' アーカイブ（終了した案件） <span id="' + key + 'ArchCnt">' +
      document.getElementById(key + 'ArchCnt').textContent + '</span>';
  }

  function doLogout() {
    if (confirm('ログアウトします。よろしいですか？')) location.href = '/';
  }

  buildMonthOptions();
  render();
</script>
@endverbatim
@endpush
