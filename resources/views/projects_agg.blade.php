<!DOCTYPE html>
{{-- 画面の色（テーマ）＝人ごとの設定を効かせる。この画面は共通の骨組み（layouts.app）を
     使っていない独立ページなので、ここにも同じ印を付ける（2026-09-09）。
     ⚠ 色そのものは public/ecs/style.css。ここには色を書かない。 --}}
<html lang="ja" data-theme="{{ \App\Support\Themes::current() }}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>社員・ディレクター集計（ECS）</title>
  <link rel="stylesheet" href="/ecs/style.css?v={{ \App\Support\Asset::ver('ecs/style.css') }}">
  @verbatim
  <style>
    body { background: var(--bg); padding: 18px 20px; }
    .agg-top { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
    .agg-top h1 { font-size: 18px; margin: 0; }
    .agg-top .month-nav { display: flex; align-items: center; gap: 8px; }
    .agg-top .month-nav button { border: 1px solid var(--line); background: #fff; border-radius: 8px; width: 30px; height: 30px; font-size: 15px; cursor: pointer; font-family: inherit; }
    /* 月の切替（2026-09-02 追加）。リンクだがボタンに見せる。 */
    .agg-top .month-nav .mon-btn {
      display: inline-flex; align-items: center; justify-content: center;
      border: 1px solid var(--line); background: #fff; border-radius: 8px;
      width: 30px; height: 30px; font-size: 15px; text-decoration: none; color: var(--ink);
    }
    .agg-top .month-nav .mon-btn.wide { width: auto; padding: 0 10px; font-size: 12.5px; font-weight: 700; }
    .agg-top .month-nav .mon-btn:hover { background: #f3ece0; }
    .agg-top .month-nav .mon { font-size: 14px; font-weight: 700; min-width: 96px; text-align: center; }
    .agg-top .spacer { flex: 1; }
    .live { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 999px; }
    .live.on  { background: var(--ok-soft); color: #15803d; }
    .live.off { background: #ece3d4;        color: #7a6a58; }
    .live .dot { width: 9px; height: 9px; border-radius: 999px; background: currentColor; }
    .note { font-size: 12.5px; color: var(--muted); line-height: 1.6; margin: 0 0 12px; }
    /* 所属の切替（2026-09-07）。拠点の切替スイッチと同じ見た目にそろえる。 */
    .dept-switch {
      display: flex; flex-wrap: wrap; align-items: center; gap: 8px;
      margin: 0 0 14px; padding: 10px 12px;
      background: #fbf6ef; border: 1px solid var(--line); border-radius: 10px;
    }
    .dept-switch .ds-label { font-size: 12px; font-weight: 700; color: var(--muted); margin-right: 2px; }
    .dept-switch .ds-chip {
      text-decoration: none; font-size: 13px; font-weight: 600; color: var(--ink); background: #fff;
      border: 1px solid var(--line-strong, #d8c4ae); border-radius: 999px; padding: 5px 13px;
    }
    .dept-switch .ds-chip:hover { border-color: var(--brand); }
    .dept-switch .ds-chip.active { background: var(--brand-fill); color: #fff; border-color: var(--brand); }
    table.tbl th.num, table.tbl td.num { text-align: right; font-variant-numeric: tabular-nums; }
    table.tbl td.nm { font-weight: 600; }
    /* 名前の文字色＝所属（D決め画面と同じ配色） */
    table.tbl td.nm.dep-plan     { color: #c2410c; }   /* イベプラ＝オレンジ */
    table.tbl td.nm.dep-sales    { color: #4338ca; }   /* セールス＝藍 */
    table.tbl td.nm.dep-creative { color: #16a34a; }   /* クリエイティブ＝緑 */
    table.tbl td.nm.dep-other    { color: #6e5b49; }   /* その他＝茶（イベプラ/セールス/クリエイティブ以外をまとめた色） */
    table.tbl td.nm.dep-none     { color: #a3968a; }   /* 所属が未設定 */
    tr.agg-total td { font-weight: 700; background: var(--brand-soft); color: var(--brand-dark); }

    /* ここから下は「黒ベース（ダークモード）」のときだけ効く上書き。
       上の色は白地むけに直接書いてあるので、黒地だと白いボタン面や茶色の文字が読みにくい。
       所属を表す文字色は、意味を変えずに明るい同系色へ置き換える。 */
    html[data-theme="dark"] .agg-top .month-nav button,
    html[data-theme="dark"] .agg-top .month-nav .mon-btn { background: var(--surface-3); color: var(--ink); border-color: var(--field-line); }
    html[data-theme="dark"] .agg-top .month-nav .mon-btn:hover { background: var(--surface-hover); }
    html[data-theme="dark"] .live.on  { color: var(--ok-ink); }
    html[data-theme="dark"] .live.off { background: var(--chip-bg); color: var(--chip-ink); }
    html[data-theme="dark"] .dept-switch { background: var(--surface-2); }
    html[data-theme="dark"] .dept-switch .ds-chip { background: var(--surface-3); color: var(--ink); border-color: var(--field-line); }
    html[data-theme="dark"] .dept-switch .ds-chip.active { background: var(--brand-fill); color: #ffffff; border-color: var(--brand); }
    html[data-theme="dark"] table.tbl td.nm.dep-plan     { color: #f59a5a; }
    html[data-theme="dark"] table.tbl td.nm.dep-sales    { color: #a5b4fc; }
    html[data-theme="dark"] table.tbl td.nm.dep-creative { color: var(--ok-ink); }
    html[data-theme="dark"] table.tbl td.nm.dep-other    { color: var(--muted); }
    html[data-theme="dark"] table.tbl td.nm.dep-none     { color: var(--muted-dim); }
  </style>
  @endverbatim
</head>
<body>
  <div class="agg-top">
    <h1>📊 社員・ディレクター集計</h1>
    {{-- 月の切替（2026-09-02 baba要望）。それまでは全期間の合計で、
         「今月は誰が多いか」が読めなかった（D決めの担当バランスは月単位なので数も合わなかった）。 --}}
    <div class="month-nav">
      {{-- ⚠ 月を動かしても、選んでいる拠点と所属を落とさないこと（落とすと全社に戻って驚く）。 --}}
      <a class="mon-btn" href="?{{ http_build_query(array_filter(['ym' => $prevPeriod, 'office' => \App\Support\OfficeScope::param($officeScope), 'dept' => $deptCode])) }}" title="前の月へ">◀</a>
      <span class="mon" id="monLabel">{{ $periodLabel }}</span>
      <a class="mon-btn" href="?{{ http_build_query(array_filter(['ym' => $nextPeriod, 'office' => \App\Support\OfficeScope::param($officeScope), 'dept' => $deptCode])) }}" title="次の月へ">▶</a>
      <a class="mon-btn wide" href="?{{ http_build_query(array_filter(['office' => \App\Support\OfficeScope::param($officeScope), 'dept' => $deptCode])) }}" title="今月に戻す">今月</a>
    </div>
    <div class="spacer"></div>
    <span class="live off" id="live"><span class="dot"></span><span id="liveText">案件一覧と未接続</span></span>
  </div>

  {{-- 拠点の切替（管理者以上だけ表示。一般社員は自拠点固定＝スイッチは出ない） --}}
  @include('partials.office_switch')

  {{-- 所属（イベプラ／セールス／…）で絞る（2026-09-07 baba要望）。
       所属の一覧は画面に書かず App\Support\Departments から受け取る（増えてもここは直さない）。
       ⚠ リンクは fullUrlWithQuery＝いま見ている月（ym）と拠点（office）を落とさない。 --}}
  <div class="dept-switch">
    <span class="ds-label">所属</span>
    <a class="ds-chip {{ $deptCode === '' ? 'active' : '' }}"
       href="{{ request()->fullUrlWithQuery(['dept' => '']) }}">すべて</a>
    @foreach ($deptOptions as $code => $name)
      <a class="ds-chip {{ $deptCode === $code ? 'active' : '' }}"
         href="{{ request()->fullUrlWithQuery(['dept' => $code]) }}">{{ $name }}</a>
    @endforeach
  </div>

  <p class="note">
    <b>D決め画面（/assign-director）</b>で保存したD／SD担当の実績を、社員ごとに数えた本物の集計です。<br>
    下書きの案件は数えません。<b>数えているのは「{{ $periodLabel }}に開催する案件」だけ</b>です（2026-09-02 から月ごとになりました）。
    ◀ ▶ で月を変えられます。
    @if ($officeScope)
      <br><b>{{ $officeScope }}所属の社員</b>だけを並べています。件数は<b>その社員が担当した案件すべて</b>（他拠点への応援も含む）です。
    @endif
    @if ($scopeDept !== '')
      <br>所属「<b>{{ $scopeDept }}</b>」の社員だけを並べています。
      「その他」は、イベプラ・セールス・クリエイティブ<b>以外</b>の所属をまとめたものです。
      <b>所属を入れていない社員は、どの所属を選んでも出ません</b>（名簿の「所属」を埋めてください）。
    @endif
  </p>

  @if (($summary['records'] ?? 0) > 0)
  <p class="note" style="margin:-4px 0 12px; font-size:13.5px; color:var(--ink);">
    <b>{{ $periodLabel }}</b>は全部で <b>{{ $summary['records'] }}</b> 件（D <b>{{ $summary['d'] }}</b> 件・SD <b>{{ $summary['sd'] }}</b> 件）／対象 社員 <b>{{ $summary['staff'] }}</b> 名・案件 <b>{{ $summary['projects'] }}</b> 件
  </p>
  @endif

  <table class="tbl">
    <thead>
      <tr>
        <th>社員</th>
        <th class="num" title="その社員がD・SDを担当した合計（D＋SD）">担当合計</th>
        <th class="num">D計</th>
        <th class="num">リアルD</th>
        <th class="num">大型D</th>
        <th class="num">大型SD</th>
        <th class="num">オンラインD</th>
      </tr>
    </thead>
    <tbody id="aggBody">
      <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:24px 0;">案件一覧の「📊 社員・ディレクター集計」ボタンから開くと、ここに数字が出ます。</td></tr>
    </tbody>
  </table>

  <p class="note" style="margin-top:12px;">
    ※名前の<b>文字色は所属</b>（<span style="color:#c2410c;font-weight:700;">イベプラ</span>・<span style="color:#4338ca;font-weight:700;">セールス</span>・<span style="color:#16a34a;font-weight:700;">クリエイティブ</span>）＝この文字の色そのものです。<br>
    ※「大型D／大型SD」＝リアルの【大型】案件でD／SDを務めた回数。「リアルD」はリアル案件全体（通常＋ロング）でDを務めた回数です。
  </p>

  <script src="/ecs/data/cases.js"></script>
  <!-- 本物の集計（ControllerがD決めの保存先＝assignmentsから作成）をJSへ渡す。下のロジックはそのまま温存。 -->
  <script>
    window.ECS_AGG = @json($rows);
    // ⚠ 本物の集計を出している、という印。所属で絞って0人になっても、
    //   見本データ（/ecs/data/cases.js）に戻らないようにするため（2026-09-07）。
    //   戻ってしまうと、架空の社員の数字が「絞り込みの結果」として出てしまう。
    window.ECS_HAS_DB_AGG = @json($hasDbAgg ?? false);
  </script>
  @verbatim
  <script>
    // ===== 親ウィンドウ（案件一覧）から送られてくる集計データを受け取って表示 =====
    // 本物の集計データがあるか（Controllerが渡す。空なら従来の案件データ集計にフォールバック）。
    // ⚠ 件数が0でも、サーバーが「本物を出している」と言っていれば本物あつかいにする。
    //   （所属で絞って0人になったときに見本データへ落ちるのを防ぐ）
    const HAS_DB_AGG = window.ECS_HAS_DB_AGG === true
      || (Array.isArray(window.ECS_AGG) && window.ECS_AGG.length > 0);

    function setLive(on) {
      document.getElementById('live').className = 'live ' + (on ? 'on' : 'off');
      document.getElementById('liveText').textContent = on ? '案件一覧と連動中'
        : (HAS_DB_AGG ? 'D決めの実績から集計' : '案件データから集計');
    }

    function render(rows) {
      const body = document.getElementById('aggBody');
      body.innerHTML = '';
      if (!rows || rows.length === 0) {
        body.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--muted);padding:24px 0;">ディレクターが割り当てられた案件がありません。</td></tr>';
        return;
      }
      const sum = { total:0, d:0, realD:0, bigD:0, bigSD:0, onlineD:0 };
      rows.forEach(r => {
        // 担当合計＝本物データは r.total、案件データ集計（見本）は D＋SD で算出。
        const total = (r.total != null) ? r.total : (r.d + (r.sd || 0));
        sum.total += total;
        ['d','realD','bigD','bigSD','onlineD'].forEach(k => sum[k] += r[k]);
        const tr = document.createElement('tr');
        tr.innerHTML = `<td class="nm ${r.deptCls || ''}" title="${r.dept || ''}">${r.name}</td><td class="num"><b>${total}</b></td><td class="num">${r.d}</td><td class="num">${r.realD}</td><td class="num">${r.bigD}</td><td class="num">${r.bigSD}</td><td class="num">${r.onlineD}</td>`;
        body.appendChild(tr);
      });
      const tr = document.createElement('tr');
      tr.className = 'agg-total';
      tr.innerHTML = `<td>合計</td><td class="num">${sum.total}</td><td class="num">${sum.d}</td><td class="num">${sum.realD}</td><td class="num">${sum.bigD}</td><td class="num">${sum.bigSD}</td><td class="num">${sum.onlineD}</td>`;
      body.appendChild(tr);
    }

    // ===== 案件データ(cases.js)から自分で集計する（案件一覧から開いていなくても数字を出す）=====
    function computeAggFromCases() {
      const cases = window.ECS_CASES || [];
      const map = {};
      function ensure(name) {
        if (!map[name]) map[name] = { name, d:0, realD:0, bigD:0, bigSD:0, onlineD:0 };
        return map[name];
      }
      cases.forEach(c => {
        if (c.draft) return;                                   // 下書きは数えない
        const fmt = c.format || '';
        const isReal   = fmt.indexOf('リアル') !== -1;
        const isOnline = fmt.indexOf('オンライン') !== -1;
        const isBig    = c.scale === '大型';
        if (c.dir && c.dir !== '未定') {
          const r = ensure(c.dir);
          r.d++;
          if (isReal)          r.realD++;
          if (isOnline)        r.onlineD++;
          if (isReal && isBig) r.bigD++;
        }
        if (c.sd && c.sd !== 'なし' && c.sd !== '未定' && isReal && isBig) {
          ensure(c.sd).bigSD++;
        }
      });
      return Object.values(map).sort((a, b) => (b.d - a.d) || (b.bigD - a.bigD));
    }
    // 本物データがあればそれを表示（無ければ従来どおり案件データから自前集計）。
    function renderSelf() {
      setLive(false);
      render(HAS_DB_AGG ? window.ECS_AGG : computeAggFromCases());
    }

    window.addEventListener('message', function (e) {
      if (e.data && e.data.type === 'agg-data') {
        // 本物のD決め実績がある場合は、親ウィンドウ（旧・計画用のディレクター欄）で上書きしない。
        if (HAS_DB_AGG) return;
        setLive(true);
        if (e.data.month) document.getElementById('monLabel').textContent = e.data.month;
        render(e.data.rows);
      }
    });

    // まず自分で集計して表示（案件一覧から開いていなくても数字が出る）
    renderSelf();

    // 案件一覧から開いた場合は、最新データを要求してライブ連動に切り替える
    function requestData() {
      if (window.opener && !window.opener.closed) {
        window.opener.postMessage({ type: 'agg-request' }, '*');
      }
    }
    requestData();
    window.addEventListener('focus', requestData);
  </script>
  @endverbatim
</body>
</html>
