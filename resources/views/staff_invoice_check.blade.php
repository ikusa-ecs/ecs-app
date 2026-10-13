@extends('layouts.app')
@section('title', '請求書チェック・手当管理表')
@section('h1', '請求書チェック・手当管理表')
@php $active = 'staff_invoice_check'; @endphp

@push('head')
<style>
  /* 色は共通の変数だけで書く＝3つのテーマぜんぶで読める（2026-10-13） */
  .si-intro { font-size: 13px; color: var(--muted); line-height: 1.8; margin: 0 0 12px; }
  .si-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
  .si-bar .ym { font-size: 16px; font-weight: 800; color: var(--ink); min-width: 110px; text-align: center; }
  .si-bar a.nav { text-decoration: none; border: 1px solid var(--line); border-radius: 8px; padding: 4px 10px; color: var(--ink); background: var(--panel); }
  .si-bar .cnt { font-size: 12.5px; color: var(--muted); }
  .si-bar label { font-size: 12.5px; color: var(--ink); display: flex; align-items: center; gap: 4px; cursor: pointer; }
  .si-bar .sp { flex: 1; }
  .si-table { width: 100%; border-collapse: collapse; background: var(--panel); color: var(--ink); border: 1px solid var(--line); border-radius: 10px; overflow: hidden; }
  .si-table th, .si-table td { border-bottom: 1px solid var(--line); padding: 8px 10px; text-align: left; vertical-align: top; font-size: 13px; }
  .si-table th { background: var(--surface-2, var(--panel)); font-size: 12px; color: var(--muted); }
  .si-table td.num { text-align: right; white-space: nowrap; }
  .si-table td.box { text-align: center; white-space: nowrap; width: 110px; }
  .si-table input[type=checkbox] { width: 20px; height: 20px; cursor: pointer; }
  .si-who { display: block; font-size: 10.5px; color: var(--muted); margin-top: 2px; }
  .si-row.done td { opacity: .55; }
  .si-name { font-weight: 700; }
  .si-detail { margin-top: 6px; }
  .si-detail summary { cursor: pointer; font-size: 12px; color: var(--muted); }
  .si-cases { margin: 6px 0 0; padding: 0; list-style: none; font-size: 12.5px; line-height: 1.8; }
  .si-cases .st { font-size: 11px; color: var(--muted); }
  .si-cases .cx { font-size: 11px; color: #b91c1c; font-weight: 700; }
  .si-cases li { margin-bottom: 4px; }
  .si-draft { margin-top: 8px; border-top: 1px dashed var(--line); padding-top: 6px; }
  .si-dt { font-size: 12px; font-weight: 700; color: var(--ink); margin-bottom: 4px; }
  .si-draft table { border-collapse: collapse; font-size: 12px; }
  .si-draft td { padding: 2px 8px 2px 0; border: 0; vertical-align: top; }
  .si-draft td.n { text-align: right; white-space: nowrap; }
  .si-draft tr.sum td { font-weight: 700; border-top: 1px solid var(--line); }
  .si-checks { margin: 4px 0 0; padding: 0; list-style: none; font-size: 11.5px; color: var(--muted); line-height: 1.7; }
  .si-guide { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 8px 12px; margin-bottom: 12px; font-size: 12.5px; color: var(--ink); }
  .si-guide summary { cursor: pointer; font-weight: 700; }
  .si-guide ul { margin: 6px 0 0; padding-left: 1.2em; line-height: 1.8; }
  /* タブ（2026-10-13）。表示する拠点の下で「請求書チェック／手当の記録」を切り替える。 */
  .si-tabs { display: flex; gap: 4px; border-bottom: 2px solid var(--line); margin: 0 0 12px; flex-wrap: wrap; }
  .si-tabs a { text-decoration: none; color: var(--muted); font-weight: 700; font-size: 13.5px; padding: 8px 14px; border: 1px solid transparent; border-bottom: 0; border-radius: 8px 8px 0 0; margin-bottom: -2px; }
  .si-tabs a.on { color: var(--ink); background: var(--panel); border-color: var(--line); border-bottom: 2px solid var(--panel); }
  .si-tab-n { font-size: 11px; font-weight: 700; margin-left: 6px; padding: 0 6px; border-radius: 999px; border: 1px solid var(--line); }
  .si-allow-form { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
  .si-allow-form select, .si-allow-form input { padding: 5px 7px; border: 1px solid var(--line); border-radius: 6px; background: var(--panel); color: var(--ink); font-family: inherit; font-size: 12.5px; }
  .si-sub { font-size: 11.5px; color: var(--muted); line-height: 1.6; padding-left: 1em; display: flex; flex-wrap: wrap; gap: 4px 12px; }
  .si-fmt { font-weight: 700; color: var(--ink); border: 1px solid var(--line); border-radius: 999px; padding: 0 7px; }
  .si-copy { font-size: 11.5px; border: 1px solid var(--line); background: var(--panel); color: var(--ink); border-radius: 6px; padding: 2px 8px; cursor: pointer; margin-left: 6px; }
  .si-saving { font-size: 10.5px; color: var(--muted); }
  .si-ng { font-size: 10.5px; color: #b91c1c; font-weight: 700; }
  @media (max-width: 720px) {
    .si-table td.box { width: auto; }
    .si-table th.hide-sp, .si-table td.hide-sp { display: none; }
  }
</style>
@endpush

@section('content')

@if (session('ok'))<div class="si-guide" style="border-color:var(--ok-line, #86efac);">✓ {{ session('ok') }}</div>@endif
@if ($errors->any())
  <div class="si-guide si-ng" style="font-size:12.5px;">
    @if ($errors->has('url'))⚠ URLの形が正しくありません（https:// から始まるURLを貼ってください）。<br>@endif
    @foreach ($errors->messages() as $key => $msgs)
      @if ($key !== 'url')
        @foreach ($msgs as $msg)⚠ {{ $msg }}<br>@endforeach
      @endif
    @endforeach
  </div>
@endif

@include('partials.office_switch')

{{-- タブ（2026-10-13 baba「手当の記録は表示する拠点の下にページを切り替えられる感じで」）。
     どちらのタブかはURLの ?tab= で覚える＝月を移っても・手当を入れたあとでも同じタブのまま。 --}}
<div class="si-tabs">
  {{-- 手当の記録が先（いろいろな社員が使う・2026-10-13 baba）。 --}}
  <a class="{{ $tab === 'allowances' ? 'on' : '' }}" href="{{ $tabUrls['allowances'] }}">✍ 手当の記録<span class="si-tab-n">{{ count($allowances) }}</span></a>
  <a class="{{ $tab === 'check' ? 'on' : '' }}" href="{{ $tabUrls['check'] }}">🧾 請求書チェック</a>
</div>

<div class="si-bar">
  <a class="nav" href="{{ $prevUrl }}">‹ 前の月</a>
  <span class="ym">{{ $month->format('Y年n月') }}</span>
  <a class="nav" href="{{ $nextUrl }}">次の月 ›</a>
  @if ($tab === 'check')
    <span class="cnt">{{ $counts['all'] }}人／📨 {{ $counts['received'] }}人／✅ {{ $counts['checked'] }}人</span>
    <span class="sp"></span>
    <label><input type="checkbox" id="siOnlyOpen" onchange="siFilter()"> ✅がまだの人だけ</label>
  @endif
</div>

@if ($tab === 'check')
<p class="si-intro">
  月を選ぶと、その月にアサイン（キャンセル以外）があるスタッフが並びます。スタッフから請求書が届いたら<b>📨</b>、中身を明細と見比べて合っていたら<b>✅</b>にチェックしてください。<b>押した瞬間に保存</b>されます（誰がいつ付けたかも残ります）。<br>
  名前の行の「明細」を開くと、その人の日付・案件・役割が並びます。※ アサイン表の取込で名簿と名前が合わなかった人は、ECSにアサインが入っていないのでここにも出ません。
</p>

{{-- 請求書PDFの置き場所（2026-10-13 baba「Googleフォームで送ってもらっているPDFの格納先URLを置きたい」）。 --}}
<div class="si-guide" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
  <b>📁 請求書PDFの置き場所</b>
  @if ($folderUrl !== '')
    <a class="si-copy" style="margin:0; text-decoration:none;" href="{{ $folderUrl }}" target="_blank" rel="noopener">開く ↗</a>
  @else
    <span class="si-who" style="display:inline;">まだ入っていません（Googleフォームで集めたPDFのフォルダのURLを貼ってください）</span>
  @endif
  <details style="margin-left:auto;">
    <summary class="si-who" style="display:inline; cursor:pointer;">✎ URLを{{ $folderUrl !== '' ? '変える' : '入れる' }}</summary>
    <form method="POST" action="/staff-invoice-check/folder" style="display:flex; gap:6px; margin-top:6px;">@csrf
      <input type="url" name="url" value="{{ $folderUrl }}" placeholder="https://drive.google.com/drive/folders/…" style="width:340px; max-width:70vw; padding:5px 8px; border:1px solid var(--line); border-radius:6px; background:var(--panel); color:var(--ink);">
      <button type="submit" class="si-copy" style="margin:0;">保存</button>
    </form>
  </details>
</div>

{{-- 請求書の作り方（マニュアルの要点・2026-10-13 baba「請求書の作り方から要りそうな情報を載せて」）。
     元＝ドライブ「【マニュアル】請求書作成（26年5月～）」。単価を変えるときは App\Support\StaffInvoiceDraft と合わせる。 --}}
<details class="si-guide">
  <summary>📘 請求書の作り方（スタッフ向けマニュアルの要点）</summary>
  <ul>
    <li>スタッフは <b>INVOY</b>（invoy.jp）で作って、PDFを提出。取引先＝株式会社IKUSA（御中）</li>
    <li>請求書番号・PDF名＝<b>「yymm請求書_氏名」</b>（例：2610請求書_戦太郎）／件名＝「氏名_◯月勤務分」</li>
    <li>発行日＝<b>勤務月の月末</b>／お支払期限＝<b>翌月末</b>／税込み表示・10％・端数切捨て・源泉徴収なし</li>
    <li>基本報酬＝オンライン <b>6,000円</b>・リアル <b>9,000円</b>／日。1日2案件＝×1.5（2案件/日）、3案件＝×2（3案件/日）</li>
    <li>手当＝MC・スライド 1,000円/案件、ロング・宿泊（前泊）・運転（20分以上）・事前レクチャー/リハーサル 2,000円/回、前日設営 4,000円/回<br>
      ※ 動画視聴・事前ブリーフィング・体験会への参加は対象外</li>
    <li>交通費＝自宅最寄り駅⇔会社（池袋・東池袋・大塚）など。<b>往復3,000円まで</b>。税区分は「対象外」</li>
    <li>キャンセル料＝前日 1/3・当日（集合1時間前まで）1/2・当日（集合1時間前を過ぎてから）全額＋交通費。体調不良・自宅Wi-Fiなどスタッフ都合は対象外</li>
    <li>品目の書き方の例：「リアルイベント：10/5、12」「MC手当：10/5」「前泊手当：10/4（10/5本番のチャンバラのため）」<br>
      ※ 下の各スタッフの「明細」に、ECSのアサインから作った<b>請求書の目安</b>を同じ書き方で出しています（標準単価なので、人によって違うことがあります）</li>
    <li><a href="https://drive.google.com/file/d/1rsX68FKkCKQqwsNEq4BrViPmL63PKjLT/view" target="_blank" rel="noopener">マニュアル（PDF）を開く</a></li>
  </ul>
</details>

@if (! $list)
  <div class="panel"><p class="si-intro" style="margin:0;">この月にアサインのあるスタッフはいません。</p></div>
@else
<table class="si-table">
  <tr>
    {{-- チェック欄は名前の前（2026-10-13 baba「請求書とチェック済みのチェックボックスは名前の前に」）。 --}}
    <th style="text-align:center;">📨 請求書</th>
    <th style="text-align:center;">✅ チェック済</th>
    <th>スタッフ</th>
    <th class="hide-sp">件数</th>
  </tr>
  @foreach ($list as $r)
    <tr class="si-row {{ $r['checked'] ? 'done' : '' }}" data-id="{{ $r['id'] }}">
      @foreach (['received' => '請求書が届いた', 'checked' => '明細と見比べて合っていた'] as $field => $title)
        <td class="box">
          <input type="checkbox" title="{{ $title }}" data-field="{{ $field }}" @checked($r[$field]) onchange="siMark(this)">
          <span class="si-who">{{ $r[$field] ? $r[$field]['at'].' '.$r[$field]['by'] : '' }}</span>
        </td>
      @endforeach
      <td>
        <span class="si-name">{{ $r['name'] }}</span>
        @if ($r['office'] !== '')<span class="si-who" style="display:inline; margin-left:6px;">{{ $r['office'] }}</span>@endif
        <details class="si-detail">
          <summary>明細 {{ count($r['cases']) }}件</summary>
          <textarea class="si-copytext" readonly style="position:absolute; left:-9999px; width:1px; height:1px;">{{ $r['copyText'] }}</textarea>
          <button type="button" class="si-copy" onclick="siCopy(this)">📋 コピー</button><span class="si-saving"></span>
          <ul class="si-cases">
            @foreach ($r['cases'] as $c)
              <li>{{ $c['date'] }}　<a href="/project-assign?project={{ urlencode($c['projectId']) }}">{{ $c['name'] }}</a>{{ $c['client'] !== '' ? '／'.$c['client'] : '' }}{{ $c['dayType'] !== '' && $c['dayType'] !== '本番' ? '［'.$c['dayType'].'］' : '' }}　{{ $c['role'] }}
                @if ($c['status'] !== '確定')<span class="st">（{{ $c['status'] }}）</span>@endif
                @if ($c['cancelled'])<span class="cx">案件中止</span>@endif
                {{-- 実施形態・集合／解散・会場住所（2026-10-13） --}}
                @if ($c['format'] !== '' || $c['meet'] !== '' || $c['leave'] !== '' || $c['place'] !== '')
                  <div class="si-sub">
                    @if ($c['format'] !== '')<span class="si-fmt">{{ $c['format'] }}</span>@endif
                    @if ($c['meet'] !== '' || $c['leave'] !== '')<span>{{ $c['meet'] !== '' ? '集合 '.$c['meet'] : '' }}{{ $c['meet'] !== '' && $c['leave'] !== '' ? '・' : '' }}{{ $c['leave'] !== '' ? '解散 '.$c['leave'] : '' }}</span>@endif
                    @if ($c['place'] !== '')<span>📍 {{ $c['place'] }}</span>@endif
                  </div>
                @endif
              </li>
            @endforeach
          </ul>
          {{-- 請求書に出てくるはずの品目（マニュアルの書き方・標準単価の目安）。正本＝App\Support\StaffInvoiceDraft。 --}}
          <div class="si-draft">
            <div class="si-dt">🧾 請求書の目安（マニュアルの書き方・標準単価）</div>
            @if ($r['draft']['items'])
              <table>
                @foreach ($r['draft']['items'] as $it)
                  <tr><td>{{ $it['label'] }}</td><td class="n">{{ $it['qty'] }}</td><td class="n">× {{ number_format($it['price']) }}円</td><td class="n">{{ number_format($it['amount']) }}円</td></tr>
                @endforeach
                <tr class="sum"><td>合計（交通費・ECSで分からない手当を除く）</td><td></td><td></td><td class="n">{{ number_format($r['draft']['total']) }}円</td></tr>
              </table>
            @endif
            <ul class="si-checks">
              @foreach ($r['draft']['checks'] as $ck)
                <li>⚠ {{ $ck }}</li>
              @endforeach
            </ul>
          </div>
        </details>
      </td>
      <td class="num hide-sp">{{ count($r['cases']) }}件<span class="si-who">うち確定 {{ $r['confirmed'] }}</span></td>
    </tr>
  @endforeach
</table>
@endif
@endif {{-- /請求書チェックのタブ --}}

@if ($tab === 'allowances')
{{-- 手当の記録（2026-10-13 baba「運転手当・スライド手当・事前レクチャー手当を社員に追加してもらいたい」）。
     入れたものは各スタッフの「🧾 請求書の目安」に足される。マイページの収支入力からも入れられる（保存先は同じ）。
     前泊・ロング・MC・前日設営・リハはアサインから自動で数えるので、ここでは選べない（二重に数えないため）。 --}}
<div class="si-guide" style="margin-top:18px;" id="allowances">
  <div style="font-weight:800; font-size:14px; margin-bottom:4px;">✍ 手当の記録（{{ $month->format('n月') }}）</div>
  <div class="si-who" style="display:block; margin-bottom:8px;">運転手当（20分以上運転）・スライド手当・事前レクチャー手当など、アサインからは分からない手当を入れてください。入れたものは各スタッフの「🧾 請求書の目安」に足されます。マイページの「収支入力」からも入れられます。</div>
  <form method="POST" action="/staff-allowances" class="si-allow-form">@csrf
    <select name="staff_id" id="alStaff" required onchange="alFillCases()">
      <option value="">スタッフを選ぶ</option>
      @foreach ($formStaff as $s)
        <option value="{{ $s['id'] }}" @selected(old('staff_id') === $s['id'])>{{ $s['name'] }}</option>
      @endforeach
    </select>
    <select name="project_id" id="alCase" onchange="alPickCase()">
      <option value="">案件（任意）</option>
    </select>
    <input type="date" name="date" id="alDate" required value="{{ old('date') }}">
    <select name="kind" id="alKind" required onchange="alKindChanged()">
      @foreach ($allowanceKinds as $k => $unit)
        <option value="{{ $k }}" @selected(old('kind') === $k)>{{ $k }}{{ $unit ? '（'.number_format($unit).'円）' : '' }}</option>
      @endforeach
    </select>
    <input type="number" name="count" min="1" max="20" value="{{ old('count', 1) }}" style="width:60px;" title="回数"> 回
    <input type="number" name="amount" id="alAmount" min="0" max="200000" placeholder="金額（その他）" value="{{ old('amount') }}" style="width:120px; display:none;">
    <input type="text" name="note" maxlength="200" placeholder="メモ（例：10/18本番の本能寺レクチャー）" value="{{ old('note') }}" style="width:240px;">
    <button type="submit" class="si-copy" style="margin:0; font-weight:700;">＋ 入れる</button>
  </form>
  <div class="si-who" id="alHint" style="display:block; margin-top:4px;"></div>

  @if ($allowances)
    <table class="si-table" style="margin-top:10px;">
      <tr><th>日付</th><th>スタッフ</th><th>手当</th><th class="hide-sp">案件・メモ</th><th style="text-align:right;">金額</th><th class="hide-sp">入れた人</th><th></th></tr>
      @foreach ($allowances as $a)
        <tr>
          <td>{{ $a['md'] }}</td>
          <td>{{ $a['staff'] }}</td>
          <td>{{ $a['kind'] }}{{ $a['count'] > 1 ? ' ×'.$a['count'] : '' }}</td>
          <td class="hide-sp">{{ $a['project'] }}{{ $a['project'] !== '' && $a['note'] !== '' ? '／' : '' }}{{ $a['note'] }}</td>
          <td class="num">{{ number_format($a['amount']) }}円</td>
          <td class="hide-sp"><span class="si-who" style="display:inline;">{{ $a['by'] }}</span></td>
          <td>
            <form method="POST" action="/staff-allowances/{{ $a['id'] }}/delete" onsubmit="return confirm('この手当を消しますか？')">@csrf
              <button type="submit" class="si-copy" style="margin:0;">消す</button>
            </form>
          </td>
        </tr>
      @endforeach
    </table>
  @else
    <p class="si-who" style="display:block; margin-top:8px;">この月はまだ入っていません。</p>
  @endif
</div>

<script>
  // 手当の入力欄（2026-10-13）。スタッフを選ぶと、その人のこの月の案件が選べる。案件を選ぶと日付が入る。
  var AL_CASES = @json($formCases);
  var AL_HINTS = @json($allowanceHints);
  function alFillCases() {
    var sel = document.getElementById('alCase');
    var sid = document.getElementById('alStaff').value;
    sel.innerHTML = '<option value="">案件（任意）</option>';
    (AL_CASES[sid] || []).forEach(function (c) {
      var o = document.createElement('option');
      o.value = c.id; o.textContent = c.label; o.dataset.ymd = c.ymd;
      sel.appendChild(o);
    });
  }
  function alPickCase() {
    var o = document.getElementById('alCase').selectedOptions[0];
    if (o && o.dataset.ymd) document.getElementById('alDate').value = o.dataset.ymd;
  }
  function alKindChanged() {
    var k = document.getElementById('alKind').value;
    document.getElementById('alAmount').style.display = (k === 'その他') ? '' : 'none';
    document.getElementById('alHint').textContent = AL_HINTS[k] || '';
  }
  alFillCases(); alKindChanged();
</script>
@endif {{-- /手当の記録のタブ --}}

<script>
  // 押した瞬間に保存（2026-10-13）。⚠ 失敗をだまって流さない＝チェックを元に戻して赤字で知らせる。
  var SI_YM = @json($ym);
  var SI_CSRF = @json(csrf_token());
  var siBusy = 0;
  function siMark(box) {
    var tr = box.closest('tr');
    var who = box.parentNode.querySelector('.si-who');
    var on = box.checked;
    who.textContent = '保存中…'; who.className = 'si-who';
    siBusy++;
    fetch('/staff-invoice-check/mark', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': SI_CSRF },
      body: JSON.stringify({ staff_id: tr.dataset.id, ym: SI_YM, field: box.dataset.field, on: on })
    })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('通信エラー ' + r.status)); })
      .then(function (j) {
        if (!j || !j.ok) throw new Error('保存できませんでした');
        who.textContent = on ? (j.at + ' ' + j.by) : '';
        if (box.dataset.field === 'checked') tr.classList.toggle('done', on);
        siFilter();
      })
      .catch(function (e) {
        box.checked = !on;   // 保存できていないので元に戻す
        who.textContent = '⚠ 保存できませんでした。もう一度押してください';
        who.className = 'si-who si-ng';
      })
      .then(function () { siBusy--; });
  }
  function siFilter() {
    var only = document.getElementById('siOnlyOpen');
    document.querySelectorAll('tr.si-row').forEach(function (tr) {
      tr.style.display = (only && only.checked && tr.classList.contains('done')) ? 'none' : '';
    });
  }
  function siCopy(btn) {
    var box = btn.parentNode.querySelector('.si-copytext');
    var mark = btn.nextElementSibling;
    var done = function () { if (mark) mark.textContent = ' ✓ コピーしました'; };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(box.value).then(done, function () { box.select(); document.execCommand('copy'); done(); });
    } else {
      box.select(); document.execCommand('copy'); done();
    }
  }
  // 保存の途中で閉じようとしたら引き止める。
  window.addEventListener('beforeunload', function (ev) { if (siBusy > 0) { ev.preventDefault(); ev.returnValue = ''; } });
</script>

@endsection
