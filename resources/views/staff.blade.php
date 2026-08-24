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
  {{-- 「退職にする」「削除」を出すか＝Administratorだけ。自分自身には出さない。 --}}
  window.ECS_CAN_MANAGE_PEOPLE = @json($canManagePeople ?? false);
  window.ECS_MY_ID = @json($myId ?? null);
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
      <div class="mock-note">氏名・事務所・専属・通算・できるポジション・相性（NG）は、登録済みのスタッフデータ（DB）を表示しています。<br>※「詳細」を開くと、可否・NGペア・専属・人柄メモを<b>その場で編集・保存</b>できます（本人プロフィールは今後対応）。</div>

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
            <option value="OP">OP（音響）</option>
            <option value="MC">MC（司会進行）</option>
            <option value="SP">軍師・サポーター</option>
          </select>
          <div class="spacer"></div>
          <a class="btn primary" href="/account-new?role=staff"
             title="アカウント発行画面が開きます。スタッフのログインアカウントはそこで発行します。">＋ スタッフを招待</a>
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
      <div class="mock-note">稼働率・連勤・選ばれた率・ご無沙汰・活性度は、登録済みのアサイン・希望・応募データ（DB）から計算して表示しています（対象月＝{{ now()->format('Y年n月') }}）。データが無い場合は下の見本値を表示します。<br>※「気にかけたい人」のまとめは<b>アサインダッシュボード</b>に移動しました。</div>

      <!-- 数値カード -->
      <div class="grid cols-4" style="margin-bottom:20px;">
        <div class="stat">
          <div class="label">今月のアサイン総数</div>
          <div class="value" id="cTotal">0</div>
          <div class="sub">{{ now()->format('Y年n月') }}</div>
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
          <div class="label">活性度（人数）</div>
          <div style="display:flex; gap:6px; flex-wrap:wrap; margin:8px 0 6px;">
            <span class="act active">アクティブ <b id="cActive">0</b></span>
            <span class="act semi">準 <b id="cSemi">0</b></span>
            <span class="act inactive">非 <b id="cInactive">0</b></span>
          </div>
          <div class="sub">その月のエントリー率で判定</div>
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
            <option value="reg">並び：登録した順（番号順）</option>
          </select>
          <div class="spacer"></div>
          <button class="btn" onclick="location.href='/staff/export.csv'">CSV出力</button>
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
      </div><!-- /pane-work -->
@endverbatim
@endsection

@push('scripts')
{{-- 稼働状況は DB（assignments＋shift_preferences＋applications＋people）から計算して渡す。 --}}
<script>window.ECS_STATUS = @json($status); window.ECS_CSRF = '{{ csrf_token() }}';</script>
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
  // スタッフが「できる」として持つポジションは OP / MC / 軍師 の3つに限定。
  // （D はできるスタッフがほぼいない・FC/CK/受付 は誰でもやる前提なので「できること」管理の対象外）
  const POS = [
    { k:'OP',  label:'OP',       key:true },
    { k:'MC',  label:'MC',       key:true },
    { k:'SP', label:'軍師・サポ', key:true },
  ];
  const posFull = { OP:'OP（音響）', MC:'MC（司会進行）', SP:'軍師・サポーター' };

  // その人の「本人プロフィール」＝ people の実データ（Controller が profile として渡す）。
  // 以前は擬似ランダムの見本を出していたが、本人が公開ボードの設定／初回設定で入力した実データを表示する。
  // live ＝ どれか1つでも入力済みか（未入力なら「本人未入力」と出す）。
  function profileFor(p){
    const d = p.profile || {};
    const filled = ['appeal','likeC','dislikeC','strong','weak','height','shoe','shirt','pref','station','drive','english']
      .some(k => d[k]) || d.mcPass || d.kigurumi || d.stay;
    return { live: !!filled, ...d };
  }

  const tbody = document.getElementById('tbody');

  // OPの種類サフィックス（B案）：オンライン/リアルの可否を短く表す。未設定は空。
  function opFlavor(p){
    if (p.opOnline && p.opReal) return '（オ/リ）';
    if (p.opOnline) return '（オンライン）';
    if (p.opReal)   return '（リアル）';
    return '';
  }
  function posTagsHtml(p){
    const tags = POS.filter(x => p.pos[x.k]).map(x =>
      `<span class="ptag ${x.key?'key':''}">${x.label}${x.k==='OP'?opFlavor(p):''}</span>`);
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
      `<label><input type="checkbox" class="edit-pos" value="${x.k}" ${p.pos[x.k]?'checked':''}> ${posFull[x.k]}</label>`
    ).join('');
    const prof = profileFor(p);
    const profBadge = prof.live
      ? ' <span class="badge green" style="font-size:11px;">本人入力あり</span>'
      : ' <span class="muted" style="font-size:11px;">※本人未入力</span>';
    const skillBits = [];
    if (prof.mcPass)   skillBits.push('MC合格');
    if (prof.kigurumi) skillBits.push('着ぐるみOK');
    if (prof.stay)     skillBits.push('前泊・後泊OK');
    if (prof.drive)    skillBits.push('運転：' + prof.drive);
    if (prof.english)  skillBits.push('英語：' + prof.english);
    const physBits = [];
    if (prof.height) physBits.push('身長 ' + prof.height);
    if (prof.shoe)   physBits.push('靴 ' + prof.shoe);
    if (prof.shirt)  physBits.push('衣装 ' + prof.shirt);
    const areaBits = [];
    if (prof.pref)    areaBits.push(prof.pref);
    if (prof.station) areaBits.push(prof.station);
    return `
      <div class="detail-box">
        <div style="border:1px solid #e4ddd3;border-radius:8px;padding:10px 12px;margin-bottom:12px;background:#fbf8f3;">
          <h4 style="margin:0 0 6px;">本人プロフィール（本人入力）${profBadge}</h4>
          <div style="font-size:13px;line-height:1.9;">
            <div><b>一言アピール：</b>${prof.appeal||'（未入力）'}</div>
            <div><b>好きなコンテンツ：</b>${prof.likeC||'（未入力）'}</div>
            <div><b>苦手なコンテンツ：</b>${prof.dislikeC||'（未入力）'}</div>
            <div><b>得意なポジション：</b>${prof.strong||'（未入力）'}</div>
            <div><b>苦手なポジション：</b>${prof.weak||'（未入力）'}</div>
            <div><b>スキル：</b>${skillBits.length ? skillBits.join('／') : '（未入力）'}</div>
            <div><b>身体・サイズ：</b>${physBits.length ? physBits.join('／') : '（未入力）'}</div>
            <div><b>エリア：</b>${areaBits.length ? areaBits.join('　') : '（未入力）'}</div>
          </div>
        </div>
        <div class="dgrid">
          <div>
            <h4>ポジション可否（できる現場の役割）</h4>
            <div class="pos-check">${posChecks}</div>
            <div class="op-flavor" style="margin-top:6px; font-size:12.5px; color:var(--muted);">
              <span style="margin-right:8px;">OPの種類：</span>
              <label style="margin-right:10px;"><input type="checkbox" class="edit-op-online" ${p.opOnline?'checked':''}> オンライン可</label>
              <label><input type="checkbox" class="edit-op-real" ${p.opReal?'checked':''}> リアル(現地)可</label>
            </div>

            <h4 style="margin-top:16px;">区分</h4>
            <div class="trait">
              <label><input type="checkbox" class="edit-exclusive" ${p.exclusive?'checked':''}> 専属スタッフ</label>
            </div>

            <h4 style="margin-top:16px;">人柄・育成メモ</h4>
            <div class="trait">
              <label><input type="checkbox" class="edit-follow" ${p.traits.follow?'checked':''}> 新人フォローができる</label>
              <label><input type="checkbox" class="edit-starter" ${p.traits.starter?'checked':''}> 自分で考えて動ける</label>
              <label><input type="checkbox" class="edit-atmos" ${p.traits.atmos?'checked':''}> 現場の空気を良くする</label>
            </div>
          </div>
          <div>
            <h4>NGペア（同席を避ける組合せ）</h4>
            <textarea class="edit-ng" placeholder="NGにしたい相手の氏名を1行に1名ずつ">${p.ng.join('\n')}</textarea>
            <div class="muted" style="font-size:12px; margin-top:4px;">1行に1名。登録済みスタッフ名と一致すれば自動でひも付きます。</div>

            <h4 style="margin-top:16px;">メモ（イベプラ・Dからの印象／要点）</h4>
            <textarea class="edit-impression" placeholder="このスタッフについてのメモ">${p.dnote}</textarea>
          </div>
        </div>
        <div class="save-row">
          <button class="btn primary sm" onclick="rosterSave(${idx}, this)">保存する</button>
          <button class="btn sm" onclick="rosterToggle(${idx})">閉じる</button>
          <span class="save-status muted" style="font-size:12px;"></span>
        </div>
        ${personAdminHtml(p)}
      </div>`;
  }

  // Administrator だけに出す「退職にする／在籍に戻す」「削除」（社員名簿と同じ作り）。
  // 辞めた方は削除ではなく退職（在籍を外す）＝過去の案件の記録を残すため。
  function personAdminHtml(p){
    if (!window.ECS_CAN_MANAGE_PEOPLE) return '';
    if (p.id === window.ECS_MY_ID) {
      return `<div class="save-row" style="border-top:1px dashed var(--line); padding-top:10px;">
        <span class="muted" style="font-size:12px;">※ ご自身の退職・削除はできません。</span></div>`;
    }
    const isActive = p.active !== false;
    const actLabel = isActive ? '退職にする（在籍を外す）' : '在籍に戻す';
    // onclick に名前やIDを埋めると引用符でこわれやすいので data- で持たせる。
    return `<div class="save-row" style="border-top:1px dashed var(--line); padding-top:10px; flex-wrap:wrap;">
        <button class="btn sm" data-act-id="${p.id}" data-act-next="${isActive ? 'false' : 'true'}">${actLabel}</button>
        <button class="btn sm" style="color:#b91c1c; border-color:#f0c2c2;"
                data-del-id="${p.id}" data-del-name="${String(p.name).replace(/"/g, '&quot;')}">🗑 名簿から削除</button>
        <span class="muted" style="font-size:12px;">辞められた方は<b>「退職にする」</b>を選んでください（名簿に残り、アサインの候補には出なくなります）。<b>削除</b>は、間違えて登録した人・テストで作った人の片づけ用です。アサインやエントリーの記録がある人は削除できません。</span>
      </div>`;
  }

  // クリックの受け取りは1か所にまとめる（行はJSで作り直されるため）。
  document.addEventListener('click', function (e) {
    const act = e.target.closest('[data-act-id]');
    if (act) { setPersonActive(act.dataset.actId, act.dataset.actNext === 'true'); return; }
    const del = e.target.closest('[data-del-id]');
    if (del) { deletePerson(del.dataset.delId, del.dataset.delName); }
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

  // 詳細パネルの「保存する」→ 可否(OP/MC/SP)・専属・人柄・NG・メモをDBへ保存（AJAX）。
  function saveStaff(idx, btn){
    const dr = tbody.querySelector(`tr.detail-row[data-for="${idx}"]`);
    if (!dr) return;
    const p = staff[idx];
    const posSel = Array.from(dr.querySelectorAll('.edit-pos:checked')).map(c => c.value);
    const opOnline = !!(dr.querySelector('.edit-op-online') && dr.querySelector('.edit-op-online').checked);
    const opReal   = !!(dr.querySelector('.edit-op-real') && dr.querySelector('.edit-op-real').checked);
    const exclusive = !!(dr.querySelector('.edit-exclusive') && dr.querySelector('.edit-exclusive').checked);
    const follow    = !!(dr.querySelector('.edit-follow') && dr.querySelector('.edit-follow').checked);
    const starter   = !!(dr.querySelector('.edit-starter') && dr.querySelector('.edit-starter').checked);
    const atmosT    = !!(dr.querySelector('.edit-atmos') && dr.querySelector('.edit-atmos').checked);
    const ngText    = (dr.querySelector('.edit-ng') || {}).value || '';
    const memo      = (dr.querySelector('.edit-impression') || {}).value || '';
    const statusEl  = dr.querySelector('.save-status');
    const body = new URLSearchParams();
    posSel.forEach(v => body.append('positions[]', v));
    ['OP','MC','SP'].forEach(v => body.append('managed_positions[]', v));  // この画面が扱う可否はこの3つだけ
    body.append('op_online', opOnline ? '1' : '0');   // OPオンライン可（B案）
    body.append('op_real',   opReal ? '1' : '0');     // OPリアル(現地)可（B案）
    if (exclusive) body.append('exclusive', '1');
    if (follow)    body.append('follow', '1');
    if (starter)   body.append('starter', '1');
    if (atmosT)    body.append('atmos', '1');
    body.append('ng', ngText);
    body.append('impression', memo);
    if (btn) btn.disabled = true;
    if (statusEl) { statusEl.textContent = '保存中…'; statusEl.style.color = 'var(--muted)'; }
    fetch(`/staff/${encodeURIComponent(p.id)}/edit`, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': window.ECS_CSRF || '', 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    })
    .then(r => r.ok ? r.json() : Promise.reject(r.status))
    .then(() => {
      ['OP','MC','SP'].forEach(k => { p.pos[k] = posSel.includes(k); });
      p.opOnline = opOnline; p.opReal = opReal;
      p.exclusive = exclusive;
      p.traits = { follow: follow, starter: starter, atmos: atmosT };
      p.ng = ngText.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
      p.dnote = memo;
      const mr = tbody.querySelector(`tr.main-row[data-idx="${idx}"]`);
      if (mr) {
        const tds = mr.querySelectorAll('td');
        if (tds[3]) tds[3].innerHTML = p.exclusive ? '<span class="badge green">専属</span>' : '<span class="muted" style="font-size:12px;">—</span>';
        if (tds[5]) tds[5].innerHTML = posTagsHtml(p);
        if (tds[6]) tds[6].innerHTML = relHtml(p);
      }
      if (statusEl) { statusEl.textContent = '✓ 保存しました'; statusEl.style.color = '#15803d'; }
      if (btn) btn.disabled = false;
    })
    .catch(err => {
      if (statusEl) { statusEl.textContent = '保存に失敗しました（' + err + '）'; statusEl.style.color = 'var(--danger)'; }
      if (btn) btn.disabled = false;
    });
  }

  // 名簿タブの中で、HTML内のクリックから呼ぶ関数だけ外に出す（名前は roster〜 で固有化）
  window.rosterFilter = applyFilter;
  window.rosterToggle = toggleDetail;
  window.rosterSave = saveStaff;

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
      if (sortKey === 'reg')     return String(a.id).localeCompare(String(b.id), 'ja', {numeric:true}); // 登録した順＝スタッフ番号の若い順
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

  // 数値カード（「気にかけたい人」の一覧はアサインダッシュボードへ移動済み）
  function renderSummary(){
    const total = status.reduce((s,p) => s + p.month, 0);
    const rated = status.map(rateOf).filter(v => v !== null && v !== undefined);
    const avg = rated.length ? Math.round(rated.reduce((s,v) => s + v, 0) / rated.length) : 0;
    const zero = status.filter(p => p.zeroPref);
    const active = status.filter(p => p.active === 'active');
    const semi = status.filter(p => p.active === 'semi');
    const inactive = status.filter(p => p.active === 'inactive');
    document.getElementById('cActive').textContent = active.length;
    document.getElementById('cSemi').textContent = semi.length;
    document.getElementById('cTotal').textContent = total;
    document.getElementById('cAvg').textContent = avg + '%';
    document.getElementById('cZero').textContent = zero.length;
    document.getElementById('cInactive').textContent = inactive.length;
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
