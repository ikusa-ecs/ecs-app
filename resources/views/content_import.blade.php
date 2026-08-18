@extends('layouts.app')
@section('title', 'コンテンツCSV取込')
@section('h1', 'コンテンツCSV取込')
@php $active = 'content_import'; @endphp

@push('head')
{{-- CSRFトークンをJSに渡す（生テキスト枠の外なのでBladeが展開する）。送信時に使う。 --}}
<script>window.ECS_CSRF = "{{ csrf_token() }}";</script>
<style>
  .pi-wrap { max-width: 1000px; }
  .pi-card { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 16px 18px; margin-bottom: 14px; }
  .pi-card h2 { font-size: 14px; margin: 0 0 8px; }
  .pi-steps { font-size: 12.5px; color: #6b5c49; line-height: 1.7; margin: 0 0 4px; padding-left: 1.1em; }
  .pi-cols { font-size: 12px; color: #6b5c49; line-height: 1.7; }
  .pi-cols b { color: var(--ink); }
  .pi-drop { border: 2px dashed var(--line); border-radius: 10px; padding: 22px; text-align: center; color: #8a7a66; background: #fff; transition: .15s; }
  .pi-drop.drag { border-color: #4f8a63; background: #eef6f0; color: #2e7d4f; }
  .pi-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-top: 12px; }
  .pi-summary { font-size: 13px; margin: 10px 2px; }
  .pi-summary .ok { color: #2e9e6b; font-weight: 700; }
  .pi-summary .ng { color: #d9534f; font-weight: 700; }
  table.pi-table { width: 100%; border-collapse: collapse; font-size: 12px; }
  table.pi-table th, table.pi-table td { border: 1px solid var(--line); padding: 4px 7px; text-align: left; white-space: nowrap; }
  table.pi-table th { background: #f6f1ea; position: sticky; top: 0; }
  table.pi-table tr.row-ng td { background: #fdecec; }
  table.pi-table tr.row-ok td { background: #f2faf5; }
  .pi-reason { color: #b91c1c; font-size: 11px; white-space: normal; }
  .pi-scroll { max-height: 52vh; overflow: auto; border: 1px solid var(--line); border-radius: 8px; }
  .pi-flash { border-radius: 10px; padding: 12px 14px; font-size: 13px; margin-bottom: 14px; }
  .pi-flash.ok { background: var(--ok-soft, #eef6f0); color: #166534; border: 1px solid #bfe6d2; }
  .pi-flash.err { background: #fdecec; color: #b91c1c; border: 1px solid #f3c0c0; }
</style>
@endpush

@section('content')
<div class="pi-wrap">

  @if (session('status'))
    <div class="pi-flash ok">{{ session('status') }}</div>
  @endif
  @if (session('import_error'))
    <div class="pi-flash err">{{ session('import_error') }}</div>
  @endif

  <div class="pi-card">
    <h2>使い方</h2>
    <ol class="pi-steps">
      <li>「① テンプレートをダウンロード」を押して、CSVをExcel等で開く</li>
      <li>1行に1コンテンツずつ記入して保存（文字コードはそのままでOK＝BOM付きUTF-8）</li>
      <li>「② ファイルを選ぶ」で読み込み、下のプレビューで内容とエラーを確認</li>
      <li>問題なければ「③ この内容で登録」。<b>エラー行は登録されず、OK行だけ登録されます</b></li>
    </ol>
    <div class="pi-cols">
      <b>列：</b>
      コンテンツ名 ※必須（同名は重複エラー） ／ 分類（任意：盛り上げ系/真面目系 等） ／
      体力系（体を動かす種目なら「○」・そうでなければ空） ／ 紙が必要（謎解きの紙を使うなら「○」） ／
      1チーム枚数（紙の枚数・数字・空なら1） ／ 利用中（使うなら「○」・空なら利用中）<br>
      <b>自動で入る：</b> コンテンツ番号（CT-### を自動採番）。
    </div>
  </div>

  <div class="pi-card">
    <h2>① テンプレート</h2>
    <button type="button" class="btn ghost" onclick="piDownloadTemplate()">⬇ テンプレートをダウンロード</button>
  </div>

  <div class="pi-card">
    <h2>② ファイルを選ぶ</h2>
    {{-- 実登録はこのフォームでCSVファイルそのものをPOSTし、サーバーが再検証して登録する。 --}}
    <form id="piForm" method="POST" action="/content-import" enctype="multipart/form-data">
      <input type="hidden" name="_token" id="piToken">
      <div id="piDrop" class="pi-drop">
        ここにCSVをドラッグ＆ドロップ、または<br>
        <input type="file" name="csv" id="piFile" accept=".csv,text/csv" style="margin-top:8px;">
      </div>
    </form>

    <div id="piResult" style="display:none;">
      <div class="pi-summary">
        読み込み：<b id="piTotal">0</b> 行 ／ <span class="ok">OK <b id="piOk">0</b></span> ／ <span class="ng">エラー <b id="piNg">0</b></span>
      </div>
      <div class="pi-scroll">
        <table class="pi-table">
          <thead>
            <tr>
              <th>行</th><th>判定</th><th>コンテンツ名</th><th>分類</th>
              <th>体力系</th><th>紙が必要</th><th>1チーム枚数</th><th>利用中</th><th>理由</th>
            </tr>
          </thead>
          <tbody id="piBody"></tbody>
        </table>
      </div>
      <div class="pi-actions">
        <button type="button" class="btn primary" id="piImportBtn" onclick="piImport()">③ この内容で登録</button>
        <span id="piNote" style="font-size:12px; color:#8a7a66;"></span>
      </div>
    </div>
  </div>

</div>
@endsection

@push('scripts')
{{-- CSVの文字コード（UTF-8 / Excel保存のShift_JIS）を見分けて読む共通処理 --}}
<script src="/ecs/csv-read.js?v={{ \App\Support\Asset::ver('ecs/csv-read.js') }}"></script>
@verbatim
<script>
  // ===== コンテンツCSV取込：テンプレDL・プレビュー・検証（サーバーの基準と同じ）=====
  var PI_HEADERS = ['コンテンツ名', '分類', '体力系', '紙が必要', '1チーム枚数', '利用中'];
  // 「○/はい/1」などを「はい」として扱う語（サーバーの truthy と同じ）
  var PI_TRUE = ['○', '◯', '✓', '1', 'はい', '有', 'yes', 'true', 'on', '利用中'];

  function piTruthy(v) { return PI_TRUE.indexOf(String(v).trim().toLowerCase()) !== -1; }

  function piDownloadTemplate() {
    var rows = [
      PI_HEADERS,
      ['水合戦', '盛り上げ系', '○', '', '', '○'],
      ['謎解き脱出', '真面目系', '', '○', '1', '○'],
    ];
    var csv = rows.map(function (r) {
      return r.map(function (v) {
        v = (v == null) ? '' : String(v);
        return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
      }).join(',');
    }).join('\r\n');
    // BOMを付けてExcelの文字化けを防ぐ
    var blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'コンテンツ取込テンプレート.csv';
    a.click();
    URL.revokeObjectURL(a.href);
  }

  // 引用符対応の簡易CSVパーサ（1行）
  function piParseLine(line) {
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

  function piParse(text) {
    text = text.replace(/^﻿/, '');
    var lines = text.split(/\r\n|\r|\n/).filter(function (l) { return !(l.trim() === ''); });
    if (lines.length < 2) return { header: [], rows: [] };
    var header = piParseLine(lines.shift()).map(function (h) { return h.trim(); });
    var idx = {};
    header.forEach(function (h, i) { idx[h] = i; });
    var rows = lines.map(function (l) {
      var cells = piParseLine(l);
      var get = function (name) {
        var i = idx[name];
        return (i != null && cells[i] != null) ? String(cells[i]).trim() : '';
      };
      return {
        name: get('コンテンツ名'), category: get('分類'),
        physical: get('体力系'), paper: get('紙が必要'),
        sheets: get('1チーム枚数'), active: get('利用中')
      };
    });
    return { header: header, rows: rows };
  }

  function piValidate(r, seen) {
    var e = [];
    if (!r.name) e.push('コンテンツ名が空');
    else {
      var lo = r.name.toLowerCase();
      if (seen[lo]) e.push('コンテンツ名が重複'); else seen[lo] = true;
    }
    if (r.sheets && (!/^\d+$/.test(r.sheets) || +r.sheets < 1)) e.push('1チーム枚数は1以上の数字');
    return e;
  }

  function piRender(parsed) {
    var body = document.getElementById('piBody');
    body.innerHTML = '';
    var seen = {}, ok = 0, ng = 0;
    parsed.rows.forEach(function (r, i) {
      var errs = piValidate(r, seen);
      var good = errs.length === 0;
      good ? ok++ : ng++;
      var tr = document.createElement('tr');
      tr.className = good ? 'row-ok' : 'row-ng';
      function td(t, cls) { return '<td' + (cls ? ' class="' + cls + '"' : '') + '>' + (t == null ? '' : String(t).replace(/</g, '&lt;')) + '</td>'; }
      // ○表記は「○」に正規化して見やすく表示
      var phys = piTruthy(r.physical) ? '○' : '';
      var pap = piTruthy(r.paper) ? '○' : '';
      var act = (r.active === '' || piTruthy(r.active)) ? '○' : '';
      tr.innerHTML = td(i + 2) + td(good ? '✓ OK' : '✗ NG') + td(r.name) + td(r.category)
        + td(phys) + td(pap) + td(r.sheets) + td(act) + td(errs.join('／'), 'pi-reason');
      body.appendChild(tr);
    });
    document.getElementById('piTotal').textContent = parsed.rows.length;
    document.getElementById('piOk').textContent = ok;
    document.getElementById('piNg').textContent = ng;
    document.getElementById('piResult').style.display = '';
    var btn = document.getElementById('piImportBtn');
    var note = document.getElementById('piNote');
    if (ok === 0) {
      btn.disabled = true;
      note.textContent = '登録できる行がありません（すべてエラー）。';
    } else {
      btn.disabled = false;
      note.textContent = 'OKの ' + ok + ' 行だけが登録されます（エラー行は自動でスキップ）。';
    }
  }

  function piReadAndPreview(file) {
    if (!file) return;
    // 文字コードは ECS_readCsvFile が見分ける（UTF-8／Excel保存のShift_JIS どちらでも読める）。
    ECS_readCsvFile(file, function (text) { piRender(piParse(text)); });
  }

  function piImport() {
    var f = document.getElementById('piFile').files[0];
    if (!f) { alert('先にCSVファイルを選んでください。'); return; }
    if (!confirm('OKの行をコンテンツに登録します。よろしいですか？')) return;
    document.getElementById('piToken').value = window.ECS_CSRF || '';
    document.getElementById('piForm').submit();
  }

  // ファイル選択
  document.getElementById('piFile').addEventListener('change', function () {
    piReadAndPreview(this.files[0]);
  });

  // ドラッグ＆ドロップ
  (function () {
    var drop = document.getElementById('piDrop');
    var fileInput = document.getElementById('piFile');
    ['dragenter', 'dragover'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('drag'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('drag'); });
    });
    drop.addEventListener('drop', function (e) {
      var f = e.dataTransfer.files[0];
      if (!f) return;
      // 「取り込む」でも送れるよう、選択欄にも入れる
      try { var dt = new DataTransfer(); dt.items.add(f); fileInput.files = dt.files; } catch (x) {}
      piReadAndPreview(f);
    });
    // 枠外にドロップしてブラウザがファイルを開く事故を防ぐ
    window.addEventListener('dragover', function (e) { e.preventDefault(); });
    window.addEventListener('drop', function (e) { e.preventDefault(); });
  })();
</script>
@endverbatim
@endpush
