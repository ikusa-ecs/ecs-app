@extends('layouts.app')
@section('title', '共通設定')
@section('h1', '共通設定')
@php($active = 'settings')

@push('head')
@verbatim
<style>
    .settings-wrap { max-width: 640px; }

    /* 設定の1項目（ラベル＋説明） */
    .set-row {
      display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;
      padding: 14px 2px; border-bottom: 1px solid var(--line);
    }
    .set-row:last-child { border-bottom: none; }
    .set-row .set-label { font-size: 14px; font-weight: 700; color: var(--ink); }
    .set-row .set-note { display: block; font-size: 12px; font-weight: 400; color: var(--muted); margin-top: 3px; }
    .set-row .set-control { flex-shrink: 0; }

    /* アカウント欄 */
    .acct-value { font-size: 14px; color: var(--ink); font-weight: 600; overflow-wrap: anywhere; }
    .line-btn {
      background: #fff; color: var(--brand-dark); border: 1px solid var(--line);
      border-radius: 10px; padding: 11px 16px; font-size: 14px; font-weight: 700; cursor: pointer;
      font-family: inherit;
    }
    .line-btn:hover { background: #f3ece0; }
    .line-btn.danger { color: var(--danger); border-color: #f0b9b9; }
    .line-btn.danger:hover { background: var(--danger-soft); }

    /* 日付入力 */
    .date-input {
      padding: 10px 12px; border: 1px solid var(--line); border-radius: 10px;
      font-size: 15px; font-family: inherit; background: #fff;
    }

    /* ON/OFFスイッチ（見た目だけのトグル） */
    .switch { position: relative; width: 46px; height: 26px; flex-shrink: 0; display: inline-block; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .switch .track {
      position: absolute; inset: 0; background: #d8cfc0; border-radius: 999px;
      transition: background .15s; cursor: pointer;
    }
    .switch .track::before {
      content: ''; position: absolute; left: 3px; top: 3px; width: 20px; height: 20px;
      background: #fff; border-radius: 50%; transition: transform .15s; box-shadow: 0 1px 2px rgba(0,0,0,.2);
    }
    .switch input:checked + .track { background: var(--brand); }
    .switch input:checked + .track::before { transform: translateX(20px); }

    /* 保存ボタンと「保存しました」 */
    .save-bar { display: flex; align-items: center; gap: 14px; margin-top: 16px; }
    .saved-msg { display: none; color: #2e7d32; font-weight: 700; font-size: 13px; }

    /* ① アサインMTG日の予定表 */
    .mtg-current { font-size: 13px; color: var(--ink); margin: 4px 0 12px; }
    .mtg-list { display: flex; flex-wrap: wrap; gap: 8px; margin: 4px 0 12px; }
    .mtg-empty { font-size: 12.5px; color: var(--muted); margin: 4px 0 12px; }
    .mtg-chip {
      display: inline-flex; align-items: center; gap: 8px;
      background: #f3ece0; border: 1px solid var(--line); border-radius: 999px;
      padding: 6px 10px 6px 12px; font-size: 13px; font-weight: 600; color: var(--ink);
    }
    .mtg-chip.is-current { background: var(--brand); color: #fff; border-color: var(--brand); }
    .mtg-chip b.rm { cursor: pointer; font-weight: 700; opacity: .8; }
    .mtg-chip b.rm:hover { opacity: 1; }
    /* 危険日（手動）：大型案件日の一覧 */
    .danger-current { font-size: 13px; font-weight: 700; color: var(--ink); margin: 4px 0 6px; }
    .big-list { display: flex; flex-direction: column; gap: 6px; max-height: 220px; overflow: auto;
      border: 1px solid var(--line); border-radius: 10px; padding: 8px; background: #fff; }
    .big-list .empty { color: var(--muted); font-size: 12.5px; padding: 4px; }
    .big-row { display: flex; align-items: center; gap: 10px; font-size: 13px; }
    .big-row .bdate { font-weight: 700; min-width: 70px; }
    .big-row .bname { flex: 1; color: var(--ink); word-break: break-word; }
    .big-row .badd { font-size: 12px; padding: 3px 10px; white-space: nowrap; }
    .big-row .badd.done { opacity: .6; }
    .mtg-chip.danger { background: #fdecec; border-color: #f1b5b5; color: #b91c1c; }

    /* ①-3 スタッフ画面の便利リンク集（1行＝1リンク。名前・URL・ひとこと） */
    .lk-list { display: flex; flex-direction: column; gap: 8px; margin: 4px 0 12px; }
    .lk-empty { font-size: 12.5px; color: var(--muted); margin: 4px 0 12px; }
    .lk-row {
      display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
      border: 1px solid var(--line); border-radius: 10px; padding: 8px; background: #fff;
    }
    .lk-row .lk-ord { display: flex; flex-direction: column; gap: 2px; }
    .lk-row .lk-ord button {
      border: 1px solid var(--line); background: #fff; border-radius: 6px; cursor: pointer;
      font-size: 10px; line-height: 1; padding: 3px 5px; font-family: inherit; color: var(--muted);
    }
    .lk-row .lk-ord button:disabled { opacity: .3; cursor: default; }
    .lk-row .lk-fields { flex: 1; display: flex; flex-direction: column; gap: 6px; min-width: 200px; }
    .lk-row input {
      width: 100%; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px;
      font-size: 13.5px; font-family: inherit; background: #fff; box-sizing: border-box;
    }
    .lk-row input.bad { border-color: #e08a8a; background: #fdf4f4; }
    .lk-row .lk-rm {
      border: 1px solid #f0b9b9; background: #fff; color: var(--danger); border-radius: 8px;
      padding: 7px 11px; font-size: 13px; font-weight: 700; cursor: pointer; font-family: inherit;
    }
  </style>
@endverbatim
@endpush

@section('content')
@verbatim
      <div class="mock-note">ここはアサイン担当が触る「全員に効く設定」です。この画面の設定・マスタ件数はサーバ（DB）に保存され、全員・全画面に反映されます。</div>

      <!-- ① アサインMTG日の予定表 -->
      <div class="panel settings-wrap">
        <div class="panel-head"><h2>アサインMTG日の予定表</h2></div>
        <p class="muted" style="font-size:12.5px; margin:0 0 6px;">
          毎月のアサインMTGの日を、先の月ぶんもまとめて登録できます。システムは自動で
          <strong>「今日までで一番新しいMTG日」</strong>を基準に使い、その日より後に登録された案件を「追加案件」として扱います（毎月手で直す必要はありません）。
        </p>

        <div class="mtg-current">現在の基準日：<b id="mtgCurrent">—</b></div>

        <div id="mtgList" class="mtg-empty"><!-- JSで日付チップを描画 --></div>

        <div class="set-row" style="border-bottom:none;">
          <div>
            <span class="set-label">MTG日を追加</span>
            <span class="set-note">日付を選んで「追加」。来月・再来月ぶんも入れておけます。</span>
          </div>
          <div class="set-control" style="display:flex; gap:8px;">
            <input type="date" id="mtgAddDate" class="date-input">
            <button class="line-btn" onclick="addMtgDate()">追加</button>
          </div>
        </div>

        <div class="save-bar">
          <button class="btn primary" onclick="saveMtgDates()">この予定表を保存する</button>
          <span class="saved-msg" id="mtgSaved">✓ 保存しました</span>
        </div>
        <p class="muted" style="font-size:11.5px; margin:12px 0 0;">
          ※ 案件登録画面の「追加案件」自動判定に使われます。登録が無い／まだ最初のMTGが来ていないときは自動判定せず、登録時に手動で選びます。「追加」を押しただけでは保存されません。最後に「この予定表を保存する」を押してください。
        </p>
      </div>

      <!-- ①-2 危険日（手動指定） -->
      <div class="panel settings-wrap" style="margin-top:20px;">
        <div class="panel-head"><h2>危険日（手動指定）</h2></div>
        <p class="muted" style="font-size:12.5px; margin:0 0 8px;">
          ダッシュボードの危険日カレンダーは自動でも判定しますが、<strong>「大型が1件でも人手的に危険」「全拠点を合わせると危険」</strong>など自動で拾えない日は、ここで手動で足せます。足した日はカレンダーで赤くなります。
        </p>

        <div class="danger-current">大型案件の開催日（これから）— 押すと危険日に追加できます</div>
        <div id="bigList" class="big-list"><!-- JSで大型案件日を描画 --></div>

        <div style="font-size:12.5px; font-weight:700; color:var(--ink); margin:14px 0 4px;">いま設定されている危険日</div>
        <div id="dangerList" class="mtg-empty"><!-- JSで危険日チップを描画 --></div>

        <div class="set-row" style="border-bottom:none;">
          <div>
            <span class="set-label">危険日を手動で追加</span>
            <span class="set-note">日付を選んで「追加」。上の大型案件日の「危険日にする」からも足せます。</span>
          </div>
          <div class="set-control" style="display:flex; gap:8px;">
            <input type="date" id="dangerAddDate" class="date-input">
            <button class="line-btn" onclick="addDangerDate()">追加</button>
          </div>
        </div>

        <div class="save-bar">
          <button class="btn primary" onclick="saveDangerDates()">危険日を保存する</button>
          <span class="saved-msg" id="dangerSaved">✓ 保存しました</span>
        </div>
        <p class="muted" style="font-size:11.5px; margin:12px 0 0;">
          ※「追加」だけでは保存されません。最後に「危険日を保存する」を押してください。保存するとダッシュボードのカレンダーに反映されます。
        </p>
      </div>

      <!-- ①-3 スタッフ画面の便利リンク集 -->
      <div class="panel settings-wrap" style="margin-top:20px;">
        <div class="panel-head"><h2>スタッフ画面のリンク集</h2></div>
        <p class="muted" style="font-size:12.5px; margin:0 0 8px;">
          スタッフNotion・アンケートフォームなど、<strong>スタッフによく開いてもらいたい外部ページ</strong>をここに登録します。
          登録するとスタッフ画面の「設定」タブに一覧で出て、1タップで開けます。URLが変わったらここを直すだけでOKです。
        </p>

        <div id="linkList" class="lk-empty"><!-- JSでリンク行を描画 --></div>

        <div class="save-bar" style="margin-top:4px;">
          <button class="line-btn" onclick="addStaffLink()">＋ リンクを追加</button>
        </div>

        <div class="save-bar">
          <button class="btn primary" onclick="saveStaffLinks()">リンク集を保存する</button>
          <span class="saved-msg" id="linkSaved">✓ 保存しました</span>
        </div>
        <p class="muted" style="font-size:11.5px; margin:12px 0 0;">
          ※ URLは <code>https://</code> から始まるものだけ登録できます（安全のため）。「追加」だけでは保存されません。最後に「リンク集を保存する」を押してください。
        </p>
      </div>

      <!-- ② 通知設定は「個人ごとの設定」なので マイページ へ移動（2026-07-01 baba） -->

      <!-- ③ マスタ管理 -->
      <div class="panel settings-wrap" style="margin-top:20px;">
        <div class="panel-head"><h2>マスタ管理</h2></div>
        <p class="muted" style="font-size:12.5px; margin:0 0 6px;">
          案件登録やアサインで使う「選択肢の元データ」をまとめて管理します。ここを直すと、全画面の選択肢に反映されます。
        </p>

        <div class="set-row">
          <div>
            <span class="set-label">拠点（事務所）</span>
            <span class="set-note"><span id="mcOfficeEx">東京／大阪 など</span>（現在 <span id="mcOffice">-</span>件）</span>
          </div>
          <div class="set-control"><button class="line-btn" onclick="location.href='/masters#offices'">管理する</button></div>
        </div>

        <div class="set-row">
          <div>
            <span class="set-label">コンテンツ</span>
            <span class="set-note">水合戦／運動会／縁日 など、案件名に使うコンテンツ（現在 <span id="mcContent">-</span>件）</span>
          </div>
          <div class="set-control"><button class="line-btn" onclick="location.href='/masters#contents'">管理する</button></div>
        </div>

        <div class="set-row">
          <div>
            <span class="set-label">ポジション（役割）</span>
            <span class="set-note"><span id="mcPosEx">D／SD／OP／MC／FC／CK／軍師・サポーター／受付</span>（現在 <span id="mcPos">-</span>件）</span>
          </div>
          <div class="set-control"><button class="line-btn" onclick="location.href='/masters#positions'">一覧を見る</button></div>
        </div>

        <p class="muted" style="font-size:11.5px; margin:12px 0 0;">
          ※ コンテンツ・拠点は「管理する」から追加・編集・削除できます。ポジション（役割）はシステムの土台のため一覧表示のみです。
        </p>
      </div>
@endverbatim
@endsection

@push('scripts')
<script>
  // マスタ件数（SettingsController が DB から数えた実データ）。
  window.ECS_SETTINGS_COUNTS = @json($masterCounts ?? null);
  // アサインMTG日の予定表（DB保存の一覧・昇順）。案件登録の「追加案件」自動判定に使う。
  window.ECS_MTG_DATES = @json($assignMtgDates ?? []);
  // 今日までで一番新しいMTG日＝現在の基準日（無ければ null）。
  window.ECS_MTG_CURRENT = @json($assignMtgCurrent ?? null);
  // 危険日（手動指定）の一覧（YYYY-MM-DD の配列）。ダッシュボードのカレンダーに反映される。
  window.ECS_DANGER_DATES = @json($dangerDates ?? []);
  // 大型案件の開催日一覧（これから）＝危険日にワンクリックで足す候補。
  window.ECS_BIG_EVENTS = @json($bigEventDates ?? []);
  // スタッフ画面の便利リンク集（[{title,url,memo}, ...]）。
  window.ECS_STAFF_LINKS = @json($staffLinks ?? []);
  // 保存POST用のCSRFトークン。
  window.ECS_CSRF = @json(csrf_token());
</script>
@verbatim
<script>
  // --- 「保存しました」を一定時間だけ表示 ---
  function flashSaved(id) {
    const el = document.getElementById(id);
    el.style.display = 'inline';
    setTimeout(() => { el.style.display = 'none'; }, 2500);
  }

  // --- ① アサインMTG日の予定表（DBに保存＝全員・全画面に効く）---
  // 画面上の作業コピー。DB由来の一覧をコピーして持ち、追加/削除→最後にまとめて保存する。
  let MTG_DATES = Array.isArray(window.ECS_MTG_DATES) ? window.ECS_MTG_DATES.slice() : [];

  function todayStr() {                          // 端末の今日を 'YYYY-MM-DD' で
    const d = new Date(), p = n => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
  }
  function mdLabel(iso) {                         // 'YYYY-MM-DD' → 'M/D'
    const a = String(iso).split('-');
    return a.length === 3 ? (Number(a[1]) + '/' + Number(a[2])) : iso;
  }
  function currentBase() {                        // 今日までで一番新しいMTG日（過ぎた中で最新）
    const t = todayStr();
    const past = MTG_DATES.filter(d => d <= t);
    return past.length ? past[past.length - 1] : null;
  }
  function renderMtg() {
    MTG_DATES.sort();
    const cur = currentBase();
    const curEl = document.getElementById('mtgCurrent');
    if (curEl) curEl.textContent = cur
      ? (mdLabel(cur) + '（この日より後の登録が追加案件）')
      : '未設定（自動判定なし）';

    const list = document.getElementById('mtgList');
    if (!list) return;
    if (!MTG_DATES.length) {
      list.className = 'mtg-empty';
      list.textContent = 'まだ登録がありません。下の欄から追加してください。';
      return;
    }
    list.className = 'mtg-list';
    list.innerHTML = MTG_DATES.map(function (d, i) {
      const isCur = (d === cur);
      return '<span class="mtg-chip' + (isCur ? ' is-current' : '') + '">' +
             mdLabel(d) + (isCur ? '（基準）' : '') +
             ' <b class="rm" onclick="removeMtgDate(' + i + ')">×</b></span>';
    }).join('');
  }
  function addMtgDate() {
    const el = document.getElementById('mtgAddDate');
    const v = el.value;
    if (!v) { alert('日付を選んでください。'); return; }
    if (MTG_DATES.indexOf(v) === -1) MTG_DATES.push(v);
    el.value = '';
    renderMtg();
  }
  function removeMtgDate(i) {
    MTG_DATES.splice(i, 1);
    renderMtg();
  }
  function saveMtgDates() {
    fetch('/settings/mtg-dates', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': window.ECS_CSRF,
        'Accept': 'application/json',
      },
      body: JSON.stringify({ dates: MTG_DATES }),
    })
      .then(r => { if (!r.ok) throw new Error('save failed'); return r.json(); })
      .then(res => { if (Array.isArray(res.dates)) MTG_DATES = res.dates; renderMtg(); flashSaved('mtgSaved'); })
      .catch(() => { alert('保存に失敗しました。通信環境を確認してもう一度お試しください。'); });
  }

  // --- ①-2 危険日（手動指定）（DBに保存＝ダッシュボードのカレンダーに効く）---
  let DANGER_DATES = Array.isArray(window.ECS_DANGER_DATES) ? window.ECS_DANGER_DATES.slice() : [];
  const BIG_EVENTS = Array.isArray(window.ECS_BIG_EVENTS) ? window.ECS_BIG_EVENTS.slice() : [];

  // 大型案件の開催日一覧を描く。すでに危険日にした日はボタンを「追加済み」にする。
  function renderBigEvents() {
    const box = document.getElementById('bigList');
    if (!box) return;
    if (!BIG_EVENTS.length) {
      box.innerHTML = '<div class="empty">これから開催の大型案件はありません。</div>';
      return;
    }
    box.innerHTML = BIG_EVENTS.map(function (e) {
      const done = DANGER_DATES.indexOf(e.date) !== -1;
      return '<div class="big-row">' +
        '<span class="bdate">' + e.label + '</span>' +
        '<span class="bname">' + e.name + '</span>' +
        '<button class="line-btn badd' + (done ? ' done' : '') + '" onclick="addDangerFromBig(\'' + e.date + '\')">' +
        (done ? '追加済み' : '危険日にする') + '</button>' +
        '</div>';
    }).join('');
  }
  function renderDanger() {
    DANGER_DATES.sort();
    const list = document.getElementById('dangerList');
    if (!list) return;
    if (!DANGER_DATES.length) {
      list.className = 'mtg-empty';
      list.textContent = 'まだ手動の危険日はありません。上の大型案件日か、下の欄から追加してください。';
    } else {
      list.className = 'mtg-list';
      list.innerHTML = DANGER_DATES.map(function (d, i) {
        return '<span class="mtg-chip danger">' + mdLabel(d) +
               ' <b class="rm" onclick="removeDangerDate(' + i + ')">×</b></span>';
      }).join('');
    }
    renderBigEvents();   // ボタンの「追加済み」表示を更新
  }
  function addDangerDate() {
    const el = document.getElementById('dangerAddDate');
    const v = el.value;
    if (!v) { alert('日付を選んでください。'); return; }
    if (DANGER_DATES.indexOf(v) === -1) DANGER_DATES.push(v);
    el.value = '';
    renderDanger();
  }
  function addDangerFromBig(date) {
    if (DANGER_DATES.indexOf(date) === -1) DANGER_DATES.push(date);
    renderDanger();
  }
  function removeDangerDate(i) {
    DANGER_DATES.splice(i, 1);
    renderDanger();
  }
  function saveDangerDates() {
    fetch('/settings/danger-dates', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': window.ECS_CSRF,
        'Accept': 'application/json',
      },
      body: JSON.stringify({ dates: DANGER_DATES }),
    })
      .then(r => { if (!r.ok) throw new Error('save failed'); return r.json(); })
      .then(res => { if (Array.isArray(res.dates)) DANGER_DATES = res.dates; renderDanger(); flashSaved('dangerSaved'); })
      .catch(() => { alert('保存に失敗しました。通信環境を確認してもう一度お試しください。'); });
  }

  // --- ①-3 スタッフ画面のリンク集（DBに保存＝全スタッフの画面に効く）---
  // 画面上の作業コピー。追加/並べ替え/削除→最後にまとめて保存する（他の設定と同じ流儀）。
  let STAFF_LINKS = Array.isArray(window.ECS_STAFF_LINKS) ? window.ECS_STAFF_LINKS.map(l => ({
    title: l.title || '', url: l.url || '', memo: l.memo || '',
  })) : [];

  function escAttrS(s) {   // input の value に安全に入れるためのエスケープ
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
      .replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function renderStaffLinks() {
    const box = document.getElementById('linkList');
    if (!box) return;
    if (!STAFF_LINKS.length) {
      box.className = 'lk-empty';
      box.textContent = 'まだ登録がありません。「＋ リンクを追加」から足してください。';
      return;
    }
    box.className = 'lk-list';
    box.innerHTML = STAFF_LINKS.map(function (l, i) {
      // URLが https:// で始まっていない行は赤枠にして、保存前に気づけるようにする。
      const badUrl = l.url !== '' && !/^https?:\/\//i.test(l.url);
      return '<div class="lk-row">' +
        '<div class="lk-ord">' +
          '<button onclick="moveStaffLink(' + i + ',-1)"' + (i === 0 ? ' disabled' : '') + ' title="上へ">▲</button>' +
          '<button onclick="moveStaffLink(' + i + ',1)"' + (i === STAFF_LINKS.length - 1 ? ' disabled' : '') + ' title="下へ">▼</button>' +
        '</div>' +
        '<div class="lk-fields">' +
          '<input type="text" placeholder="表示する名前（例：スタッフNotion）" value="' + escAttrS(l.title) + '" oninput="editStaffLink(' + i + ',\'title\',this.value)">' +
          '<input type="url" class="' + (badUrl ? 'bad' : '') + '" placeholder="https://..." value="' + escAttrS(l.url) + '" oninput="editStaffLink(' + i + ',\'url\',this.value)">' +
          '<input type="text" placeholder="ひとこと説明（任意・例：マニュアルはこちら）" value="' + escAttrS(l.memo) + '" oninput="editStaffLink(' + i + ',\'memo\',this.value)">' +
        '</div>' +
        '<button class="lk-rm" onclick="removeStaffLink(' + i + ')">削除</button>' +
      '</div>';
    }).join('');
  }
  function addStaffLink() {
    STAFF_LINKS.push({ title: '', url: '', memo: '' });
    renderStaffLinks();
  }
  // 入力中は再描画しない（カーソルが飛ぶため）。値だけ控えておく。
  function editStaffLink(i, key, val) {
    if (STAFF_LINKS[i]) STAFF_LINKS[i][key] = val;
  }
  function removeStaffLink(i) {
    if (!confirm('このリンクを削除します。よろしいですか？')) return;
    STAFF_LINKS.splice(i, 1);
    renderStaffLinks();
  }
  function moveStaffLink(i, dir) {
    const j = i + dir;
    if (j < 0 || j >= STAFF_LINKS.length) return;
    const tmp = STAFF_LINKS[i];
    STAFF_LINKS[i] = STAFF_LINKS[j];
    STAFF_LINKS[j] = tmp;
    renderStaffLinks();
  }
  function saveStaffLinks() {
    // 名前もURLも空の行（追加しただけの行）は、そのまま捨てて保存する。
    const links = STAFF_LINKS.filter(l => (l.title || '').trim() !== '' || (l.url || '').trim() !== '');

    const ng = links.find(l => (l.title || '').trim() === '' || !/^https?:\/\//i.test((l.url || '').trim()));
    if (ng) {
      alert('名前と、https:// から始まるURLの両方を入れてください。（赤枠の行を確認してください）');
      STAFF_LINKS = links;
      renderStaffLinks();
      return;
    }

    fetch('/settings/staff-links', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': window.ECS_CSRF,
        'Accept': 'application/json',
      },
      body: JSON.stringify({ links: links }),
    })
      .then(r => { if (!r.ok) throw new Error('save failed'); return r.json(); })
      .then(res => { if (Array.isArray(res.links)) STAFF_LINKS = res.links; renderStaffLinks(); flashSaved('linkSaved'); })
      .catch(() => { alert('保存に失敗しました。通信環境を確認してもう一度お試しください。'); });
  }

  // --- マスタ件数を DB の実データで表示（嘘の固定件数を置き換え）---
  function fillMasterCounts() {
    const mc = window.ECS_SETTINGS_COUNTS;
    if (!mc) return;
    const setText = (id, v) => { const el = document.getElementById(id); if (el && v != null && v !== '') el.textContent = v; };
    if (mc.offices)   { setText('mcOffice', mc.offices.count);   setText('mcOfficeEx', mc.offices.examples); }
    if (mc.contents)  { setText('mcContent', mc.contents.count); }
    if (mc.positions) { setText('mcPos', mc.positions.count);    setText('mcPosEx', mc.positions.examples); }
  }

  // 初期表示
  renderMtg();
  renderDanger();
  renderStaffLinks();
  fillMasterCounts();
</script>
@endverbatim
@endpush
