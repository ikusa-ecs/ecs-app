@extends('layouts.app')
@section('title', '過去案件の取込')
@section('h1', '過去案件の取込（アサイン込み）')
@php($active = 'past_import')

@push('head')
{{-- 名簿の氏名をJSに渡す（アサイン列の照合をその場で見せるため）。 --}}
<script>
  window.ECS_CSRF = "{{ csrf_token() }}";
  window.ECS_ROSTER_NAMES = @json($rosterNames ?? []);
</script>
<style>
  .pj-wrap { max-width: 1040px; }
  .pj-card { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 16px 18px; margin-bottom: 14px; }
  .pj-card h2 { font-size: 14px; margin: 0 0 8px; }
  .pj-lead { font-size: 12.5px; color: #6b5c49; line-height: 1.8; }
  .pj-lead b { color: var(--ink); }
  .pj-flash { border-radius: 10px; padding: 12px 14px; font-size: 13px; margin-bottom: 14px; line-height: 1.8; }
  .pj-flash.ok { background: var(--ok-soft, #eef6f0); color: #166534; border: 1px solid #bfe6d2; }
  .pj-flash.err { background: #fdecec; color: #b91c1c; border: 1px solid #f3c0c0; }
  .pj-flash.warn { background: #fdf3e2; color: #8a5a10; border: 1px solid #ecd9b6; }
  .pj-drop { border: 2px dashed var(--line); border-radius: 10px; padding: 22px; text-align: center; color: #8a7a66; background: #fff; transition: .15s; }
  .pj-drop.drag { border-color: #4f8a63; background: #eef6f0; color: #2e7d4f; }
  .pj-file { font-size: 13px; color: #2e7d4f; font-weight: 700; margin-top: 10px; }
  .pj-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-top: 12px; }
  .pj-names { margin: 8px 0 0; padding-left: 1.2em; font-size: 12.5px; }
  .pj-names li { margin: 2px 0; }
  .pj-summary { font-size: 13px; margin: 10px 2px; }
  .pj-summary .ok { color: #2e9e6b; font-weight: 700; }
  .pj-summary .ng { color: #d9534f; font-weight: 700; }
  .pj-scroll { max-height: 46vh; overflow: auto; border: 1px solid var(--line); border-radius: 8px; }
  table.pj-table { width: 100%; border-collapse: collapse; font-size: 12px; }
  table.pj-table th, table.pj-table td { border: 1px solid var(--line); padding: 4px 7px; text-align: left; white-space: nowrap; }
  table.pj-table th { background: #f6f1ea; position: sticky; top: 0; }
  table.pj-table tr.row-ng td { background: #fdecec; }
  table.pj-table tr.row-ok td { background: #f2faf5; }
  .pj-reason { color: #b91c1c; font-size: 11px; white-space: normal; }
  .pj-miss { color: #8a5a10; font-size: 11px; white-space: normal; }
</style>
@endpush

@section('content')
<div class="pj-wrap">

  @if (session('status'))
    <div class="pj-flash ok">{{ session('status') }}</div>
  @endif
  @if (session('import_error'))
    <div class="pj-flash err">{{ session('import_error') }}</div>
  @endif

  {{-- 名簿に無かった人・同姓同名で決められなかった人は、必ず一覧で知らせる
       （その人のアサインだけ入っていない＝黙って落とすと気づけないため）。 --}}
  @if (session('past_missing') && count(session('past_missing')))
    <div class="pj-flash warn">
      <b>⚠ 次の方は名簿に見つからなかったので、アサインを入れていません。</b><br>
      名簿に登録してから、同じCSVをもう一度取り込むと入ります（案件は上書きされ、二重にはなりません）。
      辞められた方の場合は、名簿に登録して「退職にする」を押しておくと履歴として残せます。
      <ul class="pj-names">
        @foreach (session('past_missing') as $n)
          <li>{{ $n }}</li>
        @endforeach
      </ul>
    </div>
  @endif
  @if (session('past_ambiguous') && count(session('past_ambiguous')))
    <div class="pj-flash warn">
      <b>⚠ 次の方は名簿に同じ氏名が2人以上いるため、どちらか決められませんでした。</b><br>
      人の取り違えを防ぐため、あえて入れていません。名簿でどちらかの氏名を直すか、ご相談ください。
      <ul class="pj-names">
        @foreach (session('past_ambiguous') as $n)
          <li>{{ $n }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  <div class="pj-card">
    <h2>この画面は何をするもの？</h2>
    <p class="pj-lead">
      <b>終わった案件（過去の実績）を、アサインごとまとめて登録する画面です。</b>
      アサイン表の「1行に1案件」が並ぶシート（例：<code>202601_list</code>）を <b>CSVで保存してそのままアップロード</b>してください。
      <b>列を並べ替える必要はありません。</b>Excelでそのまま保存した（Shift_JISの）CSVでも読めます。
    </p>
    <ul class="pj-lead" style="padding-left:1.2em;">
      <li><b>D・MC・OP・スタッフの列から、アサインも「確定」で入ります。</b>
        （ふつうの案件取込は、その時点でDが決まっていないので取り込みません）</li>
      <li>案件は<b>「確定」・スタッフに公開済み</b>で入ります＝本人が自分の過去の実績として見られます。<b>募集はしません</b>。</li>
      <li><b>同じ案件は上書き</b>します。同じかどうかは<b>「日程・コンテンツ・顧客名・集合時間」が全部同じか</b>で見ます。
        1つでも違えば別案件として新しく作ります（同じ日・同じコンテンツでも顧客が違えば別案件）。<br>
        <span class="muted">＝失敗しても、直してもう一度入れれば大丈夫です（案件が二重に増えません）。</span></li>
    </ul>
    <p class="pj-lead">
      <b>人の書き方</b>：D・MC・OP・スタッフの列には<b>氏名</b>を書いてください。複数いるときは<b>カンマ区切り</b>（例：<code>田中 健一, 鈴木彩</code>）。
      <b>氏名の空白の有無は気にしなくて大丈夫</b>です。「スタッフ」列の人は<b>FC（巡回ファシリ）</b>として入ります。<br>
      <b>日程</b>は<b>年から</b>入れてください（<code>2026/1/20</code>・<code>2026-01-20</code>・Excelの日付セルどれでもOK）。<br>
      ECSに対応する項目が無い列（拘束・顧客担当名・シート期日 など）は無視し、取り込み後に「取り込まなかった列」として表示します。
    </p>
  </div>

  <div class="pj-card">
    <h2>① CSVを選ぶ</h2>
    {{-- 実登録はこのフォームでCSVファイルそのものをPOSTし、サーバーが読み直して登録する。 --}}
    <form id="pjForm" method="POST" action="/past-import" enctype="multipart/form-data">
      @csrf
      <div class="pj-drop" id="pjDrop">
        ここにCSVをドラッグ＆ドロップ、または<br>
        <input type="file" name="csv" id="pjFile" accept=".csv,text/csv" style="margin-top:8px;">
        <div class="pj-file" id="pjFileName"></div>
      </div>
    </form>
  </div>

  {{-- ファイルを選ぶと、ここに中身が出る（他の取込画面と同じ動き）。 --}}
  <div class="pj-card" id="pjResult" style="display:none;">
    <h2>② 取り込む内容の確認</h2>
    <div class="pj-summary">
      読み込み：<b id="pjTotal">0</b> 行 ／ <span class="ok">OK <b id="pjOk">0</b></span> ／ <span class="ng">エラー <b id="pjNg">0</b></span>
      ／ アサインに入る人：<b id="pjPeople">0</b> 名
    </div>
    <div id="pjWarn"></div>
    <div class="pj-scroll">
      <table class="pj-table">
        <thead>
          <tr>
            <th>行</th><th>判定</th><th>日程</th><th>コンテンツ</th><th>顧客名</th><th>集合</th>
            <th>運営人数</th><th>D</th><th>MC</th><th>OP</th><th>スタッフ</th><th>理由・注意</th>
          </tr>
        </thead>
        <tbody id="pjBody"></tbody>
      </table>
    </div>
    <div class="pj-actions">
      <button type="button" class="btn primary" id="pjBtn" onclick="pjSubmit()">③ この内容で取り込む</button>
      <span class="muted" style="font-size:12px;">
        ※ 案件は「確定・公開済み」で入り、アサインは「確定」で入ります。取り込み直しても二重になりません。
      </span>
    </div>
  </div>

</div>
@endsection

@push('scripts')
{{-- CSVの文字コード（UTF-8 / Excel保存のShift_JIS）を見分けて読む共通処理 --}}
<script src="/ecs/csv-read.js?v={{ \App\Support\Asset::ver('ecs/csv-read.js') }}"></script>
@verbatim
<script>
  // ===== 過去案件の取込：ファイルを選んだらその場で中身を見せる =====
  // ⚠ 他の取込画面と同じ「選んだら出る」動きにそろえている（2026-08-24）。
  //    最初はボタンを押す形にしていて「選んでも何も起きない」と誤解された。

  // 名簿の氏名（空白を落としたもの → 人数）。同姓同名も分かるようにする。
  var PJ_NAMES = (function () {
    var m = {};
    (window.ECS_ROSTER_NAMES || []).forEach(function (n) {
      var k = String(n).replace(/[\s　]+/g, '');
      m[k] = (m[k] || 0) + 1;
    });
    return m;
  })();

  // 見出しの言い方をそろえる（全角カッコ・スペース・記号を落として比べる）。
  function pjNormKey(s) {
    return String(s == null ? '' : s)
      .replace(/[\s　]+/g, '')
      .replace(/[（）]/g, function (c) { return c === '（' ? '(' : ')'; })
      .toLowerCase();
  }

  // この画面が見る列（サーバーの読み替えと同じ言い方を並べる）。
  var PJ_COLS = {
    date:    ['日程', '開催日', '日付', '開催日程', 'イベント日'],
    content: ['コンテンツ', '案件名', 'コンテンツ名'],
    client:  ['顧客名(代理店名)', '顧客名', 'クライアント', '顧客', '取引先'],
    meet:    ['集合', '集合時間'],
    count:   ['運営人数', '運営'],
    d:       ['D', 'ディレクター'],
    mc:      ['MC'],
    op:      ['OP', '音響担当'],
    staff:   ['スタッフ', '運営スタッフ', '現場スタッフ']
  };

  function pjParseLine(line) {
    var out = [], cur = '', q = false;
    for (var i = 0; i < line.length; i++) {
      var c = line[i];
      if (q) {
        if (c === '"' && line[i + 1] === '"') { cur += '"'; i++; }
        else if (c === '"') { q = false; }
        else { cur += c; }
      } else {
        if (c === '"') { q = true; }
        else if (c === ',') { out.push(cur); cur = ''; }
        else { cur += c; }
      }
    }
    out.push(cur);
    return out;
  }

  // 「2026/1/20」「2026年1月20日」「Excelの日付（数字）」を 2026-01-20 に直す。
  // 年が無いもの（1/20）は勘で補わずエラーにする＝サーバーと同じ考え方。
  function pjNormDate(v) {
    v = String(v == null ? '' : v).trim();
    if (v === '') return '';
    if (/^\d{4}-\d{2}-\d{2}$/.test(v)) return v;
    var m = v.match(/^(\d{4})[\/年.-](\d{1,2})[\/月.-](\d{1,2})/);
    if (m) {
      return m[1] + '-' + ('0' + m[2]).slice(-2) + '-' + ('0' + m[3]).slice(-2);
    }
    // Excelのシリアル値（1900-01-01 起点。1900年うるう年の1日ずれを含む）
    if (/^\d+(\.\d+)?$/.test(v)) {
      var n = Math.floor(parseFloat(v));
      if (n > 20000 && n < 80000) {
        var base = Date.UTC(1899, 11, 30);
        var d = new Date(base + n * 86400000);
        return d.getUTCFullYear() + '-' + ('0' + (d.getUTCMonth() + 1)).slice(-2) + '-' + ('0' + d.getUTCDate()).slice(-2);
      }
    }
    return '';
  }

  function pjIsRealDate(s) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return false;
    var p = s.split('-'), y = +p[0], mo = +p[1], da = +p[2];
    var dt = new Date(y, mo - 1, da);
    return dt.getFullYear() === y && dt.getMonth() === mo - 1 && dt.getDate() === da;
  }

  // 1つのセルの氏名を分けて、名簿にいるか調べる。
  function pjCheckNames(cell) {
    var names = String(cell == null ? '' : cell)
      .split(/[,、，\/／\r\n]+/)
      .map(function (s) { return s.trim(); })
      .filter(function (s) { return s !== ''; });
    var ok = [], miss = [], dup = [];
    names.forEach(function (n) {
      var c = PJ_NAMES[n.replace(/[\s　]+/g, '')] || 0;
      if (c === 1) ok.push(n);
      else if (c > 1) dup.push(n);
      else miss.push(n);
    });
    return { names: names, ok: ok, miss: miss, dup: dup };
  }

  function pjEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }

  function pjRender(text) {
    text = text.replace(/^﻿/, '');
    var lines = text.split(/\r\n|\r|\n/).filter(function (l) { return l.trim() !== ''; });
    var result = document.getElementById('pjResult');
    var warn = document.getElementById('pjWarn');
    var body = document.getElementById('pjBody');

    if (lines.length < 2) {
      result.style.display = '';
      warn.innerHTML = '<div class="pj-flash err">CSVにデータ行がありません（1行目の見出しのみ、または空です）。</div>';
      body.innerHTML = '';
      document.getElementById('pjTotal').textContent = '0';
      document.getElementById('pjOk').textContent = '0';
      document.getElementById('pjNg').textContent = '0';
      document.getElementById('pjPeople').textContent = '0';
      document.getElementById('pjBtn').disabled = true;
      return;
    }

    var header = pjParseLine(lines.shift()).map(function (h) { return pjNormKey(h); });
    var idx = {};
    Object.keys(PJ_COLS).forEach(function (key) {
      for (var i = 0; i < PJ_COLS[key].length; i++) {
        var pos = header.indexOf(pjNormKey(PJ_COLS[key][i]));
        if (pos >= 0) { idx[key] = pos; break; }
      }
    });

    var missingCols = [];
    if (idx.date === undefined) missingCols.push('日程');
    if (idx.content === undefined) missingCols.push('コンテンツ');

    var rows = [], okCount = 0, ngCount = 0;
    var allMiss = {}, allDup = {}, peopleCount = 0;

    lines.forEach(function (line, i) {
      var cells = pjParseLine(line);
      var get = function (key) {
        var p = idx[key];
        return (p !== undefined && cells[p] != null) ? String(cells[p]).trim() : '';
      };

      var rawDate = get('date');
      var date = pjNormDate(rawDate);
      var content = get('content');
      var count = get('count');

      var errs = [];
      if (content === '') errs.push('コンテンツ（案件名）が空です');
      if (!pjIsRealDate(date)) {
        errs.push(rawDate !== ''
          ? '日程が読めません（' + rawDate + '）。年から入れてください（例 2026-01-20）'
          : '日程が空です（例 2026-01-20）');
      }
      if (count !== '' && !/^\d+$/.test(count)) errs.push('運営人数は数字で入れてください');

      var people = { d: pjCheckNames(get('d')), mc: pjCheckNames(get('mc')), op: pjCheckNames(get('op')), staff: pjCheckNames(get('staff')) };
      var notes = [];
      ['d', 'mc', 'op', 'staff'].forEach(function (k) {
        people[k].miss.forEach(function (n) { allMiss[n] = true; });
        people[k].dup.forEach(function (n) { allDup[n] = true; });
        if (!errs.length) peopleCount += people[k].ok.length;
      });
      var missAll = [].concat(people.d.miss, people.mc.miss, people.op.miss, people.staff.miss);
      var dupAll = [].concat(people.d.dup, people.mc.dup, people.op.dup, people.staff.dup);
      if (missAll.length) notes.push('名簿に無い：' + missAll.join('・'));
      if (dupAll.length) notes.push('同姓同名で決められない：' + dupAll.join('・'));

      if (errs.length) ngCount++; else okCount++;

      rows.push({
        no: i + 2, ok: errs.length === 0, date: date || rawDate, content: content,
        client: get('client'), meet: get('meet'), count: count,
        d: get('d'), mc: get('mc'), op: get('op'), staff: get('staff'),
        reason: errs.concat(notes).join(' / ')
      });
    });

    document.getElementById('pjTotal').textContent = rows.length;
    document.getElementById('pjOk').textContent = okCount;
    document.getElementById('pjNg').textContent = ngCount;
    document.getElementById('pjPeople').textContent = peopleCount;

    var w = '';
    if (missingCols.length) {
      w += '<div class="pj-flash err">必要な列が見つかりません：<b>' + pjEsc(missingCols.join('・'))
        + '</b>。アサイン表の「1行1案件」のシートをそのまま保存したCSVか確認してください。</div>';
    }
    if (Object.keys(allMiss).length) {
      w += '<div class="pj-flash warn"><b>名簿に無い人：</b>' + pjEsc(Object.keys(allMiss).join('・'))
        + '<br>この方のアサインは入りません。名簿に登録してから、同じCSVをもう一度入れてください（案件は上書きされ二重になりません）。</div>';
    }
    if (Object.keys(allDup).length) {
      w += '<div class="pj-flash warn"><b>同姓同名で決められない人：</b>' + pjEsc(Object.keys(allDup).join('・'))
        + '<br>取り違えを防ぐため入れません。名簿でどちらかの氏名を直してください。</div>';
    }
    warn.innerHTML = w;

    body.innerHTML = rows.map(function (r) {
      return '<tr class="' + (r.ok ? 'row-ok' : 'row-ng') + '">'
        + '<td>' + r.no + '</td>'
        + '<td>' + (r.ok ? 'OK' : 'エラー') + '</td>'
        + '<td>' + pjEsc(r.date) + '</td>'
        + '<td>' + pjEsc(r.content) + '</td>'
        + '<td>' + pjEsc(r.client) + '</td>'
        + '<td>' + pjEsc(r.meet) + '</td>'
        + '<td>' + pjEsc(r.count) + '</td>'
        + '<td>' + pjEsc(r.d) + '</td>'
        + '<td>' + pjEsc(r.mc) + '</td>'
        + '<td>' + pjEsc(r.op) + '</td>'
        + '<td>' + pjEsc(r.staff) + '</td>'
        + '<td class="' + (r.ok ? 'pj-miss' : 'pj-reason') + '">' + pjEsc(r.reason) + '</td>'
        + '</tr>';
    }).join('');

    result.style.display = '';
    document.getElementById('pjBtn').disabled = (okCount === 0 || missingCols.length > 0);
    result.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function pjReadAndPreview(file) {
    if (!file) return;
    document.getElementById('pjFileName').textContent = '選んだファイル：' + file.name;
    // 文字コード（UTF-8 / Excel保存のShift_JIS）を見分けて読む共通処理を使う。
    // 正本＝public/ecs/csv-read.js の ECS_readCsvFile（他の取込画面と同じ）。
    if (window.ECS_readCsvFile) {
      window.ECS_readCsvFile(file, pjRender);
    } else {
      var fr = new FileReader();
      fr.onload = function () { pjRender(String(fr.result || '')); };
      fr.readAsText(file);
    }
  }

  function pjSubmit() {
    var f = document.getElementById('pjFile').files[0];
    if (!f) { alert('先にCSVファイルを選んでください。'); return; }
    var msg = ['過去案件を取り込みます。',
               '',
               '・案件は「確定」・スタッフに公開済みで入ります',
               '・D／MC／OP／スタッフの列の人は「確定」のアサインで入ります',
               '・同じ案件（日程・コンテンツ・顧客名・集合時間が同じ）は上書きします',
               '',
               'よろしいですか？'].join(String.fromCharCode(10));
    if (!confirm(msg)) return;
    document.getElementById('pjForm').submit();
  }

  // ファイルを選んだら、その場で中身を出す。
  document.getElementById('pjFile').addEventListener('change', function () {
    pjReadAndPreview(this.files[0]);
  });

  // ドラッグ＆ドロップ
  (function () {
    var drop = document.getElementById('pjDrop');
    var input = document.getElementById('pjFile');
    if (!drop || !input) return;
    ['dragenter', 'dragover'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('drag'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('drag'); });
    });
    drop.addEventListener('drop', function (e) {
      if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
        input.files = e.dataTransfer.files;
        pjReadAndPreview(input.files[0]);
      }
    });
  })();
</script>
@endverbatim
@endpush
