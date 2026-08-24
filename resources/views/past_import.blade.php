@extends('layouts.app')
@section('title', '過去案件の取込')
@section('h1', '過去案件の取込（アサイン込み）')
@php($active = 'past_import')

@push('head')
<style>
  .pj-wrap { max-width: 1000px; }
  .pj-card { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 16px 18px; margin-bottom: 14px; }
  .pj-card h2 { font-size: 14px; margin: 0 0 8px; }
  .pj-lead { font-size: 12.5px; color: #6b5c49; line-height: 1.8; }
  .pj-lead b { color: var(--ink); }
  .pj-flash { border-radius: 10px; padding: 12px 14px; font-size: 13px; margin-bottom: 14px; line-height: 1.8; }
  .pj-flash.ok { background: var(--ok-soft, #eef6f0); color: #166534; border: 1px solid #bfe6d2; }
  .pj-flash.err { background: #fdecec; color: #b91c1c; border: 1px solid #f3c0c0; }
  .pj-flash.warn { background: #fdf3e2; color: #8a5a10; border: 1px solid #ecd9b6; }
  .pj-drop { border: 2px dashed var(--line); border-radius: 10px; padding: 22px; text-align: center; color: #8a7a66; background: #fff; }
  .pj-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-top: 12px; }
  .pj-names { margin: 8px 0 0; padding-left: 1.2em; font-size: 12.5px; }
  .pj-names li { margin: 2px 0; }
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
      <b>列を並べ替える必要はありません。</b><br>
      <b>ふつうの「案件登録のCSV取込」との違い</b>は3つです。
    </p>
    <ul class="pj-lead" style="padding-left:1.2em;">
      <li><b>D・MC・OP・スタッフの列から、アサインも一緒に入ります。</b>状態は<b>「確定」</b>です
        （ふつうの案件取込は、その時点でDが決まっていないので取り込みません）。</li>
      <li>案件は<b>「確定」・スタッフに公開済み</b>で入ります＝本人が自分の過去の実績として見られます。
        <b>募集はしません</b>（スタッフ画面の「募集中」には出ません）。</li>
      <li><b>同じ案件は上書き</b>します。同じかどうかは<b>「日程・コンテンツ・顧客名・集合時間」が全部同じか</b>で見ます。
        1つでも違えば別案件として新しく作ります（同じ日・同じコンテンツでも顧客が違えば別案件）。<br>
        <span class="muted">＝失敗しても、直してもう一度入れれば大丈夫です（案件が二重に増えません）。</span></li>
    </ul>
    <p class="pj-lead">
      <b>人の書き方</b>：D・MC・OP・スタッフの列には<b>氏名</b>を書いてください。複数いるときは<b>カンマ区切り</b>（例：<code>田中 健一, 鈴木彩</code>）。
      スラッシュ・読点でも区切れます。<b>氏名の空白の有無は気にしなくて大丈夫</b>です（「田中健一」でも「田中 健一」でも当たります）。<br>
      「スタッフ」列の人は、役割が書かれていないので <b>FC（巡回ファシリ）</b>として入ります。<br>
      <b>名簿に無い人はその人だけ飛ばして、この画面の上に一覧で出します。</b>同姓同名が2人いる場合も、取り違えを防ぐため入れずに知らせます。<br>
      <b>日程</b>は<b>年から</b>入れてください（<code>2026/1/20</code>・<code>2026-01-20</code>・Excelの日付セルどれでもOK）。<br>
      ECSに対応する項目が無い列（拘束・顧客担当名・シート期日 など）は無視し、<b>取り込み後に「取り込まなかった列」として表示</b>します。
    </p>
    <p class="pj-lead">
      <b>文字コード</b>：Excelでそのまま保存した（Shift_JISの）CSVでも読めます。UTF-8でも大丈夫です。
    </p>
  </div>

  <div class="pj-card">
    <h2>CSVを選ぶ</h2>
    <form id="pjForm" method="POST" action="/past-import" enctype="multipart/form-data">
      @csrf
      <div class="pj-drop">
        ここにCSVをドラッグ＆ドロップ、または<br>
        <input type="file" name="csv" id="pjFile" accept=".csv,text/csv" style="margin-top:8px;">
      </div>
      <div class="pj-actions">
        <button type="button" class="btn primary" onclick="pjSubmit()">この内容で取り込む</button>
        <span class="muted" style="font-size:12px;">
          ※ 案件は「確定・公開済み」で入り、アサインは「確定」で入ります。取り込み直しても二重になりません。
        </span>
      </div>
    </form>
  </div>

</div>
@endsection

@push('scripts')
@verbatim
<script>
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

  // ドラッグ＆ドロップ
  (function () {
    var drop = document.querySelector('.pj-drop');
    var input = document.getElementById('pjFile');
    if (!drop || !input) return;
    ['dragenter', 'dragover'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.style.borderColor = '#4f8a63'; });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.style.borderColor = ''; });
    });
    drop.addEventListener('drop', function (e) {
      if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
        input.files = e.dataTransfer.files;
      }
    });
  })();
</script>
@endverbatim
@endpush
