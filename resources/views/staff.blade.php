@extends('layouts.app')
@section('title', 'スタッフ管理（名簿）')
@section('h1', 'スタッフ管理（名簿）')
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
    /* スタッフ管理（名簿）モック専用スタイル */

    /* 絞り込みバー */
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

    /* できるポジションのタグ */
    .postags { display: flex; flex-wrap: wrap; gap: 4px; }
    .ptag { font-size: 11px; padding: 1px 7px; border-radius: 6px; background: #f1e9dc; color: #7a6a58; border: 1px solid var(--line); white-space: nowrap; }
    .ptag.key { background: #e0f2fe; color: #0369a1; border-color: #c3e3f5; } /* D/MC/OP/軍師＝経験者向け */

    /* 相性 */
    .rel { font-size: 12px; }
    .rel .ng { color: var(--danger); }
    .rel .good { color: var(--ok); }

    /* 詳細トグル */
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
  </style>
@endverbatim
@endpush

@section('content')
@verbatim
      <div class="mock-note">これは見た目確認用のモックです。スタッフ・ポジション可否・相性などはすべて仮の見本で、編集しても保存はされません。</div>

      <div class="panel">
        <div class="filterbar">
          <input type="text" id="kw" placeholder="氏名で検索" oninput="applyFilter()">
          <select id="fLv" onchange="applyFilter()">
            <option value="">区分：すべて</option>
            <option value="new">新人</option>
            <option value="mid">中堅</option>
            <option value="vet">ベテラン</option>
          </select>
          <select id="fPos" onchange="applyFilter()">
            <option value="">できるポジション：すべて</option>
            <option value="OP">OP（音響）</option>
            <option value="MC">MC（司会進行）</option>
            <option value="GUN">軍師・サポーター</option>
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
@endverbatim
@endsection

@push('scripts')
@verbatim
<script>
  // ===== スタッフ名簿（共通名簿 /ecs/data/people.js から読み込む）=====
  // 社員/スタッフを1つにまとめた共通名簿から、スタッフだけ読み込む。
  // これで同じ人の値の食い違いが無くなり、名簿はこの1ファイルを直せば全画面に反映される。
  const staff = ECS_staffList();

  const lvLabel = window.ECS_LV_LABEL;
  // 区分は入社日／登録日からの年数で判定（新人=1年未満／中堅=1〜3年/ベテラン=3年以上）
  function lvOf(p){ return window.ECS_lvOf(p); }
  // スタッフが「できる」として持つポジションは OP / MC / 軍師 の3つに限定。
  // （D はできるスタッフがほぼいない・FC/CK/受付 は誰でもやる前提なので「できること」管理の対象外）
  const POS = [
    { k:'OP',  label:'OP',       key:true },
    { k:'MC',  label:'MC',       key:true },
    { k:'GUN', label:'軍師・サポ', key:true },
  ];
  const posFull = { OP:'OP（音響）', MC:'MC（司会進行）', GUN:'軍師・サポーター' };

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
        <td class="right"><span class="row-toggle" onclick="toggleDetail(${idx}, this)">詳細 ▾</span></td>`;
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
          <button class="btn sm" onclick="toggleDetail(${idx})">閉じる</button>
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

  render();
</script>
@endverbatim
@endpush
