@extends('layouts.app')
@section('title', '社員名簿')
@section('h1', '社員名簿')
@php($active = 'employees')

@push('head')
{{-- 社員データは DB から（Controller が people テーブルの社員を整えて渡す）。 --}}
<script>
  window.ECS_EMPLOYEES = @json($employees);
</script>
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

    /* 区分バッジ（イベプラ／セールス／クリエイティブ） */
    .dept { font-size: 11.5px; padding: 1px 9px; border-radius: 999px; font-weight: 600; white-space: nowrap; }
    .dept.plan     { background: #e0f2fe; color: #0369a1; }   /* イベプラ */
    .dept.sales    { background: var(--ok-soft); color: #15803d; } /* セールス */
    .dept.creative { background: #f3e8ff; color: #7c3aed; }   /* クリエイティブ */

    /* 経験コンテンツのタグ（詳細パネル内のみ） */
    .contags { display: flex; flex-wrap: wrap; gap: 4px; }
    .ctag { font-size: 11px; padding: 1px 7px; border-radius: 6px; background: #f8f3ea; color: #7a6a58; border: 1px solid var(--line); white-space: nowrap; }
    .ctag.dir { background: var(--ok-soft); color: #15803d; border-color: #cdeccf; } /* Dの経験があるコンテンツ */

    /* 詳細トグル */
    .row-toggle { cursor: pointer; color: var(--brand); font-weight: 600; font-size: 12.5px; white-space: nowrap; }
    tr.detail-row > td { background: #faf6ee; padding: 0; }
    .detail-box { padding: 16px 18px; }
    .detail-box .dgrid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
    @media (max-width: 900px){ .detail-box .dgrid { grid-template-columns: 1fr; } }
    .detail-box h4 { margin: 0 0 8px; font-size: 13px; }
    .size-row { display: flex; gap: 18px; flex-wrap: wrap; }
    .size-row .size-item { font-size: 13px; }
    .size-row .size-item .v { font-weight: 600; }
    .detail-box .save-row { margin-top: 14px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .exp-block { margin-top: 14px; }
    .exp-block:first-child { margin-top: 0; }

    .privacy-note { font-size: 12px; color: var(--muted); background: #f8f3ea; border: 1px dashed var(--line); border-radius: 8px; padding: 8px 12px; margin-top: 14px; }
  </style>
@endverbatim
@endpush

@section('content')
@verbatim
      <div class="mock-note">これは見た目確認用のモックです。社員・区分・経験コンテンツ・サイズなどはすべて仮の見本で、編集しても保存はされません。</div>

      <div class="panel">
        <div class="filterbar">
          <input type="text" id="kw" placeholder="氏名で検索" oninput="applyFilter()">
          <select id="fDept" onchange="applyFilter()">
            <option value="">区分：すべて</option>
            <option value="plan">イベプラ</option>
            <option value="sales">セールス</option>
            <option value="creative">クリエイティブ</option>
          </select>
          <select id="fFresh" onchange="applyFilter()">
            <option value="">新人：すべて</option>
            <option value="fresh">新人（入社半年以内）のみ</option>
          </select>
          <div class="spacer"></div>
          <button class="btn primary" onclick="alert('モックのため、追加は行いません。')">＋ 社員を追加</button>
        </div>

        <div class="count-line"><span id="countTxt">0</span> 名を表示中</div>

        <table class="tbl">
          <thead>
            <tr>
              <th>氏名</th>
              <th>区分</th>
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
  // dept: 区分（plan=イベプラ / sales=セールス / creative=クリエイティブ）
  // joinedMonths: 入社からの経過月数（6以下＝新人バッジを氏名の横に表示）
  // exp:  経験のあるコンテンツ（詳細パネル内のみ表示）
  // dexp: そのうち「D（ディレクター）として」経験のあるコンテンツ
  // 社員データは DB（people テーブル）から。Controller が↑の <script> で
  // window.ECS_EMPLOYEES に入れて渡す。これまでの直書き配列の代わり。
  const employees = window.ECS_EMPLOYEES || [];

  const deptLabel = { plan:'イベプラ', sales:'セールス', creative:'クリエイティブ' };

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
            <br><span class="muted" style="font-size:11.5px;">${p.id}</span></td>
        <td><span class="dept ${p.dept}">${deptLabel[p.dept]}</span></td>
        <td><span class="muted" style="font-size:12.5px;">${p.office || '—'}</span></td>
        <td><span class="muted" style="font-size:12.5px;">${p.wear} / ${p.shoe}</span></td>
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
              <div class="contags">${p.exp.length ? p.exp.map(c=>`<span class="ctag">${c}</span>`).join('') : '<span class="muted" style="font-size:12px;">（なし）</span>'}</div>
            </div>

            <div class="exp-block">
              <h4>Dの経験があるコンテンツ</h4>
              <div class="contags">${p.dexp.length ? p.dexp.map(c=>`<span class="ctag dir">${c}</span>`).join('') : '<span class="muted" style="font-size:12px;">（まだなし）</span>'}</div>
            </div>
          </div>
          <div>
            <h4>サイズ</h4>
            <div class="size-row">
              <div class="size-item">服：<span class="v">${p.wear}</span></div>
              <div class="size-item">靴：<span class="v">${p.shoe}</span></div>
            </div>

            ${fresh ? `<div class="muted" style="font-size:12px; margin-top:12px;">🌱 入社半年以内の新人です。経験コンテンツとDの経験コンテンツを重点的に確認してください。</div>` : ''}
          </div>
        </div>
        <div class="save-row">
          <button class="btn primary sm" onclick="alert('モックのため保存はしません。')">保存</button>
          <button class="btn sm" onclick="toggleDetail(${idx})">閉じる</button>
          <span class="muted" style="font-size:12px;">※社員はエントリーしません。この名簿はアサインとは別管理です。</span>
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
      const okKw    = !kw    || p.name.includes(kw) || p.id.includes(kw);
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
