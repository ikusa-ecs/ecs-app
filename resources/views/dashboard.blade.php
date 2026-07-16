@extends('layouts.app')
@section('title', 'ダッシュボード')
@section('h1', 'ダッシュボード')
@php($active = 'dashboard')

@push('head')
{{-- cases.js は危険日の判定ロジック（ECS_caseDate / ECS_dangerCheck）のために読み込む。
     データ本体（ECS_CASES）は、この直後で DB の案件（Controller が cases.js と同じ形に整えて渡す）で上書きする。
     ＝ 計算式はそのまま・データの出どころだけ DB に切り替える。 --}}
<script src="/ecs/data/cases.js"></script>
<script>
  window.ECS_CASES = @json($cases);
  // 危険日（手動指定）＝設定画面で足した日（YYYY-MM-DD の配列）。自動判定に加えてカレンダーで赤くする。
  window.ECS_MANUAL_DANGER = @json($manualDanger ?? []);
</script>
@verbatim
<style>
  /* 月次の件数集計テーブル */
  .cnt-sub { font-size: 13px; font-weight: 700; margin: 0 0 5px; color: var(--ink); }
  .cnt-table td { padding: 2px 12px; font-size: 12.5px; line-height: 1.3; }
  .cnt-table td.num { text-align: right; font-variant-numeric: tabular-nums; font-weight: 700; }
  .cnt-table tr.total td { font-weight: 700; background: var(--brand-soft); color: var(--brand-dark); }
  .cnt-table tr.grp td { font-weight: 700; background: #f1ece3; }
  .cnt-table tr.ind td { color: var(--muted); }

  /* 危険日カレンダー */
  .cal-head { display:flex; align-items:center; gap:12px; }
  .cal-head .mlabel { font-size:15px; font-weight:700; min-width:120px; text-align:center; }
  .cal-nav { border:1px solid var(--line); background:#fff; border-radius:8px; width:32px; height:32px; cursor:pointer; font-size:16px; line-height:1; }
  .cal-nav:hover { background:var(--brand-soft); }
  .cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:4px; margin-top:8px; }
  .cal-dow { text-align:center; font-size:11.5px; font-weight:700; color:var(--muted); padding:2px 0; }
  .cal-dow.sat { color:#3b6db5; }
  .cal-dow.sun { color:var(--danger); }
  .cal-cell { min-height:58px; border:1px solid var(--line); border-radius:6px; padding:3px 5px; background:#fff; position:relative; }
  .cal-cell.empty { background:transparent; border:none; }
  .cal-cell .dnum { font-size:12.5px; font-weight:700; color:var(--ink); }
  .cal-cell.sat .dnum { color:#3b6db5; }
  .cal-cell.sun .dnum { color:var(--danger); }
  .cal-cell.today { outline:2px solid var(--brand); outline-offset:-2px; }
  .cal-cell.has { background:#f3f7ff; }
  .cal-cell.danger { background:var(--danger-soft); border-color:var(--danger); cursor:help; }
  .cal-cell .cnum { display:inline-block; margin-top:4px; font-size:11px; font-weight:700; color:#3b6db5; }
  .cal-cell.danger .cnum { color:var(--danger); }
  .cal-cell .big-mark { display:inline-block; margin-top:3px; font-size:10px; font-weight:700; background:#fde68a; color:#7a5200; border:1px solid #e0b84a; border-radius:5px; padding:0 4px; }
  .cal-cell .warn-ico { position:absolute; top:4px; right:5px; font-size:13px; }
  .cal-legend { display:flex; flex-wrap:wrap; gap:14px; margin-top:8px; font-size:11.5px; color:var(--muted); }
  .cal-legend .lg { display:inline-flex; align-items:center; gap:6px; }
  .cal-legend .sw { width:14px; height:14px; border-radius:4px; border:1px solid var(--line); display:inline-block; }
  .cal-legend .sw.has { background:#f3f7ff; }
  .cal-legend .sw.danger { background:var(--danger-soft); border-color:var(--danger); }
  .cal-legend .sw.big { background:#fde68a; border-color:#e0b84a; }
  /* 危険日リスト */
  .dgr-list { margin-top:16px; }
  .dgr-item { display:flex; gap:10px; align-items:flex-start; padding:8px 0; border-top:1px solid var(--line); }
  .dgr-item .ddate { min-width:64px; font-weight:700; color:var(--danger); font-size:13px; }
  .dgr-item .drsn { font-size:12.5px; color:var(--ink); }
  .dgr-item .drsn .nm { color:var(--muted); }
</style>
@endverbatim
@endpush

@section('content')
@verbatim
      <div class="mock-note">ここに出ている数値は<b>登録された本物の案件データ</b>から自動計算しています（KPI・危険日カレンダー・件数集計とも）。※ 危険日判定で使う「稼働スタッフ40名」は今は暫定の目安です。</div>

      <!-- 数値サマリ（全社員向け・案件データから自動計算） -->
      <div class="grid cols-4">
        <div class="stat">
          <div class="label">今月の案件数</div>
          <div class="value" id="kpiThisMonth">–</div>
          <div class="sub" id="kpiThisMonthSub">全社・本番/設営など合計</div>
        </div>
        <div class="stat">
          <div class="label">今週の現場</div>
          <div class="value ok" id="kpiThisWeek">–</div>
          <div class="sub" id="kpiThisWeekSub">今週（月〜日）に開催</div>
        </div>
        <div class="stat">
          <div class="label">今月の大型案件</div>
          <div class="value warn" id="kpiBig">–</div>
          <div class="sub">規模「大型」の案件</div>
        </div>
        <div class="stat">
          <div class="label">来月の案件数</div>
          <div class="value" id="kpiNextMonth">–</div>
          <div class="sub" id="kpiNextMonthSub">先の予定の見込み</div>
        </div>
      </div>

      <!-- 危険日カレンダー -->
      <div class="panel" style="margin-top:20px;">
        <div class="panel-head">
          <h2>危険日カレンダー</h2>
          <div class="spacer"></div>
          <div class="cal-head">
            <button class="cal-nav" id="calPrev" title="前の月">‹</button>
            <span class="mlabel" id="calLabel">—</span>
            <button class="cal-nav" id="calNext" title="次の月">›</button>
          </div>
        </div>
        <p class="muted" style="font-size:12px; margin:0 0 4px;">
          同じ日に案件が集中して、人手が足りなくなりそうな日（危険日）を赤く表示します。判定は「大型2件以上／リアル系5件以上／必要スタッフ合計が稼働40名の7割（28名）以上」のいずれか。案件データは本物ですが、判定に使う「稼働40名」は今は暫定の目安です。
        </p>
        <div class="cal-grid" id="calDow"></div>
        <div class="cal-grid" id="calGrid"></div>
        <div class="cal-legend">
          <span class="lg"><span class="sw danger"></span>危険日（要注意）</span>
          <span class="lg"><span class="sw has"></span>案件あり</span>
          <span class="lg"><span class="sw big"></span>大型案件あり</span>
          <span class="lg">数字＝その日の件数</span>
        </div>
        <div class="dgr-list" id="dgrList"></div>
      </div>

      <!-- 件数集計（月を選択して、その月の案件を拠点×種別で集計） -->
      <div class="panel" style="margin-top:16px;">
        <div class="panel-head">
          <h2>件数集計</h2>
          <div class="spacer"></div>
          <label for="cntMonth" class="muted" style="font-size:12.5px; margin-right:6px;">表示する月：</label>
          <select id="cntMonth" onchange="window.ECS_renderCount && window.ECS_renderCount()"
                  style="padding:6px 10px; border:1px solid var(--line); border-radius:8px; font-family:inherit; font-size:13px; background:#fff;"></select>
        </div>
        <div class="grid cols-2">

          <!-- 拠点別サマリ -->
          <div>
            <div class="cnt-sub">拠点別</div>
            <table class="tbl cnt-table"><tbody id="cntByBase"></tbody></table>
          </div>

          <!-- 種別内訳 -->
          <div>
            <div class="cnt-sub">種別内訳</div>
            <table class="tbl cnt-table"><tbody id="cntByType"></tbody></table>
          </div>

        </div>
        <p class="muted" style="font-size:11.5px; margin:8px 0 0;">
          ※ 実施形態（例「イベント東(リアル)」）から拠点と種別を読み取り、選んだ月の案件を集計しています。「リアル」の内訳が「リアル通常＋リアルロング」です。<br>
          ※「仮案件・追加案件・ARENA場所貸し」や「東→他拠点／他拠点→東」の方向別は、まだデータ項目が無いため未集計です（今後対応）。
        </p>
      </div>
@endverbatim
@endsection

@push('scripts')
@verbatim
<script>
(function(){
  var today = new Date(); today.setHours(0,0,0,0);
  var y = today.getFullYear(), m = today.getMonth();

  // 今週（月曜〜日曜）の範囲
  var weekStart = new Date(today);
  weekStart.setDate(today.getDate() - ((today.getDay() + 6) % 7)); // 月曜
  var weekEnd = new Date(weekStart);
  weekEnd.setDate(weekStart.getDate() + 6); // 日曜

  // 集計対象＝過去・下書きを除いた案件
  var cases = (window.ECS_CASES || []).filter(function(c){ return !c.archived && !c.draft; });

  var cntThisMonth = 0, cntNextMonth = 0, cntThisWeek = 0, cntBig = 0;
  var nextY = (m === 11) ? y + 1 : y, nextM = (m === 11) ? 0 : m + 1;

  cases.forEach(function(c){
    var d = window.ECS_caseDate(c.off);
    if (d.getFullYear() === y && d.getMonth() === m){
      cntThisMonth++;
      if (c.scale === '大型') cntBig++;
    }
    if (d.getFullYear() === nextY && d.getMonth() === nextM) cntNextMonth++;
    if (d >= weekStart && d <= weekEnd) cntThisWeek++;
  });

  function set(id, v){ var el = document.getElementById(id); if (el) el.textContent = v; }
  set('kpiThisMonth', cntThisMonth);
  set('kpiThisWeek', cntThisWeek);
  set('kpiBig', cntBig);
  set('kpiNextMonth', cntNextMonth);

  set('kpiThisMonthSub', (m + 1) + '月の案件（全社）');
  var fmt = function(dt){ return (dt.getMonth() + 1) + '/' + dt.getDate(); };
  set('kpiThisWeekSub', fmt(weekStart) + '〜' + fmt(weekEnd) + ' に開催');
  set('kpiNextMonthSub', (nextM + 1) + '月の案件（見込み）');
})();

/* ===== 危険日カレンダー ===== */
(function(){
  var DOW = ['月','火','水','木','金','土','日'];
  var today = new Date(); today.setHours(0,0,0,0);
  var cursor = new Date(today.getFullYear(), today.getMonth(), 1);

  // 曜日見出し（一度だけ）
  var dowEl = document.getElementById('calDow');
  DOW.forEach(function(d, i){
    var c = document.createElement('div');
    c.className = 'cal-dow' + (i===5?' sat':'') + (i===6?' sun':'');
    c.textContent = d;
    dowEl.appendChild(c);
  });

  // その月の各日について、開催される案件を集める
  function casesByDay(y, m){
    var map = {}; // 日(数値) -> [{scale,fmt,need,name}]
    (window.ECS_CASES || []).forEach(function(c){
      if (c.archived || c.draft) return;
      var d = window.ECS_caseDate(c.off);
      if (d.getFullYear() === y && d.getMonth() === m){
        var day = d.getDate();
        (map[day] = map[day] || []).push({ scale:c.scale, fmt:c.fmt, need:c.need, name:c.name, client:c.client, sales:c.sales, meet:c.meet, leave:c.leave });
      }
    });
    return map;
  }

  function render(){
    var y = cursor.getFullYear(), m = cursor.getMonth();
    document.getElementById('calLabel').textContent = y + '年' + (m+1) + '月';

    // 手動指定の危険日（設定画面で足した日）。自動判定に加えて赤くする。
    var MANUAL_DANGER = new Set(window.ECS_MANUAL_DANGER || []);

    var map = casesByDay(y, m);
    var firstDow = new Date(y, m, 1).getDay();           // 0=日
    var lead = (firstDow + 6) % 7;                         // 月曜始まりの先頭空白
    var daysInMonth = new Date(y, m+1, 0).getDate();

    var grid = document.getElementById('calGrid');
    grid.innerHTML = '';

    // 先頭の空白セル
    for (var i=0; i<lead; i++){
      var e = document.createElement('div');
      e.className = 'cal-cell empty';
      grid.appendChild(e);
    }

    var dangerDays = [];

    for (var day=1; day<=daysInMonth; day++){
      var cell = document.createElement('div');
      var dow = new Date(y, m, day).getDay();
      cell.className = 'cal-cell' + (dow===6?' sat':'') + (dow===0?' sun':'');
      if (y===today.getFullYear() && m===today.getMonth() && day===today.getDate()) cell.className += ' today';

      var items = map[day] || [];
      var num = document.createElement('div');
      num.className = 'dnum';
      num.textContent = day;
      cell.appendChild(num);

      // その日が手動危険日か（案件が無くても手動で危険日にできる）。
      var ymd = y + '-' + ('0'+(m+1)).slice(-2) + '-' + ('0'+day).slice(-2);
      var isManual = MANUAL_DANGER.has(ymd);
      // 自動判定（案件があるときだけ）＋手動指定を合わせた「理由」リスト。
      var chk = items.length ? window.ECS_dangerCheck(items) : { danger:false, reasons:[] };
      var reasons = (chk.reasons || []).slice();
      if (isManual) reasons.push('手動で危険日に設定');

      if (items.length){
        cell.className += ' has';
        var hasBig = items.some(function(it){ return it.scale === '大型'; });

        var cnt = document.createElement('span');
        cnt.className = 'cnum';
        cnt.textContent = items.length + '件';
        cell.appendChild(cnt);

        // マスにカーソルを当てると、その日の案件の中身（案件名・クライアント・営業担当・時間）を表示
        var tipLines = items.map(function(it){
          var tm = (it.meet && it.meet !== '—') ? (it.meet + '〜' + (it.leave || '—')) : '時間未定';
          return '・' + (it.name || '（案件名なし）') +
                 '／' + (it.client || 'クライアント未定') +
                 '／営業:' + (it.sales || '—') +
                 '／' + tm;
        });
        cell.title = (m+1) + '/' + day + '（' + items.length + '件）\n' + tipLines.join('\n');

        if (hasBig){
          var bm = document.createElement('span');
          bm.className = 'big-mark';
          bm.textContent = '大型';
          cell.appendChild(document.createElement('br'));
          cell.appendChild(bm);
        }
      }

      // 自動 or 手動で危険日なら赤くする（手動は案件0件の日でも対象）。
      if (chk.danger || isManual){
        cell.className += ' danger';
        cell.title = (cell.title || ((m+1) + '/' + day)) + '\n\n⚠ 危険日\n・' + reasons.join('\n・');
        var w = document.createElement('span');
        w.className = 'warn-ico';
        w.textContent = '⚠';
        cell.appendChild(w);
        dangerDays.push({ day:day, reasons:reasons, items:items });
      }
      grid.appendChild(cell);
    }

    // 危険日リスト
    var list = document.getElementById('dgrList');
    list.innerHTML = '';
    if (!dangerDays.length){
      var none = document.createElement('div');
      none.className = 'alert ok';
      none.innerHTML = '<span class="ico">✓</span><div>この月に危険日はありません。</div>';
      list.appendChild(none);
    } else {
      var h = document.createElement('div');
      h.style.cssText = 'font-size:13px; font-weight:700; margin-bottom:4px;';
      h.textContent = '⚠ この月の危険日（' + dangerDays.length + '日）';
      list.appendChild(h);
      dangerDays.forEach(function(dd){
        var row = document.createElement('div');
        row.className = 'dgr-item';
        var nm = dd.items.length ? dd.items.map(function(it){ return it.name; }).join('／') : '案件なし（手動指定）';
        row.innerHTML = '<span class="ddate">' + (m+1) + '/' + dd.day + '</span>' +
          '<span class="drsn">' + dd.reasons.join('。') + '。' +
          '<span class="nm">（' + nm + '）</span></span>';
        list.appendChild(row);
      });
    }
  }

  document.getElementById('calPrev').addEventListener('click', function(){
    cursor.setMonth(cursor.getMonth() - 1); render();
  });
  document.getElementById('calNext').addEventListener('click', function(){
    cursor.setMonth(cursor.getMonth() + 1); render();
  });

  render();
})();

/* ===== 件数集計（拠点×種別・月を選択） ===== */
(function(){
  var sel = document.getElementById('cntMonth');
  if (!sel) return;

  // 集計対象＝過去・下書きを除いた案件
  var cases = (window.ECS_CASES || []).filter(function(c){ return !c.archived && !c.draft; });

  function dOf(off){ return window.ECS_caseDate(off); }
  function mKey(off){ var d=dOf(off); return d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2); }
  function mLabel(key){ var p=key.split('-'); return p[0]+'年'+Number(p[1])+'月'; }

  // 実施形態「イベント東(リアル)」→ {base:'イベント東', type:'リアル'}（全角・半角カッコ対応）
  function parseFmt(s){
    s = String(s||'').trim();
    var mo = s.match(/^(.*?)[（(]\s*(.*?)\s*[）)]\s*$/);
    if (mo) return { base:(mo[1].trim()||'その他'), type:(mo[2].trim()||'その他') };
    return { base:(s||'その他'), type:'その他' };
  }

  // 月セレクトの選択肢（案件のある月）。既定＝今月（あれば）／無ければ最も早い月。
  var keys = {};
  cases.forEach(function(c){ keys[mKey(c.off)] = true; });
  var monthList = Object.keys(keys).sort();
  var today = new Date();
  var thisKey = today.getFullYear()+'-'+('0'+(today.getMonth()+1)).slice(-2);
  var def = keys[thisKey] ? thisKey : (monthList[0] || thisKey);
  sel.innerHTML = monthList.length
    ? monthList.map(function(k){ return '<option value="'+k+'"'+(k===def?' selected':'')+'>'+mLabel(k)+'</option>'; }).join('')
    : '<option value="">（案件なし）</option>';

  // 拠点の並び順（よく出るものを先に・それ以外は後ろ）
  var BASE_ORDER = ['イベント東','イベント東北','イベント他拠点'];
  function baseRank(b){ var i=BASE_ORDER.indexOf(b); return i<0?BASE_ORDER.length:i; }
  function num(v){ return '<td class="num">'+v+'</td>'; }

  // 全データに登場する拠点を最初に集める（0件の月でも全拠点を表示するため）
  var allBaseMap = {};
  cases.forEach(function(c){ allBaseMap[parseFmt(c.format).base] = true; });
  BASE_ORDER.forEach(function(b){ allBaseMap[b] = true; }); // 定番拠点は案件0でも常に出す
  var allBases = Object.keys(allBaseMap).sort(function(a,b){ return baseRank(a)-baseRank(b) || (a<b?-1:1); });

  window.ECS_renderCount = function(){
    var key = sel.value || def;
    var rows = cases.filter(function(c){ return mKey(c.off)===key; });

    // 拠点ごとに種別を数える
    var byBase = {};
    rows.forEach(function(c){
      var f = parseFmt(c.format);
      var b = byBase[f.base] || (byBase[f.base] = { total:0, types:{} });
      b.total++;
      b.types[f.type] = (b.types[f.type]||0) + 1;
    });
    // 全拠点を対象に（この月に0件の拠点も「0」で表示する）
    var bases = allBases;
    function baseOf(b){ return byBase[b] || { total:0, types:{} }; }

    // 拠点別テーブル
    var b1 = bases.map(function(b){ return '<tr><td>'+b+'</td>'+num(baseOf(b).total)+'</tr>'; }).join('');
    b1 += '<tr class="total"><td>合計</td>'+num(rows.length)+'</tr>';
    document.getElementById('cntByBase').innerHTML = b1;

    // 種別内訳テーブル
    var b2 = '';
    bases.forEach(function(b){
      var t = baseOf(b).types;
      var online = (t['オンライン']||0), real = (t['リアル']||0), longR = (t['リアルロング']||0);
      b2 += '<tr class="grp"><td>'+b+'</td>'+num(baseOf(b).total)+'</tr>';
      // 標準の内訳は 0 でも必ず表示する
      b2 += '<tr><td>　オンライン</td>'+num(online)+'</tr>';
      b2 += '<tr><td>　リアル</td>'+num(real+longR)+'</tr>';
      b2 += '<tr class="ind"><td>　　└ リアル通常</td>'+num(real)+'</tr>';
      b2 += '<tr class="ind"><td>　　└ リアルロング</td>'+num(longR)+'</tr>';
      // その他の種別（ヘルプのみ 等）は、ある分だけ表示
      Object.keys(t).forEach(function(tp){
        if (tp==='オンライン'||tp==='リアル'||tp==='リアルロング') return;
        b2 += '<tr><td>　'+tp+'</td>'+num(t[tp])+'</tr>';
      });
    });
    document.getElementById('cntByType').innerHTML = bases.length ? b2
      : '<tr><td class="muted">この月の案件はありません</td><td class="num">0</td></tr>';
  };

  window.ECS_renderCount();
})();
</script>
@endverbatim
@endpush
