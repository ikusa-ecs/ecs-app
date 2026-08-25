@extends('layouts.app')
@section('title', '過去案件の取込')
@section('h1', '過去案件の取込（アサイン込み）')
@php($active = 'past_import')

@push('head')
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
      アサイン表のシートを <b>CSVで保存してそのままアップロード</b>してください。
      <b>列を並べ替える必要はありません。</b>Excelでそのまま保存した（Shift_JISの）CSVでも読めます。
    </p>
    <ul class="pj-lead" style="padding-left:1.2em;">
      <li><b>アサイン表の2つの形、どちらでも入ります（自動で見分けます）。</b>
        <ul style="padding-left:1.2em;">
          <li><b>1行に1案件のシート</b>（例：<code>202601_list</code>）… D・MC・OP・スタッフの列から人を読みます。</li>
          <li><b>月ごとのシート</b>（例：<code>202701</code>）… 1案件が横1ブロックに並んでいる、いつものアサイン表です。
            名前の横のポジション（D／MC／OP／FC など）をそのまま読みます。<br>
            <span class="muted">※ このシートは日程に年が書かれていないため、<b>ファイル名の「202701」から年を読みます</b>。
              スプレッドシートの「ファイル → ダウンロード → カンマ区切り形式」で落としたファイル名のままお使いください。</span></li>
        </ul>
      </li>
      <li><b>アサイン表に名前のある人は、アサインも「確定」で入ります。</b>
        （ふつうの案件取込は、その時点でDが決まっていないので取り込みません）</li>
      <li>案件は<b>「確定」・スタッフに公開済み</b>で入ります＝本人が自分の過去の実績として見られます。<b>募集はしません</b>。</li>
      <li><b>同じ案件は上書き</b>します。同じかどうかは<b>「日程・コンテンツ・顧客名・集合時間」が全部同じか</b>で見ます。
        1つでも違えば別案件として新しく作ります（同じ日・同じコンテンツでも顧客が違えば別案件）。<br>
        <span class="muted">＝失敗しても、直してもう一度入れれば大丈夫です（案件が二重に増えません）。</span></li>
    </ul>
    <p class="pj-lead">
      <b>人の書き方</b>：氏名で照合します。<b>氏名の空白の有無は気にしなくて大丈夫</b>です。
      1行1案件のシートでは、複数いるときは<b>カンマ区切り</b>（例：<code>田中 健一, 鈴木彩</code>）。「スタッフ」列の人は<b>FC（巡回ファシリ）</b>として入ります。<br>
      <b>名簿に無い人・同姓同名の人は、取り違えを防ぐため入れません</b>（誰が入らなかったかは画面に出ます）。<br>
      <b>日程</b>は、1行1案件のシートでは<b>年から</b>入れてください（<code>2026/1/20</code>・<code>2026-01-20</code>・Excelの日付セルどれでもOK）。
      月ごとのシートは「<code>9月1日(火)</code>」のままでOKです（年はファイル名から補います）。<br>
      ECSに対応する項目が無い列（拘束・顧客担当名・シート期日 など）は無視し、取り込み後に「取り込まなかった列」として表示します。
    </p>
  </div>

  <div class="pj-card">
    <h2>① CSVを選ぶ</h2>
    {{-- 実登録はこのフォームでCSVファイルそのものをPOSTし、サーバーが読み直して登録する。 --}}
    <form id="pjForm" method="POST" action="/past-import" enctype="multipart/form-data">
      @csrf
      {{-- ⚠ どの拠点の案件として入れるか。他拠点のアサイン表を代わりに取り込むことがあるため、
           取り込んだ人の拠点で決め打ちにしない（2026-08-25 baba）。 --}}
      <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
        <label for="pjOffice" style="font-size:13px;"><b>どの拠点の案件として入れますか</b></label>
        <select name="office" id="pjOffice"
                style="padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:14px;">
          @foreach ($offices as $o)
            <option value="{{ $o }}" @selected($o === $myOffice)>{{ $o }}</option>
          @endforeach
        </select>
        <span class="muted" style="font-size:11.5px;">
          ※ 東北のアサイン表を東京の方が取り込むときは、ここを<b>東北</b>にしてください。
          ここが違うと、その拠点の案件一覧に出てきません。
        </span>
      </div>
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
      読み込み：<b id="pjTotal">0</b> 件 ／ <span class="ok">OK <b id="pjOk">0</b></span> ／ <span class="ng">エラー <b id="pjNg">0</b></span>
      ／ アサインに入る人：<b id="pjPeople">0</b> 名
    </div>
    <div id="pjWarn"></div>
    <div class="pj-scroll">
      <table class="pj-table">
        <thead>
          <tr>
            <th>件</th><th>判定</th><th>日程</th><th>コンテンツ</th><th>顧客名</th>
            <th>運営人数</th><th>入る人</th><th>理由・注意</th>
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
@verbatim
<script>
  // ===== 過去案件の取込：ファイルを選んだらその場で中身を見せる =====
  // ⚠ 読み取り（列の読み替え・月シートの読み方・名簿との突き合わせ）は
  //   すべてサーバー（PastProjectImportController::preview）にやらせている。
  //   画面にも同じ読み取りを書くと、片方だけ直して食い違う事故が起きるため（2026-08-25）。
  //   ここがやるのは「送る」と「返ってきたものを表に並べる」だけ。

  function pjEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }

  function pjClear() {
    document.getElementById('pjTotal').textContent = '0';
    document.getElementById('pjOk').textContent = '0';
    document.getElementById('pjNg').textContent = '0';
    document.getElementById('pjPeople').textContent = '0';
    document.getElementById('pjBody').innerHTML = '';
  }

  function pjShowError(message) {
    document.getElementById('pjResult').style.display = '';
    document.getElementById('pjWarn').innerHTML = '<div class="pj-flash err">' + pjEsc(message) + '</div>';
    pjClear();
    document.getElementById('pjBtn').disabled = true;
  }

  function pjRender(data) {
    if (!data || !data.ok) {
      pjShowError((data && data.message) || 'CSVを読み込めませんでした。');
      return;
    }

    var rows = data.rows || [];
    var okCount = 0, ngCount = 0, peopleCount = 0;
    rows.forEach(function (r) {
      if (r.errors && r.errors.length) { ngCount++; } else { okCount++; peopleCount += (r.people || 0); }
    });

    document.getElementById('pjTotal').textContent = rows.length;
    document.getElementById('pjOk').textContent = okCount;
    document.getElementById('pjNg').textContent = ngCount;
    document.getElementById('pjPeople').textContent = peopleCount;

    var w = '';
    if (data.isMonthly) {
      w += '<div class="pj-flash">月ごとのアサイン表（1案件＝横1ブロック）として読みました。'
        + '日程の年は、ファイル名の「202701」のような数字から補っています。</div>';
    }
    if (rows.length === 0) {
      w += '<div class="pj-flash err">取り込める案件が見つかりませんでした。'
        + 'アサイン表のシートをそのまま「カンマ区切り形式」で保存したCSVか確認してください。</div>';
    }
    if (data.missing && data.missing.length) {
      w += '<div class="pj-flash warn"><b>名簿に無い人：</b>' + pjEsc(data.missing.join('・'))
        + '<br>この方のアサインは入りません。名簿に登録してから、同じCSVをもう一度入れてください（案件は上書きされ二重になりません）。</div>';
    }
    if (data.ambiguous && data.ambiguous.length) {
      w += '<div class="pj-flash warn"><b>同姓同名で決められない人：</b>' + pjEsc(data.ambiguous.join('・'))
        + '<br>取り違えを防ぐため入れません。名簿でどちらかの氏名を直してください。</div>';
    }
    if (data.unknownRoles && data.unknownRoles.length) {
      w += '<div class="pj-flash warn"><b>知らないポジションの書き方：</b>' + pjEsc(data.unknownRoles.join('・'))
        + '<br>勝手に決めずに入れていません。この書き方も使うなら教えてください（対応を足します）。</div>';
    }
    if (data.unmapped && data.unmapped.length) {
      w += '<div class="pj-flash">取り込まなかった項目：' + pjEsc(data.unmapped.join('・'))
        + '<br>ECSに入れる場所がまだ無い項目です（案件は入ります）。</div>';
    }
    document.getElementById('pjWarn').innerHTML = w;

    document.getElementById('pjBody').innerHTML = rows.map(function (r) {
      var ok = !(r.errors && r.errors.length);
      var notes = (r.errors || []).slice();
      if (r.missing && r.missing.length) { notes.push('名簿に無い：' + r.missing.join('・')); }
      if (r.ambiguous && r.ambiguous.length) { notes.push('同姓同名：' + r.ambiguous.join('・')); }
      return '<tr class="' + (ok ? 'row-ok' : 'row-ng') + '">'
        + '<td>' + pjEsc(r.label) + '</td>'
        + '<td>' + (ok ? 'OK' : 'エラー') + '</td>'
        + '<td>' + pjEsc(r.date) + '</td>'
        + '<td>' + pjEsc(r.name) + '</td>'
        + '<td>' + pjEsc(r.client) + '</td>'
        + '<td>' + pjEsc(r.count) + '</td>'
        + '<td>' + pjEsc(r.people) + ' 名</td>'
        + '<td class="' + (ok ? 'pj-miss' : 'pj-reason') + '">' + pjEsc(notes.join(' / ')) + '</td>'
        + '</tr>';
    }).join('');

    var result = document.getElementById('pjResult');
    result.style.display = '';
    document.getElementById('pjBtn').disabled = (okCount === 0);
    result.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function pjReadAndPreview(file) {
    if (!file) return;
    document.getElementById('pjFileName').textContent = '選んだファイル：' + file.name;
    document.getElementById('pjResult').style.display = '';
    document.getElementById('pjWarn').innerHTML = '<div class="pj-flash">読み込んでいます…</div>';
    pjClear();
    document.getElementById('pjBtn').disabled = true;

    var form = document.getElementById('pjForm');
    var token = form.querySelector('input[name="_token"]').value;
    var fd = new FormData();
    fd.append('csv', file);
    fd.append('_token', token);

    fetch('/past-import/preview', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token }
    }).then(function (res) {
      if (!res.ok) { throw new Error(String(res.status)); }
      return res.json();
    }).then(pjRender).catch(function () {
      pjShowError('CSVを読み込めませんでした。CSV（カンマ区切り）のファイルか確認してください。'
        + '直らないときは、いったんこの画面を開き直してからもう一度お試しください。');
    });
  }

  function pjSubmit() {
    var f = document.getElementById('pjFile').files[0];
    if (!f) { alert('先にCSVファイルを選んでください。'); return; }
    var office = document.getElementById('pjOffice').value;
    var msg = ['過去案件を「' + office + '」の案件として取り込みます。',
               '',
               '・案件は「確定」・スタッフに公開済みで入ります',
               '・アサイン表に名前のある人は「確定」のアサインで入ります',
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
