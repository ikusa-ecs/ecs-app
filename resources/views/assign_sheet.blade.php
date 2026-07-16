@extends('layouts.app')
@section('title', 'アサイン表')
@section('h1', 'アサイン表')
@php $active = 'assign_sheet'; @endphp

@push('head')
<style>
  /* ===== アサイン表（東京アサイン表そっくりの縦カード）専用スタイル ===== */

  /* 上部の操作バー（月切替・絞り込み・検索） */
  .sheet-controls {
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    background: var(--panel); border: 1px solid var(--line); border-radius: 12px;
    padding: 9px 14px; margin-bottom: 8px;
  }
  .sheet-controls select, .sheet-controls input[type="search"] {
    padding: 7px 10px; border: 1px solid var(--line); border-radius: 8px;
    font-size: 13px; font-family: inherit; background: #fff; color: var(--ink);
  }
  .sheet-controls .month-sel { font-size: 15px; font-weight: 700; min-width: 130px; }
  .sheet-controls label.chk { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--ink); cursor: pointer; }
  .sheet-controls .spacer { flex: 1; }
  .sheet-controls .count { font-size: 12.5px; color: #7a6a58; }

  /* 種別の色の見本（凡例） */
  .sheet-legend { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; margin: 0 2px 12px; font-size: 11.5px; color: #7a6a58; }
  .sheet-legend .lg { display: inline-flex; align-items: center; gap: 5px; }
  .sheet-legend .sw { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }

  /* 種別ごとの色（日付ヘッダー＆凡例で共通に使う） */
  .t-real   { background: #2f6fb3; }
  .t-long   { background: #244a86; }
  .t-online { background: #1f9d74; }
  .t-basho  { background: #7a52c9; }
  .t-tokyo  { background: #d9822b; }
  .t-help   { background: #0891b2; }
  .t-taiken { background: #be185d; }
  .t-other  { background: #8a7a66; }

  /* カードを横に並べる帯。高さに上限を付けて“この枠の中で”縦横スクロールさせる。
     こうすると各カードの頭（下の .acard-sticky）を枠の上端に貼り付けられる＝
     下のスタッフ名を見ようと下へスクロールしても、案件名が消えない。 */
  .sheet-scroll { overflow: auto; max-height: calc(100vh - 172px); padding-bottom: 8px; }
  /* align-items:stretch＝全カードの高さを一番背の高いカードにそろえる。
     こうすると背の低いカードも下端まで伸び、下へスクロールしても頭（案件名）が最後まで上に残る。 */
  .sheet-row { display: flex; gap: 8px; align-items: stretch; min-width: min-content; }

  /* 1案件＝縦カード（できるだけ詰める）。
     ※ sticky を効かせるため overflow:hidden は使わない（角丸は頭側で付ける）。 */
  .acard {
    flex: 0 0 auto; width: 202px;
    border: 1px solid var(--line); border-radius: 10px; background: #fff;
    box-shadow: 0 1px 2px rgba(60,45,30,.06);
  }

  /* 頭（日付）＋案件名を1つにまとめ、スクロール枠の上端に貼り付ける（sticky）。 */
  .acard-sticky {
    position: sticky; top: 0; z-index: 3; background: #fff;
    border-radius: 10px 10px 0 0;
    box-shadow: 0 3px 4px -2px rgba(60,45,30,.12);
  }

  /* カード頭：日付（種別で色が変わる）・NO・種別・充足 */
  .acard-head { padding: 6px 9px; color: #fff; border-radius: 10px 10px 0 0; }
  .acard-head .top { display: flex; align-items: baseline; gap: 6px; }
  .acard-head .no { font-size: 10px; opacity: .8; }
  .acard-head .date { font-size: 16px; font-weight: 800; line-height: 1.15; }
  .acard-head .tlabel { font-size: 10px; font-weight: 700; margin-left: auto; background: rgba(255,255,255,.24); padding: 0 6px; border-radius: 999px; }
  .acard-head .head-tags { display: flex; gap: 4px; flex-wrap: wrap; margin-top: 4px; }
  .htag { font-size: 10px; font-weight: 700; padding: 0 6px; border-radius: 999px; background: rgba(255,255,255,.22); }
  .htag.fill-ok { background: #2e9e6b; }
  .htag.fill-short { background: #e0692b; }
  .htag.extra { background: rgba(0,0,0,.32); }
  .htag.dtype { background: rgba(0,0,0,.30); }

  /* 項目行：ラベル＋値（詰める） */
  .arow { display: flex; gap: 5px; padding: 1px 9px; font-size: 11.5px; line-height: 1.32; }
  .arow .lbl { flex: 0 0 52px; color: #a89680; font-size: 10.5px; padding-top: 1px; }
  .arow .val { flex: 1; color: var(--ink); word-break: break-word; min-width: 0; }
  .arow.hl { padding-top: 3px; }
  .arow.hl .val { font-weight: 700; }
  .arow.first { padding-top: 5px; }
  .arow a.val { color: #2f6fb3; text-decoration: underline; }

  .chips { display: flex; gap: 4px; flex-wrap: wrap; padding: 3px 9px 1px; }
  .chip-ck { font-size: 10px; font-weight: 700; padding: 0 6px; border-radius: 5px; border: 1px solid var(--line); color: #b0a595; }
  .chip-ck.on { background: #e9f6ef; border-color: #bfe6d2; color: #2e9e6b; }

  /* メンバー表 */
  .acard-members { margin-top: 4px; border-top: 2px solid var(--line); }
  .acard-members .mhead { display: flex; font-size: 10px; font-weight: 800; color: #a89680;
    background: #f6f1ea; padding: 3px 9px; }
  .acard-members .mhead .p { flex: 0 0 46px; }
  .mrow { display: flex; align-items: center; padding: 2px 9px; font-size: 11.5px; border-top: 1px solid #f2ece3; }
  .mrow .p { flex: 0 0 46px; }
  .mrow .pos-badge { font-size: 10px; font-weight: 800; padding: 0 6px; border-radius: 5px; background: #eef2f7; color: #4a6484; }
  .mrow .pos-badge.d { background: #f3e9ff; color: #6b3fc0; }
  .mrow .pos-badge.none { background: #f1ece4; color: #bcae9c; }
  .mrow .nm { flex: 1; color: var(--ink); }
  .mrow .nm .emp { font-size: 9.5px; color: #b8875a; margin-left: 3px; }
  .mrow .st { font-size: 9.5px; font-weight: 700; padding: 0 5px; border-radius: 999px; }
  .mrow .st.kari { background: #fff3e0; color: #d9822b; }
  .mrow .mempty { color: #c9bdae; font-size: 11px; padding: 6px 9px; }
  /* メンバー行の担当/巡回（読み取り表示） */
  .mrow .m-tag { font-size: 9.5px; color: #8a7a66; margin-left: 4px; white-space: nowrap; }

  /* ▼ 編集モード（カードに .editing が付くと入力欄を出す。日別ボードと同じ「選ぶと保存」） */
  .mhead .m-edit-btn { margin-left: auto; font-size: 9.5px; font-weight: 700; color: #2f6fb3;
    cursor: pointer; background: #eaf1f8; border-radius: 5px; padding: 0 6px; }
  .acard.editing .mhead .m-edit-btn { background: #2f6fb3; color: #fff; }
  .mrow .m-edit { display: none; }
  .acard.editing .mrow .m-edit { display: inline-flex; }
  .acard.editing .mrow .pos-badge,
  .acard.editing .mrow .m-tag { display: none; }
  .mrow .m-role { font-size: 10px; padding: 1px 2px; border: 1px solid var(--line); border-radius: 5px;
    background: #fff; max-width: 46px; font-family: inherit; }
  .mrow .m-note { width: 70px; font-size: 10px; padding: 1px 4px; border: 1px solid var(--line);
    border-radius: 5px; font-family: inherit; margin-left: 4px; }
  .mrow .m-patrol { width: 34px; font-size: 10px; padding: 1px 3px; border: 1px solid var(--line);
    border-radius: 5px; font-family: inherit; margin-left: 3px; }

  .sheet-empty { padding: 40px; text-align: center; color: #a08a73; }
</style>
@endpush

@section('content')

@php
  // 未充足（メンバー数 < 必要人数）のカード数を数えて表示に使う。
  $shortCount = collect($cards)->filter(fn ($c) => $c['need_i'] > 0 && $c['filled'] < $c['need_i'])->count();
@endphp

<div class="sheet-controls">
  {{-- 月を選ぶと、その月の案件だけ表示（GETで開き直す＝確実で分かりやすい）。 --}}
  <form method="GET" action="/assign-sheet" style="margin:0;">
    <select name="month" class="month-sel" onchange="this.form.submit()">
      @forelse ($months as $m)
        <option value="{{ $m['value'] }}" @selected($m['value'] === $selectedMonth)>{{ $m['label'] }}</option>
      @empty
        <option value="">案件なし</option>
      @endforelse
    </select>
  </form>

  <input type="search" id="sheetSearch" placeholder="コンテンツ・顧客で絞り込み" oninput="ecsSheetFilter()" style="min-width:190px;">

  <label class="chk"><input type="checkbox" id="sheetShort" onchange="ecsSheetFilter()"> 人数が足りない案件だけ（{{ $shortCount }}）</label>

  <span class="spacer"></span>
  <span class="count"><b id="sheetShown">{{ count($cards) }}</b> / {{ count($cards) }} 件</span>
</div>

{{-- 日付ヘッダーの色の意味（種別）。 --}}
<div class="sheet-legend">
  <span class="lg"><span class="sw t-real"></span>リアル</span>
  <span class="lg"><span class="sw t-long"></span>リアルロング</span>
  <span class="lg"><span class="sw t-online"></span>オンライン</span>
  <span class="lg"><span class="sw t-basho"></span>場所貸し</span>
  <span class="lg"><span class="sw t-tokyo"></span>他拠点⇒東</span>
  <span class="lg"><span class="sw t-help"></span>ヘルプのみ</span>
  <span class="lg"><span class="sw t-taiken"></span>体験会</span>
  <span class="lg"><span class="sw t-other"></span>その他</span>
</div>

@if (count($cards) === 0)
  <div class="sheet-empty">この月に表示できる案件がありません。上のプルダウンで別の月を選んでください。</div>
@else
<div class="sheet-scroll">
  <div class="sheet-row" id="sheetRow">
    @foreach ($cards as $c)
      @php
        $short = $c['need_i'] > 0 && $c['filled'] < $c['need_i'];

        // 値のある項目だけを [ラベル, 値] で積む（空欄の行は出さず、上に詰める）。
        $rows = [];
        if ($c['scale'] !== '' || $c['sales'] !== '') $rows[] = ['規模/営業', trim($c['scale'] . '　' . $c['sales'])];
        if ($c['client'] !== '' || $c['agency'] !== '') $rows[] = ['顧客', $c['client'] . ($c['agency'] !== '' ? '（' . $c['agency'] . '）' : '')];
        if ($c['operationPlace'] !== '') $rows[] = ['運営場所', $c['operationPlace'] . ($c['isMulti'] ? '（複数開催）' : '')];
        if ($c['onlineTool'] !== '' || $c['broadcast'] !== '') $rows[] = ['配信', trim($c['onlineTool'] . '　' . $c['broadcast'])];
        if ($c['meet'] !== '' || $c['leave'] !== '') $rows[] = ['集合/解散', ($c['meet'] !== '' ? $c['meet'] : '—') . ' 〜 ' . ($c['leave'] !== '' ? $c['leave'] : '—')];
        if ($c['enter'] !== '' || $c['evStart'] !== '' || $c['evEnd'] !== '') $rows[] = ['入/開/終', ($c['enter'] !== '' ? $c['enter'] : '—') . '/' . ($c['evStart'] !== '' ? $c['evStart'] : '—') . '/' . ($c['evEnd'] !== '' ? $c['evEnd'] : '—')];
        if ($c['guests'] !== '' || $c['teams'] !== '') $rows[] = ['客数/組', ($c['guests'] !== '' ? $c['guests'] . '名' : '') . ($c['teams'] !== '' ? ' ' . $c['teams'] . '組' : '')];
        // 形式（イベント東(リアル)等）はヘッダーの色つきバッジに一本化＝行では運営人数だけ出す（baba 2026-07-16）。
        if ($c['need'] !== '') $rows[] = ['運営', $c['need'] . '名'];
        if ($c['staffRole'] !== '') $rows[] = ['運営方式', $c['staffRole']];
        if ($c['audio'] !== '') $rows[] = ['音響', $c['audio']];
        if ($c['location'] !== '') $rows[] = ['会場住所', $c['location']];
        if ($c['assembly'] !== '' || $c['alcohol'] !== '') $rows[] = ['集合形式', $c['assembly'] . ($c['alcohol'] !== '' ? ' 酒:' . $c['alcohol'] : '')];
        if ($c['goods'] !== '' || $c['catering'] !== '') $rows[] = ['物品/ケータ', $c['goods'] . ($c['catering'] !== '' ? ' ' . $c['catering'] : '')];
        if ($c['transport'] !== '') $rows[] = ['移動', $c['transport']];
        $jisseki = [];
        if ($c['logo'] !== '') $jisseki[] = 'ロゴ:' . $c['logo'];
        if ($c['camera'] !== '') $jisseki[] = 'カメ:' . $c['camera'];
        if ($c['article'] !== '') $jisseki[] = '記事:' . $c['article'];
        if ($c['video'] !== '') $jisseki[] = '動画:' . $c['video'];
        if ($jisseki) $rows[] = ['実績公開', implode(' ', $jisseki)];
        if ($c['note'] !== '') $rows[] = ['備考', $c['note']];
        if ($c['roleDetail'] !== '') $rows[] = ['担当内訳', $c['roleDetail']];
        $anyPrep = $c['lineSent'] || $c['handover'] || $c['script'];
      @endphp
      <div class="acard"
           data-search="{{ mb_strtolower($c['content'] . ' ' . $c['client'] . ' ' . $c['agency']) }}"
           data-short="{{ $short ? '1' : '0' }}">

        {{-- 頭＋案件名は sticky でスクロール枠の上端に残す（下のメンバーを見ても案件名が消えない）。 --}}
        <div class="acard-sticky">
        {{-- 頭：日付（種別で色が変わる）・NO・種別・充足 --}}
        <div class="acard-head t-{{ $c['typeKey'] }}">
          <div class="top">
            <span class="date">{{ $c['date'] !== '' ? $c['date'] : '日付未定' }}</span>
            <span class="no">No.{{ $c['no'] }}</span>
          </div>
          <div class="head-tags">
            {{-- 形式は入力したまま（イベント東(リアル)等）をバッジ表示。色は種別で自動（baba 2026-07-16）。 --}}
            @if ($c['format'] !== '')<span class="htag fmt">{{ $c['format'] }}</span>@endif
            @if ($c['dayType'] !== '本番')<span class="htag dtype">{{ $c['dayType'] }}</span>@endif
            @if ($c['category'] === '追加案件')<span class="htag extra">追加</span>@endif
            @if ($c['lodging'] !== '')<span class="htag">{{ $c['lodging'] }}</span>@endif
            @if ($c['need_i'] > 0)
              <span class="htag {{ $short ? 'fill-short' : 'fill-ok' }}">{{ $c['filled'] }}/{{ $c['need_i'] }}名</span>
            @else
              <span class="htag">{{ $c['filled'] }}名</span>
            @endif
          </div>
        </div>

        {{-- コンテンツ（見出し・常に出す） --}}
        <div class="arow hl first"><span class="lbl">案件</span><span class="val">{{ $c['content'] }}</span></div>
        </div>{{-- /acard-sticky（ここまでが上に貼り付く部分） --}}

        {{-- 値のある項目だけ --}}
        @foreach ($rows as $r)
          <div class="arow"><span class="lbl">{{ $r[0] }}</span><span class="val">{{ $r[1] }}</span></div>
        @endforeach

        {{-- 進行チェック（1つでも付いていれば出す） --}}
        @if ($anyPrep)
          <div class="chips">
            <span class="chip-ck {{ $c['lineSent'] ? 'on' : '' }}">LINE</span>
            <span class="chip-ck {{ $c['handover'] ? 'on' : '' }}">引継</span>
            <span class="chip-ck {{ $c['script'] ? 'on' : '' }}">台本</span>
          </div>
        @endif
        @if ($c['opsSheet'] !== '')
          <div class="arow"><span class="lbl">運営シート</span><a class="val" href="{{ $c['opsSheet'] }}" target="_blank" rel="noopener">開く</a></div>
        @endif

        {{-- メンバー --}}
        <div class="acard-members">
          <div class="mhead"><span class="p">P</span><span>名前（割り当てメンバー）</span>@if (count($c['members']))<span class="m-edit-btn" onclick="ecsSheetToggleEdit(this)">✎編集</span>@endif</div>
          @forelse ($c['members'] as $m)
            <div class="mrow" data-project="{{ $c['id'] }}" data-staff="{{ $m['staffId'] }}" data-status="{{ $m['status'] ?: '仮' }}">
              <span class="p">
                <span class="pos-badge {{ $m['roleCode'] === 'D' ? 'd' : ($m['pos'] === '—' ? 'none' : '') }}">{{ $m['pos'] }}</span>
                <select class="m-edit m-role" title="役割（選ぶと保存）" onchange="ecsSheetSave(this,'role',this.value)">
                  <option value="">—</option>
                  @foreach ($roleOptions as $code => $label)
                    <option value="{{ $code }}" {{ $m['roleCode'] === $code ? 'selected' : '' }}>{{ $code }}</option>
                  @endforeach
                </select>
              </span>
              <span class="nm">{{ $m['name'] }}@if($m['type'] === 'emp')<span class="emp">社員</span>@endif
                @if ($m['note'] !== '' || $m['patrol'] !== null)<span class="m-tag">· {{ $m['note'] }}@if ($m['patrol'] !== null) 巡回{{ $m['patrol'] }}@endif</span>@endif
                <input class="m-edit m-note" list="sheetNoteOpts" placeholder="担当" value="{{ $m['note'] }}" title="担当メモ（軍師/サポ等・入力で保存）" onchange="ecsSheetSave(this,'note',this.value)">
                <input class="m-edit m-patrol" type="number" min="0" placeholder="巡" value="{{ $m['patrol'] ?? '' }}" title="巡回数（入力で保存）" onchange="ecsSheetSave(this,'patrol',this.value)">
              </span>
              @if ($m['status'] === '仮')<span class="st kari">仮</span>@endif
            </div>
          @empty
            <div class="mempty">まだアサインされていません</div>
          @endforelse
        </div>

      </div>
    @endforeach
  </div>
</div>
@endif

{{-- 担当メモ入力の候補（軍師/サポ 等）。編集モードの入力欄が参照する。 --}}
<datalist id="sheetNoteOpts">
  @foreach ($noteOptions as $opt)<option value="{{ $opt }}">@endforeach
</datalist>

@endsection

@push('scripts')
<script>
  // 担当/巡回/役割の保存先（日別ボードと同じ quickToggle を再利用）とCSRFトークン。
  window.ECS_QUICK_URL = '/entries/assign';
  window.ECS_CSRF = @json(csrf_token());
</script>
@verbatim
<script>
  // 案件カードを「編集モード」に切り替える（役割/担当/巡回の入力欄を出す）。
  function ecsSheetToggleEdit(btn) {
    var card = btn.closest('.acard');
    if (!card) return;
    card.classList.toggle('editing');
    btn.textContent = card.classList.contains('editing') ? '完了' : '✎編集';
  }
  // 変更したメンバーの役割/担当/巡回を assignments に保存する（送ったキーだけ更新）。
  function ecsSheetSave(inp, field, value) {
    var row = inp.closest('.mrow');
    if (!row) return;
    var body = {
      project_id: row.getAttribute('data-project'),
      staff_id: row.getAttribute('data-staff'),
      action: 'assign',
      status: row.getAttribute('data-status') || '仮'
    };
    body[field] = value;
    fetch(window.ECS_QUICK_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF, 'Accept': 'application/json' },
      body: JSON.stringify(body)
    })
      .then(function (r) { return r.json(); })
      .then(function (res) { if (!(res && res.ok)) alert('保存に失敗しました。' + (res && res.message ? '\n' + res.message : '')); })
      .catch(function () { alert('通信エラーで保存できませんでした。'); });
  }

  // 検索ボックス（コンテンツ・顧客）と「人数が足りない案件だけ」で、カードを出し分ける。
  // サーバーで作ったカードを画面側で見せ隠しするだけ（開き直し不要で軽い）。
  function ecsSheetFilter() {
    var q = (document.getElementById('sheetSearch').value || '').trim().toLowerCase();
    var shortOnly = document.getElementById('sheetShort').checked;
    var cards = document.querySelectorAll('#sheetRow .acard');
    var shown = 0;
    cards.forEach(function (el) {
      var okText = !q || (el.getAttribute('data-search') || '').indexOf(q) >= 0;
      var okShort = !shortOnly || el.getAttribute('data-short') === '1';
      var show = okText && okShort;
      el.style.display = show ? '' : 'none';
      if (show) shown++;
    });
    var s = document.getElementById('sheetShown');
    if (s) s.textContent = shown;
  }
</script>
@endverbatim
@endpush
