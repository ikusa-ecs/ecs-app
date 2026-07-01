@extends('layouts.app')
@section('title', 'スタッフ')
@section('h1', 'スタッフ')
@php($active = 'staff')

@push('head')
{{-- スタッフデータは DB から（Controller が people.js と同じ形に整えて渡す）。
     区分（新人/中堅/ベテラン）計算などの小さなヘルパーは、これまで people.js が
     提供していたものをここで定義する。表示JS本体はそのまま動く。 --}}
<script>
  window.ECS_PEOPLE = @json($people);
  window.ECS_LV_LABEL = { new: '新人', mid: '中堅', vet: 'ベテラン' };
  window.ECS_yearsSince = function (joinDate) {
    if (!joinDate) return 0;
    var d = new Date(joinDate);
    if (isNaN(d)) return 0;
    return (Date.now() - d.getTime()) / (365.25 * 24 * 60 * 60 * 1000);
  };
  window.ECS_lvOf = function (person) {
    var y = window.ECS_yearsSince(person && person.joinDate);
    return y < 1 ? 'new' : (y < 3 ? 'mid' : 'vet');
  };
  window.ECS_staffList = function () {
    return (window.ECS_PEOPLE || []).filter(function (p) { return p.role === 'staff'; });
  };
</script>
@verbatim
  <style>
    /* ===== タブ切替（名簿／稼働状況） ===== */
    .staff-tabs { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
    .staff-tab {
      padding: 9px 18px; border: 1px solid var(--line); border-radius: 999px;
      background: #fff; cursor: pointer; font-size: 14px; color: #6b5544; font-weight: 600;
    }
    .staff-tab.active { background: var(--brand); border-color: var(--brand-dark); color: #fff; }
    .pane { display: none; }
    .pane.show { display: block; }

    /* ===== 共通（名簿・稼働の両方で使う） ===== */
    .filterbar { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 16px; }
    .filterbar input[type="text"], .filterbar select {
      padding: 8px 11px; border: 1px solid var(--line); border-radius: 8px; font-size: 13.5px; font-family: inherit; background: #fff;
    }
    .filterbar input[type="text"] { min-width: 220px; }
    .filterbar .spacer { flex: 1; }
    .count-line { font-size: 12.5px; color: var(--muted); margin-bottom: 10px; }

    /* 区分バッジ */
    .lv { font-size: 11.5px; padding: 1px 8px; border-radius: 999px; font-weight: 600; white-space: nowrap; }
    .lv.new { background: var(--brand-soft); color: var(--brand-dark); }
    .lv.mid { background: #ece3d4; color: #7a6a58; }
    .lv.vet { background: var(--ok-soft); color: #15803d; }

    /* ===== 名簿タブ専用 ===== */
    .postags { display: flex; flex-wrap: wrap; gap: 4px; }
    .ptag { font-size: 11px; padding: 1px 7px; border-radius: 6px; background: #f1e9dc; color: #7a6a58; border: 1px solid var(--line); white-space: nowrap; }
    .ptag.key { background: #e0f2fe; color: #0369a1; border-color: #c3e3f5; } /* D/MC/OP/軍師＝経験者向け */

    .rel { font-size: 12px; }
    .rel .ng { color: var(--danger); }
    .rel .good { color: var(--ok); }

    .row-toggle { cursor: pointer; color: var(--brand); font-weight: 600; font-size: 12.5px; white-space: nowrap; }
    tr.detail-row > td { background: #faf6ee; padding: 0; }
    .detail-box { padding: 16px 18px; }
    .detail-box .dgrid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
    @media (max-width: 900px){ .detail-box .dgrid { grid-template-columns: 1fr; } }
    .detail-box h4 { margin: 0 0 8px; font-size: 13px; }
    .pos-check { display: flex; flex-wrap: wrap; gap: 8px 16px; }
    .pos-check label { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 500; }
    .pos-check input { width: 15px; height: 15px; accent-color: var(--brand); }
    .detail-box textarea { width: 100%; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; font-family: inherit; font-size: 13px; background: #fff; min-height: 64px; }
    .detail-box .trait { display: flex; flex-wrap: wrap; gap: 6px 14px; }
    .detail-box .trait label { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; }
    .detail-box .save-row { margin-top: 14px; display: flex; gap: 10px; align-items: center; }
    .pair-line { font-size: 13px; margin-bottom: 4px; }
    .pair-line b { font-weight: 600; }
    .privacy-note { font-size: 12px; color: var(--muted); background: #f8f3ea; border: 1px dashed var(--line); border-radius: 8px; padding: 8px 12px; margin-top: 14px; }

    /* ===== 稼働状況タブ専用 ===== */
    /* 活性度バッジ */
    .act { font-size: 11.5px; padding: 2px 9px; border-radius: 999px; font-weight: 600; white-space: nowrap; }
    .act.active   { background: var(--ok-soft); color: #15803d; }
    .act.semi     { background: var(--warn-soft); color: #b45309; }
    .act.inactive { background: var(--danger-soft); color: #b91c1c; }

    /* 稼働率・充足率のミニバー */
    .mini { display: flex; align-items: center; gap: 8px; }
    .mini .bar { width: 90px; }
    .mini .pct { font-variant-numeric: tabular-nums; font-size: 12.5px; width: 38px; text-align: right; }

    .renkin.warn { color: var(--warn); font-weight: 600; }
    .renkin.bad  { color: var(--danger); font-weight: 600; }

    /* 選ばれた率が低い人（応募が多いのに選ばれていない）＝赤字で強調 */
    .pickrate.low { color: var(--danger); font-weight: 700; }
    /* ご無沙汰度（最終アサインからの経過日数） */
    .gobusata { font-variant-numeric: tabular-nums; }
    .gobusata.warn { color: var(--warn); font-weight: 600; }
    .gobusata.bad  { color: var(--danger); font-weight: 700; }
  </style>
@endverbatim
@endpush

@section('content')
@verbatim
      <!-- タブ -->
      <div class="staff-tabs">
        <button class="staff-tab active" data-pane="roster" onclick="switchStaffTab('roster')">☷ 名簿</button>
        <button class="staff-tab" data-pane="work" onclick="switchStaffTab('work')">📊 稼働状況</button>
      </div>

      <!-- ===================== 名簿タブ ===================== -->
      <div class="pane show" id="pane-roster">
      <div class="mock-note">氏名・事務所・専属・通算・できるポジション・相性（NG）は、登録済みのスタッフデータ（DB）を表示しています。<br>※「詳細」を開いた中の編集（ポジション可否・人柄メモ・保存ボタンなど）と、本人プロフィール／イベプラの雰囲気メモは、現在まだ保存に対応していません（見本表示です）。</div>

      <div class="panel">
        <div class="filterbar">
          <input type="text" id="kw" placeholder="氏名で検索" oninput="rosterFilter()">
          <select id="fLv" onchange="rosterFilter()">
            <option value="">区分：すべて</option>
            <option value="new">新人</option>
            <option value="mid">中堅</option>
            <option value="vet">ベテラン</option>
          </select>
          <select id="fPos" onchange="rosterFilter()">
            <option value="">できるポジション：すべて</option>
            <option value="OP">OP（オペレーター）</option>
            <option value="MC">MC</option>
            <option value="SP">SP（サポート）</option>
          </select>
          <div class="spacer"></div>
          <button class="btn primary" onclick="alert('モックのため、招待は行いません。')">＋ スタッフを招待</button>
        </div>

        <div class="count-line"><span id="countTxt">0</span> 名を表示中</div>

        <table class="tbl">
          <thead>
            <tr>
              <th>氏名</th>
              <th>区分</th>
              <th>事務所</th>
              <th>専属</th>
              <th class="num">通算</th>
              <th>できるポジション</th>
              <th>相性</th>
              <th class="right"></th>
            </tr>
          </thead>
          <tbody id="tbody"><!-- JSで生成 --></tbody>
        </table>
      </div>

      <div class="privacy-note">
        🔒 この名簿に載せるのは「アサインの判断に必要な最低限の情報」だけです（連絡先・住所・評価コメントなどは載せません）。社員なら全員が同じ内容を閲覧できます（権限での出し分けはしません）。
      </div>
      </div><!-- /pane-roster -->

      <!-- ===================== 稼働状況タブ ===================== -->
      <div class="pane" id="pane-work">
      <div class="mock-note">稼働率・連勤・選ばれた率・ご無沙汰・活性度は、登録済みのアサイン・希望・応募データ（DB）から計算して表示しています（対象月＝2026年7月）。データが無い場合は下の見本値を表示します。</div>

      <!-- 数値カード -->
      <div class="grid cols-4" style="margin-bottom:20px;">
        <div class="stat">
          <div class="label">今月のアサイン総数</div>
          <div class="value" id="cTotal">0</div>
          <div class="sub">2026年7月</div>
        </div>
        <div class="stat">
          <div class="label">平均稼働率</div>
          <div class="value" id="cAvg">0%</div>
          <div class="sub">希望に対するアサインの割合の平均</div>
        </div>
        <div class="stat">
          <div class="label">今月の希望が0件</div>
          <div class="value danger" id="cZero">0</div>
          <div class="sub">0件回避で優先したい人</div>
        </div>
        <div class="stat">
          <div class="label">非アクティブ</div>
          <div class="value warn" id="cInactive">0</div>
          <div class="sub">その月エントリーなし</div>
        </div>
      </div>

      <div class="panel">
        <div class="filterbar">
          <select id="wLv" onchange="workFilter()">
            <option value="">区分：すべて</option>
            <option value="new">新人</option>
            <option value="mid">中堅</option>
            <option value="vet">ベテラン</option>
          </select>
          <select id="fAct" onchange="workFilter()">
            <option value="">活性度：すべて</option>
            <option value="active">アクティブ</option>
            <option value="semi">準アクティブ</option>
            <option value="inactive">非アクティブ</option>
          </select>
          <select id="fSort" onchange="workRender()">
            <option value="rate">並び：稼働率が高い順</option>
            <option value="rateAsc">並び：稼働率が低い順</option>
            <option value="month">並び：今月アサインが多い順</option>
            <option value="pick">並び：選ばれた率が低い順</option>
            <option value="gobusata">並び：ご無沙汰が長い順</option>
          </select>
          <div class="spacer"></div>
          <button class="btn" onclick="alert('モックのため、CSV出力は行いません。')">CSV出力</button>
        </div>

        <div class="count-line"><span id="wCount">0</span> 名を表示中</div>

        <div class="tbl-scroll" style="overflow-x:auto;">
        <table class="tbl">
          <thead>
            <tr>
              <th>氏名</th>
              <th>区分</th>
              <th>活性度</th>
              <th class="num">今月</th>
              <th>稼働率</th>
              <th>選ばれた率</th>
              <th class="num">ご無沙汰</th>
              <th class="num">最大連勤</th>
              <th class="num">通算</th>
            </tr>
          </thead>
          <tbody id="wBody"><!-- JSで生成 --></tbody>
        </table>
        </div>
        <div class="muted" style="font-size:12px; margin-top:10px;">
          稼働率＝今月のアサイン日数 ÷ 本人の希望日数（＝希望充足率と同じ意味なので1列に統合。目安30%を担保）。「今月」の◯/20の20は月上限＝過重労働防止のため全員一律20件（稼働率の分母ではなく上限の目安）。活性度＝その月のエントリー率で判定（月ごと）。<br>
          選ばれた率＝アサイン回数 ÷ エントリー（応募）回数。何回も応募しているのに低い人は新人離脱のサイン（赤字）。ご無沙汰＝最後にアサインされてからの日数。長い人ほど声がかかっていない（14日以上で橙・30日以上で赤）。
        </div>
      </div>

      <!-- 警告リスト -->
      <div class="panel">
        <div class="panel-head"><h2>気にかけたい人</h2></div>
        <div id="warnZero"></div>
        <div id="warnRenkin"></div>
        <div id="warnFill"></div>
        <div id="warnPick"></div>
        <div id="warnGobusata"></div>
      </div>
      </div><!-- /pane-work -->
@endverbatim
@endsection

@push('scripts')
{{-- 稼働状況は DB（assignments＋shift_preferences＋applications＋people）から計算して渡す。 --}}
<script>window.ECS_STATUS = @json($status);</script>
@verbatim
<script>
  // ===== タブ切替 =====
  function switchStaffTab(pane){
    document.querySelectorAll('.staff-tab').forEach(t => t.classList.toggle('active', t.dataset.pane === pane));
    document.getElementById('pane-roster').classList.toggle('show', pane === 'roster');
    document.getElementById('pane-work').classList.toggle('show', pane === 'work');
  }
</script>

<script>
  // ===================== 名簿タブ =====================
  // 社員/スタッフを1つにまとめた共通名簿から、スタッフだけ読み込む。
  // 独立したかたまり（IIFE）にして、稼働状況タブと関数名がぶつからないようにする。
  (function(){
  const staff = ECS_staffList();

  const lvLabel = window.ECS_LV_LABEL;
  // 区分は入社日／登録日からの年数で判定（新人=1年未満／中堅=1〜3年/ベテラン=3年以上）
  function lvOf(p){ return window.ECS_lvOf(p); }
  // スタッフが「できる」として持つポジションは OP / MC / SP の3つに限定。
  // （D はできるスタッフがほぼいない・FC/RP/ET は誰でもやる前提なので「できること」管理の対象外）
  const POS = [
    { k:'OP', label:'OP', key:true },
    { k:'MC', label:'MC', key:true },
    { k:'SP', label:'SP', key:true },
  ];
  const posFull = { OP:'OP（オペレーター）', MC:'MC', SP:'SP（サポート）' };

  // ===== スタッフ設定（staff_portal.html）で本人が入力した内容を、ここに反映する =====
  // このモックでのログイン中の本人＝佐藤 健太（S-032）。
  // 本人だけは「スタッフ設定」で保存した実データ(localStorage)を読み込んで表示する。
  // → スタッフ設定で書き換えてからこの画面を開くと、内容が反映される。本人以外はサンプル表示。
  const ME_ID = 'S-032';
  const PROFILE_STORE = 'ecs_staff_profile';   // staff_portal.html と同じキー

  function readMyProfile(){
    try { const raw = localStorage.getItem(PROFILE_STORE); if (raw) return JSON.parse(raw); } catch(e){}
    return null;
  }

  // 本人以外（またはまだ未保存）のときに出す仮表示の素材
  const APPEAL_POOL  = ['元気な進行が得意です！','どんな現場でも笑顔で対応します。','裏方の段取りが好きです。','子ども向けが得意です。','落ち着いて状況を見られます。'];
  const LIKEC_POOL   = ['運動会・水合戦','謎解き・クイズ大会','縁日・お祭り','チームビルディング','オンライン配信'];
  const DISLIKEC_POOL= ['オンライン配信','長時間の屋外','特になし','大規模アリーナ','早朝集合'];
  const STRONGFREE_POOL = ['盛り上げ役が好きです。','全体を見て動くのが得意。','受付まわりは任せてください。','設営の段取りが速いです。',''];
  const WEAKFREE_POOL   = ['細かい受付業務はやや苦手。','大人数の前の司会は緊張します。','機材操作は勉強中です。','特になし。',''];
  const ATMOS_POOL   = ['明るくて現場が和む。挨拶がしっかりしている。','黙々と正確に動くタイプ。指示は具体的だと安心。','後輩の面倒見がよい。場を仕切れる。','まだ緊張気味だが素直。フォロー役とセットが◎。','ムードメーカー。お客様対応が丁寧。'];
  function seedOf(id){ let s=0; for(const c of id) s=(s*31+c.charCodeAt(0))>>>0; return s; }
  function pick(arr, seed){ return arr[seed % arr.length]; }

  // その人の「本人プロフィール（設定画面の内容）」を返す
  function profileFor(p){
    if (p.id === ME_ID){
      const d = readMyProfile();
      if (d){
        return {
          live: true,
          appeal: d.pfAppeal||'', likeC: d.pfLike||'', dislikeC: d.pfDislike||'',
          strong: d.pfStrongPosFree||'', weak: d.pfWeakPosFree||''
        };
      }
    }
    const s = seedOf(p.id);
    return {
      live: false,
      appeal: pick(APPEAL_POOL, s), likeC: pick(LIKEC_POOL, s), dislikeC: pick(DISLIKEC_POOL, s+1),
      strong: pick(STRONGFREE_POOL, s), weak: pick(WEAKFREE_POOL, s+2)
    };
  }
  // 「イベプラからの雰囲気」（社員＝イベプラが書くメモ）の仮表示
  function atmosFor(p){ return pick(ATMOS_POOL, seedOf(p.id)); }

  const tbody = document.getElementById('tbody');

  function posTagsHtml(p){
    const tags = POS.filter(x => p.pos[x.k]).map(x =>
      `<span class="ptag ${x.key?'key':''}">${x.label}</span>`);
    return `<div class="postags">${tags.join('')}</div>`;
  }
  function relHtml(p){
    if (p.ng.length) return `<div class="rel"><span class="ng">NG：${p.ng.join('、')}</span></div>`;
    return '<span class="muted" style="font-size:12px;">—</span>';
  }

  function render(){
    tbody.innerHTML = '';
    staff.forEach((p, idx) => {
      const tr = document.createElement('tr');
      tr.className = 'main-row';
      tr.dataset.idx = idx;
      tr.innerHTML = `
        <td><strong>${p.name}</strong><br><span class="muted" style="font-size:11.5px;">${p.id}</span></td>
        <td><span class="lv ${lvOf(p)}">${lvLabel[lvOf(p)]}</span></td>
        <td><span class="muted" style="font-size:12.5px;">${p.office || '—'}</span></td>
        <td>${p.exclusive ? '<span class="badge green">専属</span>' : '<span class="muted" style="font-size:12px;">—</span>'}</td>
        <td class="num">${p.total}</td>
        <td>${posTagsHtml(p)}</td>
        <td>${relHtml(p)}</td>
        <td class="right"><span class="row-toggle" onclick="rosterToggle(${idx}, this)">詳細 ▾</span></td>`;
      tbody.appendChild(tr);

      // 詳細行（最初は隠す）
      const dr = document.createElement('tr');
      dr.className = 'detail-row';
      dr.dataset.for = idx;
      dr.style.display = 'none';
      dr.innerHTML = `<td colspan="8">${detailHtml(p, idx)}</td>`;
      tbody.appendChild(dr);
    });
    applyFilter();
  }

  function detailHtml(p, idx){
    const posChecks = POS.map(x =>
      `<label><input type="checkbox" ${p.pos[x.k]?'checked':''} onchange="alert('モックのため保存はしません。')"> ${posFull[x.k]}</label>`
    ).join('');
    const ngStr = p.ng.join('、');
    const prof = profileFor(p);
    const atmos = atmosFor(p);
    const profBadge = prof.live
      ? ' <span class="badge green" style="font-size:11px;">本人入力を反映中</span>'
      : ' <span class="muted" style="font-size:11px;">※サンプル表示</span>';
    return `
      <div class="detail-box">
        <div style="border:1px solid #e4ddd3;border-radius:8px;padding:10px 12px;margin-bottom:12px;background:#fbf8f3;">
          <h4 style="margin:0 0 6px;">本人プロフィール（スタッフ設定の内容）${profBadge}</h4>
          <div style="font-size:13px;line-height:1.9;">
            <div><b>一言アピール：</b>${prof.appeal||'（未入力）'}</div>
            <div><b>好きなコンテンツ：</b>${prof.likeC||'（未入力）'}</div>
            <div><b>苦手なコンテンツ：</b>${prof.dislikeC||'（未入力）'}</div>
            <div><b>得意なポジション：</b>${prof.strong||'（未入力）'}</div>
            <div><b>苦手なポジション：</b>${prof.weak||'（未入力）'}</div>
          </div>
        </div>
        <div class="dgrid">
          <div>
            <h4>ポジション可否（できる現場の役割）</h4>
            <div class="pos-check">${posChecks}</div>

            <h4 style="margin-top:16px;">人柄・育成メモ</h4>
            <div class="trait">
              <label><input type="checkbox" ${p.traits.follow?'checked':''} onchange="alert('モックのため保存はしません。')"> 新人フォローができる</label>
              <label><input type="checkbox" ${p.traits.starter?'checked':''} onchange="alert('モックのため保存はしません。')"> 自分で考えて動ける</label>
              <label><input type="checkbox" ${p.traits.atmos?'checked':''} onchange="alert('モックのため保存はしません。')"> 現場の空気を良くする</label>
            </div>
          </div>
          <div>
            <h4>初回アサイン時のDアンケート（要点）</h4>
            <textarea>${p.dnote}</textarea>

            <h4 style="margin-top:16px;">イベプラからの雰囲気</h4>
            <textarea placeholder="イベプラが現場で感じた雰囲気・印象を記入" onchange="alert('モックのため保存はしません。')">${atmos}</textarea>

            <h4 style="margin-top:16px;">NGペア（同席を避ける組合せ）</h4>
            <div class="pair-line"><b>NGペア：</b>${ngStr || '（なし）'}</div>
            <div class="muted" style="font-size:12px; margin-top:4px;">※NGペアの編集はモックでは省略しています。</div>
          </div>
        </div>
        <div class="save-row">
          <button class="btn primary sm" onclick="alert('モックのため保存はしません。')">保存</button>
          <button class="btn sm" onclick="rosterToggle(${idx})">閉じる</button>
          <span class="muted" style="font-size:12px;">※載せるのは最低限の情報のみ。社員は全員この内容を閲覧できます。</span>
        </div>
      </div>`;
  }

  function toggleDetail(idx, el){
    const dr = tbody.querySelector(`tr.detail-row[data-for="${idx}"]`);
    if (!dr) return;
    const open = dr.style.display === 'none';
    dr.style.display = open ? '' : 'none';
    // 矢印の向き
    const toggle = tbody.querySelector(`tr.main-row[data-idx="${idx}"] .row-toggle`);
    if (toggle) toggle.innerHTML = open ? '詳細 ▴' : '詳細 ▾';
  }

  // 絞り込み
  function applyFilter(){
    const kw  = document.getElementById('kw').value.trim();
    const fLv = document.getElementById('fLv').value;
    const fPos= document.getElementById('fPos').value;
    let shown = 0;
    staff.forEach((p, idx) => {
      const mr = tbody.querySelector(`tr.main-row[data-idx="${idx}"]`);
      const dr = tbody.querySelector(`tr.detail-row[data-for="${idx}"]`);
      const okKw  = !kw  || p.name.includes(kw) || p.id.includes(kw);
      const okLv  = !fLv || lvOf(p) === fLv;
      const okPos = !fPos|| p.pos[fPos];
      const visible = okKw && okLv && okPos;
      mr.style.display = visible ? '' : 'none';
      if (!visible && dr) { dr.style.display = 'none';
        const t = mr.querySelector('.row-toggle'); if (t) t.innerHTML = '詳細 ▾'; }
      if (visible) shown++;
    });
    document.getElementById('countTxt').textContent = shown;
  }

  // 名簿タブの中で、HTML内のクリックから呼ぶ関数だけ外に出す（名前は roster〜 で固有化）
  window.rosterFilter = applyFilter;
  window.rosterToggle = toggleDetail;

  render();
  })();
</script>

<script>
  // ===================== 稼働状況タブ =====================
  // active: 活性度（active=アクティブ / semi=準アクティブ / inactive=非アクティブ）
  // month=今月アサイン数 / cap=月上限（稼働率の分母ではなく上限の目安・一律20） / rate=稼働率(%)=月÷希望日数（希望0件はnull） / renkin=最大連勤 / zeroPref=今月の希望が0件
  // applied=エントリー(応募)回数 / picked=そのうち実際にアサインされた回数（選ばれた率 = picked ÷ applied）
  // lastDays=最後にアサインされてからの経過日数（ご無沙汰度。null=履歴なし）
  (function(){
  const status = (window.ECS_STATUS && window.ECS_STATUS.length) ? window.ECS_STATUS : [
    { id:'S-001', name:'高橋 由依', lv:'vet', active:'active',   month:13, cap:20, fill:72, renkin:3, total:82, zeroPref:false, applied:15, picked:13, lastDays:1  },
    { id:'S-007', name:'伊藤 健',   lv:'vet', active:'active',   month:14, cap:20, fill:78, renkin:4, total:90, zeroPref:false, applied:16, picked:14, lastDays:0  },
    { id:'S-003', name:'渡辺 さくら', lv:'vet', active:'active', month:12, cap:20, fill:60, renkin:3, total:75, zeroPref:false, applied:14, picked:12, lastDays:2  },
    { id:'S-027', name:'清水 陽',   lv:'vet', active:'active',   month:11, cap:20, fill:64, renkin:2, total:70, zeroPref:false, applied:13, picked:11, lastDays:3  },
    { id:'S-009', name:'松本 美優', lv:'mid', active:'active',   month:9,  cap:20, fill:55, renkin:5, total:48, zeroPref:false, applied:12, picked:9,  lastDays:4  },
    { id:'S-005', name:'井上 大輝', lv:'mid', active:'active',   month:8,  cap:20, fill:50, renkin:3, total:44, zeroPref:false, applied:11, picked:8,  lastDays:5  },
    { id:'S-021', name:'山田 涼',   lv:'mid', active:'semi',     month:4,  cap:20, fill:22, renkin:2, total:33, zeroPref:false, applied:9,  picked:4,  lastDays:18 },
    { id:'S-018', name:'木村 拓海', lv:'mid', active:'semi',     month:3,  cap:20, fill:18, renkin:2, total:36, zeroPref:false, applied:8,  picked:3,  lastDays:22 },
    { id:'S-014', name:'鈴木 美咲', lv:'mid', active:'inactive', month:1,  cap:20, fill:0,  renkin:1, total:40, zeroPref:true,  applied:5,  picked:1,  lastDays:41 },
    { id:'S-032', name:'佐藤 健太', lv:'new', active:'inactive', month:0,  cap:20,  fill:0,  renkin:0, total:6,  zeroPref:true,  applied:7,  picked:0,  lastDays:60 },
    { id:'S-035', name:'池田 莉子', lv:'new', active:'semi',     month:2,  cap:20,  fill:33, renkin:1, total:4,  zeroPref:false, applied:9,  picked:2,  lastDays:16 },
    { id:'S-038', name:'橋本 颯',   lv:'new', active:'semi',     month:2,  cap:20,  fill:28, renkin:1, total:3,  zeroPref:false, applied:8,  picked:2,  lastDays:20 },
    { id:'S-041', name:'石川 葵',   lv:'new', active:'inactive', month:0,  cap:20,  fill:0,  renkin:0, total:2,  zeroPref:true,  applied:4,  picked:0,  lastDays:75 },
  ];

  const lvLabel  = { new:'新人', mid:'中堅', vet:'ベテラン' };
  const actLabel = { active:'アクティブ', semi:'準アクティブ', inactive:'非アクティブ' };
  // 区分は通算回数(total)から自動判定（新人=10回まで／中堅=11〜29回／ベテラン=30回以上）
  function lvOf(total){ return total <= 10 ? 'new' : (total < 30 ? 'mid' : 'vet'); }

  // 稼働率＝月アサイン÷希望日数。DBが計算済みなら p.rate を使う（希望0件は null＝「—」）。
  // 旧見本（p.rate が無い行）は従来どおり月÷上限で出すフォールバック。
  function rateOf(p){
    if (p.rate === null) return null;            // DB：希望0件で算出不可
    if (p.rate !== undefined) return p.rate;     // DB：算出済みの稼働率
    return Math.round(p.month / p.cap * 100);    // 旧見本フォールバック
  }
  function barClass(pct){ return pct >= 70 ? 'warn' : (pct >= 30 ? 'ok' : 'danger'); }
  // ↑ 稼働率は高すぎる(70%+)と詰め込みすぎ＝warn、低すぎ(30%未満)＝danger の目安色

  // 選ばれた率＝アサイン回数 ÷ エントリー回数（応募していない人は「—」）
  function pickRateOf(p){ return p.applied > 0 ? Math.round(p.picked / p.applied * 100) : null; }
  // 何回も応募しているのに選ばれた率が低い人ほど赤く（公平性のサイン）
  function pickClass(p){
    const r = pickRateOf(p);
    if (r === null) return 'ok';
    return (r < 30 && p.applied >= 4) ? 'danger' : (r < 50 ? 'warn' : 'ok');
  }
  // ご無沙汰度の色（30日以上=danger / 14日以上=warn）
  function gobusataClass(days){ return days >= 30 ? 'bad' : (days >= 14 ? 'warn' : ''); }

  const tbody = document.getElementById('wBody');

  function render(){
    const sortKey = document.getElementById('fSort').value;
    const arr = status.slice();
    arr.sort((a,b) => {
      if (sortKey === 'rate')    return (rateOf(b) ?? -1) - (rateOf(a) ?? -1);
      if (sortKey === 'rateAsc') return (rateOf(a) ?? 999) - (rateOf(b) ?? 999);
      if (sortKey === 'month')   return b.month - a.month;
      if (sortKey === 'pick')    return (pickRateOf(a) ?? 999) - (pickRateOf(b) ?? 999);
      if (sortKey === 'gobusata')return (b.lastDays ?? -1) - (a.lastDays ?? -1);
      return 0;
    });

    tbody.innerHTML = '';
    arr.forEach(p => {
      const rate = rateOf(p);
      const pickRate = pickRateOf(p);
      const tr = document.createElement('tr');
      tr.dataset.lv = lvOf(p.total);
      tr.dataset.act = p.active;
      const renkinCls = p.renkin >= 5 ? 'bad' : (p.renkin >= 4 ? 'warn' : '');
      tr.innerHTML = `
        <td><strong>${p.name}</strong><br><span class="muted" style="font-size:11.5px;">${p.id}</span></td>
        <td><span class="lv ${lvOf(p.total)}">${lvLabel[lvOf(p.total)]}</span></td>
        <td><span class="act ${p.active}">${actLabel[p.active]}</span></td>
        <td class="num">${p.month} / ${p.cap}</td>
        <td>${rate === null
            ? '<span class="muted" style="font-size:12px;">—</span>'
            : `<div class="mini">
                 <span class="bar" style="flex:0 0 90px;"><span class="${barClass(rate)}" style="width:${Math.min(rate,100)}%;"></span></span>
                 <span class="pct">${rate}%</span>
               </div>`}
        </td>
        <td>${pickRate === null
            ? '<span class="muted" style="font-size:12px;">—</span>'
            : `<div class="mini">
                 <span class="bar" style="flex:0 0 90px;"><span class="${barClass(pickRate)}" style="width:${pickRate}%;"></span></span>
                 <span class="pct pickrate ${pickClass(p) === 'danger' ? 'low' : ''}">${pickRate}%</span>
               </div>
               <span class="muted" style="font-size:11px;">${p.picked}/${p.applied} 件</span>`}
        </td>
        <td class="num">${p.lastDays === null
            ? '<span class="muted">—</span>'
            : `<span class="gobusata ${gobusataClass(p.lastDays)}">${p.lastDays}日</span>`}</td>
        <td class="num"><span class="renkin ${renkinCls}">${p.renkin}</span></td>
        <td class="num">${p.total}</td>`;
      tbody.appendChild(tr);
    });
    applyFilter();
  }

  // 数値カード＆警告リスト
  function renderSummary(){
    const total = status.reduce((s,p) => s + p.month, 0);
    const rated = status.map(rateOf).filter(v => v !== null && v !== undefined);
    const avg = rated.length ? Math.round(rated.reduce((s,v) => s + v, 0) / rated.length) : 0;
    const zero = status.filter(p => p.zeroPref);
    const inactive = status.filter(p => p.active === 'inactive');
    document.getElementById('cTotal').textContent = total;
    document.getElementById('cAvg').textContent = avg + '%';
    document.getElementById('cZero').textContent = zero.length;
    document.getElementById('cInactive').textContent = inactive.length;

    // 希望0件
    const zEl = document.getElementById('warnZero');
    if (zero.length) {
      zEl.innerHTML = `<div class="alert danger"><span class="ico">⚠</span><div><strong>今月の希望が0件：</strong>${zero.map(p=>p.name).join('、')}。<br>0件回避のため、次のアサインで優先的に検討しましょう。</div></div>`;
    } else { zEl.innerHTML = ''; }

    // 連勤注意（4連勤以上）
    const renkin = status.filter(p => p.renkin >= 4);
    const rEl = document.getElementById('warnRenkin');
    if (renkin.length) {
      rEl.innerHTML = `<div class="alert warn"><span class="ico">⚠</span><div><strong>連勤に注意：</strong>${renkin.map(p=>`${p.name}（${p.renkin}連勤）`).join('、')}。<br>夏場の3連勤・通年5連勤超えは避けたいラインです。</div></div>`;
    } else { rEl.innerHTML = ''; }

    // 稼働率が低い（希望を出しているのに30%未満）＝希望に対してアサインが少ない
    const lowRate = status.filter(p => { const r = rateOf(p); return r !== null && r > 0 && r < 30; });
    const fEl = document.getElementById('warnFill');
    if (lowRate.length) {
      fEl.innerHTML = `<div class="alert warn"><span class="ico">▲</span><div><strong>稼働率が30%未満：</strong>${lowRate.map(p=>`${p.name}（${rateOf(p)}%）`).join('、')}。<br>希望を出してくれているのにアサインが少なめです。</div></div>`;
    } else { fEl.innerHTML = ''; }

    // 選ばれた率が低い（4回以上応募して30%未満）＝応募してくれているのに選ばれていない＝離脱リスク
    const lowPick = status.filter(p => p.applied >= 4 && pickRateOf(p) < 30);
    const pEl = document.getElementById('warnPick');
    if (lowPick.length) {
      pEl.innerHTML = `<div class="alert warn"><span class="ico">▲</span><div><strong>応募が多いのに選ばれた率が低い：</strong>${lowPick.map(p=>`${p.name}（${pickRateOf(p)}%・${p.picked}/${p.applied}）`).join('、')}。<br>エントリーしてくれているのに選ばれていません。新人離脱を防ぐため次のアサインで検討を。</div></div>`;
    } else { pEl.innerHTML = ''; }

    // ご無沙汰（最終アサインから30日以上）
    const gobusata = status.slice().filter(p => p.lastDays >= 30).sort((a,b)=>b.lastDays-a.lastDays);
    const gEl = document.getElementById('warnGobusata');
    if (gobusata.length) {
      gEl.innerHTML = `<div class="alert warn"><span class="ico">⌛</span><div><strong>しばらくアサインがない：</strong>${gobusata.map(p=>`${p.name}（${p.lastDays}日）`).join('、')}。<br>声をかけて状況を確認したい人です。</div></div>`;
    } else { gEl.innerHTML = ''; }

    if (!zero.length && !renkin.length && !lowRate.length && !lowPick.length && !gobusata.length) {
      document.getElementById('warnZero').innerHTML = '<div class="alert ok"><span class="ico">✓</span><div>特に気にかけるべき人はいません。</div></div>';
    }
  }

  // 絞り込み
  function applyFilter(){
    const fLv = document.getElementById('wLv').value;
    const fAct= document.getElementById('fAct').value;
    let shown = 0;
    tbody.querySelectorAll('tr').forEach(tr => {
      const okLv  = !fLv  || tr.dataset.lv === fLv;
      const okAct = !fAct || tr.dataset.act === fAct;
      const visible = okLv && okAct;
      tr.style.display = visible ? '' : 'none';
      if (visible) shown++;
    });
    document.getElementById('wCount').textContent = shown;
  }

  // 稼働状況タブの中で、HTML内のクリックから呼ぶ関数だけ外に出す（名前は work〜 で固有化）
  window.workFilter = applyFilter;
  window.workRender = render;

  renderSummary();
  render();
  })();
</script>
@endverbatim
@endpush
