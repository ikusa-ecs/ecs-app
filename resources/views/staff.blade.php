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
  window.ECS_CAN_MANAGE_OFFICE = @json($canManageOffice ?? false);   // 拠点を直せるか（管理者以上）
  window.ECS_EXPERIENCE = @json($experience ?? new stdClass);   // 経験回数（アサインから自動集計）
  window.ECS_MY_ID = @json($myId ?? null);
  {{-- ログイン案内メールのボタンを出すか＝管理者以上。 --}}
  window.ECS_CAN_INVITE = @json($canInvite ?? false);
  window.ECS_CSRF = '{{ csrf_token() }}';
  {{-- 拠点で絞って見るための選択肢と、自分の拠点（2026-08-25 baba要望）。
       ⚠ 拠点名をJSに書き足さない。正本は拠点マスタ（共通設定 → マスタ管理）。 --}}
  window.ECS_OFFICES = @json($offices ?? []);
  window.ECS_MY_OFFICE = @json($myOffice ?? '');
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
          <!-- メールをもらった順に案内を送る運用のため、「まだの人」だけ出せるようにする。 -->
          <!-- 拠点で絞る。選択肢は拠点マスタから（ここに拠点名を書かない）。既定は自分の拠点。 -->
          <select id="fOffice" onchange="rosterFilter()"></select>
          <!-- 並び替え（2026-08-27 baba要望）。既定は今までどおり「経験回数の多い順」。 -->
          <select id="fRosterSort" onchange="rosterSort()">
            <option value="total">並び：経験回数の多い順</option>
            <option value="kana">並び：五十音順（あいうえお順）</option>
            <option value="reg">並び：登録した順（番号順）</option>
          </select>
          <select id="fLogin" onchange="rosterFilter()">
            <option value="">ログイン：すべて</option>
            <option value="none">まだアカウント無し</option>
            <option value="invited">案内メール送信済み（未設定）</option>
            <option value="spot">臨時スタッフのみ</option>
            <option value="notspot">臨時スタッフを除く</option>
            <option value="temp">仮パスワード発行済み</option>
            <option value="ready">ログインできる</option>
            <option value="notready">まだログインできない人（まとめて）</option>
          </select>
          <div class="spacer"></div>
          <a class="btn primary" href="/account-new?role=staff"
             title="アカウント発行画面が開きます。スタッフのログインアカウントはそこで発行します。">＋ スタッフを招待</a>
        </div>

        <div class="count-line"><span id="countTxt">0</span> 名を表示中
          <span class="muted" id="officeHint" style="font-size:11.5px;"></span>
        </div>

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
@endverbatim
      {{-- 対象月（今月）を出すので、Bladeを解釈する区間に置く。--}}
      <div class="mock-note">稼働率・連勤・選ばれた率・ご無沙汰・活性度は、登録済みのアサイン・希望・応募データ（DB）から計算して表示しています（対象月＝{{ now()->format('Y年n月') }}）。データが無い場合は下の見本値を表示します。<br>※「気にかけたい人」のまとめは<b>アサインダッシュボード</b>に移動しました。</div>

      <!-- 数値カード -->
      <div class="grid cols-4" style="margin-bottom:20px;">
        <div class="stat">
          <div class="label">今月のアサイン総数</div>
          <div class="value" id="cTotal">0</div>
          <div class="sub">{{ now()->format('Y年n月') }}</div>
        </div>
@verbatim
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
          <!-- 名簿タブと同じく拠点で絞る（既定は自分の拠点）。 -->
          <select id="wOffice" onchange="workFilter()"></select>
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
    const filled = ['appeal','likeC','dislikeC','strong','weak','height','shoe','shirt','pref','station','drive','english',
                    'otherLang','toolsOther','note']
      .some(k => d[k]) || d.mcPass || d.kigurumi || d.stay
      || (d.challenge||[]).length > 0 || (d.tools||[]).length > 0;
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

  // 拠点（事務所）の編集欄。2026-08-27 baba要望＝これまで画面から直せなかった。
  // ⚠ 直せるのは管理者以上（拠点を間違えると別の拠点のデータが見えるため）。それ未満は表示だけ。
  // ⚠ 拠点名は拠点マスタ（window.ECS_OFFICES）から作る＝画面に拠点名を直書きしない。
  // 氏名を直す（Administratorだけ・2026-08-28 baba要望）。
  // ⚠ スタッフは「苗字と名前をつめる」運用なので、保存すると空白は自動で取り除かれる
  //   （社員は「姓 名」と空ける）。中身は App\Support\StaffName が正本。
  function nameEditor(p){
    if (!window.ECS_CAN_MANAGE_PEOPLE) return '';
    return `<h4 style="margin-top:16px;">氏名</h4>
      <div class="trait">
        <input type="text" class="edit-name" value="${escAttrS(p.name)}"
               style="padding:5px 8px; font-family:inherit; font-size:13px; width:220px;">
        <div class="muted" style="font-size:12px; margin-top:4px;">
          スタッフは<b>苗字と名前をつめて</b>保存します（空白は自動で取り除きます）。
        </div>
      </div>`;
  }

  function officeEditor(p){
    const cur = p.office || '';
    if (!window.ECS_CAN_MANAGE_OFFICE) {
      return '<span class="muted" style="font-size:12.5px;">'
        + (cur ? escAttrS(cur) : '未設定（東京として扱われます）')
        + '　※直せるのは管理者以上です</span>';
    }
    let opts = '<option value=""' + (cur === '' ? ' selected' : '') + '>未設定</option>';
    (window.ECS_OFFICES || []).forEach(function(o){
      opts += '<option value="' + escAttrS(o) + '"' + (o === cur ? ' selected' : '') + '>' + escAttrS(o) + '</option>';
    });
    return '<select class="edit-office" style="padding:5px 8px; font-family:inherit; font-size:13px;">' + opts + '</select>'
      + '<span class="muted" style="font-size:11.5px; margin-left:8px;">'
      + '未設定のままだと「東京」として扱われます</span>';
  }


  // ===== 経験回数（自動集計・2026-08-27 baba要望）=====
  // ⚠ 表に保存していない＝アサインから毎回数えたもの（正本＝App\Support\ExperienceCount）。
  //   数え方＝「確定のアサイン」で「開催日が過ぎたもの」だけ。キャンセルは数えない。
  //   ここに数え方を書かないこと（サーバー側と食い違う）。
  function experienceHtml(p){
    const e = (window.ECS_EXPERIENCE || {})[p.id];
    if (!e || !e.projects) {
      return '<span class="muted" style="font-size:12.5px;">まだありません'
        + '（確定のアサインで開催日が過ぎたものを数えます）</span>';
    }

    const dayNote = (e.days > e.projects) ? '（出勤 ' + e.days + ' 日）' : '';
    let html = '<div style="font-size:12.5px; margin-bottom:6px;">通算 <b>' + e.projects + ' 件</b> ' + dayNote + '</div>';

    if (e.byRole && e.byRole.length) {
      html += '<div style="font-size:12.5px; margin-bottom:4px;"><b>ポジションごと</b></div><div style="margin-bottom:8px;">';
      e.byRole.forEach(function(r){
        html += '<span class="badge" style="margin:0 6px 4px 0;">' + escAttrS(r.label) + ' ' + r.count + '回</span>';
      });
      html += '</div>';
    }

    // ⚠ コンテンツごとの表は「経験回数」の画面（/experience）に移した（2026-08-28 baba要望）。
    //   ここは1人ずつしか見られないので「このコンテンツをやれる人は誰か」を探せなかった。
    //   ここには「よくやったコンテンツ」だけ出して、詳しくは向こうで見てもらう。
    if (e.byContent && e.byContent.length) {
      html += '<div style="font-size:12.5px; margin-bottom:4px;"><b>よくやったコンテンツ</b></div><div>';
      e.byContent.slice(0, 5).forEach(function(c){
        html += '<span class="badge" style="margin:0 6px 4px 0;">' + escAttrS(c.name) + ' ' + c.count + '回</span>';
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

  function render(){
    tbody.innerHTML = '';
    staff.forEach((p, idx) => {
      const tr = document.createElement('tr');
      tr.className = 'main-row';
      tr.dataset.idx = idx;
      tr.innerHTML = `
        <td><strong>${p.name}</strong>${loginBadge(p)}<br><span class="muted" style="font-size:11.5px;">${p.id}</span></td>
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
    if (prof.otherLang) skillBits.push('その他の言語：' + prof.otherLang);
    // 日常で使っているオンラインツール（一覧のチェック＋自由記入をつなげる）
    const toolBits = (prof.tools || []).slice();
    if (prof.toolsOther) toolBits.push(prof.toolsOther);
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
            <div><b>やってみたいポジション：</b>${(prof.challenge||[]).length ? prof.challenge.join('／') : '（未入力）'}</div>
            <div><b>使っているツール：</b>${toolBits.length ? toolBits.join('／') : '（未入力）'}</div>
            <div><b>身体・サイズ：</b>${physBits.length ? physBits.join('／') : '（未入力）'}</div>
            <div><b>エリア：</b>${areaBits.length ? areaBits.join('　') : '（未入力）'}</div>
            <div><b>その他備考：</b>${prof.note||'（未入力）'}</div>
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

            ${nameEditor(p)}

            <h4 style="margin-top:16px;">拠点（事務所）</h4>
            <div class="trait">${officeEditor(p)}</div>

            <h4 style="margin-top:16px;">経験回数（自動集計）</h4>
            <div>${experienceHtml(p)}</div>

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
        ${inviteHtml(p)}
        ${personAdminHtml(p)}
      </div>`;
  }

  // Administrator だけに出す「退職にする／在籍に戻す」「削除」（社員名簿と同じ作り）。
  // 辞めた方は削除ではなく退職（在籍を外す）＝過去の案件の記録を残すため。
  // ログインの状態バッジ（2026-08-25）。メールアドレスをもらった順に案内を送る運用なので、
  // 「誰がまだログインできないか」が名簿でひと目で分かるようにする。
  function loginBadge(p){
    var m = {
      ready:   ['ログインできる', '#e7f6ec', '#15803d'],
      invited: ['案内メール送信済み', '#fdf3e2', '#8a5a10'],
      temp:    ['仮パスワード発行済み', '#e0f2fe', '#0369a1'],
      none:    ['まだアカウント無し', '#f1ece4', '#7a6f63']
    };
    // 臨時スタッフ（インターン・知り合いの助っ人など）は、そもそもログインしない決まり。
    // 「まだアカウント無し」と出すと、案内を送り忘れているように見えるので分けて出す（2026-08-25 baba）。
    var v = p.spot ? ['臨時（ログインなし）', '#f1ece4', '#7a6f63'] : (m[p.login] || m.none);
    return '<span style="margin-left:6px; font-size:11px; padding:1px 8px; border-radius:999px; white-space:nowrap;'
      + 'background:' + v[1] + '; color:' + v[2] + ';">' + v[0] + '</span>';
  }

  // 「ログイン案内を送る」ボタン。メールが未登録ならその場で入力してもらう。
  function inviteHtml(p){
    if (!window.ECS_CAN_INVITE) return '';
    // 臨時スタッフはログインしない＝案内メールの出番が無い（押しても断られる）。
    // かわりに「臨時を解除する」を出す（2026-08-28 baba要望）。
    // ⚠ メアドが分かったからと言って ふつうに登録し直すと、同じ人が名簿に2人できてしまう。
    //   アサインの記録が付いている方と、ログインできる方が別々になるので、ここで印だけ外す。
    if (p.spot) {
      return `<div class="save-row" style="border-top:1px dashed var(--line); padding-top:10px; flex-wrap:wrap;">
        <button class="btn sm" data-unspot-id="${p.id}">臨時を解除して正式スタッフにする</button>
        <span class="muted" style="font-size:12px;">
          メールアドレスが分かったときはこちら。解除すると<b>ログイン案内メールを送れる</b>ようになります。<br>
          いまのアサイン・出勤の記録はそのまま残ります（<b>登録し直すと名簿が二重になります</b>）。
        </span>
      </div>`;
    }
    var label = p.login === 'ready' ? '案内メールを送り直す' : '📧 ログイン案内メールを送る';
    var sub = p.invitedAt ? ('（前回 ' + p.invitedAt + ' に送信）') : '';
    return `<div class="save-row" style="border-top:1px dashed var(--line); padding-top:10px; flex-wrap:wrap;">
        <label class="size-item" style="font-size:13px;">メール：
          <input type="text" class="size-input" style="width:230px;" id="inv-mail-${p.id}" value="${escAttrS(p.email)}" placeholder="staff@example.com">
        </label>
        <button class="btn sm" data-invite-id="${p.id}">${label}</button>
        <span class="muted" style="font-size:12px;">${sub}
          パスワードはメールに書きません。本人が<b>リンクから自分で決めます</b>（有効7日間）。</span>
      </div>`;
  }

  function escAttrS(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) {
      return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[ch];
    });
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
    if (del) { deletePerson(del.dataset.delId, del.dataset.delName); return; }
    const inv = e.target.closest('[data-invite-id]');
    if (inv) { sendInvite(inv.dataset.inviteId); return; }
    const uns = e.target.closest('[data-unspot-id]');
    if (uns) { releaseSpot(uns.dataset.unspotId); }
  });

  // 臨時の印を外す（正式なスタッフにする）。記録はそのまま残る。
  function releaseSpot(id){
    const msg = ['この方の「臨時」を外して、正式なスタッフにします。',
                 '',
                 '・いまのアサイン・出勤の記録はそのまま残ります',
                 '・このあと「ログイン案内メールを送る」でログインを作れます',
                 '',
                 'よろしいですか？'].join(String.fromCharCode(10));
    if (!confirm(msg)) return;
    fetch('/people/' + encodeURIComponent(id) + '/unspot', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF || '', 'Accept': 'application/json' }
    }).then(r => r.json().then(j => ({ ok: r.ok, j })))
      .then(({ ok, j }) => {
        alert((j && j.message) || (ok ? '解除しました。' : '解除できませんでした。'));
        if (ok) location.reload();
      })
      .catch(() => alert('通信に失敗しました。もう一度お試しください。'));
  }

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
    const kw  = document.getElementById('kw').value.trim();
    const fLv = document.getElementById('fLv').value;
    const fPos= document.getElementById('fPos').value;
    const fOfficeEl = document.getElementById('fOffice');
    const fOffice = fOfficeEl ? fOfficeEl.value : '';
    const fLoginEl = document.getElementById('fLogin');
    const fLogin = fLoginEl ? fLoginEl.value : '';
    let shown = 0;
    staff.forEach((p, idx) => {
      const mr = tbody.querySelector(`tr.main-row[data-idx="${idx}"]`);
      const dr = tbody.querySelector(`tr.detail-row[data-for="${idx}"]`);
      const okKw  = !kw  || p.name.includes(kw) || p.id.includes(kw) || (p.kana || '').includes(kw);
      const okLv  = !fLv || lvOf(p) === fLv;
      const okPos = !fPos|| p.pos[fPos];
      // ログインの状態で絞る。「まだログインできない人」＝本人がパスワードを決めていない人ぜんぶ。
      const okLogin = !fLogin
        || (fLogin === 'spot' ? !!p.spot
          : fLogin === 'notspot' ? !p.spot
          : fLogin === 'notready' ? (p.login !== 'ready' && !p.spot)
          : (p.login === fLogin && !p.spot));
      const okOffice = !fOffice || officeOf(p) === fOffice;
      const visible = okKw && okLv && okPos && okLogin && okOffice;
      mr.style.display = visible ? '' : 'none';
      if (!visible && dr) { dr.style.display = 'none';
        const t = mr.querySelector('.row-toggle'); if (t) t.innerHTML = '詳細 ▾'; }
      if (visible) shown++;
    });
    document.getElementById('countTxt').textContent = shown;
    showOfficeHint(fOffice);
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
    // 拠点（管理者以上だけ欄が出る）。欄が無いときは送らない＝勝手に空にしない。
    const officeSel = dr.querySelector('.edit-office');
    if (officeSel) body.append('office', officeSel.value);
    // 氏名（Administratorだけ欄が出る）。同じく、欄が無いときは送らない。
    const nameEl = dr.querySelector('.edit-name');
    const nameChanged = !!(nameEl && nameEl.value.trim() !== '' && nameEl.value.trim() !== p.name);
    if (nameEl) body.append('name', nameEl.value);
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
      // 氏名を変えたときは、表・詳細・並び順のあちこちに出るので画面を読み込み直す
      // （直した名前が一部だけ古いまま残ると、直っていないように見えるため）。
      if (nameChanged) { location.reload(); }
    })
    .catch(err => {
      if (statusEl) { statusEl.textContent = '保存に失敗しました（' + err + '）'; statusEl.style.color = 'var(--danger)'; }
      if (btn) btn.disabled = false;
    });
  }

  // 並び替え（2026-08-27 baba要望）。
  // ⚠ 行は「staff の何番目か（idx）」で詳細行とつないでいるので、並べ替えたら作り直す
  //   （並べ替えだけして表示を入れ替えないと、名前と詳細がずれる）。
  function sortRoster() {
    const key = document.getElementById('fRosterSort').value;
    if (key === 'kana') {
      // ふりがな順。ふりがな未入力の人は末尾へ＝社員名簿の並び（Person::scopeByKana）と同じ考え方。
      staff.sort(function (a, b) {
        const ak = (a.kana || '').trim(), bk = (b.kana || '').trim();
        if (!ak && !bk) return String(a.name).localeCompare(String(b.name), 'ja');
        if (!ak) return 1;
        if (!bk) return -1;
        return ak.localeCompare(bk, 'ja') || String(a.name).localeCompare(String(b.name), 'ja');
      });
    } else if (key === 'reg') {
      staff.sort(function (a, b) {
        return String(a.id).localeCompare(String(b.id), 'ja', { numeric: true });
      });
    } else {
      // 既定＝経験回数の多い順（サーバーが返す並びと同じ）。同じ回数なら番号順で毎回同じ並びにする。
      staff.sort(function (a, b) {
        return (b.total || 0) - (a.total || 0)
          || String(a.id).localeCompare(String(b.id), 'ja', { numeric: true });
      });
    }
    render();
    applyFilter();
  }

  // 名簿タブの中で、HTML内のクリックから呼ぶ関数だけ外に出す（名前は roster〜 で固有化）
  window.rosterSort = sortRoster;
  window.rosterFilter = applyFilter;
  window.rosterToggle = toggleDetail;
  window.rosterSave = saveStaff;

  buildOfficeFilter('fOffice');
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
      // 事務所が空の人は「東京」扱い（名簿タブと同じ決まり）。
      tr.dataset.office = (p.office && String(p.office).trim()) ? String(p.office).trim() : '東京';
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

  // 拠点の選択肢を作る（既定は自分の拠点）。名簿タブとは別の囲いなので同じものをここにも置く。
  function buildWorkOfficeFilter(){
    var sel = document.getElementById('wOffice');
    if (!sel) return;
    var mine = (window.ECS_MY_OFFICE || '').trim();
    var html = '<option value="">拠点：すべて</option>';
    (window.ECS_OFFICES || []).forEach(function(o){
      html += '<option value="' + o + '"' + (o === mine ? ' selected' : '') + '>' + o + '</option>';
    });
    sel.innerHTML = html;
  }

  // 絞り込み
  function applyFilter(){
    const fLv = document.getElementById('wLv').value;
    const fAct= document.getElementById('fAct').value;
    const fOfficeEl = document.getElementById('wOffice');
    const fOffice = fOfficeEl ? fOfficeEl.value : '';
    let shown = 0;
    tbody.querySelectorAll('tr').forEach(tr => {
      const okLv  = !fLv  || tr.dataset.lv === fLv;
      const okAct = !fAct || tr.dataset.act === fAct;
      const okOffice = !fOffice || tr.dataset.office === fOffice;
      const visible = okLv && okAct && okOffice;
      tr.style.display = visible ? '' : 'none';
      if (visible) shown++;
    });
    document.getElementById('wCount').textContent = shown;
  }

  // 稼働状況タブの中で、HTML内のクリックから呼ぶ関数だけ外に出す（名前は work〜 で固有化）
  window.workFilter = applyFilter;
  window.workRender = render;

  buildWorkOfficeFilter();
  renderSummary();
  render();
  })();
</script>
@endverbatim
@endpush
