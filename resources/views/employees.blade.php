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
  {{-- 「退職にする」「削除」を出すか＝Administratorだけ。自分自身には出さない。 --}}
  window.ECS_CAN_MANAGE_PEOPLE = @json($canManagePeople ?? false);
  window.ECS_CAN_MANAGE_OFFICE = @json($canManageOffice ?? false);   // 拠点を直せるか（管理者以上）
  window.ECS_EXPERIENCE = @json($experience ?? new stdClass);   // 経験回数（アサインから自動集計）
  window.ECS_MY_ID = @json($myId ?? null);
  {{-- 所属の選択肢（実際の10種類）。氏名・ふりがな・所属を直す欄で使う。 --}}
  window.ECS_DEPT_ALL = @json(\App\Support\Departments::ALL);
  {{-- ログイン案内メールのボタンを出すか＝管理者以上。 --}}
  window.ECS_CAN_INVITE = @json($canInvite ?? false);
  {{-- 「アサイン表に出す／出さない」を切り替えられるか＝管理者以上（2026-08-26 baba要望）。 --}}
  window.ECS_CAN_ASSIGN_POOL = @json($canManageAssignPool ?? false);
  {{-- 拠点で絞って見るための選択肢と、自分の拠点（2026-08-25 baba要望）。
       ⚠ 拠点名をJSに書き足さない。正本は拠点マスタ（共通設定 → マスタ管理）。 --}}
  window.ECS_OFFICES = @json($offices ?? []);
  window.ECS_MY_OFFICE = @json($myOffice ?? '');
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
          <!-- 拠点で絞る。選択肢は拠点マスタから（ここに拠点名を書かない）。既定は自分の拠点。 -->
          <select id="fOffice" onchange="applyFilter()"></select>
          <div class="spacer"></div>
          <a class="btn primary" href="/account-new" title="アカウント発行画面が開きます。社員のログインアカウントはそこで発行します。">＋ 社員を追加</a>
        </div>

        <div class="count-line"><span id="countTxt">0</span> 名を表示中
          <span class="muted" id="officeHint" style="font-size:11.5px;"></span>
        </div>

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

  // 所属バッジ。兼務があれば全部出す（先頭＝主な所属）。所属が無ければ「未設定」。
  function deptBadges(p){
    const list = Array.isArray(p.depts) ? p.depts : [];
    if (!list.length) return '<span class="dept none">未設定</span>';
    return list.map(function(d, i){
      // 主な所属（先頭）は太字のまま。兼務は少し小さく出して見分けられるようにする。
      const style = i === 0 ? '' : ' style="opacity:.85; font-size:11px;"';
      return '<span class="dept ' + d.code + '"' + style + '>' + d.name + '</span>';
    }).join(' ');
  }

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
        <td><strong>${p.name}</strong>${fresh ? '<span class="fresh-badge">新人</span>' : ''}${p.active === false ? '<span class="dept none" style="margin-left:6px;">退職</span>' : ''}${p.inAssignPool === false ? '<span class="dept none" style="margin-left:6px;" title="社員の出勤可能日の一覧・D決め・D/SD/物品担当のプルダウンに出しません">アサイン対象外</span>' : ''}${loginBadge(p)}
            <br><span class="muted" style="font-size:11.5px;">${p.id}</span>
            <br><span class="muted" style="font-size:11.5px;">${p.kana
              ? p.kana
              : '<span style="color:#b5673a;">ふりがな未入力</span>'}</span></td>
        <td>${deptBadges(p)}</td>
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

            <div class="exp-block">
              <h4>経験回数（自動集計）</h4>
              <p class="muted" style="font-size:11.5px; margin:0 0 6px;">
                上の2つは<b>手で選ぶ申告</b>です。こちらは<b>アサインの実績から自動で数えたもの</b>で、直せません。
              </p>
              ${experienceAutoHtml(p)}
            </div>
          </div>
          <div>
            ${identityEditorHtml(p, idx)}
            ${officeEditorHtml(p, idx)}
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

            ${assignPoolHtml(p, idx)}

            ${fresh ? `<div class="muted" style="font-size:12px; margin-top:12px;">🌱 入社半年以内の新人です。経験コンテンツとDの経験コンテンツを重点的に確認してください。</div>` : ''}
          </div>
        </div>
        <div class="save-row">
          <button class="btn primary sm" onclick="saveExperience(${idx})">経験コンテンツを保存</button>
          <span class="save-ok" id="expSaved-${idx}" style="display:none;">✓ 保存しました</span>
          <button class="btn sm" onclick="toggleDetail(${idx})">閉じる</button>
          <span class="muted" style="font-size:12px;">※「経験コンテンツ」「Dの経験コンテンツ」「サイズ」の変更は保存されます。社員はエントリーしません（この名簿はアサインとは別管理）。</span>
        </div>
        ${inviteHtml(p)}
        ${personAdminHtml(p)}
      </div>`;
  }

  // ログインの状態バッジ（2026-08-25）。誰がまだログインできないかを名簿で分かるようにする。
  function loginBadge(p){
    var m = {
      ready:   ['ログインできる', '#e7f6ec', '#15803d'],
      invited: ['案内メール送信済み', '#fdf3e2', '#8a5a10'],
      temp:    ['仮パスワード発行済み', '#e0f2fe', '#0369a1'],
      none:    ['まだアカウント無し', '#f1ece4', '#7a6f63']
    };
    var v = m[p.login] || m.none;
    return '<span style="margin-left:6px; font-size:11px; padding:1px 8px; border-radius:999px; white-space:nowrap;'
      + 'background:' + v[1] + '; color:' + v[2] + ';">' + v[0] + '</span>';
  }

  // 「ログイン案内を送る」ボタン。メールが未登録ならその場で入力してもらう。
  function inviteHtml(p){
    if (!window.ECS_CAN_INVITE) return '';
    var label = p.login === 'ready' ? '案内メールを送り直す' : '📧 ログイン案内メールを送る';
    var sub = p.invitedAt ? ('（前回 ' + p.invitedAt + ' に送信）') : '';
    return `<div class="save-row" style="border-top:1px dashed var(--line); padding-top:10px; flex-wrap:wrap;">
        <label class="size-item" style="font-size:13px;">メール：
          <input type="text" class="size-input" style="width:230px;" id="inv-mail-${p.id}" value="${escAttr(p.email)}" placeholder="name@ikusa.co.jp">
        </label>
        <button class="btn sm" data-invite-id="${p.id}">${label}</button>
        <span class="muted" style="font-size:12px;">${sub}
          パスワードはメールに書きません。本人が<b>リンクから自分で決めます</b>（有効7日間）。</span>
      </div>`;
  }

  function sendInvite(id){
    var el = document.getElementById('inv-mail-' + id);
    var email = el ? el.value.trim() : '';
    if (!email) { alert('メールアドレスを入れてください。'); return; }
    if (!confirm(email + ' 宛にログイン案内メールを送ります。よろしいですか？')) return;
    fetch('/people/' + encodeURIComponent(id) + '/invite', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF || '', 'Accept': 'application/json' },
      body: JSON.stringify({ email: email })
    }).then(r => r.json().then(j => ({ ok: r.ok, j })))
      .then(({ ok, j }) => {
        alert((j && j.message) || (ok ? '送りました。' : '送れませんでした。'));
        if (ok) location.reload();
      })
      .catch(() => alert('通信に失敗しました。もう一度お試しください。'));
  }

  // Administrator だけに出す「氏名・ふりがな・所属を直す」欄。
  // なぜ要るか＝名簿CSVの見本行（山田花子／やまだ はなこ）を消し忘れて取り込むと、
  // 本人がログインするまで間違ったふりがなが残ってしまう（2026-08-24 実際に発生）。
  function identityEditorHtml(p, idx){
    if (!window.ECS_CAN_MANAGE_PEOPLE) return '';
    const deptOpts = (window.ECS_DEPT_ALL || []).map(function(d){
      return '<option value="' + d + '"' + (p.deptMain === d ? ' selected' : '') + '>' + d + '</option>';
    }).join('');
    const deptChecks = (window.ECS_DEPT_ALL || []).map(function(d){
      const on = Array.isArray(p.depts) && p.depts.some(function(x){ return x.name === d; });
      return '<label style="display:inline-flex; align-items:center; gap:5px; font-size:12px; margin-right:12px;">'
        + '<input type="checkbox" class="idn-dept-' + idx + '" value="' + d + '"' + (on ? ' checked' : '') + ' style="width:auto;"> ' + d + '</label>';
    }).join('');
    return `<h4>氏名・ふりがな・所属を直す</h4>
      <div class="size-row" style="gap:10px;">
        <label class="size-item">氏名：<input type="text" class="size-input" style="width:150px;" id="idn-name-${idx}" value="${escAttr(p.name)}"></label>
        <label class="size-item">ふりがな：<input type="text" class="size-input" style="width:170px;" id="idn-kana-${idx}" value="${escAttr(p.kana)}" placeholder="例：やまだ たろう"></label>
      </div>
      <div class="size-row" style="margin-top:8px;">
        <label class="size-item">主な所属：
          <select class="size-input" style="width:150px;" id="idn-dept-${idx}"><option value="">未設定</option>${deptOpts}</select>
        </label>
      </div>
      <div style="margin-top:6px;">
        <span class="muted" style="font-size:12px; display:block; margin-bottom:4px;">兼務している所属</span>
        ${deptChecks}
      </div>
      <div class="save-row" style="margin-top:10px;">
        <button class="btn primary sm" onclick="saveIdentity(${idx}, this)">氏名・ふりがな・所属を保存</button>
        <span class="save-ok" id="idnSaved-${idx}" style="display:none;">✓ 保存しました</span>
        <span class="muted" style="font-size:12px;">※ CSVの見本行を消し忘れたときなど、本人以外が直す必要があるときに使います。</span>
      </div>
      <hr style="border:none; border-top:1px dashed var(--line); margin:14px 0;">`;
  }


  // ===== 経験回数（自動集計・2026-08-27 baba要望）=====
  // ⚠ 上の「経験のあるコンテンツ」は**手で選ぶ申告**。こちらは**アサインの実績を自動で数えたもの**で別物。
  //   正本＝App\Support\ExperienceCount。数え方＝確定のアサインで開催日が過ぎたもの（キャンセルは除く）。
  //   ここに数え方を書かないこと（サーバー側と食い違う）。
  function experienceAutoHtml(p){
    const e = (window.ECS_EXPERIENCE || {})[p.id];
    if (!e || !e.projects) {
      return '<span class="muted" style="font-size:12.5px;">まだありません'
        + '（確定のアサインで開催日が過ぎたものを数えます）</span>';
    }

    const dayNote = (e.days > e.projects) ? '（出勤 ' + e.days + ' 日）' : '';
    let html = '<div style="font-size:12.5px; margin-bottom:6px;">通算 <b>' + e.projects + ' 件</b> ' + dayNote + '</div>';

    if (e.byRole && e.byRole.length) {
      html += '<div style="margin-bottom:8px;">';
      e.byRole.forEach(function(r){
        html += '<span class="badge" style="margin:0 6px 4px 0;">' + escAttr(r.label) + ' ' + r.count + '回</span>';
      });
      html += '</div>';
    }

    // ⚠ コンテンツごとの表は「経験回数」の画面（/experience）に移した（2026-08-28 baba要望）。
    //   ここは1人ずつしか見られないので「このコンテンツをやれる人は誰か」を探せなかった。
    if (e.byContent && e.byContent.length) {
      html += '<div style="font-size:12.5px; margin-bottom:4px;"><b>よくやったコンテンツ</b></div><div>';
      e.byContent.slice(0, 5).forEach(function(c){
        html += '<span class="badge" style="margin:0 6px 4px 0;">' + escAttr(c.name) + ' ' + c.count + '回</span>';
      });
      if (e.byContent.length > 5) {
        html += '<span class="muted" style="font-size:11.5px;">ほか' + (e.byContent.length - 5) + '種類</span>';
      }
      html += '</div>';
    }

    html += '<div style="margin-top:8px; font-size:12px;">'
      + '<a href="/experience" style="color:#6b5544;">🏅 経験回数の画面で見る（コンテンツごと・ポジションごとに人を探せます）→</a></div>';

    return html;
  }

  // 拠点（事務所）を直す欄。2026-08-27 baba要望＝これまで画面から直せなかった。
  // ⚠ 直せるのは管理者以上（氏名・所属の Administrator のみとは別の線引き）。
  // ⚠ 拠点名は拠点マスタ（window.ECS_OFFICES）から作る＝画面に拠点名を直書きしない。
  function officeEditorHtml(p, idx){
    if (!window.ECS_CAN_MANAGE_OFFICE) return '';
    const cur = p.office || '';
    const opts = (window.ECS_OFFICES || []).map(function(o){
      return '<option value="' + escAttr(o) + '"' + (o === cur ? ' selected' : '') + '>' + escAttr(o) + '</option>';
    }).join('');
    return `<h4>拠点（事務所）を直す</h4>
      <div class="size-row" style="gap:10px;">
        <label class="size-item">拠点：
          <select class="size-input" style="width:150px;" id="ofc-${idx}"><option value="">未設定</option>${opts}</select>
        </label>
      </div>
      <div class="save-row" style="margin-top:10px;">
        <button class="btn primary sm" onclick="saveOffice(${idx}, this)">拠点を保存</button>
        <span class="save-ok" id="ofcSaved-${idx}" style="display:none;">✓ 保存しました</span>
        <span class="muted" style="font-size:12px;">※ 未設定のままだと「東京」として扱われます。拠点を間違えると別の拠点のデータが見えます。</span>
      </div>
      <hr style="border:none; border-top:1px dashed var(--line); margin:14px 0;">`;
  }

  function saveOffice(idx, btn){
    const p = employees[idx];
    const office = (document.getElementById('ofc-' + idx) || {}).value || '';
    const body = new URLSearchParams();
    body.append('office', office);
    if (btn) btn.disabled = true;
    fetch('/employees/' + encodeURIComponent(p.id) + '/profile', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF },
      body: body.toString()
    })
    .then(r => r.json().then(j => ({ ok: r.ok, j })))
    .then(({ ok, j }) => {
      if (!ok) { alert((j && j.message) || '保存できませんでした。'); if (btn) btn.disabled = false; return; }
      // 拠点で絞っている一覧に出る／出なくなるので読み込み直す。
      location.reload();
    })
    .catch(() => { alert('保存に失敗しました。通信を確認して、もう一度お試しください。'); if (btn) btn.disabled = false; });
  }

  // 属性値に入れる文字をエスケープする（氏名に " や < が入っても壊れないように）。
  function escAttr(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) {
      return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[ch];
    });
  }

  function saveIdentity(idx, btn){
    const p = employees[idx];
    const name = (document.getElementById('idn-name-' + idx) || {}).value || '';
    const kana = (document.getElementById('idn-kana-' + idx) || {}).value || '';
    const dept = (document.getElementById('idn-dept-' + idx) || {}).value || '';
    if (!name.trim()) { alert('氏名は空にできません。'); return; }
    const body = new URLSearchParams();
    body.append('name', name.trim());
    body.append('name_kana', kana.trim());
    body.append('department', dept);
    document.querySelectorAll('.idn-dept-' + idx + ':checked').forEach(function(el){
      body.append('departments[]', el.value);
    });
    if (btn) btn.disabled = true;
    fetch('/employees/' + encodeURIComponent(p.id) + '/profile', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF },
      body: body.toString()
    })
    .then(r => r.json().then(j => ({ ok: r.ok, j })))
    .then(({ ok, j }) => {
      if (!ok) { alert((j && j.message) || '保存できませんでした。'); if (btn) btn.disabled = false; return; }
      // 並び順（社歴順・五十音順）も変わるので読み込み直す。
      location.reload();
    })
    .catch(() => { alert('通信に失敗しました。もう一度お試しください。'); if (btn) btn.disabled = false; });
  }

  // Administrator だけに出す「退職にする／在籍に戻す」「削除」。
  // 辞めた人は削除ではなく退職（在籍を外す）＝過去の案件の記録を残すため。
  function personAdminHtml(p){
    if (!window.ECS_CAN_MANAGE_PEOPLE) return '';
    if (p.id === window.ECS_MY_ID) {
      return '<div class="save-row" style="border-top:1px dashed var(--line); padding-top:10px;">'
        + '<span class="muted" style="font-size:12px;">※ ご自身の退職・削除はできません。</span></div>';
    }
    const actLabel = p.active ? '退職にする（在籍を外す）' : '在籍に戻す';
    const next = p.active ? 'false' : 'true';
    // onclick の中に名前やIDを埋めると引用符でこわれやすいので、data- で持たせて
    // クリック時に読み取る（氏名に「'」が入っても壊れない）。
    return `<div class="save-row" style="border-top:1px dashed var(--line); padding-top:10px;">
        <button class="btn sm" data-act-id="${p.id}" data-act-next="${next}">${actLabel}</button>
        <button class="btn sm" style="color:#b91c1c; border-color:#f0c2c2;"
                data-del-id="${p.id}" data-del-name="${String(p.name).replace(/"/g, '&quot;')}">🗑 名簿から削除</button>
        <span class="muted" style="font-size:12px;">辞められた方は<b>「退職にする」</b>を選んでください（名簿に残り、アサインの候補には出なくなります）。<b>削除</b>は、間違えて登録した人・テストで作った人の片づけ用です。アサイン等の記録がある人は削除できません。</span>
      </div>`;
  }

  // クリックの受け取りは1か所にまとめる（行はJSで作り直されるので、都度つけ直さない形にする）。
  document.addEventListener('click', function (e) {
    const act = e.target.closest('[data-act-id]');
    if (act) { setPersonActive(act.dataset.actId, act.dataset.actNext === 'true'); return; }
    const del = e.target.closest('[data-del-id]');
    if (del) { deletePerson(del.dataset.delId, del.dataset.delName); return; }
    const inv = e.target.closest('[data-invite-id]');
    if (inv) { sendInvite(inv.dataset.inviteId); }
  });

  function setPersonActive(id, active){
    const msg = active
      ? 'この方を「在籍」に戻します。よろしいですか？'
      : ['この方を「退職（在籍なし）」にします。',
         '名簿には残りますが、アサインの候補には出なくなります。',
         '',
         'よろしいですか？'].join('\n');
    if (!confirm(msg)) return;
    fetch('/people/' + encodeURIComponent(id) + '/active', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF || '', 'Accept': 'application/json' },
      body: JSON.stringify({ active: active })
    }).then(r => r.json().then(j => ({ ok: r.ok, j })))
      .then(({ ok, j }) => {
        alert(j.message || (ok ? '変更しました。' : '変更できませんでした。'));
        if (ok) location.reload();
      })
      .catch(() => alert('通信に失敗しました。もう一度お試しください。'));
  }

  function deletePerson(id, name){
    const msg = ['「' + name + '」さんを名簿から削除します。',
                 '',
                 '⚠ 元に戻せません。',
                 '辞められた方の場合は、削除ではなく「退職にする」を選んでください。',
                 '',
                 '本当に削除しますか？'].join('\n');
    if (!confirm(msg)) return;
    fetch('/people/' + encodeURIComponent(id) + '/delete', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF || '', 'Accept': 'application/json' }
    }).then(r => r.json().then(j => ({ ok: r.ok, j })))
      .then(({ ok, j }) => {
        alert(j.message || (ok ? '削除しました。' : '削除できませんでした。'));
        if (ok) location.reload();
      })
      .catch(() => alert('通信に失敗しました。もう一度お試しください。'));
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

  // ===== アサイン表に出す／出さない（2026-08-26 baba要望）=====
  // ⚠ 名簿から消すわけではない。出勤可能日の一覧・D決め・D/SD/物品担当のプルダウンに
  //   出さないだけ（営業だけの人も「営業担当」には選べないと困るため）。
  function assignPoolHtml(p, idx){
    const on = p.inAssignPool !== false;
    if (!window.ECS_CAN_ASSIGN_POOL) {
      return `<h4 style="margin-top:16px;">アサイン表への表示</h4>
        <div class="muted" style="font-size:12px;">
          ${on ? 'アサインの候補に出ます。' : 'アサインの候補に<b>出しません</b>。'}
          切り替えられるのは管理者以上です。
        </div>`;
    }
    return `<h4 style="margin-top:16px;">アサイン表への表示</h4>
      <label style="display:flex; align-items:flex-start; gap:8px; font-size:12.5px; line-height:1.7;">
        <input type="checkbox" id="pool-${idx}" ${on ? 'checked' : ''}
               onchange="saveAssignPool(${idx}, this)" style="margin-top:3px;">
        <span>アサインの候補に出す<br>
          <span class="muted" style="font-size:11.5px;">
            外すと<b>社員の出勤可能日の一覧・D決め・D/SD/物品担当のプルダウン</b>に出なくなります。
            名簿・集計・営業担当のプルダウンには今までどおり出ます。
            すでに担当に入っている案件からは外れません。
          </span>
        </span>
      </label>
      <div class="save-row" style="margin-top:6px;">
        <span class="save-ok" id="poolSaved-${idx}" style="display:none;">✓ 保存しました</span>
      </div>`;
  }

  // チェックを変えたらその場で保存する（他の欄と違い、押し忘れが事故になるため）。
  function saveAssignPool(idx, el){
    const p = employees[idx];
    const want = !!el.checked;
    el.disabled = true;
    const body = new URLSearchParams();
    body.append('in_assign_pool', want ? '1' : '0');
    fetch(`/employees/${encodeURIComponent(p.id)}/profile`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF },
      body: body.toString()
    })
    .then(r => r.json().then(j => ({ ok: r.ok, j })))
    .then(({ ok, j }) => {
      if (!ok || !j.ok) { throw new Error(j && j.message ? j.message : 'save failed'); }
      p.inAssignPool = want;
      const s = document.getElementById(`poolSaved-${idx}`);
      if (s) { s.style.display = 'inline'; setTimeout(() => { s.style.display = 'none'; }, 1600); }
      applyFilter();   // 一覧の「アサイン対象外」の印を更新
    })
    .catch(e => {
      el.checked = !want;   // 保存できなかったら元に戻す（画面と中身がずれないように）
      alert(e.message || '保存できませんでした。');
    })
    .finally(() => { el.disabled = false; });
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

  // ===== 拠点（事務所）の絞り込み（2026-08-25 baba要望）=====
  // ⚠ 事務所が空の人は「東京」として扱う。アプリの他の場所（案件・ボード）と同じ決まりで、
  //   空の人がどの拠点にも出てこなくなるのを防ぐため。名簿の事務所の欄は「—」のままなので、
  //   誰が未入力かは見て分かる。
  function officeOf(p){
    var o = (p && p.office ? String(p.office) : '').trim();
    return o !== '' ? o : '東京';
  }
  // 拠点の選択肢を作る。既定は自分の拠点（「すべての拠点」も選べる）。
  function buildOfficeFilter(selId){
    var sel = document.getElementById(selId);
    if (!sel) return;
    var list = (window.ECS_OFFICES || []);
    var mine = (window.ECS_MY_OFFICE || '').trim();
    var html = '<option value="">拠点：すべて</option>';
    list.forEach(function(o){
      html += '<option value="' + o + '"' + (o === mine ? ' selected' : '') + '>' + o + '</option>';
    });
    sel.innerHTML = html;
  }

  // 「いま何拠点で絞っているか」を件数の横に出す。
  // ⚠ 既定が自分の拠点なので、これが無いと「他拠点の人が消えた」と誤解されるため。
  function showOfficeHint(office){
    var el = document.getElementById('officeHint');
    if (!el) return;
    el.textContent = office
      ? '（' + office + 'の人だけを表示中。他の拠点も見るときは「拠点：すべて」を選んでください）'
      : '（すべての拠点を表示中）';
  }

  function applyFilter(){
    const kw     = document.getElementById('kw').value.trim();
    const fDept  = document.getElementById('fDept').value;
    const fFresh = document.getElementById('fFresh').value;
    const fOfficeEl = document.getElementById('fOffice');
    const fOffice = fOfficeEl ? fOfficeEl.value : '';
    let shown = 0;
    employees.forEach((p, idx) => {
      const mr = tbody.querySelector(`tr.main-row[data-idx="${idx}"]`);
      const dr = tbody.querySelector(`tr.detail-row[data-for="${idx}"]`);
      const fresh = isFresh(p.joinedMonths);
      const okKw    = !kw    || p.name.includes(kw) || p.id.includes(kw) || (p.kana || '').includes(kw);
      // 所属の絞り込みは兼務も見る（兼ねている所属のどれかが一致すればヒット）。
      const okDept  = !fDept
        || p.dept === fDept
        || (Array.isArray(p.depts) && p.depts.some(function(d){ return d.code === fDept; }));
      const okFresh = !fFresh|| fresh;
      const okOffice = !fOffice || officeOf(p) === fOffice;
      const visible = okKw && okDept && okFresh && okOffice;
      mr.style.display = visible ? '' : 'none';
      if (!visible && dr) { dr.style.display = 'none';
        const t = mr.querySelector('.row-toggle'); if (t) t.innerHTML = '詳細 ▾'; }
      if (visible) shown++;
    });
    document.getElementById('countTxt').textContent = shown;
    showOfficeHint(fOffice);
  }

  buildOfficeFilter('fOffice');
  render();
</script>
@endverbatim
@endpush
