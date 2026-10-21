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
  .si-auto { font-size: 10.5px; font-weight: 700; color: var(--brand); border: 1px solid var(--brand); border-radius: 999px; padding: 0 6px; margin-right: 4px; }
  .si-note { display: block; width: 100%; max-width: 260px; margin-top: 4px; padding: 3px 6px; border: 1px dashed var(--line); border-radius: 6px; background: transparent; color: var(--ink); font-size: 12px; font-family: inherit; }
  .si-note:focus { border-style: solid; background: var(--panel); }
  .si-note.ng { border-color: #b91c1c; }
  .si-kind { padding: 2px 4px; border: 1px solid var(--line); border-radius: 6px; background: var(--panel); color: var(--ink); font-size: 12px; font-family: inherit; }
  .si-people { display: flex; flex-wrap: wrap; gap: 6px; }
  .si-chip { display: inline-flex; align-items: center; gap: 4px; font-size: 12.5px; border: 1px solid var(--line); border-radius: 999px; padding: 3px 10px; cursor: pointer; background: var(--panel); color: var(--ink); }
  .si-chip:has(input:checked) { border-color: var(--brand); font-weight: 700; }
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
  {{-- 請求書チェックが先（2026-10-14 baba「手当は請求書担当の人が見ている」・10/13〜14は手当の記録が先だった）。 --}}
  <a class="{{ $tab === 'check' ? 'on' : '' }}" href="{{ $tabUrls['check'] }}">🧾 請求書チェック</a>
  <a class="{{ $tab === 'allowances' ? 'on' : '' }}" href="{{ $tabUrls['allowances'] }}">✍ 手当の記録<span class="si-tab-n">{{ count($allowances) }}</span></a>
</div>

<div class="si-bar">
  <a class="nav" href="{{ $prevUrl }}">‹ 前の月</a>
  <span class="ym">{{ $month->format('Y年n月') }}</span>
  <a class="nav" href="{{ $nextUrl }}">次の月 ›</a>
  @if ($tab === 'check')
    <span class="cnt">{{ $counts['all'] }}人／📨 {{ $counts['received'] }}人／✅ {{ $counts['checked'] }}人</span>
    <span class="sp"></span>
    <label><input type="checkbox" id="siOnlyOpen" onchange="siFilter()"> ✅がまだの人だけ</label>
  @else
    <span class="sp"></span>
  @endif
  {{-- 名前で探す（2026-10-15 baba要望）。空白は無視＝「山田太郎」でも「山田 太郎」でも見つかる。 --}}
  <input type="search" id="siSearch" oninput="siFilter()" placeholder="🔍 名前・よみがなで探す" autocomplete="off"
         style="width:200px; max-width:60vw; padding:5px 8px; border:1px solid var(--line); border-radius:6px; background:var(--panel); color:var(--ink);">
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
  <summary>📘 請求書の作り方・報酬規定の要点</summary>
  <ul>
    <li>スタッフは <b>INVOY</b>（invoy.jp）で作って、PDFを提出。取引先＝株式会社IKUSA（御中）</li>
    <li>請求書番号・PDF名＝<b>「yymm請求書_氏名」</b>（例：2610請求書_戦太郎）／件名＝「氏名_◯月勤務分」</li>
    <li>発行日＝<b>勤務月の月末</b>／お支払期限＝<b>翌月末</b>／税込み表示・10％・端数切捨て・源泉徴収なし</li>
    {{-- 2026-10-13 babaが貼った報酬規定（基本報酬・追加報酬・キャンセル料・遅刻）に合わせた。単価の正本＝StaffAllowances／StaffInvoiceDraft。 --}}
    <li>基本報酬＝オンライン <b>6,000円</b>・リアル <b>9,000円</b>／1案件。1日2案件＝×1.5、3案件＝×2（事前ブリーフィング・体験会込み）。スタッフ都合・体調不良で業務続行できなかった日はなし</li>
    <li><b>同じ日・同じ会場のリアルで2案件とみなす</b>＝前半・後半ともに4時間を超えるとき（どちらかが4時間以下なら1案件）。並行して2コンテンツは1案件</li>
    <li>手当【共通】＝MC 1,000円・英語 2,000円・事前レクチャー/リハーサル 2,000円（<b>リハで4時間以上拘束なら1日分の日給</b>）・リーダー 2,000円・大型 2,000円・大型MC 2,000円（MC・リーダーと併用不可）・ディレクター 5,000円</li>
    <li>手当【リアルのみ】＝ロング 2,000円（集合〜解散が9時間超）・運転 2,000円（20分以上）・前日設営 4,000円・宿泊 2,000円/泊（スタッフ都合の前泊は対象外）・片付け 1,000円・深夜設営 2,250円（22時以降開始・5時間以上）<br>
      【オンラインのみ】＝スライド 1,000円・MC巡回 2,000円（MC手当にプラス）<br>
      ※ 多くは事前に「請求対象」と明示されたときだけ。動画視聴は対象外</li>
    <li>交通費＝実費・<b>往復3,000円まで</b>。税区分は「対象外」</li>
    <li>キャンセル料（IKUSA側からのキャンセル）＝前日（前々日19時〜前日19時）1/3・当日（前日19時〜集合1時間前）1/2・集合1時間前を過ぎてから 全額＋交通費。1円繰り上げ。体調不良は対象外</li>
    <li>遅刻の減額＝日当÷予定労働時間×遅刻時間（30分切り上げ）を引く（例：9,000÷5時間×2時間＝3,600円）。集合10分前までに連絡し10分以内・事前に承諾済み・電車の遅延（遅延証明）や不慮の事故などは、管理者の判断で対象外にすることあり</li>
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
    <tr class="si-row {{ $r['checked'] ? 'done' : '' }}" data-id="{{ $r['id'] }}" data-name="{{ $r['name'] }}" data-kana="{{ $r['kana'] }}">
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
          <summary>明細 {{ $r['liveCount'] }}件{{ count($r['cases']) - $r['liveCount'] ? '・中止 '.(count($r['cases']) - $r['liveCount']) : '' }}</summary>
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
      <td class="num hide-sp">{{ $r['liveCount'] }}件<span class="si-who">うち確定 {{ $r['confirmed'] }}</span></td>
    </tr>
  @endforeach
</table>
@endif
@endif {{-- /請求書チェックのタブ --}}

@if ($tab === 'allowances')
{{-- 手当の記録（2026-10-13 baba「運転手当・スライド手当・事前レクチャー手当を社員に追加してもらいたい」）。
     入れたものは各スタッフの「🧾 請求書の目安」に足される。マイページの収支入力からも入れられる（保存先は同じ）。
     MC・宿泊・ロング・前日設営・リハはアサインから自動でも数える＝同じ日に入れても請求書の目安では二重に数えない（StaffInvoiceDraft）。 --}}
<div class="si-guide" style="margin-top:18px;" id="allowances">
  <div style="font-weight:800; font-size:14px; margin-bottom:4px;">✍ 手当の記録（{{ $month->format('n月') }}）</div>
  <div class="si-who" style="display:block; margin-bottom:8px;">①案件を選ぶ → ②その案件のスタッフから手当を付ける人にチェック（何人でも）→ ③手当の種類などを入れて「＋入れる」。チェックした全員に同じ内容が入ります。案件に入っていない人は名前を打って追加できます。<b>前日設営・リハ日のアサインは自動で入ります</b>（いらなければ「消す」・4時間以上のリハなら種類を「1日分として数える」に切り替え）。入れたものは各スタッフの「🧾 請求書の目安」に足されます。マイページの「収支入力」からも入れられます。</div>
  {{-- 2026-10-13 baba「案件を選んだらアサインされていたスタッフで絞る」「名前を途中まで打ったら候補が出る」「複数チェックして同じ内容を入れたい」 --}}
  <form method="POST" action="/staff-allowances" id="alForm" onsubmit="return alBeforeSubmit()">@csrf
    <div class="si-allow-form">
      <b style="font-size:12.5px;">①</b>
      <select name="project_id" id="alCase" onchange="alPickCase()" style="max-width:420px;">
        <option value="">案件を選ぶ（案件の無い手当は空のまま）</option>
        @foreach ($formProjects as $fp)
          <option value="{{ $fp['id'] }}">{{ $fp['label'] }}（{{ count($fp['staff']) }}人）</option>
        @endforeach
      </select>
    </div>
    <div class="si-allow-form" style="margin-top:6px; align-items:flex-start;">
      <b style="font-size:12.5px;">②</b>
      <div style="flex:1; min-width:240px;">
        <div id="alPeople" class="si-people"><span class="si-who" style="display:inline;">案件を選ぶと、その案件のスタッフがここに並びます。</span></div>
        <div style="margin-top:6px; display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
          <input type="text" id="alAddName" list="alStaffList" placeholder="ほかの人を名前で追加（途中まで打つと候補）" style="width:260px;" autocomplete="off">
          <datalist id="alStaffList">
            @foreach ($formStaff as $s)<option value="{{ $s['name'] }}"></option>@endforeach
          </datalist>
          <button type="button" class="si-copy" style="margin:0;" onclick="alAddByName()">＋ 追加</button>
          <button type="button" class="si-copy" style="margin:0;" onclick="alCheckAll(true)">全員にチェック</button>
          <button type="button" class="si-copy" style="margin:0;" onclick="alCheckAll(false)">チェックを外す</button>
        </div>
      </div>
    </div>
    <div class="si-allow-form" style="margin-top:6px;">
      <b style="font-size:12.5px;">③</b>
      <select name="kind" id="alKind" required onchange="alKindChanged()">
        @foreach ($allowanceKinds as $k => $unit)
          <option value="{{ $k }}" @selected(old('kind') === $k)>{{ $k }}{{ $unit ? '（'.number_format($unit).'円）' : (in_array($k, $allowanceNeedsAmount, true) ? '（金額を入れる）' : '（基本報酬）') }}</option>
        @endforeach
      </select>
      <input type="date" name="date" id="alDate" required value="{{ old('date') }}" title="手当が付いた日">
      <input type="number" name="count" min="1" max="20" value="{{ old('count', 1) }}" style="width:60px;" title="回数"> 回
      <input type="number" name="amount" id="alAmount" min="0" max="200000" placeholder="1回の金額" value="{{ old('amount') }}" style="width:120px; display:none;">
      <input type="text" name="note" maxlength="200" placeholder="メモ（例：後泊／本能寺のレクチャー）" value="{{ old('note') }}" style="width:240px;">
      <button type="submit" class="si-copy" style="margin:0; font-weight:700;">＋ チェックした人に入れる</button>
    </div>
  </form>
  <div class="si-who" id="alHint" style="display:block; margin-top:4px;"></div>

  @if ($allowances)
    <table class="si-table" style="margin-top:10px;">
      {{-- 並び（2026-10-13 baba）：日付・案件・企業様名・スタッフ・手当（＋メモ）・金額・入れた人・消す --}}
      <tr><th>日付</th><th>案件</th><th class="hide-sp">企業様名</th><th>スタッフ</th><th>手当・メモ</th><th style="text-align:right;">金額</th><th class="hide-sp">入れた人</th><th></th></tr>
      @foreach ($allowances as $a)
        <tr class="al-row" data-name="{{ $a['staff'] }}" data-kana="{{ $staffKana[$a['staffId']] ?? '' }}">
          <td>{{ $a['md'] }}</td>
          <td>{{ $a['project'] !== '' ? $a['project'] : '—' }}</td>
          <td class="hide-sp">{{ $a['client'] }}</td>
          <td>{{ $a['staff'] }}</td>
          <td>
            @if ($a['auto'])<span class="si-auto" title="アサイン（前日設営・リハ日）から自動で入りました。いらなければ「消す」">自動</span>@endif
            @if ($a['switchable'])
              {{-- リハ・設営は種類を切り替えられる（4時間以上のリハなら1日分として数える・規定・2026-10-13）。 --}}
              <form method="POST" action="/staff-allowances/{{ $a['id'] }}/kind" style="display:inline;">@csrf
                <select name="kind" onchange="this.form.submit()" class="si-kind">
                  @foreach ($allowanceSwitchable as $sk)
                    <option value="{{ $sk }}" @selected($sk === $a['kind'])>{{ $sk }}</option>
                  @endforeach
                </select>
              </form>
            @else
              {{ $a['kind'] }}
            @endif
            {{ $a['count'] > 1 ? ' ×'.$a['count'] : '' }}
            {{-- メモはあとから書ける（自動で入った行にも・欄から離れたら保存・2026-10-13 baba）。 --}}
            <input type="text" class="si-note" maxlength="200" value="{{ $a['note'] }}" placeholder="✎ メモ" data-id="{{ $a['id'] }}" data-saved="{{ $a['note'] }}" onchange="alSaveNote(this)">
          </td>
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
  // 手当の入力欄（2026-10-13）。案件を選ぶ → その案件のスタッフがチェックで並ぶ → チェックした全員に同じ内容を入れる。
  // 案件に入っていない人は、名前を打って候補（datalist）から追加する（案件登録のセールス欄と同じ仕組み）。
  var AL_PROJECTS = @json($formProjects);
  var AL_STAFF = @json($formStaff);
  var AL_HINTS = @json($allowanceHints);
  var AL_NEEDS_AMOUNT = @json($allowanceNeedsAmount);
  function alEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]; }); }
  function alChip(id, name, role, checked) {
    var box = document.getElementById('alPeople');
    if (box.querySelector('input[value="' + id + '"]')) {   // もう並んでいる人はチェックだけ
      box.querySelector('input[value="' + id + '"]').checked = true;
      return;
    }
    var hint = box.querySelector('.si-who'); if (hint) hint.remove();
    var label = document.createElement('label');
    label.className = 'si-chip';
    label.innerHTML = '<input type="checkbox" name="staff_ids[]" value="' + alEsc(id) + '"' + (checked ? ' checked' : '') + '> '
      + alEsc(name) + (role ? '<span class="si-who" style="display:inline; margin-left:4px;">' + alEsc(role) + '</span>' : '');
    box.appendChild(label);
  }
  function alPickCase() {
    var id = document.getElementById('alCase').value;
    var p = AL_PROJECTS.find(function (x) { return x.id === id; });
    var box = document.getElementById('alPeople');
    box.innerHTML = '';
    if (!p) {
      box.innerHTML = '<span class="si-who" style="display:inline;">案件を選ぶと、その案件のスタッフがここに並びます（案件の無い手当は、下の名前の欄から追加してください）。</span>';
      return;
    }
    document.getElementById('alDate').value = p.ymd;
    p.staff.forEach(function (s) { alChip(s.id, s.name, s.role, false); });
    if (!p.staff.length) box.innerHTML = '<span class="si-who" style="display:inline;">この案件にスタッフはいません。</span>';
  }
  function alAddByName() {
    var input = document.getElementById('alAddName');
    var name = input.value.trim();
    var s = AL_STAFF.find(function (x) { return x.name === name; });
    if (!s) { alert('名簿に「' + name + '」さんが見つかりません。候補から選んでください。'); return; }
    alChip(s.id, s.name, '', true);
    input.value = '';
  }
  function alCheckAll(on) {
    document.querySelectorAll('#alPeople input[type=checkbox]').forEach(function (c) { c.checked = on; });
  }
  function alKindChanged() {
    var k = document.getElementById('alKind').value;
    var need = AL_NEEDS_AMOUNT.indexOf(k) >= 0;
    var amount = document.getElementById('alAmount');
    amount.style.display = need ? '' : 'none';
    amount.required = need;
    document.getElementById('alHint').textContent = AL_HINTS[k] || '';
  }
  // 送る前に、だれにも付いていない／名前の欄に打ったまま追加し忘れ、を知らせる。
  function alBeforeSubmit() {
    var pending = document.getElementById('alAddName').value.trim();
    if (pending) { alAddByName(); }
    var n = document.querySelectorAll('#alPeople input[type=checkbox]:checked').length;
    if (!n) { alert('手当を付けるスタッフにチェックしてください。'); return false; }
    return true;
  }
  // 一覧のメモ（欄から離れたら保存）。⚠ 失敗をだまって流さない＝赤枠＋お知らせ。
  function alSaveNote(input) {
    fetch('/staff-allowances/' + input.dataset.id + '/note', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
      body: JSON.stringify({ note: input.value })
    })
      .then(function (r) { if (!r.ok) throw new Error('通信エラー ' + r.status); input.classList.remove('ng'); input.dataset.saved = input.value; input.title = '✓ 保存しました'; })
      .catch(function (e) { input.classList.add('ng'); alert('メモを保存できませんでした（' + e.message + '）。もう一度入れてください。'); });
  }
  document.getElementById('alAddName').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); alAddByName(); } });
  alKindChanged();
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
  // 名前の比べ方：空白（全角・半角）を無視・英字の大小を無視・カタカナはひらがなにそろえる。
  // よみがな（名簿の name_kana）でも探せる（2026-10-15 baba要望）＝「やまだ」「ヤマダ」でも見つかる。
  function siNorm(s) {
    return String(s || '').replace(/[\s　]+/g, '').toLowerCase()
      .replace(/[ァ-ヶ]/g, function (c) { return String.fromCharCode(c.charCodeAt(0) - 0x60); });
  }
  function siFilter() {
    var only = document.getElementById('siOnlyOpen');
    var qEl = document.getElementById('siSearch');
    var q = siNorm(qEl ? qEl.value : '');
    document.querySelectorAll('tr.si-row, tr.al-row').forEach(function (tr) {
      var hide = (only && only.checked && tr.classList.contains('done'))
        || (q !== '' && siNorm(tr.dataset.name).indexOf(q) === -1 && siNorm(tr.dataset.kana).indexOf(q) === -1);
      tr.style.display = hide ? 'none' : '';
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
