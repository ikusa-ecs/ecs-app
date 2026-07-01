@extends('layouts.app')
@section('title', '稼働状況')
@section('h1', '稼働状況')
@php($active = 'staff_status')

@push('head')
@verbatim
<style>
    /* 稼働状況モック専用スタイル */
    .filterbar { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 16px; }
    .filterbar select {
      padding: 8px 11px; border: 1px solid var(--line); border-radius: 8px; font-size: 13.5px; font-family: inherit; background: #fff;
    }
    .filterbar .spacer { flex: 1; }
    .count-line { font-size: 12.5px; color: var(--muted); margin-bottom: 10px; }

    .lv { font-size: 11px; padding: 1px 7px; border-radius: 999px; font-weight: 600; white-space: nowrap; }
    .lv.new { background: var(--brand-soft); color: var(--brand-dark); }
    .lv.mid { background: #ece3d4; color: #7a6a58; }
    .lv.vet { background: var(--ok-soft); color: #15803d; }

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
      <div class="mock-note">稼働率・連勤・選ばれた率・ご無沙汰・活性度は、登録済みのアサイン・希望・応募データ（DB）から計算して表示しています（対象月＝2026年7月）。データが無い場合は下の見本値を表示します。</div>

      <!-- 数値カード -->
      <div class="grid cols-4" style="margin-bottom:20px;">
        <div class="stat">
          <div class="label">今月のアサイン総数</div>
          <div class="value" id="cTotal">0</div>
          <div class="sub">2026年7月</div>
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
          <div class="label">非アクティブ</div>
          <div class="value warn" id="cInactive">0</div>
          <div class="sub">その月エントリーなし</div>
        </div>
      </div>

      <div class="panel">
        <div class="filterbar">
          <select id="fLv" onchange="applyFilter()">
            <option value="">区分：すべて</option>
            <option value="new">新人</option>
            <option value="mid">中堅</option>
            <option value="vet">ベテラン</option>
          </select>
          <select id="fAct" onchange="applyFilter()">
            <option value="">活性度：すべて</option>
            <option value="active">アクティブ</option>
            <option value="semi">準アクティブ</option>
            <option value="inactive">非アクティブ</option>
          </select>
          <select id="fSort" onchange="render()">
            <option value="rate">並び：稼働率が高い順</option>
            <option value="rateAsc">並び：稼働率が低い順</option>
            <option value="month">並び：今月アサインが多い順</option>
            <option value="pick">並び：選ばれた率が低い順</option>
            <option value="gobusata">並び：ご無沙汰が長い順</option>
          </select>
          <div class="spacer"></div>
          <button class="btn" onclick="alert('モックのため、CSV出力は行いません。')">CSV出力</button>
        </div>

        <div class="count-line"><span id="countTxt">0</span> 名を表示中</div>

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
          <tbody id="tbody"><!-- JSで生成 --></tbody>
        </table>
        </div>
        <div class="muted" style="font-size:12px; margin-top:10px;">
          稼働率＝今月のアサイン日数 ÷ 本人の希望日数（＝希望充足率と同じ意味なので1列に統合。目安30%を担保）。「今月」の◯/20の20は月上限＝過重労働防止のため全員一律20件（稼働率の分母ではなく上限の目安）。活性度＝その月のエントリー率で判定（月ごと）。<br>
          選ばれた率＝アサイン回数 ÷ エントリー（応募）回数。何回も応募しているのに低い人は新人離脱のサイン（赤字）。ご無沙汰＝最後にアサインされてからの日数。長い人ほど声がかかっていない（14日以上で橙・30日以上で赤）。
        </div>
      </div>

      <!-- 警告リスト -->
      <div class="panel">
        <div class="panel-head"><h2>気にかけたい人</h2></div>
        <div id="warnZero"></div>
        <div id="warnRenkin"></div>
        <div id="warnFill"></div>
        <div id="warnPick"></div>
        <div id="warnGobusata"></div>
      </div>
@endverbatim
@endsection

@push('scripts')
<!-- 稼働状況は DB（assignments＋shift_preferences＋applications＋people）から計算して渡す。 -->
<script>window.ECS_STATUS = @json($status);</script>
@verbatim
<script>
  // ===== 稼働状況データ（DB優先・データが無ければ下の見本値） =====
  // active: 活性度（active=アクティブ / semi=準アクティブ / inactive=非アクティブ）
  // month=今月アサイン数 / cap=月上限（稼働率の分母ではなく上限の目安・一律20） / rate=稼働率(%)=月÷希望日数（希望0件はnull） / renkin=最大連勤 / zeroPref=今月の希望が0件
  // applied=エントリー(応募)回数 / picked=そのうち実際にアサインされた回数（選ばれた率 = picked ÷ applied）
  // lastDays=最後にアサインされてからの経過日数（ご無沙汰度。null=履歴なし）
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

  const tbody = document.getElementById('tbody');

  function render(){
    const sortKey = document.getElementById('fSort').value;
    const arr = status.slice();
    arr.sort((a,b) => {
      if (sortKey === 'rate')    return (rateOf(b) ?? -1) - (rateOf(a) ?? -1);
      if (sortKey === 'rateAsc') return (rateOf(a) ?? 999) - (rateOf(b) ?? 999);
      if (sortKey === 'month')   return b.month - a.month;
      if (sortKey === 'pick')    return (pickRateOf(a) ?? 999) - (pickRateOf(b) ?? 999);
      if (sortKey === 'gobusata')return (b.lastDays ?? -1) - (a.lastDays ?? -1);
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

  // 数値カード＆警告リスト
  function renderSummary(){
    const total = status.reduce((s,p) => s + p.month, 0);
    const rated = status.map(rateOf).filter(v => v !== null && v !== undefined);
    const avg = rated.length ? Math.round(rated.reduce((s,v) => s + v, 0) / rated.length) : 0;
    const zero = status.filter(p => p.zeroPref);
    const inactive = status.filter(p => p.active === 'inactive');
    document.getElementById('cTotal').textContent = total;
    document.getElementById('cAvg').textContent = avg + '%';
    document.getElementById('cZero').textContent = zero.length;
    document.getElementById('cInactive').textContent = inactive.length;

    // 希望0件
    const zEl = document.getElementById('warnZero');
    if (zero.length) {
      zEl.innerHTML = `<div class="alert danger"><span class="ico">⚠</span><div><strong>今月の希望が0件：</strong>${zero.map(p=>p.name).join('、')}。<br>0件回避のため、次のアサインで優先的に検討しましょう。</div></div>`;
    } else { zEl.innerHTML = ''; }

    // 連勤注意（4連勤以上）
    const renkin = status.filter(p => p.renkin >= 4);
    const rEl = document.getElementById('warnRenkin');
    if (renkin.length) {
      rEl.innerHTML = `<div class="alert warn"><span class="ico">⚠</span><div><strong>連勤に注意：</strong>${renkin.map(p=>`${p.name}（${p.renkin}連勤）`).join('、')}。<br>夏場の3連勤・通年5連勤超えは避けたいラインです。</div></div>`;
    } else { rEl.innerHTML = ''; }

    // 稼働率が低い（希望を出しているのに30%未満）＝希望に対してアサインが少ない
    const lowRate = status.filter(p => { const r = rateOf(p); return r !== null && r > 0 && r < 30; });
    const fEl = document.getElementById('warnFill');
    if (lowRate.length) {
      fEl.innerHTML = `<div class="alert warn"><span class="ico">▲</span><div><strong>稼働率が30%未満：</strong>${lowRate.map(p=>`${p.name}（${rateOf(p)}%）`).join('、')}。<br>希望を出してくれているのにアサインが少なめです。</div></div>`;
    } else { fEl.innerHTML = ''; }

    // 選ばれた率が低い（4回以上応募して30%未満）＝応募してくれているのに選ばれていない＝離脱リスク
    const lowPick = status.filter(p => p.applied >= 4 && pickRateOf(p) < 30);
    const pEl = document.getElementById('warnPick');
    if (lowPick.length) {
      pEl.innerHTML = `<div class="alert warn"><span class="ico">▲</span><div><strong>応募が多いのに選ばれた率が低い：</strong>${lowPick.map(p=>`${p.name}（${pickRateOf(p)}%・${p.picked}/${p.applied}）`).join('、')}。<br>エントリーしてくれているのに選ばれていません。新人離脱を防ぐため次のアサインで検討を。</div></div>`;
    } else { pEl.innerHTML = ''; }

    // ご無沙汰（最終アサインから30日以上）
    const gobusata = status.slice().filter(p => p.lastDays >= 30).sort((a,b)=>b.lastDays-a.lastDays);
    const gEl = document.getElementById('warnGobusata');
    if (gobusata.length) {
      gEl.innerHTML = `<div class="alert warn"><span class="ico">⌛</span><div><strong>しばらくアサインがない：</strong>${gobusata.map(p=>`${p.name}（${p.lastDays}日）`).join('、')}。<br>声をかけて状況を確認したい人です。</div></div>`;
    } else { gEl.innerHTML = ''; }

    if (!zero.length && !renkin.length && !lowRate.length && !lowPick.length && !gobusata.length) {
      document.getElementById('warnZero').innerHTML = '<div class="alert ok"><span class="ico">✓</span><div>特に気にかけるべき人はいません。</div></div>';
    }
  }

  // 絞り込み
  function applyFilter(){
    const fLv = document.getElementById('fLv').value;
    const fAct= document.getElementById('fAct').value;
    let shown = 0;
    tbody.querySelectorAll('tr').forEach(tr => {
      const okLv  = !fLv  || tr.dataset.lv === fLv;
      const okAct = !fAct || tr.dataset.act === fAct;
      const visible = okLv && okAct;
      tr.style.display = visible ? '' : 'none';
      if (visible) shown++;
    });
    document.getElementById('countTxt').textContent = shown;
  }

  renderSummary();
  render();
</script>
@endverbatim
@endpush
