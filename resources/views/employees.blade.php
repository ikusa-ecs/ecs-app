@extends('layouts.app')
@section('title', '社員名簿')
@section('h1', '社員名簿')
@php($active = 'employees')

@push('head')
{{-- 社員データは DB から（Controller が people テーブルの社員を整えて渡す）。 --}}
<script>
  window.ECS_EMPLOYEES = @json($employees);
  window.ECS_CONTENT_OPTIONS = @json($contentOptions ?? []);   // 経験コンテンツ編集のプルダウン候補
  window.ECS_CSRF = '{{ csrf_token() }}';                      // 保存に使う合言葉
  {{-- 所属の絞り込み候補（コード→グループ名）。正本は App\Support\Departments。 --}}
  window.ECS_DEPT_OPTIONS = @json(\App\Support\Departments::groupOptions());
</script>
{{-- 所属バッジの色。色をJSやCSSに直書きせず、正本（Departments）から作る。 --}}
<style>
    {!! \App\Support\Departments::badgeCss('.dept') !!}
</style>
@verbatim
<style>
    /* 社員名簿モック専用スタイル（staff.html を土台に作成） */

    /* 絞り込みバー */
    .filterbar { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 16px; }
    .filterbar input[type="text"], .filterbar select {
      padding: 8px 11px; border: 1px solid var(--line); border-radius: 8px; font-size: 13.5px; font-family: inherit; background: #fff;
    }
    .filterbar input[type="text"] { min-width: 220px; }
    .filterbar .spacer { flex: 1; }
    .count-line { font-size: 12.5px; color: var(--muted); margin-bottom: 10px; }

    /* 新人バッジ（入社半年以内）＝氏名の横 */
    .fresh-badge { font-size: 11px; padding: 1px 7px; border-radius: 999px; font-weight: 600; white-space: nowrap;
      background: var(--brand-soft); color: var(--brand-dark); margin-left: 6px; }

    /* 所属バッジ。色は上の <style> で正本（App\Support\Departments）から作っている。 */
    .dept { font-size: 11.5px; padding: 1px 9px; border-radius: 999px; font-weight: 600; white-space: nowrap; }

    /* 経験コンテンツのタグ（詳細パネル内のみ） */
    .contags { display: flex; flex-wrap: wrap; gap: 4px; }
    .ctag { font-size: 11px; padding: 1px 7px; border-radius: 6px; background: #f8f3ea; color: #7a6a58; border: 1px solid var(--line); white-space: nowrap; }
    .ctag.dir { background: var(--ok-soft); color: #15803d; border-color: #cdeccf; } /* Dの経験があるコンテンツ */
    /* タグを外す × ボタン */
    .ctag .tag-x { margin-left: 6px; color: #b91c1c; cursor: pointer; font-weight: 700; }
    .ctag .tag-x:hover { color: #7f1d1d; }
    /* タグ追加のプルダウン＋ボタン */
    .tag-add { margin-top: 8px; display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
    .tag-add select { padding: 5px 8px; border: 1px solid var(--line); border-radius: 8px; font-family: inherit; font-size: 12.5px; background: #fff; }
    .save-ok { color: #16a34a; font-weight: 700; font-size: 12px; }

    /* 詳細トグル */
    .row-toggle { cursor: pointer; color: var(--brand); font-weight: 600; font-size: 12.5px; white-space: nowrap; }
    tr.detail-row > td { background: #faf6ee; padding: 0; }
    .detail-box { padding: 16px 18px; }
    .detail-box .dgrid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
    @media (max-width: 900px){ .detail-box .dgrid { grid-template-columns: 1fr; } }
    .detail-box h4 { margin: 0 0 8px; font-size: 13px; }
    .size-row { display: flex; gap: 18px; flex-wrap: wrap; align-items: center; }
    .size-row .size-item { font-size: 13px; display: inline-flex; align-items: center; gap: 6px; }
    .size-row .size-item .v { font-weight: 600; }
    .size-input { padding: 6px 9px; border: 1px solid var(--line); border-radius: 8px; font-family: inherit; font-size: 13px; background: #fff; width: 90px; }
    .detail-box .save-row { margin-top: 14px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .exp-block { margin-top: 14px; }
    .exp-block:first-child { margin-top: 0; }

    .privacy-note { font-size: 12px; color: var(--muted); background: #f8f3ea; border: 1px dashed var(--line); border-radius: 8px; padding: 8px 12px; margin-top: 14px; }
  </style>
@endverbatim
@endpush

@section('content')
@verbatim
      <div class="mock-note">社員の情報は<b>登録された本物のデータ</b>を表示しています。氏名の横で新人（入社半年以内）が分かり、行の「詳細」で内容を確認できます。<br>※ 詳細の<b>「経験コンテンツ」「Dの経験コンテンツ」「サイズ（身長・靴・服）」はここで編集して保存できます</b>。新しい社員の追加は「＋社員を追加」からアカウント発行画面で行います。</div>

      <div class="panel">
        <div class="filterbar">
          <input type="text" id="kw" placeholder="氏名・ふりがなで検索" oninput="applyFilter()">
          <!-- 所属の選択肢は正本（App\Support\Departments）から作る。ここに部署名を書き足さない。 -->
          <select id="fDept" onchange="applyFilter()">
            <option value="">所属：すべて</option>
          </select>
          <select id="fFresh" onchange="applyFilter()">
            <option value="">新人：すべて</option>
            <option value="fresh">新人（入社半年以内）のみ</option>
          </select>
          <div class="spacer"></div>
          <a class="btn primary" href="/account-new" title="アカウント発行画面が開きます。社員のログインアカウントはそこで発行します。">＋ 社員を追加</a>
        </div>

        <div class="count-line"><span id="countTxt">0</span> 名を表示中</div>

        <table class="tbl">
          <thead>
            <tr>
              <th>氏名</th>
              <th>所属</th>
              <th>事務所</th>
              <th>服 / 靴</th>
              <th class="right"></th>
            </tr>
          </thead>
          <tbody id="tbody"><!-- JSで生成 --></tbody>
        </table>
      </div>

      <div class="privacy-note">
        🔒 <b>社員はエントリー（応募）しません＝アサインの対象プールとは別管理です。</b> この名簿は「誰がどの区分か・どのコンテンツの経験があるか」を社員が確認するためのものです。社員なら全員が同じ内容を閲覧できます（権限での出し分けはしません）。
      </div>
@endverbatim
@endsection

@push('scripts')
@verbatim
<script>
  // ===== 社員名簿の仮データ =====
  // dept: 所属の色コード（plan/sales/creative/other/none）・deptName: 実際の所属名
  // joinedMonths: 入社からの経過月数（6以下＝新人バッジを氏名の横に表示）
  // exp:  経験のあるコンテンツ（詳細パネル内のみ表示）
  // dexp: そのうち「D（ディレクター）として」経験のあるコンテンツ
  // 社員データは DB（people テーブル）から。Controller が↑の <script> で
  // window.ECS_EMPLOYEES に入れて渡す。これまでの直書き配列の代わり。
  const employees = window.ECS_EMPLOYEES || [];

  // 所属の「コード→グループ名」。正本（App\Support\Departments）から受け取る。
  // イベプラ／セールス／クリエイティブ以外は「その他」にまとめてある（色分け・絞り込みの単位）。
  const deptLabel = window.ECS_DEPT_OPTIONS || {};

  // 絞り込みプルダウンの中身を作る（部署名を画面に直書きしないため）。
  (function buildDeptFilter(){
    const sel = document.getElementById('fDept');
    if (!sel) return;
    Object.keys(deptLabel).forEach(function(code){
      const o = document.createElement('option');
      o.value = code; o.textContent = deptLabel[code];
      sel.appendChild(o);
    });
    // 所属が空の人も探せるように（誰が未入力か見つける用）。
    const o = document.createElement('option');
    o.value = 'none'; o.textContent = '未設定';
    sel.appendChild(o);
  })();

  // 入社半年以内＝新人
  function isFresh(m){ return m <= 6; }

  const tbody = document.getElementById('tbody');

  function render(){
    tbody.innerHTML = '';
    employees.forEach((p, idx) => {
      const fresh = isFresh(p.joinedMonths);
      const tr = document.createElement('tr');
      tr.className = 'main-row';
      tr.dataset.idx = idx;
      tr.innerHTML = `
        <td><strong>${p.name}</strong>${fresh ? '<span class="fresh-badge">新人</span>' : ''}
            <br><span class="muted" style="font-size:11.5px;">${p.id}</span>
            <br><span class="muted" style="font-size:11.5px;">${p.kana
              ? p.kana
              : '<span style="color:#b5673a;">ふりがな未入力</span>'}</span></td>
        <td><span class="dept ${p.dept}">${p.deptName}</span></td>
        <td><span class="muted" style="font-size:12.5px;">${p.office || '—'}</span></td>
        <td><span class="muted" style="font-size:12.5px;">${p.wear || '—'} / ${p.shoe || '—'}</span></td>
        <td class="right"><span class="row-toggle" onclick="toggleDetail(${idx}, this)">詳細 ▾</span></td>`;
      tbody.appendChild(tr);

      // 詳細行（最初は隠す）
      const dr = document.createElement('tr');
      dr.className = 'detail-row';
      dr.dataset.for = idx;
      dr.style.display = 'none';
      dr.innerHTML = `<td colspan="5">${detailHtml(p, idx)}</td>`;
      tbody.appendChild(dr);
    });
    applyFilter();
  }

  function detailHtml(p, idx){
    const fresh = isFresh(p.joinedMonths);
    return `
      <div class="detail-box">
        <div class="dgrid">
          <div>
            <div class="exp-block">
              <h4>経験のあるコンテンツ</h4>
              ${expEditorHtml(idx, 'exp')}
            </div>

            <div class="exp-block">
              <h4>Dの経験があるコンテンツ</h4>
              ${expEditorHtml(idx, 'dexp')}
            </div>
          </div>
          <div>
            <h4>サイズ（当日の衣装・ユニフォーム準備の参考）</h4>
            <div class="size-row">
              <label class="size-item">身長(cm)：<input type="text" class="size-input" id="height-${idx}" value="${p.height || ''}" placeholder="例：170"></label>
              <label class="size-item">靴：<input type="text" class="size-input" id="shoe-${idx}" value="${p.shoeSize || ''}" placeholder="例：26.5"></label>
              <label class="size-item">服：<input type="text" class="size-input" id="shirt-${idx}" value="${p.shirtSize || ''}" placeholder="例：M / L"></label>
            </div>
            <div class="save-row" style="margin-top:10px;">
              <button class="btn primary sm" onclick="saveSize(${idx}, this)">サイズを保存</button>
              <span class="save-ok" id="sizeSaved-${idx}" style="display:none;">✓ 保存しました</span>
            </div>

            ${fresh ? `<div class="muted" style="font-size:12px; margin-top:12px;">🌱 入社半年以内の新人です。経験コンテンツとDの経験コンテンツを重点的に確認してください。</div>` : ''}
          </div>
        </div>
        <div class="save-row">
          <button class="btn primary sm" onclick="saveExperience(${idx})">経験コンテンツを保存</button>
          <span class="save-ok" id="expSaved-${idx}" style="display:none;">✓ 保存しました</span>
          <button class="btn sm" onclick="toggleDetail(${idx})">閉じる</button>
          <span class="muted" style="font-size:12px;">※「経験コンテンツ」「Dの経験コンテンツ」「サイズ」の変更は保存されます。社員はエントリーしません（この名簿はアサインとは別管理）。</span>
        </div>
      </div>`;
  }

  function toggleDetail(idx, el){
    const dr = tbody.querySelector(`tr.detail-row[data-for="${idx}"]`);
    if (!dr) return;
    const open = dr.style.display === 'none';
    dr.style.display = open ? '' : 'none';
    const toggle = tbody.querySelector(`tr.main-row[data-idx="${idx}"] .row-toggle`);
    if (toggle) toggle.innerHTML = open ? '詳細 ▴' : '詳細 ▾';
  }

  // ===== 経験コンテンツ／Dの経験コンテンツの編集 =====
  // タグ（現在のコンテンツ）＋「＋コンテンツを選ぶ」プルダウンと追加ボタンを組み立てる。
  function expEditorHtml(idx, kind){
    const p = employees[idx];
    const arr = (kind === 'dexp') ? (p.dexp || []) : (p.exp || []);
    const cls = (kind === 'dexp') ? 'ctag dir' : 'ctag';
    const emptyMsg = (kind === 'dexp') ? '（まだなし）' : '（なし）';
    const tags = arr.length
      ? arr.map((c, ti) => `<span class="${cls}">${c}<a class="tag-x" title="外す" onclick="removeTag(${idx},'${kind}',${ti})">×</a></span>`).join('')
      : `<span class="muted" style="font-size:12px;">${emptyMsg}</span>`;
    const opts = (window.ECS_CONTENT_OPTIONS || []).map(o => `<option value="${o}">${o}</option>`).join('');
    return `
      <div class="contags">${tags}</div>
      <div class="tag-add">
        <select id="add-${kind}-${idx}"><option value="">＋コンテンツを選ぶ</option>${opts}</select>
        <button class="btn sm" type="button" onclick="addTag(${idx},'${kind}')">追加</button>
      </div>`;
  }
  // タグを外す（配列の位置で消す＝名前に記号があっても安全）
  function removeTag(idx, kind, ti){
    const p = employees[idx];
    const arr = (kind === 'dexp') ? p.dexp : p.exp;
    if (Array.isArray(arr) && ti >= 0 && ti < arr.length) { arr.splice(ti, 1); renderDetail(idx); }
  }
  // プルダウンで選んだコンテンツを追加（重複は無視）
  function addTag(idx, kind){
    const sel = document.getElementById(`add-${kind}-${idx}`);
    if (!sel || !sel.value) return;
    const p = employees[idx];
    if (kind === 'dexp') { p.dexp = p.dexp || []; if (p.dexp.indexOf(sel.value) === -1) p.dexp.push(sel.value); }
    else                 { p.exp  = p.exp  || []; if (p.exp.indexOf(sel.value)  === -1) p.exp.push(sel.value); }
    renderDetail(idx);
  }
  // 詳細行を今の内容で描き直す（開いたまま）
  function renderDetail(idx){
    const dr = tbody.querySelector(`tr.detail-row[data-for="${idx}"]`);
    if (dr) dr.innerHTML = `<td colspan="5">${detailHtml(employees[idx], idx)}</td>`;
  }
  // 経験コンテンツ／Dの経験コンテンツを DB に保存
  function saveExperience(idx){
    const p = employees[idx];
    fetch('/employees/experience', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF },
      body: JSON.stringify({ id: p.id, exp: p.exp || [], dexp: p.dexp || [] })
    })
    .then(r => { if (!r.ok) throw new Error('save failed'); return r.json(); })
    .then(() => {
      const m = document.getElementById(`expSaved-${idx}`);
      if (m) { m.style.display = ''; setTimeout(() => { m.style.display = 'none'; }, 1800); }
    })
    .catch(() => alert('保存に失敗しました。もう一度お試しください。'));
  }

  // サイズ（身長・靴・服）を DB に保存する。
  function saveSize(idx, btn){
    const p = employees[idx];
    const height = (document.getElementById(`height-${idx}`) || {}).value || '';
    const shoe   = (document.getElementById(`shoe-${idx}`)   || {}).value || '';
    const shirt  = (document.getElementById(`shirt-${idx}`)  || {}).value || '';
    const body = new URLSearchParams();
    body.append('height', height.trim());
    body.append('shoe_size', shoe.trim());
    body.append('shirt_size', shirt.trim());
    if (btn) btn.disabled = true;
    fetch(`/employees/${encodeURIComponent(p.id)}/profile`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF },
      body: body.toString()
    })
    .then(r => { if (!r.ok) throw new Error('save failed'); return r.json(); })
    .then(() => {
      // 画面の手元データも更新（一覧の「服 / 靴」列と詳細の初期値をそろえる）。
      p.height = height.trim();
      p.shoeSize = shoe.trim();
      p.shirtSize = shirt.trim();
      p.shoe = shoe.trim();   // 一覧列（服 / 靴）用
      p.wear = shirt.trim();  // 一覧列（服 / 靴）用
      const mr = tbody.querySelector(`tr.main-row[data-idx="${idx}"]`);
      if (mr) {
        const tds = mr.querySelectorAll('td');
        if (tds[3]) tds[3].innerHTML = `<span class="muted" style="font-size:12.5px;">${p.wear || '—'} / ${p.shoe || '—'}</span>`;
      }
      const m = document.getElementById(`sizeSaved-${idx}`);
      if (m) { m.style.display = ''; setTimeout(() => { m.style.display = 'none'; }, 1800); }
      if (btn) btn.disabled = false;
    })
    .catch(() => { alert('保存に失敗しました。もう一度お試しください。'); if (btn) btn.disabled = false; });
  }

  // 絞り込み
  function applyFilter(){
    const kw     = document.getElementById('kw').value.trim();
    const fDept  = document.getElementById('fDept').value;
    const fFresh = document.getElementById('fFresh').value;
    let shown = 0;
    employees.forEach((p, idx) => {
      const mr = tbody.querySelector(`tr.main-row[data-idx="${idx}"]`);
      const dr = tbody.querySelector(`tr.detail-row[data-for="${idx}"]`);
      const fresh = isFresh(p.joinedMonths);
      const okKw    = !kw    || p.name.includes(kw) || p.id.includes(kw) || (p.kana || '').includes(kw);
      const okDept  = !fDept || p.dept === fDept;
      const okFresh = !fFresh|| fresh;
      const visible = okKw && okDept && okFresh;
      mr.style.display = visible ? '' : 'none';
      if (!visible && dr) { dr.style.display = 'none';
        const t = mr.querySelector('.row-toggle'); if (t) t.innerHTML = '詳細 ▾'; }
      if (visible) shown++;
    });
    document.getElementById('countTxt').textContent = shown;
  }

  render();
</script>
@endverbatim
@endpush
