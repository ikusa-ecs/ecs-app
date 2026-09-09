<!DOCTYPE html>
{{-- 画面の色（テーマ）＝人ごとの設定を効かせる。この画面は共通の骨組み（layouts.app）を
     使っていない独立ページなので、ここにも同じ印を付ける（2026-09-09）。
     ⚠ 色そのものは public/ecs/style.css。ここには色を書かない。 --}}
<html lang="ja" data-theme="{{ \App\Support\Themes::current() }}">
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
    .rate .rbar > i.mid { background: var(--brand-fill); }
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

    /* 月の切替（2026-09-07）。ほかの画面（社員・ディレクター集計）と同じ見た目にそろえる。 */
    .wl-month { display: flex; align-items: center; gap: 8px; margin: 8px 0 10px; }
    .wl-month .wl-mon-btn {
      display: inline-flex; align-items: center; justify-content: center;
      border: 1px solid var(--line); background: #fff; border-radius: 8px;
      width: 30px; height: 30px; font-size: 15px; text-decoration: none; color: var(--ink);
    }
    .wl-month .wl-mon-btn.wide { width: auto; padding: 0 10px; font-size: 12.5px; font-weight: 700; }
    .wl-month .wl-mon-btn.on { background: var(--brand-fill); color: #fff; border-color: var(--brand); }
    .wl-month .wl-mon-btn:hover { background: #f3ece0; }
    .wl-month .wl-mon-btn.on:hover { background: var(--brand-fill); }
    .wl-month .wl-mon { font-size: 14px; font-weight: 700; min-width: 96px; text-align: center; }
    /* その月の希望が1件も無いとき。空の表だけだと「壊れている」と誤解されるので理由を出す。 */
    .wl-empty {
      margin: 10px 0 0; padding: 12px 14px; border-radius: 10px;
      background: #fdf6e8; border: 1px solid #e8d3ac; font-size: 13px; line-height: 1.7;
    }

    /* ここから下は「黒ベース（ダークモード）」のときだけ効く上書き。
       上の色は白地むけに直接書いてあるので、黒地だと白い入力欄・ベージュのタグ・
       生成りのお知らせ帯が読みにくい。意味の色（ベテラン＝緑など）はそのまま残す。 */
    html[data-theme="dark"] .wl-filter select,
    html[data-theme="dark"] .wl-filter input { background: var(--surface-2); color: var(--ink); border-color: var(--field-line); }
    html[data-theme="dark"] .lv.mid { background: var(--chip-bg); color: var(--chip-ink); }
    html[data-theme="dark"] .lv.vet { color: var(--ok-ink); }
    html[data-theme="dark"] .rate .rbar { background: var(--chip-bg); }
    html[data-theme="dark"] .ptag { background: var(--chip-bg); color: var(--chip-ink); }
    html[data-theme="dark"] .ptag.key { background: var(--brand-soft); color: var(--brand-dark); }
    html[data-theme="dark"] .wl-month .wl-mon-btn { background: var(--surface-3); border-color: var(--field-line); }
    html[data-theme="dark"] .wl-month .wl-mon-btn:hover { background: var(--surface-hover); }
    html[data-theme="dark"] .wl-month .wl-mon-btn.on,
    html[data-theme="dark"] .wl-month .wl-mon-btn.on:hover { background: var(--brand-fill); color: #ffffff; border-color: var(--brand); }
    html[data-theme="dark"] .wl-empty { background: var(--warn-soft); border-color: #5c4a20; color: var(--warn-ink); }
  </style>
  @endverbatim
</head>
<body>
  <div class="wl-wrap">
    <div class="wl-head">
      <h1>👥 スタッフ一覧（{{ $periodLabel }}）</h1>
      {{-- 月の切替（2026-09-07 baba要望）。それまでは当月に固定で、
           来月の希望をまとめて見ることができなかった。 --}}
      <div class="wl-month">
        <a class="wl-mon-btn" href="?period={{ $prevPeriod }}" title="前の月へ">◀</a>
        <span class="wl-mon">{{ $periodLabel }}</span>
        <a class="wl-mon-btn" href="?period={{ $nextPeriod }}" title="次の月へ">▶</a>
        <a class="wl-mon-btn wide {{ $isThisMonth ? 'on' : '' }}" href="?" title="今月に戻す">今月</a>
      </div>
      <div class="sub">稼働希望（〇）を出した人と、案件にエントリーしてくれた人の一覧です。希望数・実アサイン数・その割合、できるポジションを確認できます。（数値はすべて本物の希望・エントリー・アサインから計算しています。対象月＝<b>{{ $periodLabel }}</b>）<br>
        ◀ ▶ で月を変えられます。<b>その月に〇もエントリーも出していない人は出ません。</b></div>
      @if (count($people) === 0)
        <div class="wl-empty">
          <b>{{ $periodLabel }}は、まだ誰も稼働希望（〇）もエントリーも出していません。</b><br>
          月を間違えていないか、◀ ▶ で確かめてください。スタッフが「稼働希望」を保存するか、案件にエントリーすると、ここに並びます。
        </div>
      @endif
    </div>

    <!-- 上部の数値カード -->
    <div class="wl-cards">
      <div class="wl-card">
        <div class="c-label">希望・応募をくれた人</div>
        <div class="c-num" id="cTotal">0<small> 名</small></div>
      </div>
      <div class="wl-card">
        <div class="c-label">希望数の合計</div>
        <div class="c-num" id="cWish">0<small> 枠</small></div>
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
          <option value="wish">希望数が多い順</option>
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
          <th class="center" title="その月に入れる枠の数。〇を出した日は「その日の案件数（案件が無ければ1）」、〇は無いがエントリーした日はその件数。同じ日は二重に数えません。">希望数</th>
          <th class="center">アサイン済</th>
          <th>アサイン割合</th>
          <th class="center">MCアサイン<br>回数</th>
          <th>できるポジション</th>
        </tr>
      </thead>
      <tbody id="wlBody"></tbody>
    </table>

    <div class="note">
      ※「<b>希望数</b>」＝その月に<b>入れる枠の数</b>です（2026-09-07 に数え方を変えました）。<br>
      　・<b>〇を出した日</b>＝その日の<b>案件数</b>（案件が無い日は 1）／・<b>〇は無いがエントリーした日</b>＝その<b>エントリー件数</b>。<br>
      　⚠ <b>同じ日は二重に数えません</b>（〇の日のエントリーは「その日の案件数」に含まれます）。<br>
      　⚠ 前は「〇を出した日数」だけで、<b>エントリーが入っていませんでした</b>（エントリーだけの人はこの一覧に出ていませんでした）。<br>
      ※「アサイン割合」＝アサイン済 ÷ 希望数。割合が低い人は、入れる枠があるのにまだ入れていない人です（優先的に検討の目安）。<br>
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
        <td class="center"><b>${p.wish}</b><br><span class="muted" style="font-size:10.5px;">〇${p.okDays}日・応募${p.entries}件</span></td>
        <td class="center">${p.assigned}</td>
        <td><span class="rate"><span class="rbar"><i class="${rateClass(r)}" style="width:${r}%;"></i></span><span class="rtxt">${r}%</span></span></td>
        <td class="center">${p.pos.includes('MC') ? '<b>'+p.mc+'</b> 回' : '—'}</td>
        <td>${posHtml}</td>`;
      body.appendChild(tr);
    });

    // 上部カード（全データ基準＝絞り込みに左右されない）
    document.getElementById('cTotal').innerHTML = people.length + '<small> 名</small>';
    document.getElementById('cWish').innerHTML  = people.reduce((s,p)=>s+p.wish,0) + '<small> 枠</small>';
    document.getElementById('cZero').innerHTML  = people.filter(p=>p.assigned===0).length + '<small> 名</small>';
  }

  render();
</script>
@endverbatim
</body>
</html>
