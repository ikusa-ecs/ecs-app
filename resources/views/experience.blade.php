@extends('layouts.app')
@section('title', '経験回数')
@section('h1', '経験回数（誰が・何を・何回）')
@php($active = 'experience')

@push('head')
@verbatim
<style>
    /* ===== 経験回数 専用スタイル ===== */
    .ex-tabs { display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; }
    .ex-tab {
      padding: 9px 18px; border: 1px solid var(--line); border-radius: 999px;
      background: #fff; cursor: pointer; font-size: 14px; color: #6b5544; font-weight: 600;
    }
    .ex-tab.active { background: var(--brand); border-color: var(--brand-dark); color: #fff; }
    .ex-pane { display: none; }
    .ex-pane.show { display: block; }

    .ex-card { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 18px; margin-bottom: 16px; }
    .ex-card h3 { margin: 0 0 4px; font-size: 15px; }
    .ex-card .sub { font-size: 12px; color: var(--muted); margin: 0 0 14px; line-height: 1.7; }

    /* 絞り込みの行 */
    .ex-filters { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 12px; }
    .ex-filters select, .ex-filters input[type=search] {
      padding: 7px 9px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; font-family: inherit;
    }
    .ex-filters input[type=search] { min-width: 180px; }
    .ex-filters label { font-size: 12.5px; color: #5a4a38; display: inline-flex; align-items: center; gap: 5px; }
    .ex-hint { font-size: 12px; color: var(--muted); }

    /* 表 */
    .ex-wrap { overflow-x: auto; }
    table.ex-tbl { border-collapse: collapse; font-size: 13px; width: 100%; min-width: 720px; }
    table.ex-tbl th, table.ex-tbl td { border-bottom: 1px solid var(--line); padding: 7px 8px; text-align: left; vertical-align: top; }
    table.ex-tbl thead th { background: #f3ece2; color: #5a4a38; font-weight: 700; white-space: nowrap; border-bottom: 1px solid #e2d6c3; }
    table.ex-tbl thead th.sortable { cursor: pointer; }
    table.ex-tbl thead th.sortable:hover { background: #ece2d3; }
    table.ex-tbl td.num, table.ex-tbl th.num { text-align: right; white-space: nowrap; }
    table.ex-tbl tbody tr.person { cursor: pointer; }
    table.ex-tbl tbody tr.person:hover { background: #fbf7f1; }
    table.ex-tbl tbody tr.zero td { color: #b3a795; }
    .ex-rank { color: #8a7a66; font-size: 12px; width: 34px; }
    .ex-name { font-weight: 700; }
    .ex-sub { color: #8a7a66; font-size: 11.5px; }
    .ex-badge {
      display: inline-block; margin: 0 5px 3px 0; padding: 1px 7px; border-radius: 999px;
      font-size: 11.5px; background: #f3ece2; color: #6b5544; border: 1px solid #e2d6c3; white-space: nowrap;
    }
    .ex-badge.gone { background: #f0efee; color: #9a9186; }
    .ex-chip {
      display: inline-block; margin: 0 5px 3px 0; padding: 1px 7px; border-radius: 4px;
      font-size: 11.5px; background: #eef5fd; color: #1d4e89; border: 1px solid #cfe0f5; white-space: nowrap;
    }
    /* 開いた詳細 */
    tr.detail td { background: #fbf8f3; padding: 12px 14px; }
    table.ex-inner { border-collapse: collapse; font-size: 12px; }
    table.ex-inner td { border-bottom: 1px solid #efe8dd; padding: 3px 10px 3px 0; }
    table.ex-inner td.num { text-align: right; white-space: nowrap; font-weight: 700; }
    table.ex-inner td.muted2 { color: #8a7a66; white-space: nowrap; }

    .ex-empty { padding: 26px 10px; text-align: center; color: var(--muted); font-size: 13px; line-height: 1.8; }
    .ex-dl { font-size: 12.5px; }
    .ex-dl a { color: #6b5544; }

    @media (max-width: 720px) {
      .ex-filters select, .ex-filters input[type=search] { flex: 1 1 46%; min-width: 0; }
      table.ex-tbl { min-width: 620px; }
    }
</style>
@endverbatim
@endpush

@section('content')
@verbatim
      <div class="mock-note">
        <b>誰が、どのコンテンツ・どのポジションを、何回やったかの一覧です。</b>
        アサインを決めるときに「このコンテンツをやったことがある人は誰か」「このポジションに慣れている人は誰か」を探すための画面です。<br>
        ⚠ 数えているのは <b>アサインが「確定」で、開催日がもう過ぎた案件</b>だけです。
        仮のアサイン・これからの案件・キャンセルになった案件は数えません。<br>
        ⚠ この数は<b>保存していません。画面を開くたびに数え直します</b>ので、アサインを直せばここも自動で直ります。
      </div>

      <div class="ex-tabs">
        <button class="ex-tab active" data-pane="people" onclick="exTab('people')">🧑 人ごと</button>
        <button class="ex-tab" data-pane="content" onclick="exTab('content')">🎯 コンテンツから探す</button>
        <button class="ex-tab" data-pane="role" onclick="exTab('role')">🎬 ポジションから探す</button>
      </div>

      <!-- 共通の絞り込み -->
      <div class="ex-card">
        <div class="ex-filters">
          <select id="fOffice" onchange="exRender()"></select>
          <select id="fKind" onchange="exRender()">
            <option value="">区分：すべて</option>
            <option value="employee">社員だけ</option>
            <option value="staff">スタッフだけ</option>
          </select>
          <input type="search" id="fKw" placeholder="名前・番号でしぼる" oninput="exRender()">
          <label><input type="checkbox" id="fGone" onchange="exRender()"> 退職・停止の人も出す</label>
          <span class="ex-hint" id="fHint"></span>
          <span class="ex-dl" style="margin-left:auto;">
            <a href="/experience/export.csv">⬇ コンテンツ別CSV</a> ／
            <a href="/experience/export.csv?type=role">⬇ ポジション別CSV</a>
          </span>
        </div>
      </div>

      <!-- ===== タブ①：人ごと ===== -->
      <div class="ex-pane show" id="pane-people">
        <div class="ex-card">
          <h3>🧑 人ごとの経験</h3>
          <p class="sub">
            <b>行をクリックすると、その人のコンテンツごとの回数が開きます。</b>
            見出しの「通算」「出勤日数」を押すと並べ替えられます。
          </p>
          <div class="ex-wrap"><table class="ex-tbl" id="tblPeople"></table></div>
        </div>
      </div>

      <!-- ===== タブ②：コンテンツから探す ===== -->
      <div class="ex-pane" id="pane-content">
        <div class="ex-card">
          <h3>🎯 コンテンツから探す</h3>
          <p class="sub">コンテンツを選ぶと、<b>そのコンテンツをやったことがある人</b>を回数の多い順に並べます。</p>
          <div class="ex-filters"><select id="fContent" onchange="exRender()"></select></div>
          <div class="ex-wrap"><table class="ex-tbl" id="tblContent"></table></div>
        </div>
      </div>

      <!-- ===== タブ③：ポジションから探す ===== -->
      <div class="ex-pane" id="pane-role">
        <div class="ex-card">
          <h3>🎬 ポジションから探す</h3>
          <p class="sub">ポジションを選ぶと、<b>そのポジションをやったことがある人</b>を回数の多い順に並べます。</p>
          <div class="ex-filters"><select id="fRole" onchange="exRender()"></select></div>
          <div class="ex-wrap"><table class="ex-tbl" id="tblRole"></table></div>
        </div>
      </div>
@endverbatim
@endsection

@push('scripts')
{{-- 経験回数は保存された数ではなく、開くたびに assignments から数え直したもの（正本＝App\Support\ExperienceCount）。
     ⚠ 数え方をこのJSに書かないこと。書くとサーバー側と食い違う。 --}}
<script>
  window.ECS_PEOPLE     = @json($people ?? []);
  window.ECS_EXPERIENCE = @json($experience ?? []);
  window.ECS_CONTENTS   = @json($contentOptions ?? []);
  window.ECS_ROLES      = @json($roleOptions ?? []);
  {{-- 拠点で絞るための選択肢と、自分の拠点。⚠ 拠点名をJSに書き足さない（正本は拠点マスタ）。 --}}
  window.ECS_OFFICES    = @json($offices ?? []);
  window.ECS_MY_OFFICE  = @json($myOffice ?? '');
</script>
@verbatim
<script>
  const PEOPLE = window.ECS_PEOPLE || [];
  const EXP    = window.ECS_EXPERIENCE || {};

  function esc(s){
    return String(s == null ? '' : s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function expOf(id){
    return EXP[id] || { projects:0, days:0, byContent:[], byRole:[], byContentRole:{} };
  }

  // ===== 選択肢を作る =====
  // ⚠ 拠点は拠点マスタから作る（画面に拠点名を直書きしない）。はじめは自分の拠点。
  //   「すべての拠点」も残す＝他拠点へヘルプに行く／来てもらう運用があるので隠さない。
  function buildFilters(){
    const mine = (window.ECS_MY_OFFICE || '').trim();
    let html = '<option value="">拠点：すべて</option>';
    (window.ECS_OFFICES || []).forEach(function(o){
      html += '<option value="' + esc(o) + '"' + (o === mine ? ' selected' : '') + '>' + esc(o) + '</option>';
    });
    document.getElementById('fOffice').innerHTML = html;

    let ch = '';
    (window.ECS_CONTENTS || []).forEach(function(c, i){
      ch += '<option value="' + esc(c) + '"' + (i === 0 ? ' selected' : '') + '>' + esc(c) + '</option>';
    });
    document.getElementById('fContent').innerHTML = ch || '<option value="">（まだ実績がありません）</option>';

    let rh = '';
    (window.ECS_ROLES || []).forEach(function(r, i){
      rh += '<option value="' + esc(r.code) + '"' + (i === 0 ? ' selected' : '') + '>' + esc(r.label) + '</option>';
    });
    document.getElementById('fRole').innerHTML = rh || '<option value="">（まだ実績がありません）</option>';
  }

  // ===== 絞り込み =====
  function filtered(){
    const office = document.getElementById('fOffice').value;
    const kind   = document.getElementById('fKind').value;
    const kw     = document.getElementById('fKw').value.trim();
    const gone   = document.getElementById('fGone').checked;

    return PEOPLE.filter(function(p){
      if (office && p.office !== office) return false;
      if (kind && p.role !== kind) return false;
      if (!gone && !p.active) return false;
      if (kw && p.name.indexOf(kw) < 0 && p.id.indexOf(kw) < 0 && (p.kana || '').indexOf(kw) < 0) return false;
      return true;
    });
  }
  function hint(list){
    const office = document.getElementById('fOffice').value;
    document.getElementById('fHint').innerHTML = list.length + '名を表示中'
      + (office ? '（<b>' + esc(office) + '</b>の人だけ）' : '（すべての拠点）');
  }

  // ===== タブ①：人ごと =====
  let sortKey = 'projects';   // projects / days / kana
  function exSort(key){
    sortKey = key;
    exRender();
  }
  function peopleRows(list){
    const rows = list.map(function(p){ return { p: p, e: expOf(p.id) }; });
    rows.sort(function(a, b){
      if (sortKey === 'kana'){
        const ak = (a.p.kana || '').trim(), bk = (b.p.kana || '').trim();
        // ⚠ ふりがなが無い人は名前で比べる（無い人だけ先頭に固まらないように）。
        if (ak && bk) return ak.localeCompare(bk, 'ja') || String(a.p.name).localeCompare(String(b.p.name), 'ja');
        return String(a.p.name).localeCompare(String(b.p.name), 'ja');
      }
      const av = sortKey === 'days' ? a.e.days : a.e.projects;
      const bv = sortKey === 'days' ? b.e.days : b.e.projects;
      // 多い順。同じなら名前順（毎回同じ並びになるように）。
      return (bv - av) || String(a.p.name).localeCompare(String(b.p.name), 'ja');
    });
    return rows;
  }
  function renderPeople(list){
    const tbl = document.getElementById('tblPeople');
    if (list.length === 0){
      tbl.innerHTML = '<tbody><tr><td class="ex-empty">この条件に合う人がいません。'
        + '<br>拠点を「すべて」にするか、しぼり込みを消してみてください。</td></tr></tbody>';
      return;
    }
    let html = '<thead><tr>'
      + '<th class="ex-rank">#</th>'
      + '<th class="sortable" onclick="exSort(\'kana\')">氏名 ▾</th>'
      + '<th>区分・拠点</th>'
      + '<th class="num sortable" onclick="exSort(\'projects\')">通算 ▾</th>'
      + '<th class="num sortable" onclick="exSort(\'days\')">出勤日数 ▾</th>'
      + '<th>ポジションごと</th>'
      + '<th>よくやったコンテンツ</th>'
      + '</tr></thead><tbody>';

    peopleRows(list).forEach(function(row, i){
      const p = row.p, e = row.e;
      // ポジションのバッジ（決まった並びで来る）。
      let roles = e.byRole.map(function(r){
        return '<span class="ex-badge">' + esc(r.label) + ' ' + r.count + '</span>';
      }).join('');
      if (!roles) roles = '<span class="ex-sub">—</span>';
      // コンテンツは上位3つだけ。全部は行を開くと出る。
      const top = e.byContent.slice(0, 3).map(function(c){
        return '<span class="ex-chip">' + esc(c.name) + ' ' + c.count + '</span>';
      }).join('');
      const more = e.byContent.length > 3
        ? '<span class="ex-sub">ほか' + (e.byContent.length - 3) + '種類</span>' : '';

      html += '<tr class="person' + (e.projects ? '' : ' zero') + '" onclick="exToggle(this)">'
        + '<td class="ex-rank">' + (i + 1) + '</td>'
        + '<td><span class="ex-name">' + esc(p.name) + '</span>'
          + (p.active ? '' : ' <span class="ex-badge gone">退職・停止</span>')
          + '<br><span class="ex-sub">' + esc(p.id) + '</span></td>'
        + '<td>' + esc(p.roleLabel) + '<br><span class="ex-sub">' + esc(p.office) + '</span></td>'
        + '<td class="num"><b>' + e.projects + '</b> 件</td>'
        + '<td class="num">' + e.days + ' 日</td>'
        + '<td>' + roles + '</td>'
        + '<td>' + (top || '<span class="ex-sub">—</span>') + ' ' + more + '</td>'
        + '</tr>'
        + '<tr class="detail" style="display:none;"><td colspan="7">' + detailHtml(e) + '</td></tr>';
    });
    tbl.innerHTML = html + '</tbody>';
  }
  // 行を開く／閉じる（その人のコンテンツごとの回数）。
  function exToggle(tr){
    const d = tr.nextElementSibling;
    if (d && d.classList.contains('detail')) d.style.display = (d.style.display === 'none') ? '' : 'none';
  }
  function detailHtml(e){
    if (!e.byContent.length){
      return '<span class="ex-sub">まだ実績がありません'
        + '（確定のアサインで、開催日が過ぎたものを数えます）。</span>';
    }
    let html = '<div style="font-size:12.5px; margin-bottom:6px;"><b>コンテンツごと</b>'
      + '<span class="ex-sub">（多い順／そのコンテンツでやったポジション／最後にやった日）</span></div>'
      + '<table class="ex-inner">';
    e.byContent.forEach(function(c){
      const cr = (e.byContentRole || {})[c.name] || {};
      const parts = Object.keys(cr).map(function(k){ return k + ' ' + cr[k]; });
      html += '<tr><td>' + esc(c.name) + '</td>'
        + '<td class="num">' + c.count + '回</td>'
        + '<td class="muted2">' + esc(parts.join(' / ')) + '</td>'
        + '<td class="muted2">' + esc(c.last || '') + '</td></tr>';
    });
    return html + '</table>';
  }

  // ===== タブ②③：コンテンツ／ポジションから探す =====
  // どちらも「その人の集計の中から1つ取り出して、多い順に並べる」だけ＝作りは同じ。
  function renderPick(tblId, list, pick, headLabel, emptyLabel){
    const rows = [];
    list.forEach(function(p){
      const hit = pick(expOf(p.id));
      if (hit && hit.count > 0) rows.push({ p: p, hit: hit });
    });
    rows.sort(function(a, b){
      return (b.hit.count - a.hit.count)
        || String(b.hit.last || '').localeCompare(String(a.hit.last || ''))
        || String(a.p.name).localeCompare(String(b.p.name), 'ja');
    });

    const tbl = document.getElementById(tblId);
    if (rows.length === 0){
      tbl.innerHTML = '<tbody><tr><td class="ex-empty">' + esc(emptyLabel) + '</td></tr></tbody>';
      return;
    }
    let html = '<thead><tr><th class="ex-rank">#</th><th>氏名</th><th>区分・拠点</th>'
      + '<th class="num">' + esc(headLabel) + '</th><th class="num">通算</th>'
      + '<th>最後にやった日</th><th>そのときのポジション</th></tr></thead><tbody>';
    rows.forEach(function(r, i){
      const e = expOf(r.p.id);
      html += '<tr><td class="ex-rank">' + (i + 1) + '</td>'
        + '<td><span class="ex-name">' + esc(r.p.name) + '</span>'
          + (r.p.active ? '' : ' <span class="ex-badge gone">退職・停止</span>')
          + '<br><span class="ex-sub">' + esc(r.p.id) + '</span></td>'
        + '<td>' + esc(r.p.roleLabel) + '<br><span class="ex-sub">' + esc(r.p.office) + '</span></td>'
        + '<td class="num"><b>' + r.hit.count + '</b> 回</td>'
        + '<td class="num">' + e.projects + ' 件</td>'
        + '<td>' + esc(r.hit.last || '') + '</td>'
        + '<td>' + esc(r.hit.roles || '') + '</td></tr>';
    });
    tbl.innerHTML = html + '</tbody>';
  }

  // ===== まとめて描き直す =====
  function exRender(){
    const list = filtered();
    hint(list);
    renderPeople(list);

    const cName = document.getElementById('fContent').value;
    renderPick('tblContent', list, function(e){
      const c = e.byContent.find(function(x){ return x.name === cName; });
      if (!c) return null;
      const cr = (e.byContentRole || {})[cName] || {};
      const parts = Object.keys(cr).map(function(k){ return k + ' ' + cr[k]; });
      return { count: c.count, last: c.last, roles: parts.join(' / ') };
    }, '回数', 'このコンテンツをやったことがある人は、いまの条件では見つかりませんでした。');

    const rCode = document.getElementById('fRole').value;
    renderPick('tblRole', list, function(e){
      const r = e.byRole.find(function(x){ return x.role === rCode; });
      return r ? { count: r.count, last: r.last, roles: '' } : null;
    }, '回数', 'このポジションをやったことがある人は、いまの条件では見つかりませんでした。');
  }

  function exTab(pane){
    document.querySelectorAll('.ex-tab').forEach(function(t){ t.classList.toggle('active', t.dataset.pane === pane); });
    ['people','content','role'].forEach(function(n){
      document.getElementById('pane-' + n).classList.toggle('show', n === pane);
    });
  }

  buildFilters();
  exRender();
</script>
@endverbatim
@endpush
