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
  .t-tohoku { background: #5f8079; }
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

  /* ▼ 案件項目（時間・人数・備考）の編集。編集モードで読み取り表示を入力欄に差し替える。 */
  .pe-edit { display: none; }
  .acard.editing .pe-read { display: none; }
  .acard.editing .pe-edit { display: flex; align-items: center; gap: 3px; flex-wrap: wrap; }
  /* 空の項目は「編集モードのときだけ」出す（普段は今まで通り、値のある行だけ表示）。 */
  .acard:not(.editing) .pe-empty { display: none; }
  /* 編集モードでは進行チェックのチップをクリックで切り替えられる（見た目のヒント）。 */
  .acard.editing .chips .chip-ck { cursor: pointer; }
  .pe-in { font-size: 10.5px; padding: 1px 4px; border: 1px solid var(--line); border-radius: 5px;
    background: #fff; font-family: inherit; width: 52px; color: var(--ink); }
  .pe-in.num { width: 42px; }
  .pe-in.wide { width: 100%; }
  .pe-sep { color: #a89680; font-size: 10px; }

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
  <span class="lg"><span class="sw t-tohoku"></span>東北</span>
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

        // 各項目を積む。編集できる項目は 'edit'=>true＋'inputs'（欄の指定）を持つ＝編集モードで入力欄に切り替わる。
        // 空でも常に積み、非編集時は .pe-empty で隠す（上に詰まる）。人の割当（営業/物品/D）はここでは読み取り表示。
        $jisseki = [];
        if ($c['logo'] !== '') $jisseki[] = 'ロゴ:' . $c['logo'];
        if ($c['camera'] !== '') $jisseki[] = 'カメ:' . $c['camera'];
        if ($c['article'] !== '') $jisseki[] = '記事:' . $c['article'];
        if ($c['video'] !== '') $jisseki[] = '動画:' . $c['video'];

        $rows = [
          ['edit' => true, 'lbl' => '規模/営業',
            'read' => trim($c['scale'] . '　' . $c['sales']), 'empty' => ($c['scale'] === '' && $c['sales'] === ''),
            'inputs' => [['f' => 'scale', 'v' => $c['scale'], 'ph' => '規模', 'w' => 'wide']]],
          ['edit' => true, 'lbl' => '顧客', 'sep' => '／',
            'read' => $c['client'] . ($c['agency'] !== '' ? '（' . $c['agency'] . '）' : ''), 'empty' => ($c['client'] === '' && $c['agency'] === ''),
            'inputs' => [['f' => 'client', 'v' => $c['client'], 'ph' => '顧客'], ['f' => 'agency', 'v' => $c['agency'], 'ph' => '代理店']]],
          ['edit' => true, 'lbl' => '運営場所',
            'read' => $c['operationPlace'] . ($c['isMulti'] ? '（複数開催）' : ''), 'empty' => ($c['operationPlace'] === ''),
            'inputs' => [['f' => 'operation_place', 'v' => $c['operationPlace'], 'ph' => '運営場所', 'w' => 'wide'],
                         ['f' => 'is_multi', 'v' => ($c['isMulti'] ? '1' : '0'), 't' => 'select', 'opts' => ['0' => '単独', '1' => '複数開催']]]],
          ['edit' => true, 'lbl' => '配信', 'sep' => '／',
            'read' => trim($c['onlineTool'] . '　' . $c['broadcast']), 'empty' => ($c['onlineTool'] === '' && $c['broadcast'] === ''),
            'inputs' => [['f' => 'online_tool', 'v' => $c['onlineTool'], 'ph' => 'ツール'], ['f' => 'broadcast', 'v' => $c['broadcast'], 'ph' => '配信']]],
          ['edit' => true, 'lbl' => '集合/解散', 'sep' => '〜',
            'read' => ($c['meet'] !== '' ? $c['meet'] : '—') . ' 〜 ' . ($c['leave'] !== '' ? $c['leave'] : '—'), 'empty' => ($c['meet'] === '' && $c['leave'] === ''),
            'inputs' => [['f' => 'start_time', 'v' => $c['meet'], 'ph' => '集合'], ['f' => 'end_time', 'v' => $c['leave'], 'ph' => '解散']]],
          ['edit' => true, 'lbl' => '入/開/終', 'sep' => '/',
            'read' => ($c['enter'] !== '' ? $c['enter'] : '—') . '/' . ($c['evStart'] !== '' ? $c['evStart'] : '—') . '/' . ($c['evEnd'] !== '' ? $c['evEnd'] : '—'),
            'empty' => ($c['enter'] === '' && $c['evStart'] === '' && $c['evEnd'] === ''),
            'inputs' => [['f' => 'event_enter_time', 'v' => $c['enter'], 'ph' => '入'], ['f' => 'event_start_time', 'v' => $c['evStart'], 'ph' => '開'], ['f' => 'event_end_time', 'v' => $c['evEnd'], 'ph' => '終']]],
          ['edit' => true, 'lbl' => '客数/組',
            'read' => ($c['guests'] !== '' ? $c['guests'] . '名' : '') . ($c['teams'] !== '' ? ' ' . $c['teams'] . '組' : ''), 'empty' => ($c['guests'] === '' && $c['teams'] === ''),
            'inputs' => [['f' => 'guest_count', 'v' => $c['guests'], 't' => 'number', 'w' => 'num', 'ph' => '客', 'suf' => '名'], ['f' => 'team_count', 'v' => $c['teams'], 't' => 'number', 'w' => 'num', 'ph' => '組', 'suf' => '組']]],
          ['edit' => true, 'lbl' => '運営',
            'read' => ($c['need'] !== '' ? $c['need'] . '名' : ''), 'empty' => ($c['need'] === ''),
            'inputs' => [['f' => 'required_count', 'v' => $c['need'], 't' => 'number', 'w' => 'num', 'ph' => '人数', 'suf' => '名']]],
          ['edit' => true, 'lbl' => '運営方式',
            'read' => $c['staffRole'], 'empty' => ($c['staffRole'] === ''),
            'inputs' => [['f' => 'staff_role', 'v' => $c['staffRole'], 'ph' => '運営方式', 'w' => 'wide']]],
          ['edit' => true, 'lbl' => '音響',
            'read' => $c['audio'], 'empty' => ($c['audio'] === ''),
            'inputs' => [['f' => 'audio_equipment', 'v' => $c['audio'], 'ph' => '音響', 'w' => 'wide']]],
          ['edit' => true, 'lbl' => '会場住所',
            'read' => $c['location'], 'empty' => ($c['location'] === ''),
            'inputs' => [['f' => 'location', 'v' => $c['location'], 'ph' => '会場住所', 'w' => 'wide']]],
          ['edit' => true, 'lbl' => '集合形式', 'sep' => ' ',
            'read' => $c['assembly'] . ($c['alcohol'] !== '' ? ' 酒:' . $c['alcohol'] : ''), 'empty' => ($c['assembly'] === '' && $c['alcohol'] === ''),
            'inputs' => [['f' => 'assembly_type', 'v' => $c['assembly'], 'ph' => '集合形式'],
                         ['f' => 'alcohol', 'v' => $c['alcohol'], 't' => 'select', 'opts' => ['' => '酒—', '有' => '酒有', '無' => '酒無']]]],
          ['edit' => true, 'lbl' => '物品/ケータ',
            'read' => $c['goods'] . ($c['catering'] !== '' ? ' ' . $c['catering'] : ''), 'empty' => ($c['goods'] === '' && $c['catering'] === ''),
            'inputs' => [['f' => 'catering', 'v' => $c['catering'], 'ph' => 'ケータリング', 'w' => 'wide']]],
          ['edit' => true, 'lbl' => '移動',
            'read' => $c['transport'], 'empty' => ($c['transport'] === ''),
            'inputs' => [['f' => 'transport', 'v' => $c['transport'], 'ph' => '移動', 'w' => 'wide']]],
          ['edit' => true, 'lbl' => '実績公開', 'sep' => ' ',
            'read' => implode(' ', $jisseki), 'empty' => ($c['logo'] === '' && $c['camera'] === '' && $c['article'] === '' && $c['video'] === ''),
            'inputs' => [['f' => 'pub_logo', 'v' => $c['logo'], 'ph' => 'ロゴ'], ['f' => 'pub_camera', 'v' => $c['camera'], 'ph' => 'カメ'], ['f' => 'pub_article', 'v' => $c['article'], 'ph' => '記事'], ['f' => 'pub_video', 'v' => $c['video'], 'ph' => '動画']]],
          ['edit' => true, 'lbl' => '備考',
            'read' => $c['note'], 'empty' => ($c['note'] === ''),
            'inputs' => [['f' => 'note', 'v' => $c['note'], 'ph' => '備考', 'w' => 'wide']]],
        ];
        // 担当内訳は自動計算（読み取りのみ）。
        if ($c['roleDetail'] !== '') $rows[] = ['担当内訳', $c['roleDetail']];
        $anyPrep = $c['lineSent'] || $c['handover'] || $c['script'];
      @endphp
      <div class="acard"
           data-project="{{ $c['id'] }}"
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

        {{-- コンテンツ（見出し・常に出す）。見出しはコンテンツ名優先で表示、編集は案件名（project_name）を直す。 --}}
        <div class="arow hl first">
          <span class="lbl">案件</span>
          <span class="val pe-read">{{ $c['content'] }}</span>
          <span class="val pe-edit"><input class="pe-in wide" type="text" value="{{ $c['projectName'] }}" placeholder="案件名" title="案件名（入れると保存）" onchange="ecsSheetSaveProject(this,'project_name',this.value)"></span>
        </div>
        </div>{{-- /acard-sticky（ここまでが上に貼り付く部分） --}}

        {{-- 値のある項目だけ（編集できる項目は編集モードで入力欄に切り替わる） --}}
        @foreach ($rows as $r)
          @if (isset($r['edit']))
            <div class="arow pe-row {{ $r['empty'] ? 'pe-empty' : '' }}">
              <span class="lbl">{{ $r['lbl'] }}</span>
              <span class="val pe-read">{{ $r['read'] !== '' ? $r['read'] : '—' }}</span>
              <span class="val pe-edit">
                @foreach ($r['inputs'] as $ii => $in)
                  @if ($ii > 0 && ! empty($r['sep']))<span class="pe-sep">{{ $r['sep'] }}</span>@endif
                  @if (($in['t'] ?? 'text') === 'select')
                    <select class="pe-in {{ $in['w'] ?? '' }}" title="{{ $in['ph'] ?? $r['lbl'] }}（選ぶと保存）" onchange="ecsSheetSaveProject(this,'{{ $in['f'] }}',this.value)">
                      @foreach ($in['opts'] as $ov => $ol)
                        <option value="{{ $ov }}" {{ (string) $in['v'] === (string) $ov ? 'selected' : '' }}>{{ $ol }}</option>
                      @endforeach
                    </select>
                  @else
                    <input class="pe-in {{ $in['w'] ?? '' }}" type="{{ $in['t'] ?? 'text' }}" @if (($in['t'] ?? '') === 'number') min="0" @endif value="{{ $in['v'] }}" placeholder="{{ $in['ph'] ?? '' }}" title="{{ $in['ph'] ?? $r['lbl'] }}（入れると保存）" onchange="ecsSheetSaveProject(this,'{{ $in['f'] }}',this.value)">
                  @endif
                  @if (! empty($in['suf']))<span class="pe-sep">{{ $in['suf'] }}</span>@endif
                @endforeach
              </span>
            </div>
          @else
            <div class="arow"><span class="lbl">{{ $r[0] }}</span><span class="val">{{ $r[1] }}</span></div>
          @endif
        @endforeach

        {{-- 進行チェック（LINE/引継/台本）。普段は付いていれば表示、編集モードではクリックでオン/オフを保存。 --}}
        <div class="chips {{ $anyPrep ? '' : 'pe-empty' }}">
          <span class="chip-ck {{ $c['lineSent'] ? 'on' : '' }}" onclick="ecsSheetToggleChip(this,'prep_line_sent')">LINE</span>
          <span class="chip-ck {{ $c['handover'] ? 'on' : '' }}" onclick="ecsSheetToggleChip(this,'prep_handover')">引継</span>
          <span class="chip-ck {{ $c['script'] ? 'on' : '' }}" onclick="ecsSheetToggleChip(this,'prep_script')">台本</span>
        </div>
        @if ($c['opsSheet'] !== '')
          <div class="arow"><span class="lbl">運営シート</span><a class="val" href="{{ $c['opsSheet'] }}" target="_blank" rel="noopener">開く</a></div>
        @endif

        {{-- メンバー --}}
        <div class="acard-members">
          <div class="mhead"><span class="p">P</span><span>名前（割り当てメンバー）</span><span class="m-edit-btn" onclick="ecsSheetToggleEdit(this)">✎編集</span></div>
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

  // 案件の各項目（時間・人数・文字・はい/いいえ）を projects に保存する（送ったキーだけ更新）。
  function ecsSheetSaveProject(inp, field, value) {
    var card = inp.closest('.acard');
    var pid = card && card.getAttribute('data-project');
    if (!pid) return;
    fetch('/assign-sheet/project', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.ECS_CSRF, 'Accept': 'application/json' },
      body: JSON.stringify({ project_id: pid, field: field, value: value })
    })
      .then(function (r) { return r.json(); })
      .then(function (res) { if (!(res && res.ok)) alert('保存に失敗しました。' + (res && res.message ? '\n' + res.message : '')); })
      .catch(function () { alert('通信エラーで保存できませんでした。'); });
  }

  // 進行チェック（LINE/引継/台本）のチップを、編集モードのときだけクリックでオン/オフ保存する。
  function ecsSheetToggleChip(el, field) {
    var card = el.closest('.acard');
    if (!card || !card.classList.contains('editing')) return;   // 編集モード以外では誤操作防止で無反応
    var on = !el.classList.contains('on');
    el.classList.toggle('on', on);
    ecsSheetSaveProject(el, field, on ? '1' : '0');
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
