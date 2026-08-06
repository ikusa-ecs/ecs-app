<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>社員・ディレクター集計（ECS）</title>
  <link rel="stylesheet" href="/ecs/style.css">
  @verbatim
  <style>
    body { background: var(--bg); padding: 18px 20px; }
    .agg-top { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
    .agg-top h1 { font-size: 18px; margin: 0; }
    .agg-top .month-nav { display: flex; align-items: center; gap: 8px; }
    .agg-top .month-nav button { border: 1px solid var(--line); background: #fff; border-radius: 8px; width: 30px; height: 30px; font-size: 15px; cursor: pointer; font-family: inherit; }
    .agg-top .month-nav .mon { font-size: 14px; font-weight: 700; min-width: 96px; text-align: center; }
    .agg-top .spacer { flex: 1; }
    .live { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 999px; }
    .live.on  { background: var(--ok-soft); color: #15803d; }
    .live.off { background: #ece3d4;        color: #7a6a58; }
    .live .dot { width: 9px; height: 9px; border-radius: 999px; background: currentColor; }
    .note { font-size: 12.5px; color: var(--muted); line-height: 1.6; margin: 0 0 12px; }
    table.tbl th.num, table.tbl td.num { text-align: right; font-variant-numeric: tabular-nums; }
    table.tbl td.nm { font-weight: 600; }
    /* 名前の文字色＝所属（D決め画面と同じ配色） */
    table.tbl td.nm.dep-plan     { color: #c2410c; }   /* イベプラ＝オレンジ */
    table.tbl td.nm.dep-sales    { color: #4338ca; }   /* セールス＝藍 */
    table.tbl td.nm.dep-creative { color: #16a34a; }   /* クリエイティブ＝緑 */
    tr.agg-total td { font-weight: 700; background: var(--brand-soft); color: var(--brand-dark); }
  </style>
  @endverbatim
</head>
<body>
  <div class="agg-top">
    <h1>📊 社員・ディレクター集計</h1>
    <div class="month-nav">
      <span class="mon" id="monLabel">D／SD担当 累計</span>
    </div>
    <div class="spacer"></div>
    <span class="live off" id="live"><span class="dot"></span><span id="liveText">案件一覧と未接続</span></span>
  </div>

  {{-- 拠点の切替（管理者以上だけ表示。一般社員は自拠点固定＝スイッチは出ない） --}}
  @include('partials.office_switch')

  <p class="note">
    <b>D決め画面（/assign-director）</b>で保存したD／SD担当の実績を、社員ごとに数えた本物の集計です。<br>
    下書きの案件は数えません。表示は累計（全期間）です。
    @if ($officeScope)
      <br><b>{{ $officeScope }}所属の社員</b>だけを並べています。件数は<b>その社員が担当した案件すべて</b>（他拠点への応援も含む）です。
    @endif
  </p>

  @if (($summary['records'] ?? 0) > 0)
  <p class="note" style="margin:-4px 0 12px; font-size:13.5px; color:var(--ink);">
    全部で <b>{{ $summary['records'] }}</b> 件（D <b>{{ $summary['d'] }}</b> 件・SD <b>{{ $summary['sd'] }}</b> 件）／対象 社員 <b>{{ $summary['staff'] }}</b> 名・案件 <b>{{ $summary['projects'] }}</b> 件
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
    ※名前の<b>文字色は所属</b>（<span style="color:#c2410c;font-weight:700;">オレンジ＝イベプラ</span>・<span style="color:#4338ca;font-weight:700;">藍＝セールス</span>・<span style="color:#16a34a;font-weight:700;">緑＝クリエイティブ</span>）。<br>
    ※「大型D／大型SD」＝リアルの【大型】案件でD／SDを務めた回数。「リアルD」はリアル案件全体（通常＋ロング）でDを務めた回数です。
  </p>

  <script src="/ecs/data/cases.js"></script>
  <!-- 本物の集計（ControllerがD決めの保存先＝assignmentsから作成）をJSへ渡す。下のロジックはそのまま温存。 -->
  <script>
    window.ECS_AGG = @json($rows);
  </script>
  @verbatim
  <script>
    // ===== 親ウィンドウ（案件一覧）から送られてくる集計データを受け取って表示 =====
    // 本物の集計データがあるか（Controllerが渡す。空なら従来の案件データ集計にフォールバック）。
    const HAS_DB_AGG = Array.isArray(window.ECS_AGG) && window.ECS_AGG.length > 0;

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
