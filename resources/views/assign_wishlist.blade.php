<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ECS スタッフ一覧</title>
  <link rel="stylesheet" href="/ecs/style.css?v={{ \App\Support\Asset::ver('ecs/style.css') }}">
  @verbatim
  <style>
    /* ===== 希望者一覧（別ウィンドウ）専用スタイル ===== */
    body { background: var(--bg); margin: 0; }
    .wl-wrap { padding: 18px 22px 30px; max-width: 1100px; margin: 0 auto; }
    .wl-head h1 { font-size: 20px; margin: 0 0 4px; }
    .wl-head .sub { font-size: 12.5px; color: var(--muted); margin-bottom: 16px; }

    /* 上部の数値カード */
    .wl-cards { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 18px; }
    .wl-card {
      background: var(--panel); border: 1px solid var(--line); border-radius: 12px;
      box-shadow: var(--shadow); padding: 12px 16px; min-width: 150px;
    }
    .wl-card .c-label { font-size: 12px; color: var(--muted); font-weight: 600; }
    .wl-card .c-num { font-size: 24px; font-weight: 700; font-variant-numeric: tabular-nums; margin-top: 2px; }
    .wl-card .c-num small { font-size: 13px; color: var(--muted); font-weight: 400; }

    /* 可能ポジション別の人数カード */
    .wl-poscard {
      background: var(--panel); border: 1px solid var(--line); border-radius: 12px;
      box-shadow: var(--shadow); padding: 12px 16px; flex: 1 1 360px;
    }
    .wl-poscard .c-label { font-size: 12px; color: var(--muted); font-weight: 600; margin-bottom: 8px; }
    .pos-counts { display: flex; flex-wrap: wrap; gap: 8px; }
    .pos-count { display: inline-flex; align-items: baseline; gap: 5px; font-size: 13px;
      background: var(--brand-soft); color: var(--brand-dark); border-radius: 999px; padding: 4px 11px; font-weight: 700; }
    .pos-count b { font-size: 15px; font-variant-numeric: tabular-nums; }

    /* 絞り込み */
    .wl-filter { display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
    .wl-filter .f-item { display: flex; flex-direction: column; gap: 4px; }
    .wl-filter label { font-size: 12px; font-weight: 600; color: var(--muted); }
    .wl-filter select, .wl-filter input {
      padding: 7px 10px; border: 1px solid var(--line); border-radius: 8px;
      font-size: 13px; font-family: inherit; background: #fff; min-width: 120px;
    }

    /* テーブル */
    table.tbl th { white-space: nowrap; }
    td.center { text-align: center; font-variant-numeric: tabular-nums; }
    .lv { font-size: 11.5px; padding: 1px 7px; border-radius: 999px; font-weight: 600; }
    .lv.new { background: var(--brand-soft); color: var(--brand-dark); }
    .lv.mid { background: #ece3d4; color: #7a6a58; }
    .lv.vet { background: var(--ok-soft); color: #15803d; }

    /* 割合バー */
    .rate { display: inline-flex; align-items: center; gap: 8px; }
    .rate .rbar { width: 70px; height: 8px; background: #ece3d4; border-radius: 999px; overflow: hidden; }
    .rate .rbar > i { display: block; height: 100%; }
    .rate .rbar > i.hi { background: var(--ok); }
    .rate .rbar > i.mid { background: var(--brand); }
    .rate .rbar > i.low { background: var(--warn); }
    .rate .rtxt { font-size: 12.5px; font-weight: 700; font-variant-numeric: tabular-nums; width: 36px; }

    /* 可能ポジションのタグ */
    .ptag { font-size: 10.5px; font-weight: 700; padding: 1px 7px; border-radius: 6px; margin: 0 3px 3px 0;
      display: inline-block; background: #ece3d4; color: #7a6a58; }
    .ptag.key { background: var(--brand-soft); color: var(--brand-dark); } /* D/OP/MC/軍師＝経験者向け */

    .note { font-size: 12px; color: var(--muted); margin-top: 14px; line-height: 1.6; }

    /* ===== 一覧を1画面に多く出すための密度調整（本番は人数が多い）===== */
    .wl-wrap { padding: 14px 22px 24px; }
    .wl-cards { gap: 10px; margin-bottom: 12px; }
    .wl-card { padding: 9px 14px; }
    .wl-card .c-num { font-size: 21px; }
    .wl-filter { margin-bottom: 8px; }
    /* 表：行を詰めて1行=1行高に。縦書き化を防ぎつつ余白を最小化 */
    table.tbl th, table.tbl td { padding: 4px 9px; font-size: 12.5px; line-height: 1.35; }
    table.tbl thead th { white-space: nowrap; position: sticky; top: 0; background: var(--panel); z-index: 1; }
    .ptag { margin: 0 2px 0 0; padding: 1px 6px; }   /* ポジションタグも詰める（折返し分の高さを抑える） */
    .rate .rbar { width: 60px; }
  </style>
  @endverbatim
</head>
<body>
  <div class="wl-wrap">
    <div class="wl-head">
      <h1>👥 スタッフ一覧（{{ now()->format('Y年n月') }}）</h1>
      <div class="sub">いま稼働希望を出してくれているスタッフの一覧です。希望日数・実アサイン数・その割合、できるポジションを確認できます。（数値はすべて本物の希望・アサインから計算しています。対象月＝{{ now()->format('Y年n月') }}）</div>
    </div>

    <!-- 上部の数値カード -->
    <div class="wl-cards">
      <div class="wl-card">
        <div class="c-label">希望提出者</div>
        <div class="c-num" id="cTotal">0<small> 名</small></div>
      </div>
      <div class="wl-card">
        <div class="c-label">希望日数の合計</div>
        <div class="c-num" id="cWish">0<small> 日</small></div>
      </div>
      <div class="wl-card">
        <div class="c-label">まだアサイン0の人</div>
        <div class="c-num" id="cZero" style="color:var(--danger);">0<small> 名</small></div>
      </div>
    </div>

    <!-- 絞り込み -->
    <div class="wl-filter">
      <div class="f-item">
        <label>区分</label>
        <select id="fLv" onchange="render()">
          <option value="">すべて</option>
          <option value="new">新人</option>
          <option value="mid">中堅</option>
          <option value="vet">ベテラン</option>
        </select>
      </div>
      <div class="f-item">
        <label>できるポジション</label>
        <select id="fPos" onchange="render()">
          <option value="">すべて</option>
          <option value="D">D（ディレクター）</option>
          <option value="OP">OP（音響）</option>
          <option value="MC">MC（司会進行）</option>
          <option value="FC">FC（巡回ファシリ）</option>
          <option value="CK">CK（チェッカー）</option>
          <option value="軍師・サポーター">軍師・サポーター</option>
          <option value="受付">受付</option>
        </select>
      </div>
      <div class="f-item">
        <label>並べ替え</label>
        <select id="fSort" onchange="render()">
          <option value="wish">希望日数が多い順</option>
          <option value="rate">アサイン割合が低い順</option>
          <option value="assigned">アサイン数が少ない順</option>
        </select>
      </div>
    </div>

    <table class="tbl">
      <thead>
        <tr>
          <th>スタッフ</th>
          <th>区分</th>
          <th class="center">希望日数</th>
          <th class="center">アサイン済</th>
          <th>アサイン割合</th>
          <th class="center">MCアサイン<br>回数</th>
          <th>できるポジション</th>
        </tr>
      </thead>
      <tbody id="wlBody"></tbody>
    </table>

    <div class="note">
      ※「アサイン割合」＝アサイン済 ÷ 希望日数。割合が低い人は、希望を出しているのにまだ入れていない人です（優先的に検討の目安）。<br>
      ※できるポジションの青タグ（D／OP／MC／軍師・サポーター）は経験者向けポジションです。
    </div>
  </div>

<!-- 本物の希望者データ（Controllerが対象月の希望・アサインから作成）をJSへ渡す。
     ここだけBladeで埋め込み、下の表示ロジック（テンプレートリテラルを使う）はそのまま温存する。 -->
<script>
  window.WISHLIST = @json($people);
</script>
@verbatim
<script>
  // ===== 希望者データ（本物のDB由来。受け渡しは window.WISHLIST）=====
  // lv: new=新人 / mid=中堅 / vet=ベテラン
  // wish: 今月の稼働希望を出した日数 ／ assigned: そのうち実際にアサインされた日数
  // pos: できるポジション
  const KEY_POS = ['D','OP','MC','軍師・サポーター']; // 経験者向け（青タグ）
  const ALL_POS = ['D','OP','MC','FC','CK','軍師・サポーター','受付'];

  // mc: 今月そのうちMCとしてアサインされた回数（MCができる人のみ。できない人は表示で「—」）
  // データの中身は Controller（AssignWishlistController）が本物のDBから作成済み。
  const people = window.WISHLIST || [];

  const lvLabel = { new:'新人', mid:'中堅', vet:'ベテラン' };
  const body = document.getElementById('wlBody');

  function rateOf(p){ return p.wish ? Math.round(p.assigned / p.wish * 100) : 0; }
  function rateClass(r){ return r >= 70 ? 'hi' : (r >= 40 ? 'mid' : 'low'); }

  function render(){
    const fLv   = document.getElementById('fLv').value;
    const fPos  = document.getElementById('fPos').value;
    const fSort = document.getElementById('fSort').value;

    let list = people.filter(p => {
      if (fLv && p.lv !== fLv) return false;
      if (fPos && !p.pos.includes(fPos)) return false;
      return true;
    });

    if (fSort === 'wish')      list.sort((a,b) => b.wish - a.wish);
    if (fSort === 'rate')      list.sort((a,b) => rateOf(a) - rateOf(b));
    if (fSort === 'assigned')  list.sort((a,b) => a.assigned - b.assigned);

    // テーブル
    body.innerHTML = '';
    list.forEach(p => {
      const r = rateOf(p);
      const posHtml = p.pos.map(pos =>
        `<span class="ptag ${KEY_POS.includes(pos) ? 'key' : ''}">${pos}</span>`
      ).join('');
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td><strong>${p.name}</strong> <span class="muted" style="font-size:11px;">${p.id}</span></td>
        <td><span class="lv ${p.lv}">${lvLabel[p.lv]}</span></td>
        <td class="center">${p.wish}</td>
        <td class="center">${p.assigned}</td>
        <td><span class="rate"><span class="rbar"><i class="${rateClass(r)}" style="width:${r}%;"></i></span><span class="rtxt">${r}%</span></span></td>
        <td class="center">${p.pos.includes('MC') ? '<b>'+p.mc+'</b> 回' : '—'}</td>
        <td>${posHtml}</td>`;
      body.appendChild(tr);
    });

    // 上部カード（全データ基準＝絞り込みに左右されない）
    document.getElementById('cTotal').innerHTML = people.length + '<small> 名</small>';
    document.getElementById('cWish').innerHTML  = people.reduce((s,p)=>s+p.wish,0) + '<small> 日</small>';
    document.getElementById('cZero').innerHTML  = people.filter(p=>p.assigned===0).length + '<small> 名</small>';
  }

  render();
</script>
@endverbatim
</body>
</html>
