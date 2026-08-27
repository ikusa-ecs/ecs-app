@extends('layouts.app')
@section('title', 'アサイン表の取込')
@section('h1', 'アサイン表の取込（アサイン込み）')
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
  /* 入れ方の切り替え（ファイルを選ぶ／貼り付ける）。2026-08-27 baba要望 */
  .pj-tabs { display: flex; gap: 6px; margin-bottom: 10px; }
  .pj-tab {
    border: 1px solid var(--line); background: #fff; color: #8a7a66;
    border-radius: 8px 8px 0 0; padding: 7px 14px; font-size: 13px; cursor: pointer; font-family: inherit;
  }
  .pj-tab.on { background: #f6f1ea; color: var(--ink); font-weight: 700; border-bottom-color: #f6f1ea; }
  #pjPaste {
    width: 100%; box-sizing: border-box; min-height: 150px; margin-top: 8px;
    border: 1px solid var(--line); border-radius: 8px; padding: 8px 10px;
    font-family: ui-monospace, Consolas, monospace; font-size: 12px; line-height: 1.6;
  }
  .pj-period { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; font-size: 13px; }
  .pj-period input[type="month"] {
    border: 1px solid var(--line); border-radius: 6px; padding: 5px 8px; font-family: inherit; font-size: 13px;
  }
  .pj-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-top: 12px; }
  .pj-names { margin: 8px 0 0; padding-left: 1.2em; font-size: 12.5px; }
  .pj-names li { margin: 2px 0; }
  /* 名簿に無かった人を、その場で名簿に足せるようにする（2026-08-27 baba要望） */
  #pjMissing li { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin: 5px 0; }
  .pj-miss-name {
    border: 1px solid #d7cec2; border-radius: 5px; padding: 3px 6px;
    font-size: 12.5px; font-family: inherit; min-width: 190px; background: #fff;
  }
  .pj-miss-add { font-size: 12px; padding: 4px 10px; }
  .pj-miss-msg { font-size: 12px; }
  .pj-miss-msg.ok { color: #166534; font-weight: 700; }
  .pj-miss-msg.ng { color: #b91c1c; }
  .pj-miss-foot { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 10px; }
  .pj-miss-foot select {
    border: 1px solid var(--line); border-radius: 6px; padding: 4px 8px; font-family: inherit; font-size: 12.5px;
  }
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
  /* 表の中でそのまま直せるようにする（2026-08-25 baba要望）。 */
  table.pj-table input[type="text"], table.pj-table input[type="date"] {
    width: 100%; box-sizing: border-box; border: 1px solid #d7cec2; border-radius: 5px;
    padding: 3px 5px; font-size: 12px; background: #fff; font-family: inherit;
  }
  table.pj-table input.edited { border-color: #4f8a63; background: #f2faf5; font-weight: 700; }
  table.pj-table tr.row-skip td { background: #f1efec; color: #9a8f80; }
  table.pj-table tr.row-skip input { opacity: .55; }
  .pj-col-date { width: 140px; } .pj-col-name { width: 220px; }
  .pj-col-client { width: 180px; } .pj-col-count { width: 78px; }
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
      名簿に登録してから、<b>同じアサイン表をもう一度取り込む</b>と入ります（案件は上書きされ、二重にはなりません）。
      辞められた方の場合は、名簿に登録して「退職にする」を押しておくと履歴として残せます。
      {{-- その場で名簿に足せるようにする（2026-08-27 baba要望）。
           入口は臨時スタッフと同じ POST /people/spot ＝作り方を2つ持たない。
           ⚠ 名前の「★」「☆」は外した状態で出している（PersonLookup::displayName）。 --}}
      <ul class="pj-names" id="pjMissing">
        @foreach (session('past_missing') as $n)
          <li>
            <input type="text" class="pj-miss-name" value="{{ $n }}" data-orig="{{ $n }}">
            <button type="button" class="btn pj-miss-add" onclick="pjAddMissing(this)">＋ この名前で名簿に追加</button>
            <span class="pj-miss-msg"></span>
          </li>
        @endforeach
      </ul>
      <div class="pj-miss-foot">
        <label for="pjMissOffice"><b>どの拠点の人として追加しますか</b></label>
        <select id="pjMissOffice">
          @foreach ($offices as $o)
            <option value="{{ $o }}" @selected($o === $myOffice)>{{ $o }}</option>
          @endforeach
        </select>
        <span class="muted" style="font-size:11.5px;">
          ※ <b>ログイン無し・「臨時」の印つき</b>で名簿に入ります（メールアドレスは要りません）。
          あとから名簿でアカウントを発行できます。
        </span>
      </div>
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
      <b>アサイン表を、アサインごとまとめて登録する画面です。</b>終わった案件（過去の実績）でも、これからの案件でも入れられます。
      入れ方は2通り＝アサイン表のシートを <b>CSVで保存してアップロード</b>するか、
      <b>スプレッドシートでコピーして貼り付ける</b>かです（<b>何件ぶんでもOK</b>）。
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
      <li><b>アサイン表に名前のある人は、アサインも一緒に入ります。</b>
        （ふつうの案件取込は、その時点でDが決まっていないので取り込みません）</li>
      <li><b>「終わった案件」と「これからの案件」を選べます。</b>入り方が正反対になるためです。
        <ul style="padding-left:1.2em;">
          <li><b>終わった案件（過去の実績）</b>… 案件は<b>「確定」・スタッフに公開済み・募集しない</b>、
            アサインは<b>「確定」</b>。＝本人が自分の過去の実績として見られます。</li>
          <li><b>これからの案件</b>… 案件は<b>「調整中」・未公開・募集する</b>、アサインは<b>「仮」</b>。
            ＝まだ入れ替えられます。<b>未公開なのでスタッフにはまだ見えません。</b>
            人数を整えてから<span class="menu">スタッフ公開ボード</span>で「公開する」を押すと出ます。</li>
        </ul>
      </li>
      <li><b>取り込む前に、この画面の表で直せます。</b>日程・コンテンツ・顧客名・運営人数はその場で書き換えられ、
        <b>「この件は取り込まない」</b>に印を付けた案件は飛ばせます。
        <span class="muted">＝CSVを作り直してアップロードし直す必要はありません（元のCSVは変わりません）。</span></li>
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
    <h2>① アサイン表を読み込む（ファイル または 貼り付け）</h2>
    {{-- 実登録はこのフォームでCSVファイルそのものをPOSTし、サーバーが読み直して登録する。 --}}
    <form id="pjForm" method="POST" action="/past-import" enctype="multipart/form-data">
      @csrf
      {{-- 表で直した内容（JSON）。CSVそのものはサーバーが読み直し、ここは「上書きする値」だけを送る
           ＝読み取りの決まりを画面側に増やさないため（2026-08-25）。 --}}
      <input type="hidden" name="edits" id="pjEdits" value="">
      {{-- ⚠ どの拠点の案件として入れるか。他拠点のアサイン表を代わりに取り込むことがあるため、
           取り込んだ人の拠点で決め打ちにしない（2026-08-25 baba）。 --}}
      {{-- ⚠ この表は終わった案件か、これからの案件か。入り方（状態・公開・募集・アサイン）が
           正反対になるので必ず選ぶ。既定は今までどおり「終わった案件」（2026-08-26 baba）。 --}}
      <div style="margin-bottom:12px;">
        <div style="font-size:13px; margin-bottom:6px;"><b>この表は？</b></div>
        <label style="display:block; margin-bottom:4px; font-size:13.5px;">
          <input type="radio" name="mode" value="{{ $modePast }}" checked onchange="pjModeChanged()">
          <b>終わった案件（過去の実績）</b>
          <span class="muted" style="font-size:11.5px;">… 案件＝確定・公開済み・募集しない／アサイン＝確定</span>
        </label>
        <label style="display:block; font-size:13.5px;">
          <input type="radio" name="mode" id="pjModeFuture" value="{{ $modeFuture }}" onchange="pjModeChanged()">
          <b>これからの案件</b>
          <span class="muted" style="font-size:11.5px;">… 案件＝調整中・<b>未公開</b>・募集する／アサイン＝<b>仮</b></span>
        </label>
        <div class="muted" id="pjModeNote" style="font-size:11.5px; margin-top:6px;"></div>
      </div>
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
      {{-- 入れ方は2通り（2026-08-27 baba要望）。読み取りから先は同じ道を通る。 --}}
      <div class="pj-tabs">
        <button type="button" class="pj-tab on" id="pjTabFile" onclick="pjSetSource('file')">📄 ファイルを選ぶ</button>
        <button type="button" class="pj-tab" id="pjTabPaste" onclick="pjSetSource('paste')">📋 スプレッドシートから貼り付ける</button>
      </div>

      <div id="pjSrcFile">
        <div class="pj-drop" id="pjDrop">
          ここにCSVをドラッグ＆ドロップ、または<br>
          <input type="file" name="csv" id="pjFile" accept=".csv,text/csv" style="margin-top:8px;">
          <div class="pj-file" id="pjFileName"></div>
        </div>
      </div>

      <div id="pjSrcPaste" style="display:none;">
        <p class="pj-lead" style="margin:0 0 8px;">
          スプレッドシートで<b>案件のかたまり（縦1列ぶん）を選んでコピー</b>し、下に貼り付けてください
          （<b>何件ぶんでもOK</b>）。ファイルに落とさなくても取り込めます。
        </p>
        <div class="pj-period">
          <label for="pjPeriod"><b>何年何月ぶんか</b></label>
          <input type="month" name="period" id="pjPeriod" value="{{ now()->format('Y-m') }}">
          <span class="muted" style="font-size:11.5px;">
            ※ アサイン表の日程には<b>年が書かれていない</b>ので、ここで決めます（ファイルのときは名前から読んでいます）。
          </span>
        </div>
        <textarea name="paste" id="pjPaste" placeholder="ここに貼り付け（Ctrl+V）"></textarea>
        <div class="pj-actions" style="margin-top:8px;">
          <button type="button" class="btn" onclick="pjPasteRead()">① 貼り付けた内容を読む</button>
          <span class="muted" style="font-size:12px;">※ 読み込むと、下に確認の表が出ます。</span>
        </div>
      </div>
    </form>
  </div>

  {{-- ファイルを選ぶと、ここに中身が出る（他の取込画面と同じ動き）。 --}}
  <div class="pj-card" id="pjResult" style="display:none;">
    <h2>② 取り込む内容の確認</h2>
    <div class="pj-summary">
      読み込み：<b id="pjTotal">0</b> 件 ／ <span class="ok">OK <b id="pjOk">0</b></span> ／ <span class="ng">エラー <b id="pjNg">0</b></span>
      ／ 取り込まない <b id="pjSkip">0</b> 件 ／ アサインに入る人：<b id="pjPeople">0</b> 名
    </div>
    <div id="pjWarn"></div>
    <p class="pj-lead" style="margin:0 2px 8px;">
      <b>間違っているところは、この表の中でそのまま直せます。</b>直したところは<b>緑色</b>になります。
      入れたくない案件は<b>「取込」のチェックを外して</b>ください。<br>
      <span class="muted">直したら<b>「② 直した内容で確かめ直す」</b>を押すと、判定をやり直します（押さずに取り込んでも、直した内容で入ります）。
        <b>元のCSVファイルは変わりません。</b></span>
    </p>
    <div class="pj-scroll">
      <table class="pj-table">
        <thead>
          <tr>
            <th>件</th><th>取込</th><th>判定</th>
            <th class="pj-col-date">日程</th><th class="pj-col-name">コンテンツ</th>
            <th class="pj-col-client">顧客名</th><th class="pj-col-count">運営人数</th>
            <th>入る人</th><th>理由・注意</th>
          </tr>
        </thead>
        <tbody id="pjBody"></tbody>
      </table>
    </div>
    <div class="pj-actions">
      <button type="button" class="btn" id="pjRecheck" onclick="pjRecheck()">② 直した内容で確かめ直す</button>
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

  // 表で直した内容をためておく（「確かめ直す」を押しても消えないように）。
  // 形： { "0": {date:'2027-09-01', name:'謎解き', skip:true}, ... }（鍵＝CSVの何件目か）
  // ⚠ 直した項目だけを持つ。全部送ると「日程が読めません（9月1日）」のような
  //   元の値を使った案内が出せなくなるため。
  var pjEdits = {};

  function pjEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }

  function pjClear() {
    document.getElementById('pjTotal').textContent = '0';
    document.getElementById('pjOk').textContent = '0';
    document.getElementById('pjNg').textContent = '0';
    document.getElementById('pjSkip').textContent = '0';
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
    var okCount = 0, ngCount = 0, skipCount = 0, peopleCount = 0;
    rows.forEach(function (r) {
      if (r.skip) { skipCount++; return; }   // 取り込まない件は数に入れない
      if (r.errors && r.errors.length) { ngCount++; } else { okCount++; peopleCount += (r.people || 0); }
    });

    document.getElementById('pjTotal').textContent = rows.length;
    document.getElementById('pjOk').textContent = okCount;
    document.getElementById('pjNg').textContent = ngCount;
    document.getElementById('pjSkip').textContent = skipCount;
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
      var cls = r.skip ? 'row-skip' : (ok ? 'row-ok' : 'row-ng');
      return '<tr class="' + cls + '" data-index="' + pjEsc(r.index) + '">'
        + '<td>' + pjEsc(r.label) + '</td>'
        + '<td style="text-align:center;">'
          + '<input type="checkbox" data-f="skip" onchange="pjToggleSkip(this)"'
          + ' title="チェックを外すと、この案件は取り込みません"' + (r.skip ? '' : ' checked') + '></td>'
        + '<td>' + (r.skip ? '—' : (ok ? 'OK' : 'エラー')) + '</td>'
        + '<td>' + pjInput('date', r.date, 'date') + '</td>'
        + '<td>' + pjInput('name', r.name, 'text') + '</td>'
        + '<td>' + pjInput('client', r.client, 'text') + '</td>'
        + '<td>' + pjInput('count', r.count, 'text') + '</td>'
        + '<td>' + pjEsc(r.people) + ' 名</td>'
        + '<td class="' + (ok ? 'pj-miss' : 'pj-reason') + '">' + pjEsc(notes.join(' / ')) + '</td>'
        + '</tr>';
    }).join('');

    pjMarkEdited();

    var result = document.getElementById('pjResult');
    result.style.display = '';
    document.getElementById('pjBtn').disabled = (okCount === 0);
    result.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  // 表の中の入力欄。data-orig にはサーバーが読んだ値を入れておき、
  // それと違えば「直した」と分かるようにする。
  function pjInput(field, value, type) {
    var v = pjEsc(value == null ? '' : value);

    return '<input type="' + type + '" data-f="' + field + '" value="' + v + '" data-orig="' + v + '"'
      + ' oninput="pjMarkEdited()" onchange="pjMarkEdited()">';
  }

  // 直したところを緑にする（どこを触ったか見て分かるように）。
  function pjMarkEdited() {
    var list = document.querySelectorAll('#pjBody input[data-orig]');
    Array.prototype.forEach.call(list, function (el) {
      if (el.value !== el.dataset.orig) { el.classList.add('edited'); } else { el.classList.remove('edited'); }
    });
  }

  // 「取込」のチェックを外した行は、その場で灰色にする（判定のやり直しを待たずに分かるように）。
  function pjToggleSkip(el) {
    var tr = el.closest('tr');
    if (!tr) return;
    tr.classList.toggle('row-skip', !el.checked);
  }

  // いま表に入っている「直した内容」を pjEdits にためる。
  function pjSyncEdits() {
    var trs = document.querySelectorAll('#pjBody tr[data-index]');
    Array.prototype.forEach.call(trs, function (tr) {
      var idx = tr.dataset.index;
      var cur = pjEdits[idx] || {};
      ['date', 'name', 'client', 'count'].forEach(function (k) {
        var el = tr.querySelector('[data-f="' + k + '"]');
        if (el && el.value !== el.dataset.orig) { cur[k] = el.value; }
      });
      var sk = tr.querySelector('[data-f="skip"]');
      // チェックが外れている＝取り込まない。
      if (sk) { if (sk.checked) { delete cur.skip; } else { cur.skip = true; } }
      if (Object.keys(cur).length) { pjEdits[idx] = cur; } else { delete pjEdits[idx]; }
    });

    var json = JSON.stringify(pjEdits);
    document.getElementById('pjEdits').value = (json === '{}') ? '' : json;

    return document.getElementById('pjEdits').value;
  }

  // ===== 名簿に無かった人を、その場で名簿に足す（2026-08-27 baba要望）=====
  // ⚠ 作り方は臨時スタッフと同じ入口（POST /people/spot）を使う＝人の作り方を2つ持たない。
  //   足したあとは「同じアサイン表をもう一度取り込む」とアサインが入る（案件は上書きなので二重にならない）。
  function pjAddMissing(btn) {
    var li = btn.closest('li');
    var input = li.querySelector('.pj-miss-name');
    var msg = li.querySelector('.pj-miss-msg');
    var name = input.value.trim();
    if (!name) { msg.className = 'pj-miss-msg ng'; msg.textContent = '名前を入れてください。'; return; }

    btn.disabled = true;
    msg.className = 'pj-miss-msg';
    msg.textContent = '登録しています…';

    var token = document.querySelector('#pjForm input[name="_token"]').value;
    var fd = new FormData();
    fd.append('name', name);
    fd.append('office', document.getElementById('pjMissOffice').value);
    fd.append('_token', token);

    fetch('/people/spot', {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token }
    }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) {
        if (!res.ok || !res.d.ok) {
          btn.disabled = false;
          msg.className = 'pj-miss-msg ng';
          msg.textContent = res.d.message || '登録できませんでした。';
          return;
        }
        input.disabled = true;
        btn.style.display = 'none';
        msg.className = 'pj-miss-msg ok';
        msg.textContent = '✓ 名簿に追加しました（' + res.d.id + '）';
      })
      .catch(function () {
        btn.disabled = false;
        msg.className = 'pj-miss-msg ng';
        msg.textContent = '登録に失敗しました。通信を確認して、もう一度お試しください。';
      });
  }

  // 入れ方＝'file'（ファイルを選ぶ）か 'paste'（スプレッドシートから貼り付け）。
  var pjSource = 'file';

  function pjSetSource(mode) {
    pjSource = mode;
    var isFile = (mode === 'file');
    document.getElementById('pjSrcFile').style.display = isFile ? '' : 'none';
    document.getElementById('pjSrcPaste').style.display = isFile ? 'none' : '';
    document.getElementById('pjTabFile').className = 'pj-tab' + (isFile ? ' on' : '');
    document.getElementById('pjTabPaste').className = 'pj-tab' + (isFile ? '' : ' on');

    // ⚠ 使わないほうは空にする。両方に中身が残っていると、どちらを取り込んだのか分からなくなる。
    if (isFile) {
      document.getElementById('pjPaste').value = '';
    } else {
      document.getElementById('pjFile').value = '';
      document.getElementById('pjFileName').textContent = '';
    }
    // 入れ方を変えたら、前の読み込み結果と直しは持ち越さない。
    pjEdits = {};
    document.getElementById('pjEdits').value = '';
    document.getElementById('pjResult').style.display = 'none';
  }

  // いま選ばれている入れ方に中身があるか。
  function pjHasSource() {
    return (pjSource === 'file')
      ? !!document.getElementById('pjFile').files[0]
      : document.getElementById('pjPaste').value.trim() !== '';
  }

  function pjNeedSourceMessage() {
    return (pjSource === 'file')
      ? '先にCSVファイルを選んでください。'
      : 'スプレッドシートからコピーした中身を貼り付けてください。';
  }

  // 直した内容で、判定だけやり直す（登録はしない）。
  // ⚠ 判定はサーバーにやらせる＝画面にもう1つ同じ判定を書かないため。
  function pjRecheck() {
    if (!pjHasSource()) { alert(pjNeedSourceMessage()); return; }
    pjSyncEdits();
    pjPost(document.getElementById('pjEdits').value);
  }

  // 貼り付けた中身を読む。
  function pjPasteRead() {
    if (!pjHasSource()) { alert(pjNeedSourceMessage()); return; }
    pjEdits = {};
    document.getElementById('pjEdits').value = '';
    pjPost('');
  }

  function pjReadAndPreview(file) {
    if (!file) return;
    // 別のファイルを選び直したら、前のファイルへの直しは持ち越さない。
    pjEdits = {};
    document.getElementById('pjEdits').value = '';
    document.getElementById('pjFileName').textContent = '選んだファイル：' + file.name;
    pjPost('');
  }

  function pjPost(editsJson) {
    document.getElementById('pjResult').style.display = '';
    document.getElementById('pjWarn').innerHTML = '<div class="pj-flash">読み込んでいます…</div>';
    pjClear();
    document.getElementById('pjBtn').disabled = true;

    var form = document.getElementById('pjForm');
    var token = form.querySelector('input[name="_token"]').value;
    var fd = new FormData();
    if (pjSource === 'file') {
      fd.append('csv', document.getElementById('pjFile').files[0]);
    } else {
      // 貼り付けは「中身」と「何年何月ぶんか」を送る（日程に年が書かれていないため）。
      fd.append('paste', document.getElementById('pjPaste').value);
      fd.append('period', document.getElementById('pjPeriod').value);
    }
    fd.append('_token', token);
    fd.append('edits', editsJson || '');

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

  // 「これからの案件」が選ばれているか。
  // ⚠ このJSは verbatim の中なので Blade の書き方は展開されない（既知の罠）。
  //   値を埋め込まず、ラジオのidで見る。
  function pjIsFuture() {
    var el = document.getElementById('pjModeFuture');
    return !!el && el.checked;
  }

  // 選んだ扱いの補足を出す（押す前に「スタッフに見えるのか」が分かるように）。
  function pjModeChanged() {
    document.getElementById('pjModeNote').innerHTML = pjIsFuture()
      ? '※ <b>未公開</b>で入ります＝この取込だけではスタッフに見えません。人数を整えてから'
        + '「スタッフ公開ボード」で公開してください。アサインは「仮」なので、あとで入れ替えられます。'
      : '※ <b>公開済み</b>で入ります＝アサインされた本人が、自分の過去の実績としてすぐ見られます。';
  }
  pjModeChanged();

  function pjSubmit() {
    if (!pjHasSource()) { alert(pjNeedSourceMessage()); return; }
    var office = document.getElementById('pjOffice').value;
    // ⚠ 押す直前に集め直す。表を直したまま「確かめ直す」を押していない人がいるため。
    pjSyncEdits();
    // 「中身を直した件数」と「取り込まないにした件数」は分けて数える（意味が違うため）。
    var edited = 0, skipped = 0;
    Object.keys(pjEdits).forEach(function (k) {
      var e = pjEdits[k];
      if (e.skip) { skipped++; }
      var touched = ['date', 'name', 'client', 'count'].some(function (f) {
        return Object.prototype.hasOwnProperty.call(e, f);
      });
      if (touched) { edited++; }
    });

    var future = pjIsFuture();
    var lines = [(future ? 'これからの案件' : '過去案件')
                 + 'を「' + office + '」の案件として取り込みます。', ''];
    lines.push(edited ? '・この画面で直した ' + edited + ' 件は、直した内容で入ります'
                      : '・この画面では中身を直していません');
    if (skipped) { lines.push('・「取り込まない」にした ' + skipped + ' 件は入れません'); }
    if (future) {
      lines.push('・案件は「調整中」・未公開・募集するで入ります（スタッフにはまだ見えません）');
      lines.push('・アサイン表に名前のある人は「仮」のアサインで入ります');
    } else {
      lines.push('・案件は「確定」・スタッフに公開済みで入ります');
      lines.push('・アサイン表に名前のある人は「確定」のアサインで入ります');
    }
    lines.push('・同じ案件（日程・コンテンツ・顧客名・集合時間が同じ）は上書きします');
    lines.push('', 'よろしいですか？');
    var msg = lines.join(String.fromCharCode(10));
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
