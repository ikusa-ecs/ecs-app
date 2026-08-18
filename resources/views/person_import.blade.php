@extends('layouts.app')
@section('title', '名簿CSV取込')
@section('h1', '名簿CSV取込（社員・スタッフ）')
@php $active = 'person_import'; @endphp

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
      <li>1行に1人ずつ記入して保存（文字コードはそのままでOK＝BOM付きUTF-8）</li>
      <li>「② ファイルを選ぶ」で読み込み、下のプレビューで内容とエラーを確認</li>
      <li>問題なければ「③ この内容で登録」。<b>エラー行は登録されず、OK行だけ登録されます</b></li>
    </ol>
    <div class="pi-cols">
      <b>列：</b>
      種別（社員／スタッフ）※必須 ／ 氏名 ※必須 ／ メール（重複不可） ／ 事務所（東京/大阪/名古屋/福岡/東北/北海道） ／
      所属（社員のみ：イベプラ/セールス/クリエイティブ） ／ 入社日（例 2025-04-01） ／ 通算経験回数（スタッフのみ・数字） ／
      できるポジション（任意・スタッフのみ）<br>
      <b>できるポジションの書き方：</b> できる役割を「OP,MC,軍師」のように区切って書きます（区切りはカンマ／スラッシュ／読点のどれでもOK）。
      使える言葉＝D／OP（音響）／MC（司会進行）／FC（巡回ファシリ）／CK（チェッカー）／軍師（＝サポーター）／受付。コードでも日本語でも書けます。空欄なら何も登録しません。<br>
      <b>自動で入る：</b> 社員番号（E-/S-を自動採番）・権限（社員/スタッフを種別から）・在籍（有効）。パスワードは入れません（アカウント発行は別作業）。
    </div>
  </div>

  <div class="pi-card">
    <h2>① テンプレート</h2>
    <button type="button" class="btn ghost" onclick="piDownloadTemplate()">⬇ テンプレートをダウンロード</button>
  </div>

  <div class="pi-card">
    <h2>② ファイルを選ぶ</h2>
    {{-- 実登録はこのフォームでCSVファイルそのものをPOSTし、サーバーが再検証して登録する。 --}}
    <form id="piForm" method="POST" action="/person-import" enctype="multipart/form-data">
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
              <th>行</th><th>判定</th><th>種別</th><th>氏名</th><th>メール</th>
              <th>事務所</th><th>所属</th><th>入社日</th><th>通算</th><th>できるポジション</th><th>理由</th>
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
  // ===== 名簿CSV取込：テンプレDL・プレビュー・検証（サーバーの基準と同じ）=====
  var PI_HEADERS = ['種別', '氏名', 'メール', '事務所', '所属', '入社日', '通算経験回数', 'できるポジション'];

  function piDownloadTemplate() {
    var rows = [
      PI_HEADERS,
      ['スタッフ', '山田花子', 'hanako@example.com', '東京', '', '2025-04-01', '12', 'OP,MC,軍師'],
      ['社員', '鈴木一郎', 'ichiro@example.com', '大阪', 'イベプラ', '2020-04-01', '', ''],
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
    a.download = '名簿取込テンプレート.csv';
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
    var lines = text.split(/\r\n|\r|\n/).filter(function (l, idx) { return !(l.trim() === ''); });
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
        type: get('種別'), name: get('氏名'), email: get('メール'),
        office: get('事務所'), dept: get('所属'), hire: get('入社日'), expc: get('通算経験回数'),
        pos: get('できるポジション') || get('できる役割') || get('ポジション')
      };
    });
    return { header: header, rows: rows };
  }

  function piIsRealDate(s) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return false;
    var p = s.split('-'), y = +p[0], m = +p[1], d = +p[2];
    var dt = new Date(y, m - 1, d);
    return dt.getFullYear() === y && dt.getMonth() === m - 1 && dt.getDate() === d;
  }

  function piValidate(r, seen) {
    var e = [];
    if (r.type !== '社員' && r.type !== 'スタッフ') e.push('種別は社員/スタッフ');
    if (!r.name) e.push('氏名が空');
    if (r.email) {
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(r.email)) e.push('メール形式が不正');
      else {
        var lo = r.email.toLowerCase();
        if (seen[lo]) e.push('メール重複'); else seen[lo] = true;
      }
    }
    if (r.hire && !piIsRealDate(r.hire)) e.push('入社日が不正(例 2025-04-01)');
    if (r.expc && !/^\d+$/.test(r.expc)) e.push('通算は数字');
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
      tr.innerHTML = td(i + 2) + td(good ? '✓ OK' : '✗ NG') + td(r.type) + td(r.name) + td(r.email)
        + td(r.office) + td(r.dept) + td(r.hire) + td(r.expc) + td(r.pos) + td(errs.join('／'), 'pi-reason');
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
    if (!confirm('OKの行を名簿に登録します。よろしいですか？')) return;
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
