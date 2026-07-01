@extends('layouts.app')
@section('title', '案件のCSV一括取込')
@section('h1', '案件の登録 / 編集')
@php($active = 'project_form')

@push('head')
{{-- フォーム送信用のCSRFトークン。verbatimブロックの外なのでBladeが展開する。doImport から使う。 --}}
<script>window.ECS_CSRF = "{{ csrf_token() }}";</script>
@verbatim
<style>
    /* CSV取込モック専用スタイル */

    /* 切替タブ（1件ずつ / CSV取込） */
    .mode-tabs { display: flex; gap: 6px; margin-bottom: 20px; }
    .mode-tabs a {
      padding: 9px 18px; border: 1px solid var(--line); border-radius: 8px;
      background: #fff; color: var(--muted); font-size: 13.5px; font-weight: 600;
    }
    .mode-tabs a:hover { background: #f3ece0; text-decoration: none; }
    .mode-tabs a.active { background: var(--brand); border-color: var(--brand); color: #fff; }

    /* 手順ステップ */
    .steps { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 4px; }
    .step { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--muted); }
    .step .n {
      width: 22px; height: 22px; border-radius: 999px; background: var(--brand-soft);
      color: var(--brand-dark); font-weight: 700; display: inline-flex;
      align-items: center; justify-content: center; font-size: 12px;
    }
    .step .arrow { color: #cbb89c; }

    .sec-title {
      font-size: 13px; font-weight: 700; color: var(--brand-dark);
      margin: 4px 0 12px; padding-bottom: 6px; border-bottom: 2px solid var(--brand-soft);
    }

    /* 列の説明 */
    .cols-help { font-size: 12.5px; color: var(--muted); line-height: 1.9; }
    .cols-help code {
      background: #f3ece0; border-radius: 4px; padding: 1px 6px; font-size: 12px; color: #6e5b49;
    }

    /* ドロップゾーン */
    .dropzone {
      border: 2px dashed #d8c9b0; border-radius: 12px; background: #faf6ee;
      padding: 30px 20px; text-align: center; color: var(--muted);
    }
    .dropzone .big { font-size: 28px; }
    .dropzone .file-name { margin-top: 8px; color: var(--ink); font-weight: 600; }

    /* プレビューのサマリ */
    .summary { display: flex; gap: 22px; flex-wrap: wrap; margin-bottom: 14px; }
    .summary .s-item { font-size: 13.5px; }
    .summary .s-item b { font-size: 20px; }
    .summary .ok b { color: var(--ok); }
    .summary .err b { color: var(--danger); }

    /* プレビュー表の行状態 */
    tr.row-ok td { background: #fff; }
    tr.row-err td { background: var(--danger-soft); }
    .res-ok { color: var(--ok); font-weight: 600; }
    .res-err { color: var(--danger); font-weight: 600; }
    .err-detail { font-size: 12px; color: #b91c1c; }

    #previewArea { display: none; }

    /* M-7 危険日（高負荷日）の警告バナー */
    .danger-box { display: none; margin: 0 0 14px; padding: 12px 14px; border-radius: 10px;
      background: #fde8e8; border: 1px solid var(--danger); color: #b91c1c; font-size: 13px; }
    .danger-box h4 { margin: 0 0 6px; font-size: 13.5px; }
    .danger-box ul { margin: 0; padding-left: 20px; line-height: 1.7; }
    .danger-box .d-date { font-weight: 700; }
</style>
@endverbatim
@endpush

@section('content')
@if (session('import_error'))
      <div class="alert danger" style="margin-bottom:16px;"><span class="ico">⚠</span><div>{{ session('import_error') }}</div></div>
@endif
@verbatim
      <!-- 1件ずつ / CSV取込 の切替タブ -->
      <div class="mode-tabs">
        <a href="/project-form">✎ 1件ずつ入力</a>
        <a class="active" href="/project-import">⬆ CSVで一括取込</a>
      </div>

      <div class="mock-note">CSVを選ぶと中身を読み込んで内容チェックを表示します。「この内容で取り込む」を押すと、OKの行だけが実際に案件として登録されます（エラー行は登録されません）。「サンプルで試す」は動きの確認用で、登録はされません。</div>

      <!-- 手順 -->
      <div class="panel">
        <div class="steps">
          <div class="step"><span class="n">1</span> テンプレDL</div>
          <span class="arrow">→</span>
          <div class="step"><span class="n">2</span> 記入</div>
          <span class="arrow">→</span>
          <div class="step"><span class="n">3</span> アップロード</div>
          <span class="arrow">→</span>
          <div class="step"><span class="n">4</span> 内容を確認</div>
          <span class="arrow">→</span>
          <div class="step"><span class="n">5</span> 取り込む</div>
        </div>
      </div>

      <!-- STEP1：テンプレDL -->
      <div class="panel">
        <div class="sec-title">STEP 1　テンプレートをダウンロード</div>
        <p style="margin:0 0 14px;">まず空のテンプレート（CSV）をダウンロードし、Excelなどで案件を1行ずつ記入してください。</p>
        <button class="btn primary" onclick="downloadTemplate()">⬇ 案件取込テンプレート.csv をダウンロード</button>
        <div class="cols-help" style="margin-top:16px;">
          <strong>列の説明（1件＝1行。1件ずつ入力フォームと同じ項目です）：</strong><br>
          <u>基本情報</u>　<code>区分</code>（通常案件/追加案件）　<code>toC</code>（一般のお客様向けなら「toC」、企業向けは空欄）　<code>確度</code>（確定/Aヨミ/Bヨミ/Cヨミ）　<code>開催日</code>（必須・2026-07-20 の形式）　<code>宿泊</code>（無/前泊有 等）　<code>案件名</code>（必須・コンテンツ名）　<code>案件規模</code>（大型/中型/小型/該当なし）　<code>営業担当</code><br>
          <u>形態・取引先</u>　<code>実施形態</code>（イベント東(リアル) 等）　<code>配信種別</code>（なし/配信/中継）　<code>クライアント</code>　<code>代理店名</code>（任意）　<code>複数案件</code>（あり/なし）　<code>日程種別</code>（本番/予備日/リハ日）　<code>紐づく本番案件</code>　<code>運営場所</code>（現地/配信室 等）<br>
          <u>当日・手配</u>　<code>担当体制</code>　<code>集合時間</code>（08:00）　<code>解散時間</code>　<code>イベント入場</code>　<code>イベント開始</code>　<code>イベント終了</code>　<code>運営人数</code>（必須・数字）　<code>お客様人数</code>　<code>チーム数</code>　<code>リピート</code>（あり/なし）　<code>音響機材</code><br>
          <u>備品・会場</u>　<code>ロゴ</code> <code>カメラ</code> <code>事例記事</code> <code>動画</code>（不要/ほしい/OK/NG/-）　<code>会場住所</code>　<code>屋内外</code>（屋内/屋外）　<code>集合形式</code>（会場現地 等）　<code>お酒</code>（あり/なし）　<code>ケータリング</code>　<code>移動車両</code>　<code>スタッフ募集</code>（募集する/募集しない）<br>
          <u>運営（アサイン後に記入。空欄でOK）</u>　<code>ディレクター</code>（現場のD担当）　<code>物品担当</code>（備品準備）　<code>運営シートURL</code>（スプレッドシートのリンク）　<code>準備:LINE概要送付</code> <code>準備:引き継ぎ</code> <code>準備:台本</code>（済/未）　<code>備考</code><br>
          <span class="muted">※ 「運営」グループ（ディレクター・物品担当・運営シート・準備チェック）は登録後にアサイン画面/案件一覧で入力する項目です。CSVでは空欄のままでかまいません。<br>
          ※ 現場種別（アサインの考え方）は記入不要です。屋内外・人数などから自動で設定されます。<br>
          ※ <b>ARENA場所貸し</b>の案件は専用項目が多いため、CSVではなく「1件ずつ入力」での登録をおすすめします（IKUSA対応分は備考に記載でもOK）。</span>
        </div>
      </div>

      <!-- STEP2-5：アップロード〜取込（実際に登録するフォーム） -->
      <form id="importForm" method="POST" action="/project-import" enctype="multipart/form-data">
        <input type="hidden" name="_token" id="csrfField">

      <!-- STEP2-3：アップロード -->
      <div class="panel">
        <div class="sec-title">STEP 2-3　記入したCSVをアップロード</div>
        <div class="dropzone">
          <div class="big">📄</div>
          <div>ここにCSVファイルをドラッグ＆ドロップ、または</div>
          <div style="margin-top:10px;">
            <label class="btn">
              ファイルを選択
              <input type="file" name="csv" id="csvFile" accept=".csv" style="display:none;" onchange="onFilePicked(this)">
            </label>
          </div>
          <div class="file-name" id="fileName"></div>
        </div>
        <p class="muted" style="font-size:12.5px; margin:12px 0 0;">
          動きを確認したい場合は
          <a onclick="loadSample()" style="cursor:pointer;">サンプルデータで試す</a>
          を押すと、見本の取込内容が下に表示されます。
        </p>
      </div>

      <!-- STEP4：プレビュー＆チェック -->
      <div class="panel" id="previewArea">
        <div class="sec-title">STEP 4　取り込む内容の確認（自動チェック済み）</div>
        <div class="summary">
          <div class="s-item">読み込んだ行：<b id="cTotal">0</b> 行</div>
          <div class="s-item ok">取込できる：<b id="cOk">0</b> 件</div>
          <div class="s-item err">エラー：<b id="cErr">0</b> 件</div>
        </div>

        <!-- M-7 危険日（高負荷日）の警告 -->
        <div class="danger-box" id="dangerBox">
          <h4>⚠ 危険日（高負荷日）が見つかりました</h4>
          <ul id="dangerList"></ul>
          <p style="margin:8px 0 0; font-weight:700;">👉 アサイン担当に確認はしましたか？</p>
        </div>

        <table class="tbl">
          <thead>
            <tr>
              <th class="num">#</th>
              <th>案件名</th>
              <th>開催日</th>
              <th class="num">運営人数</th>
              <th>日程種別</th>
              <th>チェック結果</th>
            </tr>
          </thead>
          <tbody id="previewBody"></tbody>
        </table>

        <p class="muted" style="font-size:12px; margin:12px 0 0;">
          ※ エラーの行は取り込まれません。CSVを直して入れ直すか、エラーを除いて取り込めます。
        </p>

        <!-- STEP5：取込 -->
        <div style="display:flex; gap:12px; margin-top:18px; align-items:center;">
          <a class="btn" href="/projects">キャンセル</a>
          <div style="flex:1;"></div>
          <button class="btn primary" type="button" id="importBtn" onclick="doImport()">この内容で取り込む</button>
        </div>
      </div>
      </form>
@endverbatim
@endsection

@push('scripts')
<script src="/ecs/data/cases.js"></script>
@verbatim
<script>
  // ===== STEP1：テンプレートCSVのダウンロード（実際に動きます）=====
  function downloadTemplate() {
    const header = ['区分','toC','確度','開催日','宿泊','案件名','案件規模','営業担当','実施形態','配信種別','クライアント','代理店名','複数案件','日程種別','紐づく本番案件','運営場所','担当体制','集合時間','解散時間','イベント入場','イベント開始','イベント終了','運営人数','お客様人数','チーム数','リピート','音響機材','ロゴ','カメラ','事例記事','動画','会場住所','屋内外','集合形式','お酒','ケータリング','移動車両','スタッフ募集','ディレクター','物品担当','運営シートURL','準備:LINE概要送付','準備:引き継ぎ','準備:台本','備考'];
    const example = ['通常案件','','確定','2026-07-20','無','水合戦','小型','baba','イベント東(リアル)','なし','〇〇株式会社','','なし','本番','','現地','イベプラD＋スタッフ','08:00','17:00','09:30','10:00','16:00','16','120','8','なし','会場音響','-','-','-','-','東京都江東区夢の島2-1-3 〇〇公園','屋外','会場現地','なし','無','IKUSAカー','募集する','田中','鈴木','','未','未','未','着替え・タオル持参'];
    const csv = header.join(',') + '\n' + example.join(',') + '\n';
    const bom = '﻿'; // Excelで文字化けしないようBOMを付与
    const blob = new Blob([bom + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = '案件取込テンプレート.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  // ===== CSV解析（1行＝1案件。引用符・カンマ・BOMに対応）=====
  function parseCsvLine(line) {
    const out = []; let cur = ''; let q = false;
    for (let i = 0; i < line.length; i++) {
      const c = line[i];
      if (q) {
        if (c === '"') { if (line[i + 1] === '"') { cur += '"'; i++; } else q = false; }
        else cur += c;
      } else {
        if (c === '"') q = true;
        else if (c === ',') { out.push(cur); cur = ''; }
        else cur += c;
      }
    }
    out.push(cur);
    return out.map(s => s.trim());
  }

  // CSVテキスト → 行オブジェクトの配列（プレビュー用に必要な列だけ取り出す）
  function parseCsvToRows(text) {
    text = text.replace(/^﻿/, '');                          // 先頭BOMを除去
    const lines = text.split(/\r\n|\r|\n/).filter(l => l.trim() !== '');
    if (lines.length < 2) return [];
    const header = parseCsvLine(lines[0]);
    const idx = {}; header.forEach((h, i) => { idx[h.trim()] = i; });
    const g = (row, name) => { const i = idx[name]; return (i != null && row[i] != null) ? row[i] : ''; };
    return lines.slice(1).map(parseCsvLine).map(row => ({
      name:  g(row, '案件名'),
      date:  g(row, '開催日'),
      count: g(row, '運営人数'),
      scale: g(row, '案件規模'),
      // 危険日判定は実施形態コード(real/long/online)で見るので、文字列から変換（cases.jsの共通関数）
      fmt:   (window.ECS_fmtCode ? window.ECS_fmtCode(g(row, '実施形態')) : 'real'),
      need:  g(row, '運営人数'),
      kbn:   g(row, '日程種別') || '本番',
    }));
  }

  // ===== STEP2-3：ファイル選択（実際に中身を読んでプレビュー）=====
  function onFilePicked(input) {
    if (!(input.files && input.files[0])) return;
    const file = input.files[0];
    document.getElementById('fileName').textContent = '選択：' + file.name;
    const reader = new FileReader();
    reader.onload = function (e) {
      const rows = parseCsvToRows(e.target.result);
      if (!rows.length) {
        alert('CSVにデータ行が見つかりませんでした。見出し行＋データ行があるCSVを選んでください。');
        return;
      }
      renderRows(rows, true);  // true＝実ファイル（取込可能）
    };
    reader.readAsText(file);    // テンプレートはUTF-8(BOM付き)。既定のUTF-8で読む。
  }

  // ===== サンプルの取込内容（わざとエラー行を混ぜています）=====
  // scale=案件規模 / fmt=実施形態コード(real,long,online)＝危険日の判定に使います
  const sample = [
    { name:'〇〇社 水合戦',      date:'2026-07-20', count:'16', scale:'大型', fmt:'long',   outdoor:'屋外', load:'高', repeat:'なし', skills:'リーダー可', kbn:'本番' },
    { name:'●●社 大運動会',      date:'2026-07-20', count:'18', scale:'大型', fmt:'real',   outdoor:'屋外', load:'高', repeat:'なし', skills:'リーダー可', kbn:'本番' }, // 同じ7/20に大型がもう1件＝危険日
    { name:'△△大学 新歓イベント',date:'2026-07-22', count:'20', scale:'大型', fmt:'online', outdoor:'屋内', load:'中', repeat:'なし', skills:'リーダー可', kbn:'本番' },
    { name:'□□商店街 縁日',      date:'2026-07-25', count:'6',  scale:'中型', fmt:'real',   outdoor:'屋内', load:'低', repeat:'あり', skills:'',         kbn:'本番' },
    { name:'◇◇社 懇親会運営',    date:'2026-07-27', count:'8',  scale:'中型', fmt:'real',   outdoor:'屋内', load:'低', repeat:'なし', skills:'',         kbn:'本番' },
    { name:'',                   date:'2026-07-28', count:'10', scale:'中型', fmt:'real',   outdoor:'屋内', load:'中', repeat:'なし', skills:'',         kbn:'本番' }, // 案件名なし
    { name:'××株式会社 表彰式',  date:'2026/13/40', count:'10', scale:'中型', fmt:'real',   outdoor:'屋内', load:'低', repeat:'なし', skills:'',         kbn:'本番' }, // 日付不正
    { name:'●●社 運動会',        date:'2026-07-18', count:'',   scale:'大型', fmt:'real',   outdoor:'屋外', load:'高', repeat:'なし', skills:'',         kbn:'本番' }, // 人数なし
    { name:'◎◎フェス リハ',      date:'2026-07-13', count:'4',  scale:'中型', fmt:'real',   outdoor:'屋外', load:'高', repeat:'なし', skills:'',         kbn:'リハ日' },
  ];

  // 1行のチェック（必須・形式）
  function validate(r) {
    const errs = [];
    if (!r.name || !r.name.trim()) errs.push('案件名が空です');
    if (!/^\d{4}-\d{2}-\d{2}$/.test(r.date)) errs.push('開催日の形式が不正です（例 2026-07-20）');
    else { const d = new Date(r.date); if (isNaN(d.getTime())) errs.push('開催日が存在しない日付です'); }
    if (!r.count || isNaN(parseInt(r.count)) || parseInt(r.count) < 1) errs.push('運営人数が空または不正です');
    return errs;
  }

  // プレビュー描画（サンプルと実ファイルで共用）。isReal=true のときだけ「取り込む」を有効化。
  let previewIsReal = false;
  function renderRows(rows, isReal) {
    previewIsReal = !!isReal;
    const tbody = document.getElementById('previewBody');
    tbody.innerHTML = '';
    let ok = 0, err = 0;
    rows.forEach((r, i) => {
      const errs = validate(r);
      const isOk = errs.length === 0;
      isOk ? ok++ : err++;
      const tr = document.createElement('tr');
      tr.className = isOk ? 'row-ok' : 'row-err';
      tr.innerHTML = `
        <td class="num">${i + 1}</td>
        <td>${r.name ? '<strong>' + r.name + '</strong>' : '<span class="err-detail">（空）</span>'}</td>
        <td class="nowrap">${r.date}</td>
        <td class="num">${r.count || '<span class="err-detail">（空）</span>'}</td>
        <td>${r.kbn}</td>
        <td>${isOk
          ? '<span class="res-ok">✓ OK</span>'
          : '<span class="res-err">✕ エラー</span><br><span class="err-detail">' + errs.join('／') + '</span>'}</td>`;
      tbody.appendChild(tr);
    });
    document.getElementById('cTotal').textContent = rows.length;
    document.getElementById('cOk').textContent = ok;
    document.getElementById('cErr').textContent = err;

    const btn = document.getElementById('importBtn');
    if (!isReal) {
      btn.textContent = 'サンプルは登録できません（確認用）';
      btn.disabled = true;
    } else if (err > 0) {
      btn.textContent = `エラー${err}件を除いて ${ok}件を取り込む`;
      btn.disabled = ok === 0;
    } else {
      btn.textContent = `${ok}件すべてを取り込む`;
      btn.disabled = false;
    }
    // M-7 危険日（高負荷日）の検出＝エラーでないOK行だけを対象にする
    detectDangerDays(rows.filter(r => validate(r).length === 0));

    document.getElementById('previewArea').style.display = 'block';
    document.getElementById('previewArea').scrollIntoView({ behavior: 'smooth' });
  }

  // 「サンプルで試す」＝見本データで動きを確認（登録はしない）。実ファイルの選択は解除する。
  function loadSample() {
    document.getElementById('csvFile').value = '';
    document.getElementById('fileName').textContent = '';
    renderRows(sample, false);
  }

  // ===== M-7 取り込む行＋既存案件から危険日を見つけてバナー表示 =====
  let dangerDays = []; // doImport の確認メッセージでも使う
  function detectDangerDays(okRows) {
    // 日付ごとに「取り込む行」をまとめる
    const byDate = {};
    okRows.forEach(r => {
      (byDate[r.date] = byDate[r.date] || []).push({ scale: r.scale, fmt: r.fmt, need: r.count, name: r.name });
    });
    dangerDays = [];
    Object.keys(byDate).forEach(iso => {
      // 取り込む行＋すでに登録済みの同日案件（cases.js）を合わせて判定
      const items = byDate[iso].concat(ECS_casesOnDate(iso));
      const res = ECS_dangerCheck(items);
      if (res.danger) dangerDays.push({ iso, res });
    });
    dangerDays.sort((a, b) => a.iso < b.iso ? -1 : 1);

    const box = document.getElementById('dangerBox');
    const list = document.getElementById('dangerList');
    if (dangerDays.length === 0) { box.style.display = 'none'; list.innerHTML = ''; return; }
    list.innerHTML = dangerDays.map(d =>
      '<li><span class="d-date">' + d.iso + '</span>（' + d.res.count + '件・必要スタッフ数 合計' + d.res.needSum + '名）<br>'
      + d.res.reasons.join('／') + '</li>'
    ).join('');
    box.style.display = 'block';
  }

  // ===== STEP5：取込（実際にサーバへ送信して登録）=====
  function doImport() {
    const fileInput = document.getElementById('csvFile');
    // サンプル表示だけ／ファイル未選択のときは登録しない
    if (!previewIsReal || !(fileInput.files && fileInput.files[0])) {
      alert('「サンプルで試す」は動きの確認用で、登録はされません。\n実際に登録するには、記入したCSVファイルを選んでください。');
      return;
    }
    const ok = parseInt(document.getElementById('cOk').textContent, 10) || 0;
    const err = parseInt(document.getElementById('cErr').textContent, 10) || 0;
    if (ok < 1) {
      alert('取り込めるOKの行がありません。CSVを直して入れ直してください。');
      return;
    }
    // M-7 危険日があれば先に注意喚起（取り込み自体は止めない）
    if (dangerDays.length > 0) {
      const dmsg = '⚠ 危険日（高負荷日）が ' + dangerDays.length + '日 あります。\n\n'
        + dangerDays.map(d => '・' + d.iso + '：' + d.res.reasons.join('／')).join('\n')
        + '\n\nスタッフの手が足りなくなる恐れがあります。\n'
        + '👉 アサイン担当に確認はしましたか？\n\n'
        + 'このまま取り込みますか？';
      if (!confirm(dmsg)) return;
    }
    let msg = `${ok}件の案件を取り込みます。`;
    if (err > 0) msg += `\nエラー${err}件は取り込まれません。`;
    msg += '\n（OKの行だけが実際に登録されます）';
    if (!confirm(msg)) return;

    // サーバへ送信（サーバ側でもう一度チェックし、OK行だけ登録）。
    document.getElementById('csrfField').value = window.ECS_CSRF || '';
    document.getElementById('importForm').submit();
  }
</script>
@endverbatim
@endpush
