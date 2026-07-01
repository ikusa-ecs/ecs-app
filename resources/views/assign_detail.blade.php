@extends('layouts.app')
@section('title', 'アサイン画面')
@section('h1', 'アサイン（案件詳細）')
@php($active = 'assign_detail')

@push('head')
@verbatim
<style>
    /* アサイン画面モック専用スタイル */

    /* 案件ヘッダー（対象案件の条件） */
    .proj-head {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 12px;
      box-shadow: var(--shadow);
      padding: 16px 20px;
      display: flex;
      align-items: center;
      gap: 22px;
      flex-wrap: wrap;
      margin-bottom: 20px;
    }
    .proj-head .pname { font-size: 18px; font-weight: 700; }
    .proj-head .meta { display: flex; gap: 22px; flex-wrap: wrap; color: var(--muted); font-size: 13px; }
    .proj-head .meta b { color: var(--ink); font-weight: 600; }
    .proj-head .spacer { flex: 1; }

    /* メイン2カラム（提案チーム / 編成サマリ） */
    /* 縦並び：上に「編成サマリ＋チェック警告」、下に「提案チーム」をフル幅（横長）で置く */
    .assign-grid { display: flex; flex-direction: column; gap: 20px; }
    .assign-grid > .panel { order: 2; }       /* 提案チーム（表）を下＝フル幅で横長に */
    .assign-grid > .top-grid { order: 1; }    /* 編成サマリ＋チェック警告を上へ */
    .top-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: start; }
    @media (max-width: 900px) { .top-grid { grid-template-columns: 1fr; } }
    .assign-grid > * { min-width: 0; }   /* 中の表を縮められるように */
    .tbl-scroll { overflow-x: auto; }    /* 表が広いときは表の中だけ横スクロール（画面全体ははみ出さない） */

    /* スコアのバッジ・バー */
    .score { display: inline-flex; align-items: center; gap: 8px; }
    .score .num { font-weight: 700; font-variant-numeric: tabular-nums; width: 26px; text-align: right; }
    .score .bar { width: 64px; }
    .score .bar > span.s-hi  { background: var(--ok); }
    .score .bar > span.s-mid { background: var(--brand); }
    .score .bar > span.s-low { background: var(--warn); }

    /* 区分の小バッジ */
    .lv { font-size: 11.5px; padding: 1px 7px; border-radius: 999px; font-weight: 600; }
    .lv.new  { background: var(--brand-soft); color: var(--brand-dark); }
    .lv.mid  { background: #ece3d4; color: #7a6a58; }
    .lv.vet  { background: var(--ok-soft); color: #15803d; }

    /* ポジションのタグ */
    .pos { font-size: 11.5px; color: var(--muted); }
    /* ポジションの変更プルダウン */
    .pos-edit {
      min-width: 122px; border: 1px solid var(--line); background: #fff;
      border-radius: 8px; padding: 5px 7px; font-family: inherit; font-size: 12px;
      color: var(--ink); cursor: pointer;
    }
    .pos-edit:focus { outline: 2px solid var(--brand-soft); border-color: var(--brand); }

    /* D-6：できるポジション（可否） */
    .can-tags { margin-top: 3px; display: flex; flex-wrap: wrap; gap: 3px; }
    .can-tag { font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 6px;
               background: var(--ok-soft); color: #15803d; white-space: nowrap; }
    /* 現在の担当が「できる役割」に入っていない（例外起用）ときの注意 */
    .pos-warn { color: var(--warn); font-size: 11px; font-weight: 700; margin-left: 5px; cursor: help; white-space: nowrap; }
    /* ポジション絞り込みで非該当の行・チップをうすく */
    .row-dim { opacity: 0.3; }
    /* ポジション絞り込みバー */
    .posfilter { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--muted); flex-wrap: wrap; margin: 2px 0 12px; }
    .posfilter select { border: 1px solid var(--line); background: #fff; border-radius: 8px;
                        padding: 5px 8px; font-family: inherit; font-size: 12.5px; color: var(--ink); cursor: pointer; }
    .posfilter .hit { font-weight: 700; color: #15803d; }

    /* M-9：名簿から追加 */
    .add-roster { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin: 0 0 12px; font-size: 12px; color: var(--muted); }
    .add-roster label { font-weight: 700; color: var(--ink); }
    .add-roster select { border: 1px solid var(--line); background: #fff; border-radius: 8px; padding: 6px 9px;
                         font-family: inherit; font-size: 13px; color: var(--ink); cursor: pointer; min-width: 240px; }

    /* 案件ヘッダー：ディレクター（案件一覧で設定＝表示のみ） */
    .proj-head .director {
      display: inline-flex; align-items: center; gap: 8px;
      background: var(--brand-soft); color: var(--brand-dark);
      border-radius: 999px; padding: 4px 12px; font-size: 13px; font-weight: 700;
    }
    .proj-head .director a { font-size: 11.5px; font-weight: 600; color: var(--brand-dark); text-decoration: underline; }
    .dir-edit {
      border: 1px solid var(--line); background: #fff; color: var(--ink);
      border-radius: 8px; padding: 4px 8px; font-family: inherit; font-size: 13px; font-weight: 700; cursor: pointer;
    }
    .dir-edit:focus { outline: 2px solid var(--brand-soft); border-color: var(--brand); }

    /* 理由ツールチップ（？にカーソルを乗せると表示） */
    .why { position: relative; cursor: help; color: var(--muted); font-size: 12px; }
    .why .tip {
      display: none; position: absolute; right: 0; top: 20px; z-index: 5;
      width: 240px; background: #3a2d20; color: #e7dcc8; font-size: 11.5px; line-height: 1.6;
      border-radius: 8px; padding: 9px 11px; box-shadow: 0 10px 24px rgba(0,0,0,0.3); text-align: left;
    }
    .why:hover .tip { display: block; }
    .why .tip .plus { color: #86efac; } .why .tip .minus { color: #fca5a5; }

    /* 希望充足の小表示 */
    .fill { font-size: 12px; }
    .fill.ok { color: var(--ok); } .fill.warn { color: var(--warn); } .fill.bad { color: var(--danger); }
    /* 月間アサイン上限（過重労働防止・一律20件）バッジ */
    .capb { font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 999px; white-space: nowrap; }
    .capb.over { background: var(--danger-soft); color: #b91c1c; }
    .capb.near { background: #fdecd2; color: #b45309; }

    /* 編成サマリの数値行 */
    .sumrow { display: flex; justify-content: space-between; align-items: center; padding: 9px 0; border-bottom: 1px solid var(--line); font-size: 13.5px; }
    .sumrow:last-child { border-bottom: none; }
    .sumrow .k { color: var(--muted); }
    .sumrow .v { font-weight: 600; }
    .ratio-bar { display: flex; height: 12px; border-radius: 999px; overflow: hidden; width: 130px; }
    .ratio-bar i { display: block; }
    .ratio-bar .r-new { background: #93c5fd; } .ratio-bar .r-mid { background: #cbb89c; } .ratio-bar .r-vet { background: #86efac; }

    /* 下部アクションバー */
    .action-bar {
      position: sticky; bottom: 0;
      background: var(--panel); border: 1px solid var(--line); border-radius: 12px;
      box-shadow: 0 -2px 12px rgba(16,24,40,0.06);
      padding: 14px 20px; margin-top: 20px;
      display: flex; align-items: center; gap: 12px;
    }
    .action-bar .spacer { flex: 1; }
    .action-bar .need { font-size: 13px; color: var(--muted); }
    .action-bar .need b { color: var(--ok); }
    .btn.lg { padding: 11px 22px; font-size: 15px; }

    /* チェックボックス見た目 */
    table.tbl td.chk { width: 34px; text-align: center; }
    table.tbl input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--brand); cursor: pointer; }

    /* 代替候補のチップ */
    .pool { display: flex; flex-wrap: wrap; gap: 8px; }
    .pool .chip {
      display: inline-flex; align-items: center; gap: 8px;
      border: 1px solid var(--line); border-radius: 999px; padding: 6px 12px; font-size: 13px; background: #fff;
    }
    .pool .chip .add { color: var(--brand); font-weight: 700; cursor: pointer; }

    /* 確定後のモーダル風 */
    .modal-bg { display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.55); align-items: center; justify-content: center; z-index: 50; }
    .modal-bg.show { display: flex; }
    .modal { background: #fff; border-radius: 14px; padding: 26px 28px; width: 380px; max-width: 92vw; text-align: center; box-shadow: 0 24px 60px rgba(0,0,0,0.4); }
    .modal h3 { margin: 0 0 6px; font-size: 18px; }
    .modal p { color: var(--muted); font-size: 13.5px; margin: 0 0 18px; }

    /* スタッフへの公開（公開スイッチ） */
    .publish-bar {
      display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
      border-radius: 12px; padding: 14px 20px; margin-bottom: 20px;
      border: 1px solid var(--line);
    }
    .publish-bar.off { background: var(--warn-soft); border-color: #f6d9a7; }
    .publish-bar.on  { background: var(--ok-soft);   border-color: #bbe3c6; }
    .publish-bar .pb-main { flex: 1; min-width: 240px; }
    .publish-bar .pb-state { font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .publish-bar .pb-state .dot { width: 11px; height: 11px; border-radius: 999px; display: inline-block; }
    .publish-bar.off .pb-state { color: #b45309; } .publish-bar.off .pb-state .dot { background: #d97706; }
    .publish-bar.on  .pb-state { color: #15803d; } .publish-bar.on  .pb-state .dot { background: #16a34a; }
    .publish-bar .pb-desc { font-size: 12.5px; color: var(--ink); margin-top: 5px; line-height: 1.6; }
    .pub-btn {
      border: none; border-radius: 10px; padding: 11px 20px; font-size: 14px; font-weight: 700;
      cursor: pointer; font-family: inherit; white-space: nowrap;
    }
    .pub-btn.go   { background: var(--brand); color: #fff; }
    .pub-btn.go:active { background: var(--brand-dark); }
    .pub-btn.undo { background: #fff; color: #15803d; border: 1px solid #bbe3c6; }
    /* 確定前＝公開できない（ボタンはグレーで押せない） */
    .pub-btn.locked { background: #e7ded2; color: #9b8c79; cursor: not-allowed; }
    .publish-bar.locked { background: #f1ece3; border-color: var(--line); }
    .publish-bar.locked .pb-state { color: #8a7a66; }
    .publish-bar.locked .pb-state .dot { background: #b8a890; }
</style>
@endverbatim
@endpush

@section('content')
@verbatim
      <div class="mock-note">これは見た目確認用のモックです。スコア・理由・人数はすべて仮の見本で、実際の計算はしていません。</div>

      <!-- 提案チームがサンプルのときの注記（水合戦以外で表示） -->
      <div class="mock-note" id="sampleNote" style="display:none; background:var(--warn-soft); border-color:#f6d9a7; color:#b45309;">
        <b id="sampleName">この案件</b> の詳細を開いています。<b>上のヘッダー（案件名・日程・必要人数・ディレクター・同日の他案件）はこの案件のものに切り替わっています。</b>
        ただし、下の「提案チーム」の名簿は水合戦のサンプルのままです（他案件の提案名簿はモック未作成のため）。動線（カード→詳細→公開）の確認用としてご覧ください。
      </div>

      <!-- 案件ヘッダー -->
      <div class="proj-head">
        <div>
          <div class="pname"><span id="phName">〇〇社 水合戦</span> <span class="badge cat-体力" id="phCat">体力</span></div>
          <div class="meta" style="margin-top:6px;">
            <span>日程：<b id="phDate">7/20（日）</b></span>
            <span>集合：<b id="phCall">8:00</b></span>
            <span>場所：<b id="phPlace">〇〇公園（屋外）</b></span>
            <span>コンテンツ：<b id="phContent">水合戦</b></span>
            <span>必要人数：<b id="phNeed">16名</b></span>
          </div>
          <div class="meta" style="margin-top:8px;">
            <span class="director">ディレクター(D)：
              <select id="dirSelect" class="dir-edit" onchange="changeDirector(this.value)">
                <option value="未定">未定</option>
                <option value="田中">田中</option>
                <option value="鈴木" selected>鈴木</option>
                <option value="佐藤">佐藤</option>
                <option value="高橋">高橋</option>
                <option value="山本">山本</option>
              </select>
            </span>
            <span class="muted" style="font-size:11.5px;">※Dは案件一覧の画面とアサイン画面のどちらからでも選べます（共通）。</span>
          </div>
          <div class="meta" style="margin-top:8px;" id="phSamedayRow">
            <span style="color:var(--muted);">同日の他案件：</span>
            <span id="phSameday"></span>
            <span class="muted" style="font-size:11.5px;">※同じ日。すでに割り当てた人は、こちらの候補から自動で外れます（重複防止／予定）。</span>
          </div>
        </div>
        <div class="spacer"></div>
        <button class="btn" onclick="alert('モックのため、提案の再計算は行いません。')">⟳ 候補を再提案</button>
      </div>

      <!-- スタッフへの公開（公開スイッチ）＝アサイン画面とスタッフ画面をつなぐ背骨 -->
      <div class="publish-bar off" id="publishBar">
        <div class="pb-main">
          <div class="pb-state" id="pbState"><span class="dot"></span>非公開（調整中）</div>
          <div class="pb-desc" id="pbDesc">調整中はスタッフの画面に表示されません。メンバーが固まり、LINEグループに招待して「これで確定」となったら、右のボタンでスタッフに公開してください。</div>
        </div>
        <button class="pub-btn go" id="pubBtn" onclick="togglePublish()">スタッフに公開する →</button>
      </div>

      <div class="assign-grid">

        <!-- ===== 左：提案チーム ===== -->
        <div class="panel">
          <div class="panel-head">
            <h2>提案チーム（<span id="teamCount">16</span>名）</h2>
            <div class="spacer"></div>
            <span class="muted" style="font-size:12px;">スコア順／チェックを外すと外せます</span>
          </div>

          <!-- D-6：できるポジションでしぼり込み -->
          <div class="posfilter">
            <span>ポジションでしぼる：</span>
            <select id="posFilter" onchange="render()">
              <option value="">すべて表示</option>
              <option value="D">D（ディレクター）ができる人</option>
              <option value="OP">OP（音響）ができる人</option>
              <option value="MC">MC（司会進行）ができる人</option>
              <option value="FC">FC（巡回ファシリ）ができる人</option>
              <option value="CK">CK（チェッカー）ができる人</option>
              <option value="軍師">軍師・サポーターができる人</option>
              <option value="受付">受付ができる人</option>
            </select>
            <span id="posFilterHit"></span>
          </div>

          <div class="tbl-scroll">
          <table class="tbl">
            <thead>
              <tr>
                <th class="chk">☑</th>
                <th>スタッフ</th>
                <th>区分</th>
                <th>ポジション</th>
                <th class="num">稼働率</th>
                <th>希望充足</th>
                <th class="num">スコア</th>
                <th class="right">理由</th>
              </tr>
            </thead>
            <tbody id="teamBody">
              <!-- 行はJSで生成 -->
            </tbody>
          </table>
          </div>
        </div>

        <!-- ===== 上：編成サマリ＋チェック警告（横並び。CSSで提案チームの上に表示） ===== -->
        <div class="top-grid">
          <div class="panel">
            <div class="panel-head"><h2>編成サマリ</h2></div>

            <div class="sumrow">
              <span class="k">人数</span>
              <span class="v"><span id="sumCount">16</span> / <span id="sumNeed">16</span>名 <span id="countFill" class="fill ok">充足</span></span>
            </div>
            <div class="sumrow">
              <span class="k">新人 : 中堅 : ベテラン</span>
              <span class="v" style="display:flex; align-items:center; gap:10px;">
                <span id="ratioText">4 : 7 : 5</span>
                <span class="ratio-bar" id="ratioBar">
                  <i class="r-new" style="width:32px;"></i><i class="r-mid" style="width:56px;"></i><i class="r-vet" style="width:42px;"></i>
                </span>
              </span>
            </div>
            <div class="sumrow">
              <span class="k">男女比</span>
              <span class="v">男 9 : 女 7</span>
            </div>
            <div class="sumrow">
              <span class="k">平均稼働率</span>
              <span class="v" id="avgRate">62%</span>
            </div>
            <div class="sumrow">
              <span class="k">希望充足</span>
              <span class="v fill ok">おおむねOK</span>
            </div>
            <div class="sumrow">
              <span class="k">フォロー役</span>
              <span class="v fill ok">✓ 2名含む</span>
            </div>
            <div class="sumrow">
              <span class="k">合計スコア</span>
              <span class="v" id="sumScore">1,021</span>
            </div>
          </div>

          <!-- 警告 -->
          <div class="panel">
            <div class="panel-head"><h2>チェック・警告</h2></div>
            <div class="alert warn"><span class="ico">⚠</span><div><strong>鈴木 美咲</strong>（中堅）は今月の希望がまだ <strong>0件</strong>。0件回避のため優先的に組み込み済み。</div></div>
            <div class="alert ok"><span class="ico">✓</span><div>新人のみの編成ではありません（ベテラン5名・フォロー役2名）。</div></div>
            <div class="alert ok"><span class="ico">✓</span><div>NGペアの同席：なし。</div></div>
            <div class="alert ok"><span class="ico">✓</span><div>連勤上限の超過：なし。</div></div>
            <div class="alert ok"><span class="ico">✓</span><div>新人のポジションはFC／受付／CKに収まっています。</div></div>
          </div>
        </div>

      </div>

      <!-- ===== 代替候補（差し替え用） ===== -->
      <div class="panel" style="margin-top:20px;">
        <div class="panel-head">
          <h2>代替候補（<span id="poolCount">0</span>名）</h2>
          <div class="spacer"></div>
          <span class="muted" style="font-size:12px;">「＋追加」でチームに入れられます／チームから外した人もここに戻ります</span>
        </div>

        <!-- M-9：名簿から選んで追加（社員・スタッフ／提案チームにいる人は除外＝重複防止／できる役割は名簿から自動） -->
        <div class="add-roster">
          <label for="addRoster">名簿から追加：</label>
          <select id="addRoster" onchange="addFromRoster(this.value)">
            <option value="">― 名簿から選ぶ（社員・スタッフ）―</option>
          </select>
          <span>LINEで「入れる」と言われたスタッフや、案件に入る社員をここから追加できます</span>
        </div>

        <div class="pool" id="poolBody">
          <!-- チップはJSで生成 -->
        </div>
      </div>

      <!-- ===== 下部アクションバー ===== -->
      <div class="action-bar">
        <div class="spacer"></div>
        <span class="need">選択 <b id="selCount">16</b> / <span id="needCount">16</span>名</span>
        <button class="btn lg primary" onclick="openConfirm()">確定してCSV出力</button>
      </div>

    <!-- 確定確認モーダル -->
    <div class="modal-bg" id="confirmModal">
      <div class="modal">
        <h3 id="modalTitle">この内容で確定しますか？</h3>
        <p id="modalText">16名のアサインを確定し、確定後にCSVをダウンロードできます。（モックのため実際には保存されません）</p>
        <div style="display:flex; gap:10px; justify-content:center;">
          <button class="btn" onclick="closeConfirm()">キャンセル</button>
          <button class="btn primary" id="modalOk" onclick="doConfirm()">確定する</button>
        </div>
      </div>
    </div>
@endverbatim
@endsection

@push('scripts')
<script src="/ecs/data/cases.js"></script>
<script src="/ecs/data/people.js"></script>
@verbatim
<script>
  // ===== 設定 =====
  let NEED = 16; // この案件の必要人数（開いた案件に合わせて切り替え）

  // ===== 案件データ（共通リスト data/cases.js から作る。ヘッダー表示用）=====
  // detail:true … 提案チームの名簿まで作り込んである案件（今は水合戦のみ）
  const CASES = {};
  ECS_CASES.forEach(function(c){
    CASES[c.id] = {
      name: c.name, client: c.client, cat: c.cat, need: c.need, off: c.off, dir: c.dir,
      call: c.meet, place: c.placeShort || c.place, content: c.content,
      detail: (c.id === 'mizu')   // 名簿まで作り込んであるのは今は水合戦のみ
    };
  });
  const DIR_OPTS = ['未定','田中','鈴木','佐藤','高橋','山本'];

  // URLから案件IDを取得（例：assign_detail.html?case=mizu）。無ければ水合戦。
  function getCaseId(){
    try {
      const id = new URLSearchParams(location.search).get('case');
      return (id && CASES[id]) ? id : 'mizu';
    } catch(e){ return 'mizu'; }
  }
  const CASE_ID = getCaseId();
  const CUR = CASES[CASE_ID];

  // ディレクター（案件一覧とアサイン画面で共通の想定。モックは画面の値だけ更新）
  let currentDirector = '鈴木';

  // 日付ユーティリティ（ボードと同じ＝今日からoff日後）
  const DOW = ['日','月','火','水','木','金','土'];
  function caseDateText(off){
    const x = new Date(); x.setHours(0,0,0,0); x.setDate(x.getDate() + off);
    const dy = x.getDay();
    return `${x.getMonth()+1}/${x.getDate()}（${DOW[dy]}）`;
  }

  // ===== ヘッダーを開いた案件に合わせて差し替え =====
  function applyCase(){
    NEED = CUR.need;

    document.getElementById('phName').textContent = CUR.name;
    const catEl = document.getElementById('phCat');
    catEl.textContent = CUR.cat;
    catEl.className = 'badge cat-' + CUR.cat;
    document.getElementById('phDate').textContent = caseDateText(CUR.off);
    document.getElementById('phNeed').textContent = CUR.need + '名';
    document.getElementById('sumNeed').textContent  = CUR.need;
    document.getElementById('needCount').textContent = CUR.need;

    // 集合・場所・コンテンツは水合戦のみ具体値。他案件は「—（モック）」
    document.getElementById('phCall').textContent    = CUR.call    || '—';
    document.getElementById('phPlace').textContent   = CUR.place   || '—（モック）';
    document.getElementById('phContent').textContent = CUR.content || '—';

    // ディレクター：候補にあれば選択、無ければ未定
    const dirSel = document.getElementById('dirSelect');
    currentDirector = DIR_OPTS.includes(CUR.dir) ? CUR.dir : '未定';
    dirSel.value = currentDirector;

    // 同日の他案件：CASESから同じoffのものを集めてリンク化
    const sameday = Object.keys(CASES)
      .filter(id => id !== CASE_ID && CASES[id].off === CUR.off)
      .map(id => CASES[id]);
    const sd = document.getElementById('phSameday');
    if (sameday.length === 0) {
      document.getElementById('phSamedayRow').style.display = 'none';
    } else {
      sd.innerHTML = sameday.map(o =>
        `<a href="/assign-detail?case=${Object.keys(CASES).find(k=>CASES[k]===o)}" class="badge cat-${o.cat}" style="text-decoration:none; margin-right:6px;">${o.name}（${o.need}名）→</a>`
      ).join('');
    }

    // タイトル類
    document.title = 'ECS アサイン｜' + CUR.name;
    document.querySelector('.topbar h1').textContent = 'アサイン（' + CUR.name + '）';

    // 提案チームがサンプルのままの案件は注記を出す
    if (!CUR.detail) {
      document.getElementById('sampleNote').style.display = '';
      document.getElementById('sampleName').textContent = CUR.name;
    }
  }
  applyCase();

  // ===== 提案チームの仮データ（体力現場・水合戦）=====
  // lv: new=新人 / mid=中堅 / vet=ベテラン
  // in:true=提案チームに採用中 / false=代替候補プールにいる
  const roster = [
    { id:'S-001', name:'高橋 由依', lv:'vet', pos:'D（ディレクター）',     rate:58, fill:'ok',  fillTxt:'4/6', score:82, in:true, reason:[['+', 'ベテラン・現場リーダー適性'],['+','クライアント評価が高い'],['+','イレギュラー対応可']] },
    { id:'S-007', name:'伊藤 健',   lv:'vet', pos:'OP（音響）',           rate:55, fill:'ok',  fillTxt:'5/7', score:74, in:true, reason:[['+','音響の得意ポジション'],['+','自社専属'],['-','連勤気味で小さく減点']] },
    { id:'S-003', name:'渡辺 さくら',lv:'vet', pos:'MC（司会進行）',        rate:60, fill:'ok',  fillTxt:'3/5', score:71, in:true, reason:[['+','リピート案件で前回と同じMC'],['+','盛り上げ系コンテンツと相性良']] },
    { id:'S-014', name:'鈴木 美咲', lv:'mid', pos:'軍師・サポーター',       rate:40, fill:'bad', fillTxt:'0/8', score:69, in:true, reason:[['+','今月の希望が0件→0件回避で優先'],['+','新人フォローができる']] },
    { id:'S-021', name:'山田 涼',   lv:'mid', pos:'FC（巡回ファシリ）',     rate:63, fill:'warn',fillTxt:'2/9', score:66, in:true, reason:[['+','希望充足30%未達で加点'],['+','体力現場の経験豊富']] },
    { id:'S-009', name:'松本 美優', lv:'mid', pos:'FC（巡回ファシリ）',     rate:57, fill:'ok',  fillTxt:'4/7', score:64, in:true, reason:[['+','現場の空気を良くする'],['+','受付・FCの対応が丁寧']] },
    { id:'S-005', name:'井上 大輝', lv:'mid', pos:'FC（巡回ファシリ）',     rate:61, fill:'ok',  fillTxt:'5/8', score:63, in:true, reason:[['+','自分で考えて動ける'],['+','体力バランスに貢献']] },
    { id:'S-018', name:'木村 拓海', lv:'mid', pos:'CK（チェッカー）',       rate:54, fill:'ok',  fillTxt:'3/6', score:61, in:true, reason:[['+','チェック業務が正確'],['-','PC操作がやや苦手で減点']] },
    { id:'S-011', name:'林 美月',   lv:'mid', pos:'受付',                 rate:52, fill:'ok',  fillTxt:'4/6', score:60, in:true, reason:[['+','受付対応が丁寧'],['+','クライアント評価が高い']] },
    { id:'S-027', name:'清水 陽',   lv:'vet', pos:'FC（巡回ファシリ・フォロー役）', rate:59, fill:'ok', fillTxt:'5/9', score:60, in:true, reason:[['+','新人フォローができる（フォロー役）'],['+','自社専属']] },
    { id:'S-030', name:'森 結菜',   lv:'vet', pos:'FC（巡回ファシリ・フォロー役）', rate:56, fill:'ok', fillTxt:'4/7', score:59, in:true, reason:[['+','新人フォローができる（フォロー役）'],['+','安定感がある']] },
    { id:'S-032', name:'佐藤 健太', lv:'new', pos:'FC（巡回ファシリ）',     rate:30, fill:'bad', fillTxt:'0/10',score:58, in:true, reason:[['+','希望0件回避で強く優先'],['+','育成現場で経験を積ませる']] },
    { id:'S-035', name:'池田 莉子', lv:'new', pos:'受付',                 rate:33, fill:'warn',fillTxt:'1/6', score:55, in:true, reason:[['+','新人を補助ポジションで起用'],['+','フォロー役とセット']] },
    { id:'S-038', name:'橋本 颯',   lv:'new', pos:'CK（チェッカー）',       rate:28, fill:'warn',fillTxt:'1/5', score:53, in:true, reason:[['+','新人を補助ポジションで起用'],['+','育成バランスに貢献']] },
    { id:'S-041', name:'石川 葵',   lv:'new', pos:'受付',                 rate:25, fill:'bad', fillTxt:'0/4', score:52, in:true, reason:[['+','希望0件回避で優先'],['+','フォロー役とセット']] },
    { id:'S-024', name:'近藤 樹',   lv:'mid', pos:'FC（巡回ファシリ）',     rate:64, fill:'ok',  fillTxt:'6/8', score:51, in:true, reason:[['+','体力バランス（男性）に貢献'],['-','稼働率が高めで小さく減点']] },
    // ↓ 代替候補（最初はプールにいる）
    { id:'S-050', name:'山本 翔太', lv:'vet', pos:'FC（巡回ファシリ）',     rate:48, fill:'ok',  fillTxt:'5/8', score:62, in:false, reason:[['+','ベテラン・安定感'],['+','体力現場の経験豊富']] },
    { id:'S-051', name:'中村 彩',   lv:'mid', pos:'受付',                 rate:50, fill:'ok',  fillTxt:'4/7', score:55, in:false, reason:[['+','受付対応が丁寧'],['+','クライアント評価が高い']] },
    { id:'S-052', name:'小林 蓮',   lv:'new', pos:'FC（巡回ファシリ）',     rate:31, fill:'warn',fillTxt:'1/6', score:48, in:false, reason:[['+','新人を補助ポジションで起用'],['+','育成現場で経験を積ませる']] },
    { id:'S-053', name:'加藤 結衣', lv:'mid', pos:'CK（チェッカー）',       rate:53, fill:'ok',  fillTxt:'3/6', score:51, in:false, reason:[['+','チェック業務が正確'],['+','安定感がある']] },
    { id:'S-054', name:'吉田 大和', lv:'vet', pos:'OP（音響）',           rate:46, fill:'ok',  fillTxt:'4/7', score:59, in:false, reason:[['+','音響の得意ポジション'],['+','自社専属']] },
  ];

  const lvLabel = { new:'新人', mid:'中堅', vet:'ベテラン' };
  function scoreClass(s){ return s >= 70 ? 's-hi' : (s >= 55 ? 's-mid' : 's-low'); }

  // ===== 月間アサイン上限（過重労働防止・一律20件／設計書11章F・実装仕様書8章）=====
  // この画面は1案件ぶん。今月の件数＝(ボード外で既にアサイン済みの下敷き)＋(この案件のチームに入っていれば+1)。
  const MONTH_CAP = 20;
  const MONTH_BASE = { '高橋 由依':19, '伊藤 健':18, '渡辺 さくら':17, '清水 陽':16, '松本 美優':15 };
  function nameSeed(s){ let n = 0; for (const ch of s) n += ch.charCodeAt(0); return n; }
  function baseCountOf(name){ return (name in MONTH_BASE) ? MONTH_BASE[name] : nameSeed(name) % 13; }
  function monthCountOf(p){ return baseCountOf(p.name) + (p.in ? 1 : 0); }
  function capBadge(p){
    const n = monthCountOf(p);
    if (n >= MONTH_CAP)     return ` <span class="capb over" title="今月のアサインが上限(${MONTH_CAP}件)に達しています">今月${n}/${MONTH_CAP} 上限</span>`;
    if (n >= MONTH_CAP - 2) return ` <span class="capb near" title="今月のアサインが上限(${MONTH_CAP}件)に近づいています">今月${n}/${MONTH_CAP}</span>`;
    return '';
  }

  // ===== ポジション変更（プルダウン）＝ D-6：できるポジション（可否）を反映 =====
  // 正式7区分。データに付いている細かい表記（例：FC（巡回ファシリ・フォロー役））は
  // 一覧に無ければ先頭に足して、その人の現在値が消えないようにする。
  const POSITIONS = ['D（ディレクター）','OP（音響）','MC（司会進行）','FC（巡回ファシリ）','CK（チェッカー）','軍師・サポーター','受付'];

  // できるポジション（可否）。キー＝D/OP/MC/FC/CK/軍師/受付。
  // 共通名簿 data/people.js の pos:{...}（人ごとの「できる役割」）と同じ考え方。
  // ※本番はDBの1か所で持つ想定。モックではこの画面に id ごとに直接持たせている。
  const CAN = {
    'S-001':['D','MC','FC','CK','軍師','受付'], 'S-007':['D','OP','FC','CK','軍師'],
    'S-003':['MC','FC','CK','受付'],            'S-027':['MC','FC','CK','軍師','受付'],
    'S-009':['FC','CK','受付'],                 'S-005':['FC','CK','軍師','受付'],
    'S-014':['FC','CK','軍師','受付'],          'S-018':['OP','FC','CK'],
    'S-021':['FC','CK','受付'],                 'S-032':['FC','受付'],
    'S-035':['CK','受付'],                      'S-038':['FC','CK'],
    'S-041':['受付'],
    // 名簿(people.js)にまだ無い候補は妥当な仮の可否
    'S-011':['MC','FC','CK','受付'],            'S-030':['FC','CK','軍師','受付'],
    'S-024':['FC','CK','受付'],                 'S-050':['FC','CK','軍師','受付'],
    'S-051':['FC','CK','受付'],                 'S-052':['FC','受付'],
    'S-053':['FC','CK','受付'],                 'S-054':['OP','FC','CK','受付'],
  };
  // 役割の表示ラベル → 短いキー（例：D（ディレクター）→ D）
  function posKey(label){
    const s = String(label);
    if (s.startsWith('D'))  return 'D';
    if (s.startsWith('OP')) return 'OP';
    if (s.startsWith('MC')) return 'MC';
    if (s.startsWith('FC')) return 'FC';
    if (s.startsWith('CK')) return 'CK';
    if (s.startsWith('軍師')) return '軍師';
    if (s.startsWith('受付')) return '受付';
    return s;
  }
  // その人がその役割をできるか
  function canDo(p, label){ return (CAN[p.id] || []).includes(posKey(label)); }
  // 「できる役割」のタグHTML（名前の下に小さく出す）
  function canTagsHtml(p){
    const list = CAN[p.id] || [];
    if (!list.length) return '';
    const tags = list.map(k => `<span class="can-tag">${k}</span>`).join('');
    return `<div class="can-tags" title="この人ができるポジション">${tags}</div>`;
  }

  function posSelect(p){
    const opts = POSITIONS.slice();
    if (!opts.includes(p.pos)) opts.unshift(p.pos);
    // ✓＝できる役割。印の無い役割も選べる（例外的な起用）。
    const o = opts.map(v => `<option value="${v}" ${v === p.pos ? 'selected' : ''}>${canDo(p, v) ? '✓ ' : '　'}${v}</option>`).join('');
    // 現在の担当が「できる役割」に入っていない＝例外起用のとき注意マーク
    const warn = canDo(p, p.pos) ? '' : `<span class="pos-warn" title="この人の「できる役割」に入っていません（例外的な起用）">⚠ 対応外</span>`;
    return `<select class="pos-edit" title="✓＝できる役割です。印の無い役割も選べます（例外起用）" onchange="changePos('${p.id}', this.value)">${o}</select>${warn}`;
  }
  function changePos(id, val){
    const p = roster.find(x => x.id === id);
    if (p) { p.pos = val; render(); }   // モックなので保存せず画面の値だけ更新（⚠表示の更新のため再描画）
  }

  // ===== ディレクター(D)の変更 =====
  // Dは案件一覧の画面とアサイン画面で共通。本番はサーバ側で1つの値を共有する想定。
  // モックでは画面の選択を更新するだけ（案件一覧との自動連動はしていない）。
  // ※currentDirector はスクリプト上部で宣言し、applyCase() で開いた案件の値に設定済み。
  function changeDirector(val){
    currentDirector = val;
  }

  const tbody   = document.getElementById('teamBody');
  const poolBody= document.getElementById('poolBody');

  // ===== 操作（チームに入れる／外す）=====
  function removeFromTeam(id){
    const p = roster.find(x => x.id === id);
    if (p) p.in = false;   // 提案チーム → 代替候補へ
    render();
  }
  function addToTeam(id){
    const p = roster.find(x => x.id === id);
    if (!p) return;
    // 月間アサイン上限（過重労働防止・一律20件）：チームに入れると今月+1
    const after = baseCountOf(p.name) + 1;
    if (after > MONTH_CAP) {
      if (!confirm(p.name + ' さんは今月のアサインが上限の ' + MONTH_CAP + '件 に達しています。\n過重労働防止のための上限を超えます。それでも提案チームに入れますか？')) return;
    }
    p.in = true;    // 代替候補 → 提案チームへ
    render();
  }

  // ===== M-9：名簿（people.js）から選んで追加 =====
  // できる役割キー → プルダウンの正式ラベル
  const KEY_TO_LABEL = { D:'D（ディレクター）', OP:'OP（音響）', MC:'MC（司会進行）',
    FC:'FC（巡回ファシリ）', CK:'CK（チェッカー）', '軍師':'軍師・サポーター', '受付':'受付' };
  // people.js の pos:{D:true,...} → できる役割の配列（D/OP/MC/FC/CK/軍師/受付）
  function canFromPeoplePos(pos){
    const map = { D:'D', OP:'OP', MC:'MC', FC:'FC', CK:'CK', GUN:'軍師', UKE:'受付' };
    return Object.keys(map).filter(k => pos && pos[k]).map(k => map[k]);
  }

  // 名簿プルダウンの中身を作る（提案チームに既にいる人は出さない＝重複防止）
  function renderAddRoster(){
    const sel = document.getElementById('addRoster');
    if (!sel || typeof ECS_PEOPLE === 'undefined') return;
    const inTeamId   = new Set(roster.filter(p => p.in).map(p => p.id));
    const inTeamName = new Set(roster.filter(p => p.in).map(p => p.name));
    const optsFor = role => ECS_PEOPLE
      .filter(pp => pp.role === role && !inTeamId.has(pp.id) && !inTeamName.has(pp.name))
      .map(pp => `<option value="${pp.id}">${pp.name}（${ECS_LV_LABEL[ECS_lvOf(pp)]}）</option>`)
      .join('');
    const emp = optsFor('employee'), stf = optsFor('staff');
    sel.innerHTML = '<option value="">― 名簿から選ぶ（社員・スタッフ）―</option>'
      + (emp ? `<optgroup label="社員">${emp}</optgroup>` : '')
      + (stf ? `<optgroup label="スタッフ">${stf}</optgroup>` : '');
  }

  // プルダウンで選んだ人を提案チームに追加
  function addFromRoster(id){
    if (!id) return;
    let p = roster.find(x => x.id === id);
    if (!p) {
      // 名簿にいて、まだこの画面の候補に無い人（社員・未提案スタッフ）＝新しく行を作る
      const pp = ECS_personById(id);
      if (!pp) return;
      const can = (pp.role === 'staff')
        ? canFromPeoplePos(pp.pos)
        : ((pp.dexp && pp.dexp.length ? ['D'] : []).concat(['FC','CK','受付']));   // 社員はD（経験者）＋現場補助
      CAN[id] = can;
      p = {
        id: pp.id, name: pp.name, lv: ECS_lvOf(pp),
        pos: KEY_TO_LABEL[can[0]] || 'FC（巡回ファシリ）',
        rate: (pp.fill != null ? pp.fill : 50),
        fill: 'ok',
        fillTxt: (pp.role === 'staff' && pp.picked != null) ? `${pp.picked}/${pp.applied}` : '—',
        score: 50, in: false, added: true,
        reason: [['+', pp.role === 'employee' ? '担当が名簿から追加（社員）' : '担当が名簿から追加（LINEで参加OK等）']]
      };
      roster.push(p);
    }
    document.getElementById('addRoster').value = '';
    addToTeam(id);   // 月間上限チェック＋提案チームへ＋再描画
  }

  // ===== 画面を描き直す =====
  function render(){
    // D-6：ポジション絞り込みの選択値（''＝すべて）と該当人数カウンタ
    const pf = (document.getElementById('posFilter') || {}).value || '';
    let hit = 0;

    // --- 提案チーム（スコア順）---
    const inTeam = roster.filter(p => p.in).sort((a,b) => b.score - a.score);
    tbody.innerHTML = '';
    inTeam.forEach(p => {
      const tr = document.createElement('tr');
      const match = !pf || canDo(p, pf);        // 絞り込み中にこの役割ができるか
      if (pf && !match) tr.classList.add('row-dim');
      if (pf && match) hit++;
      const reasonHtml = p.reason.map(r =>
        `<span class="${r[0]==='+'?'plus':'minus'}">${r[0]==='+'?'＋':'－'} ${r[1]}</span>`
      ).join('<br>');
      tr.innerHTML = `
        <td class="chk"><input type="checkbox" checked title="外すと代替候補に戻ります" onchange="removeFromTeam('${p.id}')"></td>
        <td><strong>${p.name}</strong><br><span class="muted" style="font-size:11.5px;">${p.id}</span>${canTagsHtml(p)}</td>
        <td><span class="lv ${p.lv}">${lvLabel[p.lv]}</span></td>
        <td>${posSelect(p)}</td>
        <td class="num">${p.rate}%${capBadge(p)}</td>
        <td><span class="fill ${p.fill}">${p.fillTxt}</span></td>
        <td class="num">
          <span class="score">
            <span class="bar"><span class="${scoreClass(p.score)}" style="width:${p.score}%;"></span></span>
            <span class="num">${p.score}</span>
          </span>
        </td>
        <td class="right">
          <span class="why">？<span class="tip">${reasonHtml}</span></span>
        </td>`;
      tbody.appendChild(tr);
    });

    // --- 代替候補（スコア順）---
    const inPool = roster.filter(p => !p.in).sort((a,b) => b.score - a.score);
    poolBody.innerHTML = '';
    if (inPool.length === 0) {
      poolBody.innerHTML = '<span class="muted" style="font-size:13px;">代替候補はいません。</span>';
    } else {
      inPool.forEach(p => {
        const chip = document.createElement('span');
        chip.className = 'chip';
        const match = !pf || canDo(p, pf);      // 絞り込み中にこの役割ができるか
        if (pf && !match) chip.classList.add('row-dim');
        if (pf && match) hit++;
        chip.innerHTML = `${p.name} <span class="lv ${p.lv}">${lvLabel[p.lv]}</span>${capBadge(p)} <span class="muted">${p.score}</span> <span class="add" title="提案チームに入れます" onclick="addToTeam('${p.id}')">＋追加</span>`;
        poolBody.appendChild(chip);
      });
    }

    // D-6：絞り込み中は「該当◯名」を表示（うすい行＝その役割は対応外）
    const hitEl = document.getElementById('posFilterHit');
    if (hitEl) hitEl.innerHTML = pf ? `<span class="hit">該当 ${hit}名</span>　（うすい行＝この役割は対応外）` : '';

    // M-9：名簿から追加プルダウンを最新化（提案チームにいる人を除外）
    renderAddRoster();

    // --- 数値の更新 ---
    const n = inTeam.length;
    document.getElementById('teamCount').textContent = n;
    document.getElementById('sumCount').textContent  = n;
    document.getElementById('selCount').textContent  = n;
    document.getElementById('poolCount').textContent = inPool.length;

    // 充足の表示（人数）
    const fillEl = document.getElementById('countFill');
    if (fillEl) {
      if (n === NEED)      { fillEl.textContent = '充足';   fillEl.className = 'fill ok'; }
      else if (n < NEED)   { fillEl.textContent = `${NEED-n}名不足`; fillEl.className = 'fill bad'; }
      else                 { fillEl.textContent = `${n-NEED}名超過`; fillEl.className = 'fill warn'; }
    }

    // 新人:中堅:ベテラン の内訳
    const cNew = inTeam.filter(p=>p.lv==='new').length;
    const cMid = inTeam.filter(p=>p.lv==='mid').length;
    const cVet = inTeam.filter(p=>p.lv==='vet').length;
    const ratioEl = document.getElementById('ratioText');
    if (ratioEl) ratioEl.textContent = `${cNew} : ${cMid} : ${cVet}`;
    const rb = document.getElementById('ratioBar');
    if (rb) rb.innerHTML =
      `<i class="r-new" style="width:${cNew*8}px;"></i><i class="r-mid" style="width:${cMid*8}px;"></i><i class="r-vet" style="width:${cVet*8}px;"></i>`;

    // 平均稼働率・合計スコア
    const avg = n ? Math.round(inTeam.reduce((s,p)=>s+p.rate,0)/n) : 0;
    const sum = inTeam.reduce((s,p)=>s+p.score,0);
    const avgEl = document.getElementById('avgRate'); if (avgEl) avgEl.textContent = `${avg}%`;
    const sumEl = document.getElementById('sumScore'); if (sumEl) sumEl.textContent = sum.toLocaleString();
  }

  // 初回描画
  render();

  // ===== スタッフへの公開（公開スイッチ）=====
  // この案件を識別するキー。スタッフ画面 staff_portal.html でも同じキーを読むので、
  // 同じブラウザで両方を開くと「公開ON→スタッフ画面に表示／OFF→非表示」が連動します。
  // 案件ごとに公開状態を分ける。水合戦だけは既存キーのまま＝スタッフ画面 staff_portal.html と連動を維持。
  const PUBLISH_KEY = (CASE_ID === 'mizu') ? 'ecs_publish_mizu0720' : 'ecs_publish_' + CASE_ID;
  const CONFIRM_KEY = 'ecs_confirmed_' + CASE_ID;
  function isPublished(){
    try { return localStorage.getItem(PUBLISH_KEY) === '1'; } catch(e){ return false; }
  }
  function setPublished(v){
    try { localStorage.setItem(PUBLISH_KEY, v ? '1' : '0'); } catch(e){}
  }
  // 公開する前に「確定」が必要。確定済み or すでに公開済みなら確定済みとみなす。
  function isConfirmed(){
    try { return localStorage.getItem(CONFIRM_KEY) === '1' || isPublished(); } catch(e){ return isPublished(); }
  }
  function setConfirmed(v){
    try { localStorage.setItem(CONFIRM_KEY, v ? '1' : '0'); } catch(e){}
  }
  function renderPublish(){
    const bar   = document.getElementById('publishBar');
    const state = document.getElementById('pbState');
    const desc  = document.getElementById('pbDesc');
    const btn   = document.getElementById('pubBtn');
    // ① まだ確定していない＝公開できない（確定が先）
    if (!isConfirmed()) {
      bar.className   = 'publish-bar locked';
      state.innerHTML = '<span class="dot"></span>確定前（まだ公開できません）';
      desc.textContent = 'スタッフに公開するには、先に下の「確定してCSV出力」で内容を確定してください。確定すると、このボタンが押せるようになります。';
      btn.textContent = 'スタッフに公開する →';
      btn.className   = 'pub-btn locked';
      btn.disabled    = true;
      return;
    }
    // ② 確定済み（公開中／未公開）
    btn.disabled = false;
    const on = isPublished();
    if (on) {
      bar.className   = 'publish-bar on';
      state.innerHTML = '<span class="dot"></span>公開中';
      desc.textContent = 'この案件はスタッフの「確定アサイン」に表示されています。公開したあとでメンバーを入れ替えても、その変更は自動で反映されます。';
      btn.textContent = '公開を取り消す';
      btn.className   = 'pub-btn undo';
    } else {
      bar.className   = 'publish-bar off';
      state.innerHTML = '<span class="dot"></span>非公開（確定済み）';
      desc.textContent = '内容は確定済みです。LINEグループに招待して「これで確定」となったら、右のボタンでスタッフに公開してください。';
      btn.textContent = 'スタッフに公開する →';
      btn.className   = 'pub-btn go';
    }
  }
  function togglePublish(){
    if (!isConfirmed()) {   // 念のため：確定前は公開させない
      alert('先に「確定してCSV出力」で内容を確定してください。\n確定すると公開できます。');
      return;
    }
    const willPublish = !isPublished();
    if (willPublish) {
      if (!confirm('この案件をスタッフに公開します。\nアサインされたスタッフの「確定アサイン」に、この案件が表示されるようになります。\n（モックのため実際の通知は行いません）\n\n公開してよろしいですか？')) return;
    } else {
      if (!confirm('スタッフへの公開を取り消します。\nスタッフの画面からこの案件が見えなくなります。\n\n取り消してよろしいですか？')) return;
    }
    setPublished(willPublish);
    renderPublish();
    if (willPublish) {
      alert('✓ スタッフに公開しました（モック）。\nstaff_portal.html（スタッフ画面）の「確定アサイン」に表示されます。\n※同じブラウザで開くと連動します。');
    }
  }
  renderPublish();
  // スタッフ公開ボード（assign_publish.html）でこの案件の公開を切り替えたら、
  // この画面に戻ったとき／他タブの変更を受けたときに公開バーを最新化する。
  window.addEventListener('focus', renderPublish);
  window.addEventListener('storage', renderPublish);

  // ===== 確定モーダル =====
  function openConfirm(){
    const n = roster.filter(p=>p.in).length;
    const ok = document.getElementById('modalOk');
    const title = document.getElementById('modalTitle');
    const text = document.getElementById('modalText');
    if (n !== NEED) {
      title.textContent = '人数が一致していません';
      text.textContent = `必要${NEED}名に対して選択は ${n}名です。人数を合わせてから確定してください。（モック）`;
      ok.style.display = 'none';
    } else {
      title.textContent = 'この内容で確定しますか？';
      text.textContent = `${NEED}名のアサインを確定し、確定後にCSVをダウンロードできます。（モックのため実際には保存されません）`;
      ok.style.display = '';
    }
    document.getElementById('confirmModal').classList.add('show');
  }
  function closeConfirm(){ document.getElementById('confirmModal').classList.remove('show'); }
  function doConfirm(){
    closeConfirm();
    setConfirmed(true);
    renderPublish();   // 確定したので公開ボタンを押せる状態にする
    alert('✓ アサインを確定しました（モック）。\nCSVのダウンロードが始まります（モックのため実際には出力されません）。\n\n確定したので、上の「スタッフに公開する」ボタンが押せるようになりました。');
  }
</script>
@endverbatim
@endpush
