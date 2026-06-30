@extends('layouts.app')
@section('title', 'アサインボード（日別）')
@section('h1', 'アサインボード')
@php($active = 'assign')

@push('head')
@verbatim
<style>
    /* ===== アサインボード（日別）専用スタイル ===== */

    /* 上部の操作バー（月切替・絞り込み） */
    .board-controls {
      display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
      background: var(--panel); border: 1px solid var(--line); border-radius: 12px;
      padding: 12px 16px; margin-bottom: 18px;
    }
    .month-nav { display: flex; align-items: center; gap: 10px; }
    .month-nav button {
      border: 1px solid var(--line); background: #fff; color: var(--ink);
      border-radius: 8px; width: 32px; height: 32px; font-size: 16px; cursor: pointer; font-family: inherit;
    }
    .month-nav .mon { font-size: 16px; font-weight: 700; min-width: 110px; text-align: center; }
    .board-controls .spacer { flex: 1; }
    .board-controls select {
      padding: 8px 11px; border: 1px solid var(--line); border-radius: 8px;
      font-size: 13.5px; font-family: inherit; background: #fff;
    }
    .board-controls label.chk { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--ink); cursor: pointer; }
    /* リストの高さ切替ボタン（全カードまとめて） */
    .lh-bar { display: flex; align-items: center; flex-wrap: wrap; gap: 6px;
      background: var(--panel); border: 1px solid var(--line); border-radius: 10px;
      padding: 8px 12px; margin-bottom: 10px; }
    .lh-bar .lh-lbl { font-weight: 700; font-size: 12.5px; color: var(--ink); margin-right: 4px; }
    .lh-btn { border: 1px solid var(--line); background: #fff; color: var(--ink); border-radius: 8px;
      padding: 7px 11px; font-size: 12.5px; font-weight: 700; cursor: pointer; font-family: inherit; }
    .lh-btn:hover { background: var(--brand-soft); }
    .lh-btn.active { background: var(--brand); color: #fff; border-color: var(--brand); }

    /* 1日のかたまり */
    .day-block { margin-bottom: 12px; }
    .day-head {
      display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
      padding: 5px 10px; border-radius: 10px;
      background: var(--brand-soft); color: var(--brand-dark);
      font-weight: 700; margin-bottom: 7px;
    }
    .day-head .d-date { font-size: 15px; font-variant-numeric: tabular-nums; }
    .day-head .d-date .sun { color: var(--danger); } .day-head .d-date .sat { color: var(--brand); }
    .day-head .d-pool { font-size: 12.5px; font-weight: 600; color: var(--ink); display: flex; gap: 12px; flex-wrap: wrap; }
    .day-head .d-pool b { font-variant-numeric: tabular-nums; }
    .day-head .d-pool .remain.ok  { color: #15803d; }
    .day-head .d-pool .remain.bad { color: var(--danger); }
    .day-head .d-warn { font-size: 12px; font-weight: 700; color: #fff; background: var(--danger); padding: 2px 9px; border-radius: 999px; }

    /* その日の案件カードを横に並べる */
    .case-row { display: flex; gap: 10px; flex-wrap: wrap; }

    .case-card {
      flex: 1 1 480px; max-width: 680px;
      background: #fff; border: 1px solid var(--line); border-radius: 12px;
      box-shadow: var(--shadow); padding: 9px 11px; display: flex; flex-direction: column; gap: 6px;
    }
    /* メンバー｜希望者 を横並び（ノートPC1画面でアサインしやすく） */
    .cc-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 0; }
    @media (max-width: 560px) { .cc-cols { grid-template-columns: 1fr; } }
    .cc-col .col-h { font-size: 12px; font-weight: 700; color: var(--brand-dark); margin-bottom: 4px; display: flex; align-items: center; gap: 6px; }
    .cc-col .col-h .cl-toggle { cursor: pointer; user-select: none; }
    .cc-col .col-h .cl-toggle:hover { text-decoration: underline; }
    /* 列見出しクリックで開閉。高さは上の「リストの高さ」で全カードまとめて切替（1つずつドラッグ不要）。 */
    .col-list { display: flex; flex-direction: column; gap: 2px; height: 220px; min-height: 48px; overflow: auto;
      border-top: 1px dashed var(--line); padding-top: 4px; }
    .col-list.hide { display: none; }
    /* 全カードのリスト高さをまとめて切替（#boardBody のクラスで一括指定） */
    #boardBody.lh-compact .col-list { height: 120px; }
    #boardBody.lh-normal  .col-list { height: 240px; }
    #boardBody.lh-all     .col-list { height: auto; overflow: visible; }

    /* メンバーが他に出ている案件の小タグ（同日=赤＝かぶり／別日=緑＝連続起用OK） */
    .xcase { font-size: 9.5px; font-weight: 700; padding: 0 5px; border-radius: 999px; margin-left: 3px; white-space: nowrap; }
    .xcase.same { background: var(--danger-soft); color: #b91c1c; }
    .xcase.cont { background: var(--ok-soft);     color: #15803d; }
    .case-card.todo { border-left: 4px solid #d97706; }
    .case-card.adj  { border-left: 4px solid #2c6ca0; }
    .case-card.fix  { border-left: 4px solid #16a34a; }
    .case-card.pub  { border-left: 4px solid #16a34a; }

    .cc-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .cc-headmain { flex: 1 1 auto; min-width: 150px; }
    .cc-name { font-size: 15px; font-weight: 700; line-height: 1.35; }
    .cc-client { font-size: 12px; color: var(--muted); font-weight: 400; }
    /* 時間・開催場所（日別ボードのアサイン作業用） */
    .cc-meta { font-size: 11.5px; color: var(--ink); margin-top: 3px; display: flex; flex-wrap: wrap; gap: 2px 12px; }
    .cc-meta .ic { color: var(--muted); }
    .cc-meta .venue { font-weight: 600; }
    .cc-head .sb { flex-shrink: 0; }
    /* 案件名の欄にまとめた操作ボタン（手動編集・自動アサイン・確定/公開・詳細） */
    .cc-actions { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; width: 100%;
      padding-top: 6px; border-top: 1px dashed var(--line); }
    .cc-actions .open-btn { margin-left: auto; }

    /* 状態バッジ */
    .sb { font-size: 11px; font-weight: 700; padding: 2px 9px; border-radius: 999px; white-space: nowrap; }
    .sb.todo { background: var(--warn-soft);  color: #b45309; }
    .sb.adj  { background: #e3edf7;            color: #2c6ca0; }
    .sb.fix  { background: var(--ok-soft);     color: #15803d; }
    .sb.pub  { background: #16a34a;            color: #fff; }

    /* 充足バー */
    .cc-fill { display: flex; align-items: center; gap: 9px; }
    .cc-fill .fbar { flex: 1; height: 9px; background: #ece3d4; border-radius: 999px; overflow: hidden; }
    .cc-fill .fbar > i { display: block; height: 100%; }
    .cc-fill .fbar > i.full { background: var(--ok); }
    .cc-fill .fbar > i.mid  { background: var(--brand); }
    .cc-fill .fbar > i.low  { background: var(--warn); }
    .cc-fill .fnum { font-size: 12.5px; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .cc-fill .fnum .need { color: var(--muted); font-weight: 400; }

    /* ポジション充足ランプ */
    .cc-pos { display: flex; flex-wrap: wrap; gap: 6px; }
    .plamp { font-size: 11px; font-weight: 700; padding: 1px 8px; border-radius: 6px; }
    .plamp.ok    { background: var(--ok-soft);     color: #15803d; }
    .plamp.short { background: var(--danger-soft); color: #b91c1c; }
    .plamp.none  { background: #ece3d4;            color: #7a6a58; }

    /* 案件カードのタグ（前日設営・連勤・前泊・◯日目） */
    .cc-tags { display: flex; flex-wrap: wrap; gap: 5px; }
    .ctag { font-size: 10.5px; font-weight: 700; padding: 1px 7px; border-radius: 6px; }
    .ctag.setup  { background: #ece3d4;            color: #7a6a58; }
    .ctag.renkin { background: var(--danger-soft); color: #b91c1c; }
    .ctag.stay   { background: #fdecd9;            color: #b4530a; }
    .ctag.day    { background: var(--brand-soft);  color: var(--brand-dark); }

    /* 詳細ボタン（案件名の欄に配置） */
    .open-btn {
      border: none; border-radius: 8px; padding: 7px 14px;
      font-size: 12.5px; font-weight: 700; cursor: pointer; font-family: inherit;
      background: var(--brand); color: #fff; text-decoration: none;
    }
    .open-btn:hover { background: var(--brand-dark); }

    .empty-note { text-align: center; color: var(--muted); font-size: 13px; padding: 26px 0; }

    /* カード内：割当メンバー／希望者の折りたたみ（雛形の「名前／P」に対応） */
    .cc-members { margin-top: 2px; }
    .mem-toggle {
      border: none; background: none; color: var(--brand-dark); cursor: pointer;
      font-family: inherit; font-size: 12.5px; font-weight: 700; padding: 2px 0; text-align: left;
    }
    .mem-toggle:hover { text-decoration: underline; }
    /* ↓ display:none を既定にし、.open のときだけ開く（hidden属性はflexに負けるため使わない） */
    .mem-list { display: none; margin-top: 6px; border-top: 1px dashed var(--line); padding-top: 6px; flex-direction: column; gap: 3px; }
    .mem-list.open { display: flex; }
    .mem-row { display: flex; align-items: center; gap: 6px; font-size: 11.5px; }
    .mem-row .m-no { width: 18px; text-align: right; color: var(--muted); font-variant-numeric: tabular-nums; }
    .mem-row .m-name { flex: 1; }
    .mem-row .m-pos { font-size: 10.5px; font-weight: 700; padding: 1px 7px; border-radius: 6px; background: var(--brand-soft); color: var(--brand-dark); white-space: nowrap; }
    .mem-row .m-lv { font-size: 10.5px; font-weight: 700; padding: 1px 6px; border-radius: 999px; }
    .mem-row .m-lv.new { background: var(--brand-soft); color: var(--brand-dark); }
    .mem-row .m-lv.mid { background: #ece3d4; color: #7a6a58; }
    .mem-row .m-lv.vet { background: var(--ok-soft); color: #15803d; }
    .mem-none { font-size: 12px; color: var(--muted); margin-top: 2px; }
    .cand-toggle { color: #b4530a; }   /* 希望者トグルは少し色を変える */

    /* 自動アサインボタン */
    .auto-btn {
      border: 1px solid var(--brand); background: #fff; color: var(--brand-dark);
      border-radius: 8px; padding: 7px 13px; font-size: 12.5px; font-weight: 700;
      cursor: pointer; font-family: inherit;
    }
    .auto-btn:hover { background: var(--brand-soft); }
    .cc-foot { gap: 8px; flex-wrap: wrap; }

    /* メンバーの種別バッジ（社員・派遣） */
    .m-type { font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 999px; white-space: nowrap; }
    .m-type.emp   { background: #e3edf7; color: #2c6ca0; }   /* 社員 */
    .m-type.haken { background: #efe6f6; color: #6d28d9; }   /* 派遣 */
    /* メンバーを外す × ／ 希望者を入れる ＋追加 */
    /* 同じ日に複数案件でかぶっている人＝赤文字 */
    .m-name.dup { color: var(--danger); font-weight: 700; }
    .m-x   { color: var(--danger); font-weight: 700; cursor: pointer; padding: 0 4px; }
    .m-x:hover { background: var(--danger-soft); border-radius: 6px; }
    .c-add { color: var(--brand);  font-weight: 700; cursor: pointer; padding: 0 4px; white-space: nowrap; }
    .c-add:hover { text-decoration: underline; }

    /* 希望者の色分け（複数案件希望／カレンダー〇／複数〇） */
    .cand-row.multi-apply { background: #fdecd9; border-radius: 6px; padding: 2px 4px; }  /* 同じ日の複数案件に希望＝取り合い */
    .cand-row.multi-cal   { background: #efe6f6; border-radius: 6px; padding: 2px 4px; }  /* カレンダー〇が複数 */
    .cand-row.cal-one     { background: #e3edf7; border-radius: 6px; padding: 2px 4px; }  /* カレンダー〇 */
    .cstat { font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 999px; white-space: nowrap; }
    .cstat.apply2 { background: var(--danger-soft); color: #b91c1c; }
    .cstat.cal2   { background: #efe6f6; color: #6d28d9; }
    .cstat.cal1   { background: #e3edf7; color: #2c6ca0; }
    .cstat.only   { background: #ece3d4; color: #7a6a58; }
    .cstat.done   { background: var(--ok-soft); color: #15803d; }
    /* 月間アサイン上限（過重労働防止・一律20件）バッジ */
    .capb { font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 999px; white-space: nowrap; }
    .capb.over { background: var(--danger-soft); color: #b91c1c; }   /* 上限到達・超過 */
    .capb.near { background: #fdecd2; color: #b45309; }              /* 残りわずか */
    /* すでにこの案件のメンバーに入れた希望者＝グレーアウト（二重アサイン防止・残りの人を探しやすく） */
    .cand-row.picked { opacity: 0.45; background: #f1ece4; border-radius: 6px; padding: 2px 4px; }
    .cand-row.picked .m-name { text-decoration: line-through; }

    /* 手動編集の操作 */
    .edit-btn { border: 1px solid var(--line); background: #fff; color: var(--ink);
      border-radius: 8px; padding: 7px 12px; font-size: 12.5px; font-weight: 700; cursor: pointer; font-family: inherit; }
    .edit-btn.on { background: var(--brand); color: #fff; border-color: var(--brand); }
    .add-row { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
    .add-row .mini { border: 1px dashed var(--brand); background: #fff; color: var(--brand-dark);
      border-radius: 8px; padding: 5px 10px; font-size: 12px; font-weight: 700; cursor: pointer; font-family: inherit; }
    .add-row .mini:hover { background: var(--brand-soft); }
    /* M-9：名簿から追加プルダウン（日別ボード） */
    .add-row .mini-sel { border: 1px solid var(--brand); background: #fff; color: var(--brand-dark);
      border-radius: 8px; padding: 5px 8px; font-size: 12px; font-weight: 700; cursor: pointer;
      font-family: inherit; max-width: 200px; }

    /* 希望者の色の凡例 */
    .legend { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; font-size: 11.5px; color: var(--ink);
      background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 6px 12px; margin-bottom: 10px; }
    .legend .lg { display: inline-flex; align-items: center; gap: 6px; }
    .legend .sw { width: 14px; height: 14px; border-radius: 4px; display: inline-block; border: 1px solid var(--line); }
    .legend .sw.a { background: #fdecd9; } .legend .sw.c1 { background: #e3edf7; } .legend .sw.c2 { background: #efe6f6; } .legend .sw.only { background: #ece3d4; }
  </style>
@endverbatim
@endpush

@section('content')
@verbatim
      <div class="mock-note">
        これは見た目確認用のモックです。人数・稼働可数はすべて仮の見本です。<br>
        <b>日ごとに、その日の案件を横に並べて表示します。</b>同じ日のスタッフは取り合いになるため、各日の「稼働可／割当済／残り」を見ながら割り当てます。案件カードの「アサインを開く」で、その案件の詳細（提案チーム）に進みます。
        <span style="display:inline-block; margin-top:4px;">全体の状況（募集中・要注意スタッフ・確定履歴）は <a href="/assign-dashboard">▣ アサインダッシュボード</a> にまとめています。</span>
      </div>

      <!-- 操作バー -->
      <div class="board-controls">
        <div class="month-nav">
          <button onclick="alert('モックのため、月の切替はしません。')">◀</button>
          <span class="mon">2026年 7月</span>
          <button onclick="alert('モックのため、月の切替はしません。')">▶</button>
        </div>
        <div class="spacer"></div>
        <select id="stateFilter" onchange="render()">
          <option value="">状態：すべて</option>
          <option value="todo">未着手のみ</option>
          <option value="adj">調整中のみ</option>
          <option value="fix">確定のみ</option>
          <option value="pub">公開済のみ</option>
          <option value="unpub">未公開のみ（未着手・調整中・確定）</option>
        </select>
        <label class="chk"><input type="checkbox" id="mineOnly" onchange="render()"> 自分の担当のみ</label>
        <button class="btn" onclick="openWishlist()" style="margin-left:8px;">👥 スタッフ一覧（別ウィンドウ）</button>
      </div>

      <!-- 希望者の色の凡例 -->
      <div class="legend">
        <span style="font-weight:700;">希望者の色：</span>
        <span class="lg"><span class="sw a"></span> <b>複数〇</b>＝同じ日の複数案件に希望（取り合い）</span>
        <span class="lg"><span class="sw c1"></span> <b>終日〇</b>＝カレンダー〇（その日は稼働可＝どの案件にも入れる）</span>
        <span class="lg"><span class="sw only"></span> <b>この案件</b>＝この案件だけに希望</span>
        <span class="lg"><span class="cstat done" style="border:1px solid var(--line);">✓ アサイン済み</span>＝すでにメンバーに入れた人（グレー表示）</span>
        <span class="muted" style="font-size:11.5px;">※左「メンバー」｜右「希望者」を横並び。列の見出しクリックで各列を開閉。高さは上の「リストの高さ」で全カードまとめて変えられます。メンバー名の右タグ＝他に出ている案件（<span style="color:#b91c1c; font-weight:700;">赤=同日かぶり</span>／<span style="color:#15803d; font-weight:700;">緑=別日（連続起用OK）</span>）。同日かぶりは⚠赤文字。操作ボタン（✎手動編集／⚡自動アサイン／✓確定／📣公開／詳細）は案件名の欄にまとめています。</span>
      </div>

      <!-- リストの高さ切替（案件カードのすぐ上・全カードまとめて） -->
      <div class="lh-bar">
        <span class="lh-lbl">メンバー／希望者リストの高さ：</span>
        <button type="button" class="lh-btn" data-h="compact" onclick="setListHeight('compact')">たたむ</button>
        <button type="button" class="lh-btn active" data-h="normal" onclick="setListHeight('normal')">標準</button>
        <button type="button" class="lh-btn" data-h="all" onclick="setListHeight('all')">ぜんぶ表示</button>
      </div>

      <div id="boardBody" class="lh-normal"></div>
      <div class="empty-note" id="boardEmpty" style="display:none;">条件に合う案件がありません。</div>
@endverbatim
@endsection

@push('scripts')
<!-- 共通の案件データ（全画面で同じ1つのリストを読む） -->
<script src="/ecs/data/cases.js"></script>
<script src="/ecs/data/people.js"></script>
<!-- DBのスタッフ名一覧（NAME_POOL の単一ソース）。空のときは下のべた書きにフォールバック。 -->
<script>window.ECS_STAFF_POOL = @json($staffPool);</script>
<!-- DBのボード用案件＋割当メンバー（実データ）。空のときは見本cases.jsにフォールバック。 -->
<script>window.ECS_BOARD_CASES = @json($boardCases ?? []);</script>
<!-- 希望者カラム用：その日に稼働可/希望のスタッフ（off→一覧）と、今月のアサイン件数（名前→件数）。 -->
<script>window.ECS_BOARD_AVAIL = @json($boardAvail ?? []);</script>
<script>window.ECS_BOARD_MONTH = @json($boardMonth ?? []);</script>
@verbatim
<script>
  // ===== 案件データ（共通リスト data/cases.js から作る）=====
  // off    … 今日から何日後に開催か／cat … 現場種別／need … 必要人数／filled … 割当済み
  // state  … todo(未着手) / adj(調整中) / fix(確定) / pub(公開済)
  // pos    … 主要ポジションの充足ランプ ／ mine … 自分(baba)担当か
  // ※ボードは「近い日（今日〜3週間先）の本番・予備日」を日ごとに並べる。過去・下書き・
  //   遠い月の案件は出さない（それらは案件一覧／スタッフ画面で見る）。同じ1つのデータから作る。
  // DBのボードデータ（割当メンバー込み）があればそれを使う。空なら見本cases.jsで動かす（フォールバック）。
  const ECS_BOARD = (window.ECS_BOARD_CASES && window.ECS_BOARD_CASES.length) ? window.ECS_BOARD_CASES : null;
  const cases = (ECS_BOARD || ECS_CASES)
    .filter(c => !c.archived && !c.draft && c.off >= 0 && c.off <= 21)
    .map(c => ({
      id:c.id, off:c.off, name:c.name, client:c.client, cat:c.cat,
      need:c.need, filled:c.filled, state:c.state, mine:c.mine,
      meet:c.meet, leave:c.leave, enter:c.enter, evStart:c.evStart, evEnd:c.evEnd,
      place:c.place, placeShort:c.placeShort, meetPlace:c.meetPlace,
      tags:(c.tags||[]).slice(), pos:(c.pos||[]).map(p => p.slice()),
      // 割当メンバー：DBボードならその実データ、見本なら後で candPool から作る（下の forEach）。
      assigned:(c.assigned||[]).map(m => ({ name:m.name, lv:m.lv, pos:m.pos, type:m.type }))
    }));

  // 各日の「稼働可スタッフ数」（仮）。off → その日に稼働可と出している人数。
  // ※off12は「運動会3日目＋水合戦＋縁日」で割当済が稼働可を超える＝重複警告が出る例。
  //   ボードに出る日のうち未設定の日は既定(26名)を使う。
  const dayAvailMap = { 9:20, 10:30, 11:28, 12:30, 14:28, 16:26, 17:30, 18:24, 19:22 };
  const dayAvail = new Proxy(dayAvailMap, { get:(t,k)=> (k in t ? t[k] : 26) });

  // 案件の短縮名（メンバーが「他にどの案件に出ているか」を小さく表示するのに使う）
  const SHORT = { undo_setup:'設営', undo_d1:'運1', undo_d2:'運2', undo_d3:'運3', mizu:'水', enni1:'縁日',
                  shinkan:'新歓', shinkan_yobi:'新歓予', konshin:'懇親', hyosho:'表彰',
                  fes_reha:'リハ', mizu_yobi:'水予' };
  function shortOf(id){ return SHORT[id] || id; }
  // 名前 → その人が割り当てられている案件idの一覧（同日かぶり／複数日の連続起用の把握に使う）
  function assignmentMap(){
    const m = {};
    cases.forEach(x => x.assigned.forEach(mem => { (m[mem.name] = m[mem.name] || []).push(x.id); }));
    return m;
  }

  // ===== 割当メンバーの仮データ生成 =====
  // 雛形（東京アサイン表）の「NO／名前／P（ポジション）」に合わせ、
  // 各案件カードに割り当て済みメンバーの名前＋ポジションを出せるようにする。
  // 割当メンバーの名前プール。DB（people のスタッフ）から渡された一覧を優先し、
  // 空のとき（未シード等）だけ下のべた書きにフォールバックする＝名簿の単一ソース化。
  const NAME_POOL = (window.ECS_STAFF_POOL && window.ECS_STAFF_POOL.length) ? window.ECS_STAFF_POOL : [
    '高橋 由依','伊藤 健','渡辺 さくら','鈴木 美咲','山田 涼','松本 美優','井上 大輝','木村 拓海',
    '林 美月','清水 陽','森 結菜','佐藤 健太','池田 莉子','橋本 颯','石川 葵','近藤 樹',
    '山本 翔太','中村 彩','小林 蓮','加藤 結衣','吉田 大和','斎藤 楓','岡田 悠','前田 凛',
    '藤田 海','後藤 蓮','長谷川 葵','村上 陽菜','遠藤 樹','坂本 美羽','青木 駿','西村 杏',
    '福田 翼','太田 七海','三浦 一','藤井 結','金子 蒼','中島 心','原田 楓','和田 凪'
  ];
  // ポジションの並び（雛形の P 列に近い順：D→MC→OP→FC…）
  const POS_PATTERN = ['D','MC','OP','FC','FC','FC','受付','CK','軍師・サポーター','FC','受付','FC','CK','FC','受付','FC','FC','FC','受付','CK'];
  // 案件IDから安定した開始位置を作る（同じ案件はいつ開いても同じ名前になる）
  function seedOf(id){ let s = 0; for (const ch of id) s += ch.charCodeAt(0); return s; }

  // その案件の「希望者プール」（必要数より少し多め）。割当メンバーはこの先頭から取る＝
  // 自動アサインで選ばれる人と、カードに出す希望者が食い違わないようにする。
  const LVS = ['vet','mid','mid','new','mid','vet','new','mid','new','vet','mid','new'];
  const lvLabel = { new:'新人', mid:'中堅', vet:'ベテラン' };
  function candPool(c){
    const seed  = seedOf(c.id);
    const total = c.need + 5;
    const arr = [];
    for (let i = 0; i < total; i++){
      arr.push({ no:i+1, name: NAME_POOL[(seed + i) % NAME_POOL.length], lv: LVS[i % LVS.length], pos: POS_PATTERN[i] || 'FC' });
    }
    return arr;
  }
  // 各案件の「割当メンバー」を実体（配列）で持つ。
  // DBボード（ECS_BOARD）のときは上のmapで実データ（assignments由来）を入れ済み＝そのまま使う。
  // 見本(cases.js)フォールバック時だけ、希望者プールの先頭 filled 名で合成する。
  // type: 'staff'(スタッフ) / 'emp'(社員) / 'haken'(派遣)
  if (!ECS_BOARD) {
    cases.forEach(c => {
      c.assigned = candPool(c).slice(0, c.filled).map(m => ({ name:m.name, lv:m.lv, pos:m.pos, type:'staff' }));
    });
  }
  function filledOf(c){ return c.assigned.length; }
  function buildMembers(c){ return c.assigned; }
  function typeBadge(type){
    if (type === 'emp')   return '<span class="m-type emp">社員</span>';
    if (type === 'haken') return '<span class="m-type haken">派遣</span>';
    return '';
  }

  // ===== 月間アサイン上限（過重労働防止・一律20件／設計書11章F・実装仕様書8章）=====
  // モックなので「今月の件数」＝(ボード外で既にアサイン済みの下敷き)＋(このボード上で割当済みの件数)。
  // 下敷きは一部の名前を高めに置き、上限まわりの挙動が見えるようにしている。
  const MONTH_CAP = 20;
  const MONTH_BASE = { '高橋 由依':19, '伊藤 健':18, '渡辺 さくら':17, '清水 陽':16, '松本 美優':15 };
  function baseCountOf(name){
    // DBボードのとき：今月のアサイン件数は実データ（ECS_BOARD_MONTH）から。
    if (ECS_BOARD) return (window.ECS_BOARD_MONTH && window.ECS_BOARD_MONTH[name]) || 0;
    if (name in MONTH_BASE) return MONTH_BASE[name];
    return seedOf(name) % 13;   // それ以外は名前から決まる 0〜12
  }
  // amap（名前→出ている案件id配列）があれば渡す。無ければその場で集計。
  function monthCountOf(name, amap){
    // DBは ECS_BOARD_MONTH にボード期間の件数が入っている＝二重に足さない。
    if (ECS_BOARD) return baseCountOf(name);
    const board = ((amap || assignmentMap())[name] || []).length;
    return baseCountOf(name) + board;
  }
  // 行に付ける上限バッジ（上限到達=赤／残り2以内=橙／それ以外は出さない）
  function capBadge(name, amap){
    const n = monthCountOf(name, amap);
    if (n >= MONTH_CAP)     return `<span class="capb over" title="今月のアサインが上限(${MONTH_CAP}件)に達しています">今月${n}/${MONTH_CAP} 上限</span>`;
    if (n >= MONTH_CAP - 2) return `<span class="capb near" title="今月のアサインが上限(${MONTH_CAP}件)に近づいています">今月${n}/${MONTH_CAP}</span>`;
    return '';
  }

  // ===== その日の希望者（カレンダー〇・案件応募）=====
  // applied: その日のどの案件に応募(希望)したか ／ cal: カレンダーの〇の数(0/1/2)
  function dayPeople(off, dayCases){
    // DBボードのとき：その日の各案件への応募者（c.applicants）＋その日の稼働可スタッフ（ECS_BOARD_AVAIL）を
    // 名前で集約する。applied＝その日のどの案件に応募したか／cal＝その日に稼働可〇を出しているか。
    if (ECS_BOARD) {
      const byName = {};
      dayCases.forEach(c => (c.applicants || []).forEach(a => {
        const e = byName[a.name] || (byName[a.name] = { name:a.name, lv:a.lv, pos:a.pos, applied:[], cal:false });
        if (!e.applied.includes(c.id)) e.applied.push(c.id);
      }));
      ((window.ECS_BOARD_AVAIL && window.ECS_BOARD_AVAIL[off]) || []).forEach(a => {
        const e = byName[a.name] || (byName[a.name] = { name:a.name, lv:a.lv, pos:a.pos, applied:[], cal:false });
        e.cal = true;
      });
      return Object.values(byName);
    }
    const ids = dayCases.map(c => c.id);
    const totalNeed = dayCases.reduce((s,c) => s + c.need, 0);
    const count = Math.min(NAME_POOL.length, totalNeed + 6);
    const seed  = (off * 7) % NAME_POOL.length;
    const arr = [];
    for (let i = 0; i < count; i++){
      const applied = [ ids[i % ids.length] ];
      if (ids.length > 1 && i % 4 === 0) applied.push(ids[(i + 1) % ids.length]); // 4人に1人は同日の別案件にも希望
      const cal = (i % 3 === 0);   // カレンダーで〇（その日の稼働可）を出している人
      arr.push({
        name: NAME_POOL[(seed + i) % NAME_POOL.length],
        lv:   LVS[i % LVS.length],
        pos:  POS_PATTERN[i] || 'FC',
        applied: [...new Set(applied)],
        cal
      });
    }
    return arr;
  }
  // 希望者1人の色分け（短いタグ）
  //  複数〇＝同じ日の複数案件に希望（取り合い）／終日〇＝カレンダー〇（その日は稼働可＝どの案件にも入れる）
  //  この案件＝この案件だけに希望
  function candStatus(p){
    if (p.applied.length >= 2) return { cls:'multi-apply', tag:'<span class="cstat apply2">複数〇</span>' };
    if (p.cal)                 return { cls:'cal-one',     tag:'<span class="cstat cal1">終日〇</span>' };
    return { cls:'', tag:'<span class="cstat only">この案件</span>' };
  }
  // 列（メンバー／希望者）の開閉
  function toggleCol(el){
    const col = el.closest('.cc-col');
    const list = col.querySelector('.col-list');
    list.classList.toggle('hide');
    const ar = el.querySelector('.cl-arrow');
    if (ar) ar.textContent = list.classList.contains('hide') ? '▸' : '▾';
  }

  // 折りたたみ開閉（.open クラスで切替。hidden属性はCSSのdisplay:flexに負けるため使わない）
  function toggleList(elId, btn){
    const el = document.getElementById(elId);
    if (!el) return;
    el.classList.toggle('open');
    const open = el.classList.contains('open');
    btn.textContent = open ? btn.textContent.replace('▸','▾') : btn.textContent.replace('▾','▸');
  }
  function toggleMem(id, btn){ toggleList('mem-'  + id, btn); }
  function toggleCand(id, btn){ toggleList('cand-' + id, btn); }

  // ===== 自動アサイン／手動編集 =====
  const editing = new Set();   // 手動編集モードにしている案件id
  function toggleEdit(id){ editing.has(id) ? editing.delete(id) : editing.add(id); render(); }

  // 同じ日の「他の案件」で割当済みの名前（＝かぶり防止に使う）
  function takenSameDay(c){
    return new Set(
      cases.filter(x => x.off === c.off && x.id !== c.id)
           .flatMap(x => x.assigned.map(m => m.name))
    );
  }

  // 自動アサイン（モック）＝希望者プールから、同じ日にかぶらない人を必要数ぶん埋める
  function autoAssign(id){
    const c = cases.find(x => x.id === id);
    if (!c) return;
    if (filledOf(c) >= c.need) { alert('この案件はすでに必要人数を満たしています。'); return; }
    const taken  = takenSameDay(c);
    const amap   = assignmentMap();
    // かぶる人・今月の上限(20件)に達した人を除外して選ぶ
    const picked = candPool(c)
      .filter(m => !taken.has(m.name) && monthCountOf(m.name, amap) < MONTH_CAP)
      .slice(0, c.need);
    c.assigned = picked.map(m => ({ name:m.name, lv:m.lv, pos:m.pos, type:'staff' }));
    if (c.state === 'todo') c.state = 'adj';    // 未着手 → 調整中へ
    render();
    if (picked.length < c.need) {
      alert('⚡ 自動アサインしました（モック）。\n「' + c.name + '」に ' + picked.length + '名を割り当てました。\n同じ日の他案件とのかぶり、または今月のアサイン上限(' + MONTH_CAP + '件)で対象外になった人がいて、必要 ' + c.need + '名に ' + (c.need - picked.length) + '名 不足しています。\n→「手動編集」で社員・派遣を足して補ってください。');
    } else {
      alert('⚡ 自動アサインしました（モック）。\n「' + c.name + '」に希望者から ' + c.need + '名を割り当てました（同じ日の他案件とかぶらないよう調整済み）。');
    }
  }
  // メンバーを外す
  function removeMember(caseId, idx){
    const c = cases.find(x => x.id === caseId);
    if (!c) return;
    c.assigned.splice(idx, 1);
    render();
  }
  // 希望者をメンバーに入れる（同じ日に他案件とかぶる場合は確認）
  function addCandidate(caseId, name, lv, pos){
    const c = cases.find(x => x.id === caseId);
    if (!c) return;
    if (c.assigned.some(m => m.name === name)) { alert(name + ' さんはすでにこの案件のメンバーに入っています。'); return; }
    if (takenSameDay(c).has(name)) {
      if (!confirm(name + ' さんは同じ日の別の案件にすでに割り当てられています。\nそれでも追加しますか？（かぶりは赤文字で表示されます）')) return;
    }
    // 月間アサイン上限（過重労働防止・一律20件）のチェック
    const mc = monthCountOf(name);
    if (mc >= MONTH_CAP) {
      if (!confirm(name + ' さんは今月のアサインがすでに上限の ' + MONTH_CAP + '件 に達しています（現在 ' + mc + '件）。\n過重労働防止のための上限を超えます。それでも追加しますか？')) return;
    }
    c.assigned.push({ name, lv, pos, type:'staff' });
    render();
  }
  // ===== ボード上で完結：確定・公開 =====
  function markFix(id){
    const c = cases.find(x => x.id === id);
    if (!c) return;
    if (filledOf(c) < c.need && !confirm('必要人数（' + c.need + '名）に達していませんが、確定にしますか？')) return;
    c.state = 'fix';
    render();
  }
  function markPub(id){
    const c = cases.find(x => x.id === id);
    if (!c) return;
    if (!confirm('「' + c.name + '」をスタッフに公開します。\n（モックのため実際の通知は行いません）\n公開してよろしいですか？')) return;
    c.state = 'pub';
    render();
    alert('📣 スタッフに公開しました（モック）。\nスタッフ画面の「確定アサイン」に表示される想定です。');
  }
  // 社員を足す（人手不足時）
  function addEmployee(caseId){
    const c = cases.find(x => x.id === caseId);
    if (!c) return;
    const name = prompt('追加する社員の名前を入力してください（モック）', '');
    if (!name) return;
    c.assigned.push({ name: name.trim(), lv:'-', pos:'FC', type:'emp' });
    render();
  }
  // 派遣を足す（人手不足時）
  function addHaken(caseId){
    const c = cases.find(x => x.id === caseId);
    if (!c) return;
    const name = prompt('派遣の名前／派遣会社を入力してください（モック）', '派遣スタッフ');
    if (!name) return;
    c.assigned.push({ name: name.trim(), lv:'-', pos:'受付', type:'haken' });
    render();
  }

  // ===== M-9：名簿（people.js）から選んで追加（社員・スタッフ）=====
  // people.js の pos:{...} から、できる役割の先頭をその人の担当ポジションにする
  function firstPosOf(pos){
    const order = [['D','D'],['OP','OP'],['MC','MC'],['FC','FC'],['CK','CK'],['GUN','軍師・サポーター'],['UKE','受付']];
    for (const [k, label] of order) { if (pos && pos[k]) return label; }
    return 'FC';
  }
  // この案件カードの「名簿から追加」プルダウンの中身（すでに入っている人は除外＝重複防止）
  function rosterOptions(c){
    if (typeof ECS_PEOPLE === 'undefined') return '<option value="">名簿から追加…</option>';
    const taken = new Set(c.assigned.map(m => m.name));
    const optsFor = role => ECS_PEOPLE
      .filter(pp => pp.role === role && !taken.has(pp.name))
      .map(pp => `<option value="${pp.id}">${pp.name}（${ECS_LV_LABEL[ECS_lvOf(pp)]}）</option>`)
      .join('');
    const emp = optsFor('employee'), stf = optsFor('staff');
    return '<option value="">名簿から追加…</option>'
      + (emp ? `<optgroup label="社員">${emp}</optgroup>` : '')
      + (stf ? `<optgroup label="スタッフ">${stf}</optgroup>` : '');
  }
  // プルダウンで選んだ人をこの案件のメンバーに追加（かぶり・月上限のチェックは addCandidate と同じ）
  function addRosterMember(caseId, id){
    if (!id) return;
    const c = cases.find(x => x.id === caseId);
    if (!c) return;
    const pp = ECS_personById(id);
    if (!pp) return;
    if (c.assigned.some(m => m.name === pp.name)) { alert(pp.name + ' さんはすでにこの案件のメンバーに入っています。'); return; }
    if (takenSameDay(c).has(pp.name)) {
      if (!confirm(pp.name + ' さんは同じ日の別の案件にすでに割り当てられています。\nそれでも追加しますか？（かぶりは赤文字で表示されます）')) return;
    }
    const mc = monthCountOf(pp.name);
    if (mc >= MONTH_CAP) {
      if (!confirm(pp.name + ' さんは今月のアサインがすでに上限の ' + MONTH_CAP + '件 に達しています（現在 ' + mc + '件）。\n過重労働防止のための上限を超えます。それでも追加しますか？')) return;
    }
    const isEmp = pp.role === 'employee';
    const pos = isEmp ? ((pp.dexp && pp.dexp.length) ? 'D' : 'FC') : firstPosOf(pp.pos);
    c.assigned.push({ name: pp.name, lv: (isEmp ? '-' : ECS_lvOf(pp)), pos: pos, type: (isEmp ? 'emp' : 'staff') });
    render();
  }

  // リストの高さを全カードまとめて切替（たたむ／標準／ぜんぶ表示）
  function setListHeight(v){
    const b = document.getElementById('boardBody');
    b.classList.remove('lh-compact', 'lh-normal', 'lh-all');
    b.classList.add('lh-' + v);
    // 押したボタンを目立たせる
    document.querySelectorAll('.lh-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.h === v));
  }

  // 希望者一覧を別ウィンドウで開く
  function openWishlist(){
    const w = window.open('/assign-wishlist', 'ecs_wishlist', 'width=880,height=700');
    if (!w) { alert('ポップアップがブロックされたようです。ブラウザのポップアップ許可を確認してください。'); return; }
    w.focus();
  }

  // ===== 日付ユーティリティ =====
  const DOW = ['日','月','火','水','木','金','土'];
  function addDays(n){ const x = new Date(); x.setHours(0,0,0,0); x.setDate(x.getDate()+n); return x; }
  const stateLabel = { todo:'未着手', adj:'調整中', fix:'確定', pub:'公開済' };

  const body  = document.getElementById('boardBody');
  const empty = document.getElementById('boardEmpty');

  function render(){
    const sf   = document.getElementById('stateFilter').value;
    const mine = document.getElementById('mineOnly').checked;

    // 絞り込み
    const list = cases.filter(c => {
      if (mine && !c.mine) return false;
      if (!sf) return true;
      if (sf === 'unpub') return c.state !== 'pub';
      return c.state === sf;
    });

    // 開催日（off）ごとにまとめる
    const offs = [...new Set(list.map(c => c.off))].sort((a,b) => a - b);

    body.innerHTML = '';
    empty.style.display = list.length === 0 ? '' : 'none';

    offs.forEach(off => {
      const dayCases = list.filter(c => c.off === off);
      const date = addDays(off);
      const dy   = date.getDay();
      const dowC = dy === 0 ? 'sun' : (dy === 6 ? 'sat' : '');

      // その日のプール（稼働可・割当済・残り）。割当済＝その日の全案件の割当メンバー合計。
      // DBボードなら、その日に稼働可/希望を出したスタッフ数（ECS_BOARD_AVAIL）。見本なら従来のdayAvail。
      const avail    = ECS_BOARD
        ? (((window.ECS_BOARD_AVAIL && window.ECS_BOARD_AVAIL[off]) || []).length)
        : (dayAvail[off] || 0);
      const assigned = dayCases.reduce((s,c) => s + filledOf(c), 0);
      const remain   = avail - assigned;

      const block = document.createElement('div');
      block.className = 'day-block';

      const warnHtml = remain < 0
        ? `<span class="d-warn">⚠ 稼働可を ${Math.abs(remain)}名 超過（重複の可能性）</span>`
        : '';

      block.innerHTML = `
        <div class="day-head">
          <span class="d-date">${date.getMonth()+1}/${date.getDate()}<span class="${dowC}">（${DOW[dy]}）</span></span>
          <span class="d-pool">
            <span>稼働可 <b>${avail}</b>名</span>
            <span>割当済 <b>${assigned}</b>名</span>
            <span class="remain ${remain < 0 ? 'bad' : 'ok'}">残り <b>${remain}</b>名</span>
          </span>
          ${warnHtml}
        </div>
        <div class="case-row" id="row-${off}"></div>`;
      body.appendChild(block);

      // その日に複数案件でかぶっている人の名前（赤文字にする）
      const nameCount = {};
      dayCases.forEach(dc => dc.assigned.forEach(m => { nameCount[m.name] = (nameCount[m.name] || 0) + 1; }));
      const dupNames = new Set(Object.keys(nameCount).filter(n => nameCount[n] >= 2));

      const amap = assignmentMap();   // 名前→出ている案件id（メンバーに「他にどこに出ているか」を出す）
      const row = block.querySelector('.case-row');
      dayCases.forEach(c => row.appendChild(buildCard(c, dayCases, dupNames, amap)));
    });
  }

  function buildCard(c, dayCases, dupNames, amap){
    dupNames = dupNames || new Set();
    amap = amap || {};
    const card = document.createElement('div');
    card.className = 'case-card ' + c.state;
    card.id = 'case-' + c.id;   // 本物の案件ID。ダッシュボードからの ?focus=案件ID で狙い撃ちできる
    const editMode = editing.has(c.id);
    const filled = filledOf(c);

    // 充足バー
    const ratio = c.need ? Math.min(1, filled / c.need) : 0;
    const barCls = filled >= c.need ? 'full' : (ratio >= 0.7 ? 'mid' : 'low');

    // ポジション充足ランプ
    const posHtml = c.pos.map(p => {
      const cls = p[1];
      const mark = cls === 'ok' ? '✓' : (cls === 'short' ? '△不足' : '未');
      return `<span class="plamp ${cls}">${p[0]} ${mark}</span>`;
    }).join('');

    // タグ（前日設営・連勤・前泊・◯日目）
    const tagHtml = (c.tags || []).map(t => {
      let cls = 'day';
      if (t.includes('連勤'))      cls = 'renkin';
      else if (t.includes('設営')) cls = 'setup';
      else if (t.includes('前泊')) cls = 'stay';
      else if (t.includes('日目')) cls = 'day';
      return `<span class="ctag ${cls}">${t}</span>`;
    }).join('');

    // 割当メンバー（スタッフ＋社員＋派遣）。同じ日にかぶる人は赤文字。
    // 各メンバーの右に「他にどの案件に出ているか」を小タグで表示（同日=赤／別日=緑＝連続起用OK）。
    const members = buildMembers(c);
    const memRows = members.map((m, i) => {
      const dup = dupNames.has(m.name) ? 'dup' : '';
      const x = editMode ? `<span class="m-x" title="外す" onclick="removeMember('${c.id}',${i})">×</span>` : '';
      const others = (amap[m.name] || []).filter(id => id !== c.id);
      const xtags = others.map(id => {
        const oc = cases.find(z => z.id === id);
        const same = oc && oc.off === c.off;
        return `<span class="xcase ${same ? 'same' : 'cont'}" title="${oc ? oc.name : ''}">${shortOf(id)}</span>`;
      }).join('');
      const capb = m.type === 'staff' ? capBadge(m.name, amap) : '';
      return `<div class="mem-row"><span class="m-no">${i+1}</span><span class="m-name ${dup}">${dup ? '⚠' : ''}${m.name}</span>${typeBadge(m.type)}<span class="m-pos">${m.pos}</span>${capb}${xtags}${x}</div>`;
    }).join('');
    const memCol =
      `<div class="cc-col">
         <div class="col-h"><span class="cl-toggle" onclick="toggleCol(this)"><span class="cl-arrow">▾</span> メンバー（${filled}/${c.need}名）</span></div>
         <div class="col-list">${memRows || '<div class="mem-none">メンバー未割当</div>'}</div>
         ${editMode ? `<div class="add-row"><select class="mini-sel" title="名簿（社員・スタッフ）から選んで追加。LINEで入れると言われたスタッフもここから。" onchange="addRosterMember('${c.id}', this.value)">${rosterOptions(c)}</select><button class="mini" onclick="addHaken('${c.id}')">＋派遣</button></div>` : ''}
       </div>`;

    // この日の希望者（応募＋カレンダー〇）＝色分け。手動編集中は ＋ でメンバーへ。
    const dp = dayPeople(c.off, dayCases).filter(p => p.applied.includes(c.id) || p.cal);
    const candRows = dp.map(p => {
      // すでにこの案件のメンバーに入っている人＝アサイン済み（グレーアウト・＋ボタン無し）
      const picked = c.assigned.some(m => m.name === p.name);
      const st = candStatus(p);
      const statTag = picked ? '<span class="cstat done">✓ アサイン済み</span>' : st.tag;
      const rowCls  = picked ? 'picked' : st.cls;
      const addBtn = (editMode && !picked) ? `<span class="c-add" title="メンバーに入れる" onclick="addCandidate('${c.id}','${p.name}','${p.lv}','${p.pos}')">＋</span>` : '';
      return `<div class="mem-row cand-row ${rowCls}"><span class="m-name">${p.name}</span><span class="m-lv ${p.lv}">${lvLabel[p.lv]}</span><span class="m-pos">${p.pos}</span>${capBadge(p.name, amap)}${statTag}${addBtn}</div>`;
    }).join('');
    const candCol =
      `<div class="cc-col">
         <div class="col-h"><span class="cl-toggle" onclick="toggleCol(this)"><span class="cl-arrow">▾</span> 希望者（${dp.length}名）</span></div>
         <div class="col-list">${candRows || '<div class="mem-none">希望者はいません。</div>'}</div>
       </div>`;

    // 状態を進めるボタン（ボード上で完結：未着手/調整中→確定→公開）
    let stateBtn = '';
    if (c.state === 'todo' || c.state === 'adj') stateBtn = `<button class="edit-btn" onclick="markFix('${c.id}')">✓ 確定にする</button>`;
    else if (c.state === 'fix')                  stateBtn = `<button class="auto-btn" onclick="markPub('${c.id}')">📣 スタッフに公開</button>`;
    else if (c.state === 'pub')                  stateBtn = `<span style="font-size:12px; font-weight:700; color:#15803d;">公開中 ✓</span>`;

    card.innerHTML = `
      <div class="cc-head">
        <div class="cc-headmain">
          <div class="cc-name">${c.name}</div>
          <div class="cc-client">${c.client}</div>
          <div class="cc-meta">
            <span><span class="ic">🕘</span> 集合 ${c.meet || '—'}〜解散 ${c.leave || '—'}</span>
            <span><span class="ic">📍</span> <span class="venue" title="${c.place || ''}">${c.placeShort || c.place || '—'}</span></span>
            ${c.meetPlace ? `<span><span class="ic">🚩</span> 集合場所：${c.meetPlace}</span>` : ''}
          </div>
        </div>
        <span class="sb ${c.state}">${stateLabel[c.state]}</span>
        <div class="cc-actions">
          <button class="edit-btn ${editMode ? 'on' : ''}" onclick="toggleEdit('${c.id}')">✎ ${editMode ? '編集を終える' : '手動編集'}</button>
          ${filled < c.need ? `<button class="auto-btn" onclick="autoAssign('${c.id}')">⚡ 自動アサイン</button>` : ''}
          ${stateBtn}
          <a class="open-btn" href="/assign-detail?case=${c.id}">詳細 →</a>
        </div>
      </div>
      ${tagHtml ? `<div class="cc-tags">${tagHtml}</div>` : ''}
      <div class="cc-pos"><span class="badge cat-${c.cat}">${c.cat}</span>${posHtml}</div>
      <div class="cc-fill">
        <div class="fbar"><i class="${barCls}" style="width:${Math.round(ratio*100)}%;"></i></div>
        <span class="fnum">${filled}<span class="need"> / ${c.need}名</span></span>
      </div>
      <div class="cc-cols">
        ${memCol}
        ${candCol}
      </div>`;
    return card;
  }

  // アサインダッシュボード「アサインが必要な案件」などから ?focus=<案件ID> で来たら、
  // その案件カードまでスクロールして一時的に強調する（受け取り側）。
  // 絞り込みで対象が隠れていると見つからないので、先にフィルタを解除してから描画し直す。
  function applyFocus(){
    const id = new URLSearchParams(location.search).get('focus');
    if (!id) return;
    const sf = document.getElementById('stateFilter');
    const mine = document.getElementById('mineOnly');
    if (sf) sf.value = '';
    if (mine) mine.checked = false;
    render();
    const el = document.getElementById('case-' + id);
    if (!el) return;
    el.scrollIntoView({ behavior:'smooth', block:'center' });
    el.style.transition = 'box-shadow .3s';
    el.style.boxShadow = '0 0 0 3px #e8833a, 0 8px 24px rgba(0,0,0,.14)';
    setTimeout(() => { el.style.boxShadow = ''; }, 4000);
  }

  // 初期描画
  render();
  applyFocus();
</script>
@endverbatim
@endpush
