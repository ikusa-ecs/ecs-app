@extends('layouts.app')
@section('title', 'スタッフ公開ボード')
@section('h1', 'スタッフ公開ボード')
@php($active = 'assign_publish')

@push('head')
@verbatim
<style>
    /* ===== スタッフ公開ボード（表）専用スタイル ===== */

    /* スタッフ画面のお知らせ文の編集パネル */
    .notice-edit { margin-bottom: 18px; }
    .notice-edit .panel-head h2 { font-size: 15px; }
    .notice-edit textarea {
      width: 100%; box-sizing: border-box; min-height: 56px; resize: vertical;
      border: 1px solid var(--line); border-radius: 8px; padding: 9px 11px;
      font-family: inherit; font-size: 13.5px; color: var(--ink); background: #fffdf9;
    }
    .notice-edit textarea:focus { outline: 2px solid var(--brand-soft); border-color: var(--brand); }
    .notice-edit .row { display: flex; align-items: center; gap: 12px; margin-top: 8px; flex-wrap: wrap; }
    .notice-edit .saved { font-size: 12px; color: #15803d; opacity: 0; transition: opacity .2s; }
    .notice-edit .saved.show { opacity: 1; }

    /* 上部のサマリー＋一括操作バー */
    .pub-bar {
      display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
      background: var(--panel); border: 1px solid var(--line); border-radius: 12px;
      padding: 12px 16px; margin-bottom: 16px;
    }
    .pub-bar .stat-mini { display: flex; align-items: baseline; gap: 6px; }
    .pub-bar .stat-mini .n { font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums; }
    .pub-bar .stat-mini .n.on  { color: #15803d; }
    .pub-bar .stat-mini .n.off { color: #b45309; }
    .pub-bar .stat-mini .lbl { font-size: 12px; color: var(--muted); }
    .pub-bar .spacer { flex: 1; }
    .pub-bar select { padding: 8px 11px; border: 1px solid var(--line); border-radius: 8px; font-size: 13.5px; font-family: inherit; background: #fff; }
    .bulk-info { font-size: 12.5px; color: var(--muted); }
    .bulk-info b { color: var(--ink); }

    /* 表：ノートPCの画面に収まるようにする（2026-08-21 baba）。
       ・横スクロールは「どうしても入りきらないとき」の保険。ふだんは出さない
       ・そのために、見出しもセルも折り返しを許して幅を詰める */
    .tbl-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .tbl-scroll > table.tbl { width: 100%; table-layout: fixed; }
    /* 会場・集合場所は長くなりがちなので折り返す */
    table.tbl td.place-cell { white-space: normal; word-break: break-all; }
    /* 見出しも折り返す（「会場（住所）／集合場所」のような長い見出しで幅が広がらないように） */
    table.tbl th { white-space: normal; font-size: 12px; line-height: 1.35; }
    table.tbl td { font-size: 13px; }
    /* 列の幅を決め打ちして、合計が画面に収まるようにする */
    table.tbl col.c-chk   { width: 30px; }
    table.tbl col.c-date  { width: 86px; }
    table.tbl col.c-proj  { width: auto; }
    table.tbl col.c-place { width: 22%; }
    table.tbl col.c-meet  { width: 132px; }
    table.tbl col.c-need  { width: 74px; }
    table.tbl col.c-pub   { width: 78px; }
    table.tbl col.c-ops   { width: 150px; }
    table.tbl td { vertical-align: middle; }
    td.chk, th.chk { width: 34px; text-align: center; }
    table.tbl input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--brand); cursor: pointer; }

    /* 月グループ見出し（クリックで開閉） */
    tr.group-row { cursor: pointer; }
    tr.group-row td {
      background: var(--brand-soft); color: var(--brand-dark);
      font-weight: 700; font-size: 13px; padding: 9px 12px; border-bottom: 1px solid var(--line);
    }
    tr.group-row td:hover { filter: brightness(0.97); }
    tr.group-row.past td { background: #f1ece3; color: var(--muted); }
    tr.group-row .gcaret { display: inline-block; width: 14px; font-size: 11px; }
    tr.group-row .g-count { color: var(--muted); font-weight: 600; margin-left: 8px; font-size: 12px; }

    td.date-cell { white-space: nowrap; font-variant-numeric: tabular-nums; }
    td.date-cell .dow { font-size: 11.5px; color: var(--muted); margin-left: 2px; }
    td.date-cell .dow.sun { color: var(--danger); } td.date-cell .dow.sat { color: var(--brand); }

    td.proj-cell { min-width: 0; word-break: break-all; }
    td.proj-cell strong { font-size: 14px; }
    td.proj-cell .client { font-size: 11.5px; color: var(--muted); margin-top: 2px; }
    td.proj-cell a.proj-link { color: var(--ink); text-decoration: none; }
    td.proj-cell a.proj-link:hover strong { color: var(--brand-dark); text-decoration: underline; }

    /* 会場（住所）／集合場所 */
    td.place-cell { font-size: 12px; }
    td.place-cell .mp { font-size: 11px; color: var(--muted); margin-top: 2px; }
    /* 集合〜解散 */
    td.meet-cell .leave { font-size: 12px; color: var(--muted); }

    /* 集合（社員／スタッフ）セル */
    td.meet-cell { white-space: normal; }
    td.meet-cell .emp { font-size: 12px; color: var(--muted); }
    td.meet-cell .emp b { color: var(--ink); font-weight: 600; }
    td.meet-cell .staff-row { display: flex; align-items: center; gap: 4px; margin-top: 4px; }
    td.meet-cell .staff-row .lab { font-size: 11px; color: var(--muted); }
    td.meet-cell .staff-row .leave { color: var(--muted); }
    td.meet-cell input.smeet {
      width: 46px; border: 1px solid var(--line); border-radius: 7px; padding: 3px 2px;
      font-family: inherit; font-size: 12px; text-align: center;
    }
    td.meet-cell input.smeet:focus { outline: 2px solid var(--brand-soft); border-color: var(--brand); }
    td.meet-cell .diff { font-size: 10.5px; font-weight: 700; color: #b45309; background: var(--warn-soft); padding: 1px 6px; border-radius: 999px; }

    /* 公開状態バッジ */
    .pub-badge { font-size: 11.5px; font-weight: 700; padding: 2px 10px; border-radius: 999px; white-space: nowrap; display: inline-flex; align-items: center; gap: 5px; }
    .pub-badge .dot { width: 8px; height: 8px; border-radius: 999px; display: inline-block; }
    .pub-badge.on  { background: var(--ok-soft);  color: #15803d; } .pub-badge.on .dot  { background: #16a34a; }
    .pub-badge.off { background: var(--warn-soft); color: #b45309; } .pub-badge.off .dot { background: #d97706; }

    /* 操作セル */
    td.need-cell { white-space: nowrap; font-size: 12px; }
    .need-input { width: 46px; padding: 4px 6px; border: 1px solid var(--line); border-radius: 6px;
                  font-size: 13px; font-family: inherit; text-align: right; }
    td.ops-cell { white-space: normal; }
    /* 操作の各ボタンは折り返して縦に並ぶ＝横幅を取らない（2026-08-21 baba） */
    td.ops-cell > * { margin: 2px 3px 2px 0 !important; }
    .pub-toggle { border: none; border-radius: 8px; padding: 6px 10px; font-size: 12px; font-weight: 700; cursor: pointer; font-family: inherit; }
    .pub-toggle.go   { background: var(--brand); color: #fff; }
    .pub-toggle.go:hover { background: var(--brand-dark); }
    .pub-toggle.undo { background: #fff; color: #15803d; border: 1px solid #bbe3c6; }
    .pub-toggle.undo:hover { background: var(--ok-soft); }
    td.ops-cell a.detail-link { font-size: 11.5px; white-space: nowrap; }
    td.ops-cell .note-btn { border: 1px solid var(--line); background: #fff; border-radius: 8px; padding: 5px 7px; font-size: 11.5px; cursor: pointer; font-family: inherit; color: var(--ink); }
    td.ops-cell .note-btn:hover { background: #f3ece0; }
    /* 記載があるボタンはひと目で分かるようにする（2026-08-21 baba。案件一覧の📝と同じ考え方） */
    td.ops-cell .note-btn.has { background: var(--warn-soft, #fdf3e2); border-color: #e6c98f; font-weight: 700; }
    td.ops-cell .note-btn .dot-mark { color: var(--brand); margin-left: 3px; font-size: 10px; vertical-align: 1px; }
    td.ops-cell .cat-toggle { border: 1px solid #d1d5db; background: #fff; border-radius: 8px; padding: 5px 7px; font-size: 11.5px; cursor: pointer; font-family: inherit; color: var(--ink); }
    td.ops-cell .cat-toggle:hover { background: #f3ece0; }
    td.ops-cell .cat-toggle.is-extra { background: #fde8e8; color: #b91c1c; border-color: var(--danger); font-weight: 700; }

    /* 備考（折りたたみ行） */
    tr.note-row > td { background: #faf6ee; padding: 10px 16px 12px; border-bottom: 1px solid var(--line); }
    tr.note-row label { font-size: 11.5px; font-weight: 700; color: var(--muted); display: block; margin-bottom: 5px; }
    tr.note-row textarea {
      width: 100%; box-sizing: border-box; min-height: 44px; resize: vertical; max-width: 720px;
      border: 1px solid var(--line); border-radius: 8px; padding: 8px 10px;
      font-family: inherit; font-size: 13px; color: var(--ink); background: #fff;
    }
    tr.note-row textarea:focus { outline: 2px solid var(--brand-soft); border-color: var(--brand); }
    tr.note-row .saved { font-size: 11px; color: #15803d; margin-left: 8px; opacity: 0; transition: opacity .2s; }
    tr.note-row .saved.show { opacity: 1; }

    .empty-note { text-align: center; color: var(--muted); font-size: 13px; padding: 26px 0; }

    /* サイドバーの年月ツリー（案件一覧と同じ） */
    .ym-tree { margin: 2px 0 2px 8px; display: flex; flex-direction: column; gap: 1px; }
    .ym-year-btn, .ym-month-btn {
      display: flex; align-items: center; width: 100%; text-align: left; border: none;
      background: none; color: #6e5b49; cursor: pointer; font-family: inherit; border-radius: 7px;
    }
    .ym-year-btn  { padding: 6px 10px; font-size: 12.5px; font-weight: 700; }
    .ym-month-btn { padding: 5px 10px 5px 26px; font-size: 12.5px; }
    .ym-year-btn:hover, .ym-month-btn:hover { background: #dccbb1; color: #4f4338; }
    .ym-month-btn.active { background: var(--brand); color: #fff; font-weight: 700; }
    .ym-caret { width: 14px; display: inline-block; font-size: 10px; }
    .ym-year-btn .ym-ycount, .ym-month-btn .ym-mcount { margin-left: auto; font-size: 11px; color: #a08a73; }
    .ym-month-btn.active .ym-mcount { color: rgba(255,255,255,.85); }
    .ym-months { display: flex; flex-direction: column; gap: 1px; }
    .ym-months.collapsed { display: none; }

    /* 公開ボード / アーカイブ タブ */
    .view-tabs { display: flex; gap: 6px; margin-bottom: 14px; }
    .view-tab { padding: 8px 16px; border: 1px solid var(--line); border-radius: 8px; background: #fff;
      color: var(--muted); font-size: 13.5px; font-weight: 700; cursor: pointer; font-family: inherit; }
    .view-tab:hover { background: #f3ece0; }
    .view-tab.active { background: var(--brand); border-color: var(--brand); color: #fff; }
    .view-tab .vt-count { font-weight: 600; opacity: .85; margin-left: 4px; }

    /* 登録日（いつ追加したか） */
    td.proj-cell .added { font-size: 11px; color: var(--muted); margin-top: 2px; }
    td.proj-cell .added b { color: var(--brand-dark); font-weight: 700; }

    /* 追加案件（MTG後に入った案件）を目立たせる */
    .badge.extra { background: #fde8e8; color: #b91c1c; border: 1px solid var(--danger); font-weight: 700; }
    tr.extra-row td:first-child { box-shadow: inset 3px 0 0 var(--danger); }

    /* 月見出しジャンプの点滅 */
    @keyframes pubFlash { 0% { background: var(--warn-soft); } 100% { background: var(--brand-soft); } }
    tr.group-row.flash td { animation: pubFlash 1.4s ease-out; }
  </style>
@endverbatim
@endpush

@section('content')
      {{-- 拠点の切替（管理者以上だけ表示。一般社員は自拠点固定＝スイッチは出ない） --}}
      @include('partials.office_switch', ['osNoAll' => true, 'osActive' => $officeScope])
      @if ($officeScope)
        <p class="mock-note" style="background:#fbf6ef;">
          <b>{{ $officeScope }}</b>の案件だけを表示しています（{{ $officeScope }}に共有された他拠点の案件も含みます）。
        </p>
      @endif
@verbatim
      <div class="mock-note">
        この公開ボードは<b>案件データ・公開状態とも実際のDBにつながっています</b>（公開ボタンを押すとDBに保存され、閉じても・他のPCでも残ります）。<br>
        <b>調整中は非公開のまま、固まったら「スタッフに公開」</b>してください。チェックを付けて<b>まとめて公開／非公開</b>もできます。<br>
        ※ 公開した案件は、スタッフ画面の「確定アサイン」に表示されます（集合・解散時間やお知らせ文の変更も、保存すればスタッフ画面に反映されます）。
      </div>

      <!-- スタッフ画面のお知らせ文の編集 -->
      <div class="panel notice-edit">
        <div class="panel-head">
          <h2>📣 スタッフ画面の上のお知らせ文（{{ $officeScope }}）</h2>
          <div class="spacer"></div>
          <span class="muted" style="font-size:12px;">{{ $officeScope }}のスタッフの画面（募集タブ）の一番上に出る文です</span>
        </div>
        <textarea id="noticeInput" placeholder="例）7月分の募集が出ています。気になる案件は「エントリーする」を押してください。"></textarea>
        <div class="row">
          <button class="btn primary" onclick="saveNotice()">この文を保存</button>
          <button class="btn" onclick="resetNotice()">既定の文に戻す</button>
          <span class="saved" id="noticeSaved">✓ 保存しました（スタッフ画面に反映されます）</span>
        </div>
        <p class="muted" style="font-size:11.5px; margin:8px 0 0;">
          ※ この文は<b>{{ $officeScope }}のスタッフにだけ</b>出ます（拠点ごとに別の文にできます・2026-08-25）。
          他の拠点の文を直すときは、上の拠点スイッチで切り替えてください。
        </p>
      </div>

      <!-- 通常案件の一斉締切日（拠点ごとに1つ） -->
      <div class="panel notice-edit">
        <div class="panel-head">
          <h2>🗓 通常案件の締切日（一斉・{{ $officeScope }}）</h2>
          <div class="spacer"></div>
          <span class="muted" style="font-size:12px;">月まとめで公開した通常案件の締切として、{{ $officeScope }}のスタッフの画面に表示されます</span>
        </div>
        <div class="row">
          <input id="deadlineInput" type="date" style="padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:14px;">
          <button class="btn primary" onclick="saveDeadline()">締切日を保存</button>
          <button class="btn" onclick="clearDeadline()">未設定に戻す</button>
          <span class="saved" id="deadlineSaved">✓ 保存しました（スタッフ画面に反映されます）</span>
        </div>
        <p class="muted" style="font-size:11.5px; margin:8px 0 0;">
          ※ この締切は<b>{{ $officeScope }}のスタッフにだけ</b>出ます（拠点ごとに別の日にできます・2026-08-25）。<br>
          ※ 締切は<b>表示だけ</b>です（過ぎても応募は受け付けます）。<br>
          ※「追加」にした案件は、この日付ではなく<b>公開した日＋3日（土日なら月曜）</b>が自動で締切になります。
        </p>
      </div>

      <!-- サマリー＋一括操作 -->
      <div class="pub-bar">
        <div class="stat-mini"><span class="n on"  id="cntOn">0</span><span class="lbl">件 公開中</span></div>
        <div class="stat-mini"><span class="n off" id="cntOff">0</span><span class="lbl">件 非公開</span></div>
        <div class="spacer"></div>
        <span class="bulk-info">選択 <b id="checkCount">0</b> 件：</span>
        <button class="btn primary" onclick="bulkPublish(true)">まとめて公開</button>
        <button class="btn" onclick="bulkPublish(false)">まとめて非公開</button>
        <button class="btn" onclick="bulkCategory(false)" title="チェックした案件の「追加」をまとめて外します">追加をまとめて外す</button>
        <select id="sortMode" onchange="render()" title="並び順を切り替えます">
          <option value="calendar">並び：カレンダー順（日付）</option>
          <option value="registered">並び：登録順（新しい順・追加が上）</option>
        </select>
        <select id="filter" onchange="render()">
          <option value="">表示：すべて</option>
          <option value="on">公開中のみ</option>
          <option value="off">非公開のみ</option>
        </select>
        <button class="btn" onclick="openStaffView()">👁 スタッフ画面を見る</button>
      </div>

      <!-- 公開ボード / アーカイブ（過去）の切替 -->
      <div class="view-tabs">
        <button class="view-tab active" id="tab-active"  onclick="setView('active')">スタッフ公開ボード<span class="vt-count" id="cntActive"></span></button>
        <button class="view-tab"        id="tab-archive" onclick="setView('archive')">🗄 アーカイブ（過去）<span class="vt-count" id="cntArchive"></span></button>
      </div>

      <!-- 表 -->
      <div class="panel">
        <!-- ノートPCの画面幅からはみ出さないように、表だけを横スクロールできる箱に入れる
             （2026-08-21 baba。ページ全体が横に伸びると操作しづらいため）。 -->
        <div class="tbl-scroll">
        <table class="tbl">
          <colgroup>
            <col class="c-chk"><col class="c-date"><col class="c-proj"><col class="c-place">
            <col class="c-meet"><col class="c-need"><col class="c-pub"><col class="c-ops">
          </colgroup>
          <thead>
            <tr>
              <th class="chk"><input type="checkbox" id="selAll" onclick="toggleAll(this.checked)" title="表示中をすべて選択"></th>
              <th>日程</th>
              <th>案件名</th>
              <th>会場（住所）／集合場所</th>
              <th>集合〜解散（スタッフ）</th>
              <th>必要</th>
              <th>公開</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody id="pubBody"></tbody>
        </table>
        </div>
        <div class="empty-note" id="pubEmpty" style="display:none;">条件に合う案件がありません。</div>
      </div>

      <p class="muted" style="font-size:11.5px; margin:14px 0 0;">
        ※ 表示している<b>会場（住所）・集合場所・集合〜解散</b>は、スタッフ画面に出る情報と同じものです。<b>案件名をクリック</b>すると案件一覧の該当月へ移動します。<br>
        ※「集合〜解散（スタッフ）」は、スタッフに見せる集合・解散時間です。社員と違うときは入力して直せます（直すと「別」マークが付き、スタッフ画面にも反映されます）。<br>
        ※「詳細 →」で案件詳細（アサイン画面）に飛びます。時間・場所など細かい変更はそちらでもできます。<br>
        ※ 公開状態はDB（projects の staff_published）に保存され、案件詳細（アサイン画面）とも同じ列で連動します。<br>
        ※ <b>必要人数</b>はここで直せます（運営人数＝<b>Dを含む</b>人数。案件登録・アサイン表と同じ数字です）。<br>
        ※ 「💬 備考」は<b>案件登録の備考と同じ欄</b>です（どちらで直しても同じ内容になります）。<b>社員だけが見ます</b>＝スタッフ画面には出ません。<br>
        ※ <b>スタッフに見せたいこと</b>は「📣 スタッフに伝えること」に書いてください。
      </p>
@endverbatim
@endsection

@push('scripts')
<!-- 案件データと公開状態は DB（projects）から渡される -->
<script>
  window.ECS_PUBLISH_CASES = @json($cases);
  window.ECS_STAFF_NOTICE = @json($notice);
  window.ECS_ENTRY_DEADLINE = @json($entryDeadline ?? '');
  window.ECS_CSRF = '{{ csrf_token() }}';
  // いま見ている拠点。一括公開のときサーバーへ一緒に送り、違う拠点の案件は触らせない。
  window.ECS_OFFICE_SCOPE = @json($officeScope ?? '');
</script>
@verbatim
<script>
  // ===== 案件データ（サーバ＝DBの projects から渡される）=====
  // off … 今日から何日後の開催か／meet … 社員の集合時間／leave … 解散／place … 会場（住所）／meetPlace … 集合場所
  // added … 今日から何日前に登録したか（マイナス）。published … 公開状態（DBの staff_published）。
  const CASES = (window.ECS_PUBLISH_CASES || []).map(function(c){
    return {
      id: c.id, name: c.name, client: c.client, cat: c.cat, category: c.category, need: c.need, off: c.off,
      added: c.added, meet: c.meet, leave: c.leave, place: c.place, meetPlace: c.meetPlace, published: c.published,
      staffMeet: c.staffMeet, staffLeave: c.staffLeave, memo: c.memo,
      // スタッフ本人に伝えること（本人の「確定アサイン」の詳細にそのまま出る）
      meetDetail: c.meetDetail || '', belongings: c.belongings || '',
      dresscode: c.dresscode || '', staffNotes: c.staffNotes || ''
    };
  });

  const DEFAULT_NOTICE = '7月分の募集が出ています。気になる案件は「エントリーする」を押してください。';

  // ===== 公開状態（DBの staff_published）=====
  // サーバから渡された published を画面内の pubState に持ち、変更はサーバ（DB）へ保存する。
  const pubState = {};
  CASES.forEach(c => { pubState[c.id] = !!c.published; });
  function isPublished(id){ return !!pubState[id]; }

  // 公開ON/OFF をサーバ（DB）に保存する。先に画面を更新し、失敗したら元に戻す。
  function persistPublish(ids, publish){
    ids.forEach(id => { pubState[id] = publish; });
    render();
    fetch('/assign-publish/set', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF },
      body: JSON.stringify({ ids: ids, publish: publish, office: (window.ECS_OFFICE_SCOPE || '') })
    })
    .then(r => { if (!r.ok) throw new Error('save failed'); })
    .catch(() => {
      alert('保存に失敗しました。通信状態を確認して、もう一度お試しください。');
      ids.forEach(id => { pubState[id] = !publish; });
      render();
    });
  }

  // ===== 備考（担当メモ・DB保存＝全員で共有）=====
  // サーバから渡された memo を初期表示に使い、変更はサーバ（DB）の projects.note へ保存する
  // （案件登録の備考と同じ欄・2026-08-21 baba）。
  function getNote(id){ const c = CASES.find(x => x.id === id); return c ? (c.memo || '') : ''; }
  // 「記載あり」の印を付け直す（保存した直後に、開き直さなくても分かるように）。
  function refreshNoteMark(id){
    const c = CASES.find(x => x.id === id);
    if (!c) return;
    [['notebtn-', c.memo, '💬 備考'], ['sibtn-', c.staffNotes, '📣 スタッフに伝えること']].forEach(function (pair) {
      const btn = document.getElementById(pair[0] + id);
      if (!btn) return;
      const has = String(pair[1] || '').trim() !== '';
      btn.classList.toggle('has', has);
      btn.innerHTML = pair[2] + (has ? '<span class="dot-mark">●</span>' : '');
    });
  }
  // 入力欄からフォーカスが外れたら（onblur）保存する。連打を避けるためボタン連打ではなく1回で送る。
  function saveNote(id, el){
    const c = CASES.find(x => x.id === id);
    if (!c) return;
    const val = el.value;
    if (val === (c.memo || '')) return;   // 変更が無ければ送らない
    const prev = c.memo;
    c.memo = val;
    fetch('/assign-publish/memo', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF },
      body: JSON.stringify({ id: id, memo: val })
    })
    .then(r => { if (!r.ok) throw new Error('save failed'); flash('nsaved-' + id); refreshNoteMark(id); })
    .catch(() => { c.memo = prev; refreshNoteMark(id); alert('備考の保存に失敗しました。通信を確認してもう一度お試しください。'); });
  }

  // ===== スタッフに伝えること（集合場所の詳細・持ち物・服装・注意事項／DB保存）=====
  // 本人の「確定アサイン」の詳細にそのまま出る。案件登録でも入れられるが、
  // 公開する直前にここで書き足せるようにしている。入力欄から離れたら保存する。
  // 必要人数（運営人数）。募集をかける直前に直したい場面が多いので公開ボードでも直せる（2026-08-21 baba）。
  // 保存先は案件登録・アサイン表と同じ projects.required_count（食い違いが起きない）。
  function saveNeed(id){
    const c = CASES.find(x => x.id === id);
    const el = document.getElementById('need-' + id);
    if (!c || !el) return;
    const raw = el.value.trim();
    const next = raw === '' ? '—' : String(parseInt(raw, 10) || 0);
    if (next === String(c.need)) return;   // 変更なし
    const prev = c.need;
    c.need = next;
    fetch('/assign-publish/count', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF },
      body: JSON.stringify({ id: id, count: raw === '' ? null : parseInt(raw, 10) })
    })
    .then(r => { if (!r.ok) throw new Error('save failed'); flash('needsaved-' + id); })
    .catch(() => { c.need = prev; el.value = (prev === '—' ? '' : prev);
                   alert('必要人数の保存に失敗しました。もう一度お試しください。'); });
  }

  // スタッフに伝えること＝備考のような自由記入の1欄（2026-08-21 baba。以前は4欄に分かれていた）。
  function saveStaffInfo(id){
    const c = CASES.find(x => x.id === id);
    if (!c) return;
    const el = document.getElementById('si-notes-' + id);
    const next = el ? el.value : '';
    if (next === c.staffNotes) return;   // 変更なし
    const prev = c.staffNotes;
    c.staffNotes = next;
    fetch('/assign-publish/staff-info', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF },
      body: JSON.stringify({ id: id, notes: next })
    })
    .then(r => { if (!r.ok) throw new Error('save failed'); flash('sisaved-' + id); refreshNoteMark(id); })
    .catch(() => { c.staffNotes = prev; refreshNoteMark(id); alert('スタッフに伝えることの保存に失敗しました。通信を確認してもう一度お試しください。'); });
  }
  function toggleStaffInfo(id){
    const el = document.getElementById('sinfo-' + id);
    if (el) el.style.display = (el.style.display === 'none' || !el.style.display) ? '' : 'none';
  }

  // ===== スタッフ集合・解散時間（DB保存）。既定は社員の時間と同じ =====
  // staffMeet/staffLeave に値が入っていれば担当が直した時間、無ければ社員の時間を使う。
  function getStaffMeet(c){ return c.staffMeet || c.meet; }
  function getStaffLeave(c){ return c.staffLeave || c.leave; }
  // 直した時間を DB に保存する（kind='meet' か 'leave'）。先に画面を更新し、失敗したら元に戻す。
  function saveStaffTime(id, kind, val){
    const c = CASES.find(x => x.id === id);
    if (!c) return;
    const prev = (kind === 'meet') ? c.staffMeet : c.staffLeave;
    if (kind === 'meet') c.staffMeet = val; else c.staffLeave = val;
    render();
    const body = { id: id };
    body[kind === 'meet' ? 'staff_meet' : 'staff_leave'] = val;
    fetch('/assign-publish/time', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF },
      body: JSON.stringify(body)
    })
    .then(r => { if (!r.ok) throw new Error('save failed'); })
    .catch(() => {
      alert('保存に失敗しました。通信状態を確認して、もう一度お試しください。');
      if (kind === 'meet') c.staffMeet = prev; else c.staffLeave = prev;
      render();
    });
  }

  // ===== スタッフ画面のお知らせ文（DB保存）=====
  // サーバから渡された文（window.ECS_STAFF_NOTICE）を出し、保存はサーバ（DB）へ。
  function loadNotice(){
    const t = (window.ECS_STAFF_NOTICE || '').trim();
    document.getElementById('noticeInput').value = t || DEFAULT_NOTICE;
  }
  function loadDeadline(){
    document.getElementById('deadlineInput').value = (window.ECS_ENTRY_DEADLINE || '').trim();
  }
  function saveNotice(){
    persistNotice(document.getElementById('noticeInput').value.trim());
  }
  function resetNotice(){
    document.getElementById('noticeInput').value = DEFAULT_NOTICE;
    persistNotice('');   // 空＝既定文に戻す（スタッフ画面では既定文が出る）
  }
  // お知らせ文を DB に保存する。
  function persistNotice(v){
    fetch('/assign-publish/notice', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF },
      // どの拠点のお知らせ文かを一緒に送る（拠点ごとに持つため・2026-08-25）。
      body: JSON.stringify({ notice: v, office: window.ECS_OFFICE_SCOPE || '' })
    })
    .then(r => { if (!r.ok) throw new Error('save failed'); flash('noticeSaved'); })
    .catch(() => alert('お知らせ文の保存に失敗しました。もう一度お試しください。'));
  }

  // 「✓保存しました」を一瞬出す
  function flash(elId){
    const el = document.getElementById(elId);
    if (!el) return;
    el.classList.add('show');
    setTimeout(() => el.classList.remove('show'), 1300);
  }

  // ===== 日付・月グループ =====
  const DOW = ['日','月','火','水','木','金','土'];
  const today = (function(){ const x = new Date(); x.setHours(0,0,0,0); return x; })();
  const todayY = today.getFullYear(), todayM = today.getMonth() + 1;
  function dateOf(off){ const x = new Date(today); x.setDate(x.getDate() + off); return x; }

  CASES.forEach(c => {
    c.date = dateOf(c.off);
    c.gy = c.date.getFullYear();
    c.gm = c.date.getMonth() + 1;
    c.gkey = c.gy + '-' + c.gm;
    c.archived = c.date < today;               // 開催日が過ぎた＝アーカイブ
    c.addedDate = dateOf(c.added || 0);        // 登録（追加）した日
  });
  // 案件のある年月を日付順に
  const GROUPS = [];
  (function(){
    const seen = {};
    CASES.slice().sort((a,b) => a.date - b.date).forEach(c => {
      if (seen[c.gkey]) return;
      seen[c.gkey] = true;
      const past = (c.gy < todayY) || (c.gy === todayY && c.gm < todayM);
      GROUPS.push({ key:c.gkey, label:c.gy + '年 ' + c.gm + '月', year:c.gy, month:c.gm, past });
    });
  })();

  // ===== 状態（畳んだ月・選択中の案件）=====
  const collapsedMonths = new Set();
  const checkedIds = new Set();
  let currentView = 'active';   // 'active'=スタッフ公開ボード（未来）/ 'archive'=過去
  function toggleGroup(key){
    if (collapsedMonths.has(key)) collapsedMonths.delete(key); else collapsedMonths.add(key);
    render();
  }

  const tbody = document.getElementById('pubBody');
  const empty = document.getElementById('pubEmpty');

  function dateCell(c){
    const dy = c.date.getDay();
    const cls = dy === 0 ? 'sun' : (dy === 6 ? 'sat' : '');
    return `${c.gm}/${c.date.getDate()}<span class="dow ${cls}">(${DOW[dy]})</span>`;
  }

  // ===== 追加案件バッジの手動オン/オフ（DBの category と extra_published_at を更新）=====
  // 追加にすると「追加」バッジが付き、スタッフ画面の締切が「公開日＋3日（土日は月曜）」になる。
  function toggleCategory(id){
    const c = CASES.find(x => x.id === id);
    if (!c) return;
    const makeExtra = (c.category !== '追加案件');
    const prev = c.category;
    c.category = makeExtra ? '追加案件' : '通常案件';
    render();
    fetch('/assign-publish/category', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF },
      body: JSON.stringify({ id: id, is_extra: makeExtra })
    })
    .then(r => { if (!r.ok) throw new Error('save failed'); })
    .catch(() => { c.category = prev; render(); alert('保存に失敗しました。通信を確認してもう一度お試しください。'); });
  }

  // ===== 追加案件バッジを「まとめて」外す（チェックした案件が対象）=====
  function bulkCategory(makeExtra){
    if (checkedIds.size === 0){ alert('チェックボックスで案件を選んでください。'); return; }
    const word = makeExtra ? '「追加案件」に' : '「追加」を';
    if (!confirm(`選んだ ${checkedIds.size} 件の${word}まとめて${makeExtra ? 'します' : '外します'}。\n\nよろしいですか？`)) return;
    const ids = Array.from(checkedIds);
    const prev = {};
    ids.forEach(id => { const c = CASES.find(x => x.id === id); if (c){ prev[id] = c.category; c.category = makeExtra ? '追加案件' : '通常案件'; } });
    checkedIds.clear();
    render();
    fetch('/assign-publish/category-bulk', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF },
      body: JSON.stringify({ ids: ids, is_extra: makeExtra })
    })
    .then(r => { if (!r.ok) throw new Error('save failed'); })
    .catch(() => { ids.forEach(id => { const c = CASES.find(x => x.id === id); if (c) c.category = prev[id]; }); render(); alert('保存に失敗しました。通信を確認してもう一度お試しください。'); });
  }

  // ===== 通常案件の一斉締切日（拠点ごとに1つ・DBの settings に保存）=====
  function saveDeadline(){
    const v = (document.getElementById('deadlineInput').value || '').trim();
    fetch('/assign-publish/deadline', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF },
      body: JSON.stringify({ date: v || null, office: window.ECS_OFFICE_SCOPE || '' })
    })
    .then(r => { if (!r.ok) throw new Error('save failed'); flash('deadlineSaved'); })
    .catch(() => alert('保存に失敗しました。通信を確認してもう一度お試しください。'));
  }
  function clearDeadline(){
    document.getElementById('deadlineInput').value = '';
    saveDeadline();
  }

  // ===== 1案件の行（＋備考の折りたたみ行）を表に追加。カレンダー順・登録順の両方で使う =====
  function appendCaseRow(c){
    const pub = isPublished(c.id);
    const sm = getStaffMeet(c);
    const sl = getStaffLeave(c);
    const diffM = (sm !== c.meet);
    const diffL = (sl !== c.leave);
    const extra = (c.category === '追加案件');

    const tr = document.createElement('tr');
    if (extra) tr.className = 'extra-row';
    tr.innerHTML = `
      <td class="chk"><input type="checkbox" ${checkedIds.has(c.id) ? 'checked' : ''} onchange="onCheck('${c.id}', this.checked)"></td>
      <td class="date-cell">${dateCell(c)}</td>
      <td class="proj-cell"><a class="proj-link" href="/projects?focus=${c.gkey}" title="案件一覧のこの月へ移動します"><strong>${c.name}</strong></a> ${extra ? '<span class="badge extra">追加</span> ' : ''}<div class="client">${c.client}</div><div class="added">登録 <b>${fmtAdded(c.addedDate)}</b></div></td>
      <td class="place-cell">${c.place}<div class="mp">集合場所：${c.meetPlace}</div></td>
      <td class="meet-cell">
        <div class="staff-row" style="flex-wrap:wrap;"><span class="lab">スタッフ</span>
          <input class="smeet" type="text" value="${sm}" onchange="saveStaffTime('${c.id}', 'meet', this.value)" title="スタッフに見せる集合時間">
          <span class="leave">〜</span>
          <input class="smeet" type="text" value="${sl}" onchange="saveStaffTime('${c.id}', 'leave', this.value)" title="スタッフに見せる解散時間">
          ${(diffM || diffL) ? '<span class="diff">別</span>' : ''}
        </div>
        <div class="emp">社員 <b>${c.meet}</b> 〜 <b>${c.leave}</b></div>
      </td>
      <td class="need-cell">
        <input type="number" min="0" max="999" class="need-input" id="need-${c.id}" value="${c.need === '—' ? '' : c.need}"
               onblur="saveNeed('${c.id}')" onkeydown="if(event.key==='Enter'){this.blur();}" placeholder="—" title="運営人数（Dも含む）">名
        <span class="saved" id="needsaved-${c.id}">✓</span>
      </td>
      <td>${pub ? '<span class="pub-badge on"><span class="dot"></span>公開中</span>' : '<span class="pub-badge off"><span class="dot"></span>非公開</span>'}</td>
      <td class="ops-cell">
        ${pub
          ? `<button class="pub-toggle undo" onclick="toggle('${c.id}')">公開取消</button>`
          : `<button class="pub-toggle go" onclick="toggle('${c.id}')">公開する</button>`}
        <button class="cat-toggle ${extra ? 'is-extra' : ''}" onclick="toggleCategory('${c.id}')" title="スタッフ画面に「追加」バッジを付けます／外します">${extra ? '追加解除' : '＋追加'}</button>
        <a class="detail-link" href="/project-assign?project=${c.id}">アサイン画面 →</a>
        <button class="note-btn${String(c.memo || '').trim() !== '' ? ' has' : ''}" id="notebtn-${c.id}"
                onclick="toggleNote('${c.id}')"
                title="社員用の備考（案件登録の備考と同じ欄）">💬 備考${String(c.memo || '').trim() !== '' ? '<span class="dot-mark">●</span>' : ''}</button>
        <button class="note-btn${String(c.staffNotes || '').trim() !== '' ? ' has' : ''}" id="sibtn-${c.id}"
                onclick="toggleStaffInfo('${c.id}')"
                title="スタッフ本人に見える欄（募集中の備考＋確定アサインの詳細）">📣 スタッフに伝えること${String(c.staffNotes || '').trim() !== '' ? '<span class="dot-mark">●</span>' : ''}</button>
      </td>`;
    tbody.appendChild(tr);

    // 備考（折りたたみ行）
    const nr = document.createElement('tr');
    nr.className = 'note-row';
    nr.id = 'note-' + c.id;
    nr.style.display = 'none';
    nr.innerHTML = `
      <td colspan="8">
        <label>備考（<b>案件登録の備考と同じ欄</b>です・社員だけが見ます／入力欄から離れると自動で保存されます）<span class="saved" id="nsaved-${c.id}">✓ 保存しました</span></label>
        <textarea placeholder="例）前泊あり。〇〇さんに声かけ済み。集合場所は南口。（入力欄から離れると自動で保存されます）" onblur="saveNote('${c.id}', this)">${escapeHtml(getNote(c.id))}</textarea>
      </td>`;
    tbody.appendChild(nr);

    // スタッフに伝えること（折りたたみ行）。ここに書いた内容は本人の「確定アサイン」に出る。
    const sr = document.createElement('tr');
    sr.className = 'note-row';
    sr.id = 'sinfo-' + c.id;
    sr.style.display = 'none';
    sr.innerHTML = `
      <td colspan="8">
        <label>📣 スタッフに伝えること（<b>本人の「確定アサイン」にそのまま出ます</b>／入力欄から離れると自動で保存されます）<span class="saved" id="sisaved-${c.id}">✓ 保存しました</span></label>
        <div style="font-size:11.5px;color:var(--muted);margin:0 0 5px;">
          ここに書いた内容は、<b>募集中（案件カードの備考）</b>と<b>確定後（確定アサインの詳細）</b>の
          どちらでも<b>スタッフの画面に表示されます</b>。
        </div>
        <textarea id="si-notes-${c.id}" rows="4" style="width:100%;" onblur="saveStaffInfo('${c.id}')"
          placeholder="例）前日設営があるため、8/29(土)15時集合です。帰宅予定は21時ごろです。大型の運動会です。">${escapeHtml(c.staffNotes)}</textarea>
      </td>`;
    tbody.appendChild(sr);
  }

  // ===== 描画 =====
  function render(){
    const f = document.getElementById('filter').value;

    // 今のビューに属するか（公開ボード＝未来 / アーカイブ＝過去）
    function inView(c){ return currentView === 'archive' ? c.archived : !c.archived; }

    // タブの件数
    document.getElementById('cntActive').textContent  = '（' + CASES.filter(c => !c.archived).length + '）';
    document.getElementById('cntArchive').textContent = '（' + CASES.filter(c =>  c.archived).length + '）';

    // サマリー（今のビューの件数）
    let on = 0, off = 0;
    CASES.filter(inView).forEach(c => { isPublished(c.id) ? on++ : off++; });
    document.getElementById('cntOn').textContent  = on;
    document.getElementById('cntOff').textContent = off;

    function pass(c){
      if (!inView(c)) return false;
      const pub = isPublished(c.id);
      if (f === 'on')  return pub;
      if (f === 'off') return !pub;
      return true;
    }

    tbody.innerHTML = '';
    let shownTotal = 0;
    const sortMode = document.getElementById('sortMode').value;

    if (sortMode === 'registered') {
      // 登録順＝月フォルダなしの1本リスト。新しく登録した順（追加案件は登録が遅いので自然に上に来る）
      const items = CASES.filter(pass).sort((a,b) => b.addedDate - a.addedDate);
      items.forEach(appendCaseRow);
      shownTotal = items.length;
    } else {
      // カレンダー順＝月フォルダ＋各月の中を日付順（従来）
      GROUPS.forEach(g => {
        const items = CASES.filter(c => c.gkey === g.key && pass(c)).sort((a,b) => a.date - b.date);
        if (items.length === 0) return;
        shownTotal += items.length;
        const collapsed = collapsedMonths.has(g.key);

        // 月見出し
        const gr = document.createElement('tr');
        gr.className = 'group-row' + (g.past ? ' past' : '');
        gr.id = 'group-' + g.key;
        gr.setAttribute('onclick', `toggleGroup('${g.key}')`);
        gr.innerHTML = `<td colspan="8"><span class="gcaret">${collapsed ? '▶' : '▼'}</span>${g.label}${g.past ? '（終了）' : ''}<span class="g-count">${items.length}件</span></td>`;
        tbody.appendChild(gr);
        if (collapsed) return;   // 畳んでいる月は案件行を出さない

        items.forEach(appendCaseRow);
      });
    }

    empty.style.display = shownTotal === 0 ? '' : 'none';
    document.getElementById('checkCount').textContent = checkedIds.size;
    syncSelAll();
  }

  function escapeHtml(s){ return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  // 備考の開閉
  function toggleNote(id){
    const nr = document.getElementById('note-' + id);
    if (!nr) return;
    nr.style.display = nr.style.display === 'none' ? 'table-row' : 'none';
  }

  // ===== チェック（個別・全選択）=====
  function onCheck(id, checked){
    if (checked) checkedIds.add(id); else checkedIds.delete(id);
    document.getElementById('checkCount').textContent = checkedIds.size;
    syncSelAll();
  }
  function toggleAll(checked){
    // 表示中の行だけを対象に全選択／解除
    document.querySelectorAll('#pubBody input[type="checkbox"]').forEach(cb => {
      const tr = cb.closest('tr');
      const onchange = cb.getAttribute('onchange') || '';
      const m = onchange.match(/onCheck\('([^']+)'/);
      if (!m) return;
      cb.checked = checked;
      if (checked) checkedIds.add(m[1]); else checkedIds.delete(m[1]);
    });
    document.getElementById('checkCount').textContent = checkedIds.size;
  }
  function syncSelAll(){
    const boxes = Array.from(document.querySelectorAll('#pubBody input[type="checkbox"]'));
    const all = boxes.length > 0 && boxes.every(cb => cb.checked);
    const sa = document.getElementById('selAll');
    if (sa) sa.checked = all;
  }

  // ===== 公開ON/OFF（1件）=====
  function toggle(id){
    const c = CASES.find(x => x.id === id);
    const willPublish = !isPublished(id);
    if (willPublish){
      if (!confirm(`「${c.name}」をスタッフに公開します。\nスタッフの「確定アサイン」に表示されます。\n\n公開してよろしいですか？`)) return;
    } else {
      if (!confirm(`「${c.name}」の公開を取り消します。\nスタッフの画面から見えなくなります。\n\n取り消してよろしいですか？`)) return;
    }
    persistPublish([id], willPublish);
  }

  // ===== まとめて公開／非公開（チェックしたもの）=====
  function bulkPublish(toPublish){
    if (checkedIds.size === 0){ alert('チェックボックスで案件を選んでください。'); return; }
    const word = toPublish ? '公開' : '非公開に';
    if (!confirm(`選んだ ${checkedIds.size} 件をまとめて${word}します。\n\nよろしいですか？`)) return;
    const ids = Array.from(checkedIds);
    checkedIds.clear();
    persistPublish(ids, toPublish);
  }

  // ===== スタッフ画面を見る =====
  function openStaffView(){
    const w = window.open('/staff-portal', 'ecs_staff_view', 'width=900,height=760');
    if (!w){ alert('ポップアップがブロックされたようです。ブラウザのポップアップ許可を確認してください。'); return; }
    w.focus();
  }

  // ===== 登録日の表示 =====
  function fmtAdded(d){ return (d.getMonth() + 1) + '/' + d.getDate(); }

  // ===== 公開ボード / アーカイブ の切替 =====
  function setView(v){
    currentView = v;
    document.getElementById('tab-active').classList.toggle('active', v === 'active');
    document.getElementById('tab-archive').classList.toggle('active', v === 'archive');
    buildYmTree();
    render();
  }

  // ===== サイドバーの年月ツリー（今のビューの案件のある年月）=====
  function buildYmTree(){
    const tree = document.getElementById('ymTree');
    if (!tree) return;
    const list = CASES.filter(c => currentView === 'archive' ? c.archived : !c.archived);
    const byYear = {}; const yearOrder = [];
    GROUPS.forEach(g => {
      const count = list.filter(c => c.gkey === g.key).length;
      if (count === 0) return;
      if (!byYear[g.year]) { byYear[g.year] = []; yearOrder.push(g.year); }
      byYear[g.year].push({ key:g.key, month:g.month, count });
    });
    let html = '';
    yearOrder.forEach(y => {
      const months = byYear[y];
      const total = months.reduce((s,m) => s + m.count, 0);
      const open = (y === todayY);
      html += '<div class="ym-year"><button class="ym-year-btn" onclick="toggleYear(' + y + ')">'
        + '<span class="ym-caret" id="ymcaret-' + y + '">' + (open ? '▾' : '▸') + '</span>'
        + y + '年<span class="ym-ycount">' + total + '</span></button>'
        + '<div class="ym-months' + (open ? '' : ' collapsed') + '" id="ymmonths-' + y + '">';
      months.forEach(m => {
        html += '<button class="ym-month-btn" id="ymbtn-' + m.key + '" onclick="jumpToMonth(\'' + m.key + '\')">'
          + m.month + '月<span class="ym-mcount">' + m.count + '</span></button>';
      });
      html += '</div></div>';
    });
    tree.innerHTML = html || '<div class="muted" style="font-size:11.5px; padding:4px 10px;">案件がありません</div>';
  }
  function toggleYear(y){
    const box = document.getElementById('ymmonths-' + y);
    const car = document.getElementById('ymcaret-' + y);
    if (!box) return;
    const collapsed = box.classList.toggle('collapsed');
    if (car) car.textContent = collapsed ? '▸' : '▾';
  }
  let flashTimer = null;
  function jumpToMonth(key){
    document.querySelectorAll('.ym-month-btn').forEach(b => b.classList.remove('active'));
    const btn = document.getElementById('ymbtn-' + key);
    if (btn) btn.classList.add('active');
    collapsedMonths.delete(key);   // 畳んでいたら開く
    render();
    const gr = document.getElementById('group-' + key);
    if (!gr) return;
    gr.scrollIntoView({ behavior:'smooth', block:'start' });
    gr.classList.remove('flash'); void gr.offsetWidth; gr.classList.add('flash');
    if (flashTimer) clearTimeout(flashTimer);
    flashTimer = setTimeout(() => gr.classList.remove('flash'), 1500);
  }

  // 他タブで公開状態が変わったら反映
  window.addEventListener('storage', render);
  window.addEventListener('focus', render);

  loadNotice();
  loadDeadline();
  buildYmTree();
  render();
</script>
@endverbatim
@endpush
