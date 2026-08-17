@extends('layouts.app')
@section('title', '案件登録 / 編集')
@section('h1', '案件の登録 / 編集')
@php($active = 'project_form')

@push('head')
@verbatim
<style>
    /* 案件登録/編集モック専用スタイル */

    /* セクション見出し */
    .sec-title {
      font-size: 13px; font-weight: 700; color: var(--brand-dark);
      margin: 4px 0 14px; padding-bottom: 6px; border-bottom: 2px solid var(--brand-soft);
    }
    .panel + .panel { margin-top: 20px; }

    /* フォームを2カラムに（共通の .form-grid を利用しつつ全幅指定を追加） */
    .form-grid { row-gap: 4px; }
    .form-grid .full { grid-column: 1 / -1; }

    /* 短い項目を横3列・4列に並べる */
    .triple { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 18px; align-items: start; }
    .quad { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 18px; align-items: start; }
    .triple .form-row, .quad .form-row { margin-bottom: 0; }

    /* 屋内外などの横並びラジオ */
    .radio-row { display: flex; gap: 18px; padding-top: 4px; }
    .radio-row label { font-weight: 400; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
    .check-row { display: flex; align-items: center; gap: 8px; padding-top: 6px; }
    .check-row label { font-weight: 400; cursor: pointer; }

    /* 現場種別の自動判定ヒント */
    .auto-hint {
      background: var(--brand-soft); border: 1px solid #e6cdb8; color: var(--brand-dark);
      border-radius: 8px; padding: 8px 12px; font-size: 12.5px; margin-top: 6px;
      display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    }
    .auto-hint b { font-weight: 700; }
    .auto-hint a { font-weight: 600; cursor: pointer; }

    /* 日程種別の展開ボックス */
    .sub-msg { font-size: 12.5px; color: var(--muted); padding-top: 8px; }
    .expand-box {
      display: none; margin-top: 12px; padding: 14px;
      background: #faf6ee; border: 1px dashed var(--line); border-radius: 10px;
    }
    .expand-box.open { display: block; }

    /* （ARENA時に欄を隠す機能は baba要望で廃止。欄は常に表示し、専用パネル arenaBox だけ追加で出す） */

    /* 退避中（いまは表示中。隠したいときは display: block を display: none に戻す） */
    .parked { display: block; }

    /* セールス担当（自動表示） */
    .readonly-field {
      padding: 9px 11px; background: #f3ece0; border: 1px solid var(--line);
      border-radius: 8px; font-size: 14px; color: var(--muted);
    }

    /* 下部アクションバー */
    .form-actions {
      position: sticky; bottom: 0;
      background: var(--panel); border: 1px solid var(--line); border-radius: 12px;
      box-shadow: 0 -2px 12px rgba(16,24,40,0.06);
      padding: 14px 20px; margin-top: 20px;
      display: flex; align-items: center; gap: 12px;
    }
    .form-actions .spacer { flex: 1; }

    /* 切替タブ（1件ずつ / CSV取込） */
    .mode-tabs { display: flex; gap: 6px; margin-bottom: 20px; }
    .mode-tabs a {
      padding: 9px 18px; border: 1px solid var(--line); border-radius: 8px;
      background: #fff; color: var(--muted); font-size: 13.5px; font-weight: 600;
    }
    .mode-tabs a:hover { background: #f3ece0; text-decoration: none; }
    .mode-tabs a.active { background: var(--brand); border-color: var(--brand); color: #fff; }

    /* コンテンツのタグ入力（検索付き複数選択） */
    .tag-input { position: relative; border: 1px solid var(--line); border-radius: 8px; padding: 6px 8px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; background: #fff; }
    .tag-input .tag { background: var(--brand-soft); color: var(--brand-dark); border-radius: 999px; padding: 3px 10px; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; }
    .tag-input .tag b { cursor: pointer; }
    .tag-input input { border: none; outline: none; flex: 1; min-width: 140px; font-size: 14px; font-family: inherit; padding: 4px; }
    .suggest { display: none; position: absolute; left: 0; right: 0; top: 100%; z-index: 20; background: #fff; border: 1px solid var(--line); border-radius: 8px; box-shadow: var(--shadow); max-height: 220px; overflow-y: auto; margin-top: 4px; }
    .suggest.open { display: block; }
    .suggest .item { padding: 8px 12px; cursor: pointer; font-size: 13.5px; }
    .suggest .item:hover { background: var(--brand-soft); }
    .suggest .add-new { color: var(--brand); font-weight: 600; }
    .suggest .add-new-note { display: block; color: #6b7280; font-weight: 400; font-size: 11.5px; margin-top: 2px; }
    /* 「この案件だけで使う」を選んだコンテンツ＝タグに小さく印を付けて区別する */
    .tag .oneoff-mark {
      display: inline-block; margin-left: 5px; padding: 0 4px; border-radius: 4px;
      background: #fde68a; color: #7a5200; border: 1px solid #e0b84a;
      font-size: 10px; font-weight: 700; vertical-align: 1px;
    }

    /* ===== M-2 入力必須の3段階色分け ===== */
    /* 凡例 */
    .req-legend {
      display: flex; gap: 18px; flex-wrap: wrap; align-items: center;
      background: #faf6ee; border: 1px solid var(--line); border-radius: 10px;
      padding: 10px 14px; margin-bottom: 18px; font-size: 12.5px; color: var(--brand-dark);
    }
    .req-legend .lg { display: inline-flex; align-items: center; gap: 7px; }
    .req-legend .sw { width: 16px; height: 16px; border-radius: 4px; border: 1px solid var(--line); }
    .req-legend .sw { border-width: 2px; }
    .req-legend .sw.red { background: #fcd6d6; border-color: var(--danger); }
    .req-legend .sw.yellow { background: #fbe3b6; border-color: var(--warn); }
    .req-legend .sw.white { background: #fff; border-color: var(--line); }

    /* フィールドの状態色（input/select/textarea/タグ枠に付与）＝目立つように強め */
    .need-red { border: 2px solid var(--danger) !important; background: #fcd6d6 !important; box-shadow: 0 0 0 3px rgba(220,38,38,.18) !important; }
    .need-yellow { border: 2px solid var(--warn) !important; background: #fbe3b6 !important; box-shadow: 0 0 0 3px rgba(217,119,6,.15) !important; }

    /* ラベル横の必須／後で必要マーク */
    .req-mark { font-size: 11px; font-weight: 700; padding: 1px 7px; border-radius: 999px; margin-left: 6px; vertical-align: middle; }
    .req-mark.red { background: var(--danger-soft); color: #b91c1c; }
    .req-mark.yellow { background: var(--warn-soft); color: #b45309; }

    /* 未定チェックの行 */
    .tbd-row { display: flex; align-items: center; gap: 6px; margin-top: 7px; font-size: 12.5px; color: var(--muted); }
    .tbd-row label { font-weight: 400; cursor: pointer; }
    /* 未定にした欄（休み中）を分かりやすく薄く */
    [data-need]:disabled { opacity: .6; }
    .contentBox-tbd { opacity: .6; }

    /* 運動会のときの注意（種目を備考へ）＝赤太文字で目立たせる */
    .undokai-note {
      color: var(--danger); font-weight: 800; font-size: 14px; line-height: 1.6;
      background: #fde8e8; border: 2px solid var(--danger); border-radius: 10px;
      padding: 10px 14px; margin-bottom: 14px;
    }
    .undokai-note u { text-underline-offset: 2px; }

    /* M-7 危険日ヒント（開催日の下） */
    .danger-hint { margin-top: 8px; font-size: 12.5px; line-height: 1.6; padding: 8px 11px; border-radius: 8px;
      background: var(--brand-soft); border: 1px solid var(--line); color: var(--muted); }
    .danger-hint.warn { background: #fde8e8; border-color: var(--danger); color: #b91c1c; font-weight: 600; }
    .danger-hint .dh-reasons { margin: 4px 0 0; padding-left: 18px; font-weight: 600; }

    /* ===== スマホ（狭い画面）で案件を登録できるようにする =====
       この画面はPC前提で作ってあり、入力欄が横2列・短い欄はさらに横3〜4列に
       並ぶため、スマホでは1つ1つの欄が細くなりすぎて入力できなかった。
       狭いときは全部を縦1列に落とし、下の操作バーも押しやすい形に変える。 */
    @media (max-width: 720px) {
      /* 入力欄はすべて縦1列に。横に細く並ぶより、縦に長いほうがスマホでは扱いやすい。 */
      .form-grid { grid-template-columns: 1fr; }
      .triple { grid-template-columns: 1fr; gap: 0; }
      /* 時刻・人数などの短い欄だけは2列。1列にすると縦に長くなりすぎるため。 */
      .quad { grid-template-columns: 1fr 1fr; gap: 0 12px; }
      .triple .form-row, .quad .form-row { margin-bottom: 16px; }

      /* iPhoneは文字が16px未満の入力欄をタップすると勝手に拡大するので、
         スマホのときだけ16pxにして拡大を止める（拡大されると位置がずれて操作しづらい）。 */
      .form-row input[type="text"],
      .form-row input[type="number"],
      .form-row input[type="date"],
      .form-row input[type="time"],
      .form-row input[type="email"],
      .form-row select,
      .form-row textarea,
      .tag-input input { font-size: 16px; }

      /* 下の操作バー：横一列だとボタンがはみ出すので、縦積みで全幅にする。
         スマホでは画面に貼り付け（sticky）をやめてフォームの最後に置く。
         ボタン3〜4個を縦に並べると画面の1/3が埋まり、入力欄が隠れてしまうため。
         並びは上から「キャンセル →（次の日程）→ 下書き → 確定」。
         画面の一番下＝指が当たりやすい位置に「確定」を置く。
         うっかり触っても、消えてしまうキャンセルより保存のほうが安全なため。 */
      .form-actions {
        position: static;
        flex-direction: column; align-items: stretch; gap: 8px;
        padding: 12px 14px;
      }
      .form-actions .spacer { display: none; }
      .form-actions .btn { width: 100%; text-align: center; padding: 13px 16px; }

      /* 切替タブ（1件ずつ / CSV取込）は2つで折り返せるように */
      .mode-tabs { flex-wrap: wrap; }
      .mode-tabs a { flex: 1; text-align: center; }

      /* 横並びのラジオ・凡例は折り返す */
      .radio-row { flex-wrap: wrap; gap: 10px 18px; }
      .req-legend { gap: 8px 14px; padding: 10px 12px; }
    }
</style>
@endverbatim
@endpush

@section('content')
<form id="projForm" method="POST" action="/project-form">
@csrf
<input type="hidden" name="content_names" id="contentNamesField">
{{-- 「この案件だけで使う」を選んだコンテンツ名。ここに入っている名前は台帳に登録しない。 --}}
<input type="hidden" name="oneoff_content_names" id="oneoffNamesField">
<input type="hidden" name="intent" id="intentField">
{{-- 編集モードのとき、対象の案件IDを一緒に送る（来ていれば store() は上書き更新する） --}}
<input type="hidden" name="project_id" id="projectIdField" value="{{ $editProject['id'] ?? '' }}">
@if(!empty($editProject))
<div class="mock-note" style="background:#eef6ff;border-color:#bcd8f0;color:#1d4ed8;">
  ✎ 既存案件「{{ $editProject['id'] }}」を編集しています。内容を直して「確定」を押すと、この案件に上書き保存されます。
</div>
@endif
{{-- CSV取込のエラー行から来たときの案内（JSで表示）。 --}}
<div class="mock-note" id="csvPrefillNote" style="display:none;background:#fff7e6;border-color:#f0d9a8;color:#b45309;"></div>
@verbatim
      <!-- 1件ずつ / CSV取込 の切替タブ -->
      <div class="mode-tabs">
        <a class="active" href="/project-form">✎ 1件ずつ入力</a>
        <a href="/project-import">⬆ CSVで一括取込</a>
      </div>

      <div class="mock-note">このフォームは新規登録と既存案件の編集で共通です。「確定」または「下書きとして保存」を押すと、実際にデータベースへ保存されます。一覧の「編集」から開くと、登録済みの内容が各欄に入った状態で開きます。</div>

      <!-- サンプル入力（動きの確認用） -->
      <p class="muted" style="font-size:12.5px; margin:0 0 16px;">
        動きを確認したい場合は
        <a onclick="loadFormSample()" style="cursor:pointer; font-weight:600;">サンプルで入力してみる</a>
        を押すと、見本の内容が各欄に入ります（危険日になる日付を入れているので、保存時に警告が出ます）。
      </p>

      <!-- M-2 入力欄の色の意味（凡例） -->
      <div class="req-legend">
        <span style="font-weight:700;">入力欄の色：</span>
        <span class="lg"><span class="sw red"></span> 赤＝入力必須（まだ空）</span>
        <span class="lg"><span class="sw yellow"></span> 黄＝後で必要（今は未定でOK）</span>
        <span class="lg"><span class="sw white"></span> 白＝入力済み</span>
      </div>

      <!-- ===== 基本情報（アサイン表の並び順に合わせて再構成） ===== -->
      <div class="panel">
        <div class="sec-title">基本情報</div>
        <div class="form-grid">

          <!-- 1. 区分 -->
          <div class="form-row full">
            <label>区分</label>
            <div class="radio-row">
              <label><input type="radio" name="addtl" value="通常案件" onchange="onAddtlChange()"> 通常案件</label>
              <label><input type="radio" name="addtl" value="追加案件" onchange="onAddtlChange()"> 追加案件</label>
            </div>
            <div class="auto-hint" id="addtlNote"></div>
          </div>

          <!-- 1.5 toC -->
          <div class="form-row full">
            <label>toC</label>
            <div class="check-row">
              <input type="checkbox" id="isToc" name="is_toc">
              <label for="isToc">toCの案件</label>
            </div>
            <div class="hint">toCの案件のときにチェックします。未チェック＝toB扱い。</div>
          </div>

          <!-- 2. 確度（ヨミ） ｜ スタッフ募集（横並び） -->
          <div class="form-row">
            <label>確度（ヨミ）</label>
            <div class="radio-row" style="flex-wrap:wrap;">
              <label><input type="radio" name="yomi" value="確定" onchange="toggleYomi()"> 確定</label>
              <label><input type="radio" name="yomi" value="Aヨミ" checked onchange="toggleYomi()"> Aヨミ</label>
              <label><input type="radio" name="yomi" value="Bヨミ" onchange="toggleYomi()"> Bヨミ</label>
              <label><input type="radio" name="yomi" value="Cヨミ" onchange="toggleYomi()"> Cヨミ</label>
            </div>
            <div class="expand-box" id="yomiBox">
              <div class="form-row" style="margin-bottom:0;">
                <label>確定見込み時期（いつ頃決まりそうか）</label>
                <input type="text" name="yomi_expected" placeholder="例）7月上旬　／　7/15のMTGで決定　など">
              </div>
            </div>
          </div>
          <div class="form-row">
            <label>スタッフ募集</label>
            <div class="check-row">
              <input type="checkbox" id="noRecruit" name="noRecruit">
              <label for="noRecruit">募集しない（メンバー募集なし）</label>
            </div>
            <div class="hint">通常は募集します。すでにメンバーが決まっている等で募集が不要なときだけチェックを入れてください。</div>
          </div>

          <!-- 3. 開催日 ｜ 宿泊 -->
          <div class="form-row">
            <label>開催日<span class="req-mark red">必須</span></label>
            <input type="date" id="startDate" name="start_date" data-need="req">
            <div class="tbd-row">
              <input type="checkbox" id="dateTbd" class="tbd-check" data-tbd-for="startDate" data-memo="dateMemo">
              <label for="dateTbd">日付未定（まだふわっとしている）</label>
            </div>
            <div class="form-row" id="dateMemo" style="display:none; margin-top:8px; margin-bottom:0;">
              <label>いつ頃？</label>
              <input type="text" placeholder="例）7月上旬　／　夏ごろ　／　7月のどこか">
            </div>
            <!-- M-7 危険日（高負荷日）のヒント。開催日を選ぶとその日の混み具合を表示 -->
            <div id="dangerHint" class="danger-hint" style="display:none;"></div>
          </div>
          <div class="form-row non-arena">
            <label>宿泊<span class="req-mark yellow">必須</span></label>
            <select name="lodging" data-need="later">
              <option value="" selected>未定</option>
              <option>無</option>
              <option>前泊有</option>
              <option>一部前泊有</option>
              <option>後泊あり</option>
              <option>前後泊あり</option>
            </select>
          </div>

          <!-- 4. 案件名（コンテンツ） -->
          <div class="form-row full">
            <label>案件名（コンテンツ）<span class="req-mark red">必須</span></label>
            <div class="tag-input" id="contentBox" data-need="req">
              <span class="tags" id="contentTags"></span>
              <input type="text" id="contentSearch" placeholder="入力して検索（複数選べます）" autocomplete="off">
              <div class="suggest" id="contentSuggest"></div>
            </div>
            <div class="hint">複数のコンテンツを行う場合は続けて選べます。一覧にないものは入力して「＋追加」。選んだコンテンツが案件名になります。</div>
            <div class="tbd-row">
              <input type="checkbox" id="contentTbd" class="tbd-check" data-tbd-for="contentBox">
              <label for="contentTbd">コンテンツ未定（まだ決まっていない）</label>
            </div>
            <div class="auto-hint" id="catNote" style="display:none;"></div>
            <!-- コンテンツに「運動会」が含まれるときだけ出す注意（案件名のすぐ下にも表示） -->
            <div id="undokaiNoteTop" class="undokai-note" style="display:none;margin-top:8px;">⚠ 運動会が選ばれています。<u>種目を備考に入力してください。</u></div>
          </div>

          <!-- 5. 案件規模 ｜ 営業担当 -->
          <div class="form-row non-arena">
            <label>案件規模</label>
            <div class="radio-row" style="flex-wrap:wrap;">
              <label><input type="radio" name="scale" value="大型"> 大型</label>
              <label><input type="radio" name="scale" value="中型"> 中型</label>
              <label><input type="radio" name="scale" value="小型" checked> 小型</label>
              <label><input type="radio" name="scale" value="該当なし"> 該当なし</label>
            </div>
          </div>
          <div class="form-row">
            <label>営業担当<span class="req-mark red">必須</span></label>
            <select id="salesOwnerSel" name="sales_owner" data-need="req" onchange="onSalesChange()">
              <option value="">（選択してください）</option>
              <option value="__other__">その他（直接入力）</option>
            </select>
            <input type="text" id="salesOwnerOther" data-need="req" autocomplete="off" placeholder="社員名を入力" style="display:none;margin-top:8px;">
            <div class="hint">社員マスタ（社員）から選びます。既定はログイン中の社員です。一覧にない方は「その他（直接入力）」を選ぶと手入力できます。</div>
          </div>

          <!-- 登録拠点：この案件がどの拠点のものか（全拠点運用・設計書19.2）。
               ここは @verbatim 区間なので中身はJSで入れる（window.ECS_OFFICES / ECS_DEFAULT_OFFICE）。 -->
          <div class="form-row">
            <label>登録拠点</label>
            <select id="officeSel" name="office"></select>
            <div class="hint">この案件を担当する拠点です。既定はあなたの拠点になります。（全拠点で使うときに、拠点別の集計や他拠点への依頼の基準になります）</div>
          </div>

          <!-- 6. 実施形態 -->
          <div class="form-row full">
            <label>実施形態</label>
            <select id="format" name="format" onchange="onFormatChange()">
              <option>リアル</option>
              <option>リアルロング</option>
              <option>オンライン</option>
              <option>ARENA場所貸し</option>
              <option>体験会</option>
            </select>
            <div class="hint">案件の形態を選びます（拠点は上の「登録拠点」で持ちます）。「オンライン」を選ぶとツールが選べます。リアルロング＝集合〜解散の拘束が9時間を超える案件。</div>
            <div class="expand-box" id="toolBox">
              <div class="form-row" style="margin-bottom:0;">
                <label>オンラインツール</label>
                <div class="radio-row" style="flex-wrap:wrap;">
                  <label><input type="radio" name="onlineTool" value="zoom" checked> zoom</label>
                  <label><input type="radio" name="onlineTool" value="Teams"> Teams</label>
                  <label><input type="radio" name="onlineTool" value="webex"> webex</label>
                  <label><input type="radio" name="onlineTool" value="meet"> meet</label>
                  <label><input type="radio" name="onlineTool" value="rebako"> rebako</label>
                </div>
              </div>
            </div>
            <div class="expand-box" id="locationBox">
              <div class="form-row" style="margin-bottom:0;">
                <label>対象の拠点（複数選べます）</label>
                <div class="radio-row" style="flex-wrap:wrap;">
                  <label><input type="checkbox" name="baseLocation[]" value="東京"> 東京</label>
                  <label><input type="checkbox" name="baseLocation[]" value="大阪"> 大阪</label>
                  <label><input type="checkbox" name="baseLocation[]" value="名古屋"> 名古屋</label>
                  <label><input type="checkbox" name="baseLocation[]" value="福岡"> 福岡</label>
                  <label><input type="checkbox" name="baseLocation[]" value="北海道"> 北海道</label>
                </div>
                <div class="hint">「他拠点」がからむ案件で、どの拠点が対象かを選びます。</div>
              </div>
            </div>
            <div class="expand-box" id="arenaBox">
              <div class="hint" style="margin-top:0;">ARENA場所貸しのとき、IKUSA側で対応する項目を「あり／なし」で選びます。</div>
              <div class="form-row">
                <label>前日設営</label>
                <div class="radio-row">
                  <label><input type="radio" name="arenaSetupPrev" value="あり"> あり</label>
                  <label><input type="radio" name="arenaSetupPrev" value="なし" checked> なし</label>
                </div>
              </div>
              <div class="form-row">
                <label>照明設営</label>
                <div class="radio-row">
                  <label><input type="radio" name="arenaLightSetup" value="あり"> あり</label>
                  <label><input type="radio" name="arenaLightSetup" value="なし" checked> なし</label>
                </div>
              </div>
              <div class="form-row">
                <label>MCアサイン</label>
                <div class="radio-row">
                  <label><input type="radio" name="arenaMc" value="あり"> あり</label>
                  <label><input type="radio" name="arenaMc" value="なし" checked> なし</label>
                </div>
              </div>
              <div class="form-row">
                <label>当日音響照明スタッフ（IKUSA側）</label>
                <div class="radio-row">
                  <label><input type="radio" name="arenaAvStaff" value="あり"> あり</label>
                  <label><input type="radio" name="arenaAvStaff" value="なし" checked> なし</label>
                </div>
              </div>
              <div class="form-row">
                <label>レイアウト作成</label>
                <div class="radio-row">
                  <label><input type="radio" name="arenaLayout" value="あり"> あり</label>
                  <label><input type="radio" name="arenaLayout" value="なし" checked> なし</label>
                </div>
              </div>
              <div class="form-row">
                <label>配信・中継</label>
                <div class="radio-row">
                  <label><input type="radio" name="arenaBroadcast" value="あり"> あり</label>
                  <label><input type="radio" name="arenaBroadcast" value="なし" checked> なし</label>
                </div>
              </div>
              <div class="form-row" style="margin-bottom:0;">
                <label>食事</label>
                <div class="radio-row">
                  <label><input type="radio" name="arenaMeal" value="あり"> あり</label>
                  <label><input type="radio" name="arenaMeal" value="なし" checked> なし</label>
                </div>
              </div>
            </div>
          </div>

          <!-- 7. クライアント ｜ 代理店名 -->
          <div class="form-row">
            <label>クライアント（正式名称）<span class="req-mark yellow">必須</span></label>
            <input type="text" id="client" name="client" data-need="later" placeholder="例）〇〇株式会社">
            <div class="hint">正式名称で記載ください。</div>
            <!-- リピート（常連）のお客様なら、ここに過去案件の履歴を控えめに出す（入力/フォーカスアウトで自動照会）。 -->
            <div class="hint repeat-note" id="clientRepeatNote" style="display:none;"></div>
          </div>
          <div class="form-row">
            <label>代理店名（任意）</label>
            <input type="text" name="agency" placeholder="例）□□広告 ※代理店を挟む場合">
            <div class="hint">代理店を挟む場合に入力します。直接取引なら空欄でOKです。</div>
          </div>

          <!-- 8. 複数案件 ｜ 配信種別 → 日程種別（近くに配置） -->
          <div class="form-row">
            <label>複数案件（同じ企業で複数日程）</label>
            <div class="radio-row">
              <label><input type="radio" name="multi" value="あり" onchange="onMultiChange()"> あり</label>
              <label><input type="radio" name="multi" value="なし" checked onchange="onMultiChange()"> なし</label>
            </div>
            <div class="hint">「あり」にすると、保存後に同じ内容をコピーして次の日程を登録できます（違う所だけ直す）。</div>
          </div>
          <div class="form-row non-arena">
            <label>配信種別</label>
            <div class="radio-row" style="flex-wrap:wrap;">
              <label><input type="radio" name="broadcast" value="なし" checked> なし</label>
              <label><input type="radio" name="broadcast" value="配信"> 配信</label>
              <label><input type="radio" name="broadcast" value="中継"> 中継</label>
            </div>
          </div>
          <div class="form-row full non-arena">
            <label>日程種別</label>
            <div class="check-row">
              <input type="checkbox" id="hasSub" name="has_sub" onchange="toggleSub()">
              <label for="hasSub">予備日・リハ日として登録する（本番案件に紐づけます）</label>
            </div>
            <div class="sub-msg" id="subMsg">チェックしない場合、この案件は <b>「本番」</b> として登録されます。</div>

            <div class="expand-box" id="subBox">
              <div class="form-grid">
                <div class="form-row">
                  <label>種別</label>
                  <select name="date_type_sub" id="dateTypeSub">
                    <option>予備日</option>
                    <option>リハ日</option>
                  </select>
                </div>
                <div class="form-row">
                  <label>紐づく本番案件</label>
                  <select name="parent_project_id" id="parentProject">
                    <option value="">（選択してください）</option>
                  </select>
                </div>
              </div>
              <div class="hint">※ 予備日・リハ日は「回数」には数えませんが、連勤チェックには含めます（設計書 11章F）。チェックすると、保存後に同じ内容で次の日程を追加できます。</div>
            </div>
          </div>

          <!-- 9. 運営場所 -->
          <div class="form-row full non-arena">
            <label>運営場所</label>
            <select name="operation_place">
              <option>現地</option>
              <option>配信室(広宣)</option>
              <option>配信室横(広宣)</option>
              <option>芝生(広宣)</option>
              <option>大阪依頼</option>
              <option>名古屋依頼</option>
              <option>福岡依頼</option>
            </select>
            <div class="hint">どこで運営／配信するか。オンライン時は配信場所（配信室など）、地方拠点に任せる場合は「○○依頼」。</div>
          </div>

        </div>
      </div>

      <!-- ===== 当日・手配情報（アサイン表の並び順） ===== -->
      <div class="panel">
        <div class="sec-title">当日・手配情報</div>
        <div class="form-grid">

          <!-- 担当体制 -->
          <div class="form-row full non-arena">
            <label>担当体制</label>
            <select name="staff_role">
              <option>イベプラD＋スタッフ</option>
              <option>営業D＋イベプラSD</option>
              <option>営業D＋イベプラサポート（要望を備考に記載）</option>
              <option>営業D＋スタッフ</option>
              <option>営業D＋営業SD</option>
            </select>
          </div>

          <!-- 集合 ｜ 解散 ｜ 拘束時間（横3列） -->
          <div class="full">
            <div class="triple">
              <div class="form-row">
                <label>集合時間（スタッフ）<span class="req-mark yellow">必須</span></label>
                <input type="time" id="startTime" name="start_time" data-need="later" onchange="updateDuration()">
                <div class="hint">移動時間を含む時間を記載ください。</div>
                <div class="tbd-row">
                  <input type="checkbox" id="startTimeTbd" class="tbd-check" data-tbd-for="startTime">
                  <label for="startTimeTbd">未定</label>
                </div>
              </div>
              <div class="form-row">
                <label>解散時間（スタッフ）<span class="req-mark yellow">必須</span></label>
                <input type="time" id="endTime" name="end_time" data-need="later" onchange="updateDuration()">
                <div class="tbd-row">
                  <input type="checkbox" id="endTimeTbd" class="tbd-check" data-tbd-for="endTime">
                  <label for="endTimeTbd">未定</label>
                </div>
              </div>
              <div class="form-row">
                <label>拘束時間（自動計算）</label>
                <div class="readonly-field" id="durationField">—</div>
              </div>
            </div>
          </div>

          <!-- 入場 ｜ 開始 ｜ 終了（横3列） -->
          <div class="full">
            <div class="triple">
              <div class="form-row">
                <label>イベント入場</label>
                <input type="time" name="event_enter_time">
              </div>
              <div class="form-row">
                <label>イベント開始</label>
                <input type="time" name="event_start_time">
              </div>
              <div class="form-row">
                <label>イベント終了</label>
                <input type="time" name="event_end_time">
              </div>
            </div>
          </div>

          <!-- 運営人数 ｜ お客様人数 ｜ チーム数（横3列） -->
          <div class="full">
            <div class="triple">
              <div class="form-row">
                <label>運営人数<span class="req-mark yellow">必須</span></label>
                <input type="number" id="requiredCount" name="required_count" data-need="later" min="1" placeholder="例）16">
                <div class="check-row tbd-row" style="margin-top:8px;">
                  <input type="checkbox" id="countTentative" name="count_tentative" class="tbd-check" data-tbd-for="requiredCount">
                  <label for="countTentative">人数は仮（未定）</label>
                </div>
                <div class="hint">当日の運営に入る人数（＝アサインする人数）。</div>
              </div>
              <div class="form-row">
                <label>お客様（参加者）の人数<span class="req-mark yellow">必須</span></label>
                <input type="number" id="guestNum" name="guest_count" data-need="later" min="0" placeholder="例）120">
                <div class="radio-row" style="margin-top:8px;flex-wrap:wrap;">
                  <label><input type="radio" name="guestCount" value="確定" checked> 確定</label>
                  <label><input type="radio" name="guestCount" value="募集"> 募集</label>
                </div>
                <div class="check-row tbd-row" style="margin-top:6px;">
                  <input type="checkbox" id="guestTbd" class="tbd-check" data-tbd-for="guestNum">
                  <label for="guestTbd">人数は未定</label>
                </div>
                <div class="hint">当日参加されるお客様の人数。スタッフの運営人数とは別です。</div>
              </div>
              <div class="form-row non-arena">
                <label>チーム数<span class="req-mark yellow">必須</span></label>
                <input type="number" id="teamCount" name="team_count" data-need="later" min="0" placeholder="例）8">
                <div class="check-row tbd-row" style="margin-top:8px;">
                  <input type="checkbox" id="teamTentative" name="team_tentative" class="tbd-check" data-tbd-for="teamCount">
                  <label for="teamTentative">チーム数は仮（未定）</label>
                </div>
              </div>
            </div>
          </div>
          <div class="form-row full non-arena">
            <div class="check-row">
              <input type="checkbox" id="isRepeat" name="is_repeat">
              <label for="isRepeat">リピート案件（過去に実施したことがある）</label>
            </div>
          </div>

          <!-- 音響機材 -->
          <div class="form-row full non-arena">
            <label>音響機材</label>
            <select name="audio_equipment">
              <option>会場音響</option>
              <option>クラシックプロ大</option>
              <option>クラシックプロ中</option>
              <option>クラシックプロ小</option>
              <option>CUBE</option>
              <option>SANWA</option>
              <option>TOA</option>
              <option>不要</option>
            </select>
          </div>

          <!-- ロゴ ｜ カメラ ｜ 事例記事 ｜ 動画（横4列） -->
          <div class="full non-arena">
            <div class="quad">
              <div class="form-row">
                <label>ロゴ</label>
                <select name="pub_logo">
                  <option>不要</option>
                  <option>ほしい</option>
                  <option>OK</option>
                  <option>NG</option>
                  <option selected>-</option>
                </select>
              </div>
              <div class="form-row">
                <label>カメラ</label>
                <select name="pub_camera">
                  <option>不要</option>
                  <option>ほしい</option>
                  <option>OK</option>
                  <option>NG</option>
                  <option selected>-</option>
                </select>
              </div>
              <div class="form-row">
                <label>事例記事</label>
                <select name="pub_article">
                  <option>不要</option>
                  <option>ほしい</option>
                  <option>OK</option>
                  <option>NG</option>
                  <option selected>-</option>
                </select>
              </div>
              <div class="form-row">
                <label>動画</label>
                <select name="pub_video">
                  <option>不要</option>
                  <option>ほしい</option>
                  <option>OK</option>
                  <option>NG</option>
                  <option selected>-</option>
                </select>
              </div>
            </div>
          </div>

          <!-- 運営シートURL（運営用スプレッドシートのリンクを貼る） -->
          <div class="full">
            <div class="form-row">
              <label>運営シートURL</label>
              <input type="text" name="ops_sheet_url" placeholder="https://docs.google.com/spreadsheets/d/... を貼り付け">
            </div>
          </div>

          <!-- 場所（会場住所） ｜ 屋内/屋外 ｜ 集合形式（横3列） -->
          <div class="full">
            <div class="triple">
              <div class="form-row">
                <label>会場住所<span class="req-mark yellow">必須</span></label>
                <input type="text" id="venue" name="location" data-need="later" placeholder="例）東京都江東区夢の島2-1-3 〇〇公園">
                <div class="tbd-row">
                  <input type="checkbox" id="venueTbd" class="tbd-check" data-tbd-for="venue">
                  <label for="venueTbd">未定</label>
                </div>
              </div>
              <div class="form-row non-arena">
                <label>屋内 / 屋外</label>
                <div class="radio-row">
                  <label><input type="radio" name="outdoor" value="屋内"> 屋内</label>
                  <label><input type="radio" name="outdoor" value="屋外" checked> 屋外</label>
                </div>
              </div>
              <div class="form-row non-arena">
                <label>集合形式<span class="req-mark yellow">必須</span></label>
                <select name="assembly_type" data-need="later">
                  <option value="" selected>未定</option>
                  <option>会場現地</option>
                  <option>大住</option>
                  <option>広宣</option>
                  <option>事務所+会場現地</option>
                  <option>駅</option>
                  <option>空港</option>
                  <option>その他（備考に記載）</option>
                </select>
                <div class="hint">スタッフがどこに集合するか。詳細は備考へ。</div>
              </div>
            </div>
          </div>

          <!-- お酒 ｜ ケータリング ｜ 移動・車両（横3列） -->
          <div class="full non-arena">
            <div class="triple">
              <div class="form-row">
                <label>お酒</label>
                <div class="radio-row">
                  <label><input type="radio" name="alcohol" value="あり"> あり</label>
                  <label><input type="radio" name="alcohol" value="なし" checked> なし</label>
                </div>
              </div>
              <div class="form-row">
                <label>ケータリング</label>
                <select name="catering">
                  <option>無</option>
                  <option>ケータリング</option>
                  <option>オードブル</option>
                  <option>お弁当</option>
                  <option>キッチンカー</option>
                  <option>BBQ</option>
                  <option>LH発注あり（格付け）</option>
                  <option>LH発注あり（ゴチ）</option>
                  <option>その他</option>
                </select>
              </div>
              <div class="form-row">
                <label>移動・車両</label>
                <select name="transport">
                  <option>ー</option>
                  <option>IKUSAカー</option>
                  <option>IKUSAカー2台</option>
                  <option>IKUSAカー3台</option>
                  <option>電車</option>
                  <option>レンタカー</option>
                  <option>IKUSAカー+レンタカー</option>
                  <option>電車+IKUSAカー</option>
                  <option>電車+レンタカー</option>
                  <option>飛行機</option>
                  <option>飛行機+レンタカー</option>
                </select>
              </div>
            </div>
          </div>

        </div>
      </div>

      <!-- ===== 備考 ===== -->
      <div class="panel">
        <div class="sec-title">備考</div>
        <!-- コンテンツに「運動会」が含まれるときだけ出す注意（種目を備考に書いてもらう） -->
        <div id="undokaiNote" class="undokai-note" style="display:none;">⚠ 運動会が選ばれています。<u>種目を備考に入力してください。</u></div>
        <div class="form-row full" style="margin-bottom:0;">
          <label>メモ・連絡事項（任意）</label>
          <textarea rows="3" name="note" placeholder="例）持ち物：着替え・タオル　／　雨天時は△△へ変更"></textarea>
        </div>
      </div>

      <!-- ===== 下部アクション ===== -->
      <div class="form-actions">
        <a class="btn" href="/projects">キャンセル</a>
        <div class="spacer"></div>
        <button type="button" class="btn" id="addNextBtn" style="display:none;" onclick="saveAndNext()">＋ 同じ内容で次の日程を追加</button>
        <button type="button" class="btn" onclick="saveDraft()">下書きとして保存</button>
        <button type="button" class="btn primary" onclick="savePublish()">確定</button>
      </div>
@endverbatim
</form>
@endsection

@push('scripts')
{{-- 編集モードの値（Bladeが直接埋め込む生出力。新規登録のときは null）。 --}}
<script>window.ECS_EDIT = @json($editProject ?? null);</script>
{{-- CSV取込のエラー行「クリックで直して登録」から来たとき（?fromcsv=1）：
     取込画面が localStorage に置いた行データを、編集用データ(window.ECS_EDIT)として流し込む。
     ＝新規登録扱い（project_id は空のまま）なので「確定」で新しい案件として登録される。
     既存案件の編集（サーバ側 editProject あり）のときは触らない。 --}}
<script>
  (function () {
    if (window.ECS_EDIT) return;                          // 既存案件の編集中は何もしない
    if (!/[?&]fromcsv=1/.test(location.search)) return;   // CSV由来でなければ何もしない
    try {
      const raw = localStorage.getItem('ecs_csv_prefill');
      if (raw) { window.ECS_EDIT = JSON.parse(raw); window.ECS_FROM_CSV = true; }
    } catch (e) {}
    localStorage.removeItem('ecs_csv_prefill');            // 一度使ったら消す（再読込で残らない）
  })();
</script>
{{-- 「紐づく本番案件」の選択肢＝本物の本番案件一覧（id と表示名）。 --}}
<script>window.ECS_PARENTS = @json($parentProjects ?? []);</script>
{{-- 営業担当プルダウンの選択肢＝社員（role=employee）の名前一覧。 --}}
<script>window.ECS_SALES = @json($salesOwners ?? []);</script>
{{-- 登録拠点プルダウンの選択肢（拠点マスタ）と既定値（編集=既存/新規=ログイン者の拠点）。 --}}
<script>
  window.ECS_OFFICES = @json($offices ?? ['東京']);
  window.ECS_DEFAULT_OFFICE = @json($defaultOffice ?? '東京');
</script>
{{-- 案件名（コンテンツ）の候補＝コンテンツ台帳（有効なもの）。空ならJS側のベタ書きにフォールバック。 --}}
<script>window.ECS_CONTENT_OPTIONS = @json($contentOptions ?? []);</script>
{{-- 直近のアサインMTG日（共通設定でDB保存）。「追加案件」自動判定に使う。未設定は null。 --}}
<script>window.ECS_ASSIGN_MTG_DATE = @json($assignMtgDate ?? null);</script>
<script src="/ecs/data/cases.js"></script>
@verbatim
<script>
  // ===== 日程種別の展開 =====
  function toggleSub() {
    const on = document.getElementById('hasSub').checked;
    document.getElementById('subBox').classList.toggle('open', on);
    document.getElementById('subMsg').style.display = on ? 'none' : '';
    onMultiChange(); // 日程種別チェックでも「次の日程を追加」ボタンの表示を更新
  }

  // ===== 確度（ヨミ）：確定以外なら見込み時期欄を開く =====
  function toggleYomi() {
    const v = document.querySelector('input[name="yomi"]:checked');
    const open = v && v.value !== '確定';
    document.getElementById('yomiBox').classList.toggle('open', open);
  }
  toggleYomi(); // 初期表示

  // ===== 実施形態：オンラインならツール選択欄を開く =====
  function onFormatChange() {
    const v = document.getElementById('format').value;
    const online = v.indexOf('オンライン') !== -1;
    document.getElementById('toolBox').classList.toggle('open', online);
    const multiBase = v.indexOf('他拠点') !== -1;
    document.getElementById('locationBox').classList.toggle('open', multiBase);
    const arena = v.indexOf('ARENA') !== -1;
    document.getElementById('arenaBox').classList.toggle('open', arena);
    // ARENAのとき：欄は隠さない（baba要望）。専用の選択パネル(arenaBox)だけ下に出し、場所が空なら「ARENA」を自動入力
    if (arena) {
      const venue = document.getElementById('venue');
      if (venue && !venue.value) venue.value = 'ARENA';
    }
    if (typeof refreshNeedById === 'function') refreshNeedById('venue'); // M-2 色更新
  }
  onFormatChange(); // 初期表示

  // ===== 集合〜解散の拘束時間を計算（9時間超でリアルロングの目安）=====
  function updateDuration() {
    const s = document.getElementById('startTime').value;
    const e = document.getElementById('endTime').value;
    const f = document.getElementById('durationField');
    if (!s || !e) { f.textContent = '—'; f.style.color = ''; return; }
    const [sh, sm] = s.split(':').map(Number);
    const [eh, em] = e.split(':').map(Number);
    const mins = (eh * 60 + em) - (sh * 60 + sm);
    if (mins <= 0) { f.textContent = '時間の指定を確認してください'; f.style.color = 'var(--danger)'; return; }
    const h = Math.floor(mins / 60), m = mins % 60;
    let txt = h + '時間' + (m ? m + '分' : '');
    if (mins > 9 * 60) {
      txt += '（9時間超 → リアルロングの目安）';
      f.style.color = 'var(--warn)';
    } else {
      f.style.color = '';
    }
    f.textContent = txt;
  }
  updateDuration(); // 初期表示

  // ===== 区分（通常／追加案件）：アサインMTG日を基準に自動判定＋手動修正 =====
  // アサインMTG日は共通設定（/settings）でDB保存された値を使う（window.ECS_ASSIGN_MTG_DATE）。
  // 未設定（null）のときは自動判定せず、手動で選んでもらう。
  const MTG_DATE = window.ECS_ASSIGN_MTG_DATE
    ? new Date(window.ECS_ASSIGN_MTG_DATE + 'T00:00:00')
    : null;
  const MTG_LABEL = MTG_DATE ? ((MTG_DATE.getMonth() + 1) + '/' + MTG_DATE.getDate()) : '';
  let manualAddtl = false;
  function initAddtl() {
    if (MTG_DATE) {
      const today = new Date();
      const isAddtl = today > MTG_DATE;
      const target = document.querySelector('input[name="addtl"][value="' + (isAddtl ? '追加案件' : '通常案件') + '"]');
      if (target) target.checked = true;
    }
    updateAddtlNote();
  }
  function onAddtlChange() { manualAddtl = true; updateAddtlNote(); }
  function updateAddtlNote() {
    const note = document.getElementById('addtlNote');
    if (!note) return;
    if (!MTG_DATE) {
      note.innerHTML = 'アサインMTG日が<b>未設定</b>です（共通設定で登録できます）。区分は手動で選んでください。';
      return;
    }
    const sel = document.querySelector('input[name="addtl"]:checked');
    if (!sel) return;
    const v = sel.value;
    if (manualAddtl) {
      note.innerHTML = '手動で「<b>' + v + '</b>」に設定しています（既定は直近アサインMTG ' + MTG_LABEL + ' 基準の自動判定）。';
    } else if (v === '追加案件') {
      note.innerHTML = '直近のアサインMTG（' + MTG_LABEL + '）より後の登録のため、自動で<b>追加案件</b>にしました。手動で変更できます。';
    } else {
      note.innerHTML = '直近のアサインMTG（' + MTG_LABEL + '）までに登録された<b>通常案件</b>です。';
    }
  }
  initAddtl(); // 初期表示

  // ===== 案件名（コンテンツ）：検索して複数選択（タグ入力）=====
  // 候補はコンテンツ台帳（マスタ）から。台帳が空のときだけ昔のベタ書きを使う（保険）。
  const CONTENTS = (window.ECS_CONTENT_OPTIONS && window.ECS_CONTENT_OPTIONS.length)
    ? window.ECS_CONTENT_OPTIONS
    : ['水合戦','運動会','縁日','懇親会運営','表彰式','ワークショップ系','謎解き','チャンバラ合戦','ボウリング大会','クイズ大会','BBQ','カジノ'];
  const selectedContents = [];
  // このうち「この案件だけで使う（台帳に登録しない）」を選んだ名前。単発コンテンツ。
  const oneOffContents = [];
  // 入力された名前をそのまま画面に出すと、記号（< > & など）で表示が崩れることがあるので置き換える。
  function escHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) {
      return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[ch];
    });
  }
  function isOneOff(c) { return oneOffContents.indexOf(c) !== -1; }
  function renderContentTags() {
    document.getElementById('contentTags').innerHTML = selectedContents.map(function(c, i) {
      var mark = isOneOff(c) ? '<span class="oneoff-mark" title="この案件だけで使います（コンテンツ台帳には登録しません）">単発</span>' : '';
      return '<span class="tag">' + escHtml(c) + mark + '<b onclick="removeContent(' + i + ')">×</b></span>';
    }).join('');
    if (typeof refreshNeedById === 'function') refreshNeedById('contentBox'); // M-2 色更新
    updateUndokaiNote(); // 「運動会」が含まれていれば備考の注意を出す
  }
  // コンテンツに「運動会」が含まれるとき、備考に「種目を入力」の注意を出す
  function updateUndokaiNote() {
    var hasUndokai = selectedContents.some(function (c) { return c.indexOf('運動会') !== -1; });
    ['undokaiNote', 'undokaiNoteTop'].forEach(function (id) {
      var note = document.getElementById(id);
      if (note) note.style.display = hasUndokai ? '' : 'none';
    });
  }
  function removeContent(i) {
    var c = selectedContents[i];
    selectedContents.splice(i, 1);
    var o = oneOffContents.indexOf(c);
    if (o !== -1) oneOffContents.splice(o, 1);
    renderContentTags(); renderContentSuggest();
  }
  function addContent(c, oneOff) {
    if (c && selectedContents.indexOf(c) === -1) {
      selectedContents.push(c);
      if (oneOff && !isOneOff(c)) oneOffContents.push(c);
      renderContentTags();
    }
    var s = document.getElementById('contentSearch');
    s.value = ''; renderContentSuggest(); s.focus();
  }
  // 入力中の名前をそのまま追加する。名前を onmousedown の中に書き込まずに済むので、
  // 「'」などの記号が入った名前でも壊れない。
  function addTypedContent(oneOff) {
    var s = document.getElementById('contentSearch');
    addContent(s.value.trim(), oneOff);
  }
  function renderContentSuggest() {
    var s = document.getElementById('contentSearch');
    var q = s.value.trim();
    var box = document.getElementById('contentSuggest');
    var items = CONTENTS.filter(function(c) { return selectedContents.indexOf(c) === -1; });
    if (q) items = items.filter(function(c) { return c.indexOf(q) !== -1; });
    var html = items.map(function(c) { return '<div class="item" onmousedown="addContent(\'' + c + '\')">' + c + '</div>'; }).join('');
    if (q && CONTENTS.indexOf(q) === -1 && selectedContents.indexOf(q) === -1) {
      // 台帳に無い名前は、使い方が2通りあるので選べるようにする。
      // ①その案件限りの単発コンテンツ → 台帳を増やさない（既定として上に置く）
      // ②これから定番で使うコンテンツ → 台帳に登録して次から候補に出す
      var qs = escHtml(q);
      html += '<div class="item add-new" onmousedown="addTypedContent(true)">＋「' + qs + '」をこの案件だけで使う'
            + '<span class="add-new-note">コンテンツ台帳には登録しません（その案件限りのとき）</span></div>'
            + '<div class="item add-new" onmousedown="addTypedContent(false)">＋「' + qs + '」を新しいコンテンツとして台帳に登録する'
            + '<span class="add-new-note">次から候補に出ます。似た名前が上に無いか確認してください</span></div>';
    }
    box.innerHTML = html;
    box.classList.toggle('open', html !== '' && document.activeElement === s);
  }
  (function() {
    var s = document.getElementById('contentSearch');
    s.addEventListener('input', renderContentSuggest);
    s.addEventListener('focus', renderContentSuggest);
    s.addEventListener('blur', function() { setTimeout(function() { document.getElementById('contentSuggest').classList.remove('open'); }, 150); });
    renderContentTags();
  })();

  // ===== 複数案件：ありなら「次の日程を追加」ボタンを表示 =====
  function onMultiChange() {
    const sel = document.querySelector('input[name="multi"]:checked');
    const multiOn = sel && sel.value === 'あり';
    const subOn = document.getElementById('hasSub').checked;
    document.getElementById('addNextBtn').style.display = (multiOn || subOn) ? '' : 'none';
  }
  onMultiChange(); // 初期表示

  // ===== 保存（DBへ実際に送信）=====
  // 選んだコンテンツと「下書き/募集中」を隠しフィールドに入れてフォーム送信する。
  function submitForm(intent) {
    if (!check()) return;
    if (!confirmDanger()) return;
    document.getElementById('contentNamesField').value = selectedContents.join(',');
    // 「この案件だけで使う」を選んだ名前も一緒に送る（この名前は台帳に登録されない）
    document.getElementById('oneoffNamesField').value = oneOffContents.join(',');
    document.getElementById('intentField').value = intent;
    markCsvDoneBeforeSubmit();
    document.getElementById('projForm').submit();
  }
  // CSV取込のエラー行から来て登録するとき、その行番号を localStorage に記録する。
  // → 取込タブがこれを受け取り、その行を「✓ 登録済み」に変える（登録できたと分かる）。
  function markCsvDoneBeforeSubmit() {
    if (!window.ECS_FROM_CSV) return;
    const line = window.ECS_EDIT && window.ECS_EDIT._csvLine;
    if (!line) return;
    try {
      const done = JSON.parse(localStorage.getItem('ecs_csv_done') || '[]');
      if (done.indexOf(line) === -1) done.push(line);
      localStorage.setItem('ecs_csv_done', JSON.stringify(done));
    } catch (e) {}
  }
  // 第1段では「次の日程を追加」も通常の保存として扱う（連続登録は次の段で対応）
  function saveAndNext() { submitForm('publish'); }
  function saveDraft() { submitForm('draft'); }
  function savePublish() {
    if (!check()) return;
    if (!confirmDanger()) return;
    if (!confirm('この案件を確定して保存しますか？')) return;
    document.getElementById('contentNamesField').value = selectedContents.join(',');
    // 「この案件だけで使う」を選んだ名前も一緒に送る（この名前は台帳に登録されない）
    document.getElementById('oneoffNamesField').value = oneOffContents.join(',');
    document.getElementById('intentField').value = 'publish';
    markCsvDoneBeforeSubmit();
    document.getElementById('projForm').submit();
  }

  // ===== M-7 危険日（高負荷日）の警告 =====
  // この案件の「規模・実施形態・運営人数」をフォームから読み取る
  function currentCaseInput() {
    const scaleEl = document.querySelector('input[name="scale"]:checked');
    const fmtText = document.getElementById('format').value;
    const need = parseInt(document.getElementById('requiredCount').value, 10) || 0;
    return { scale: scaleEl ? scaleEl.value : '', fmt: ECS_fmtCode(fmtText), need: need, name: 'この案件' };
  }
  // 開催日に「既存案件＋この案件」を並べて危険判定する。日付未定なら null
  function dangerForForm() {
    const dateTbd = document.getElementById('dateTbd');
    const iso = document.getElementById('startDate').value;
    if (!iso || (dateTbd && dateTbd.checked)) return null;
    const items = ECS_casesOnDate(iso).concat([currentCaseInput()]);
    const res = ECS_dangerCheck(items);
    res.iso = iso; res.existing = items.length - 1;
    return res;
  }
  // 開催日の下のヒントを更新
  function updateDangerHint() {
    const box = document.getElementById('dangerHint');
    const res = dangerForForm();
    if (!res) { box.style.display = 'none'; return; }
    box.style.display = '';
    box.classList.toggle('warn', res.danger);
    let html = '📅 この日は <b>' + res.count + '件</b>（既存' + res.existing + '件＋この案件）・必要スタッフ数の合計 <b>' + res.needSum + '名</b>';
    if (res.danger) {
      html = '⚠ <b>危険日（高負荷日）かもしれません</b><br>' + html
        + '<ul class="dh-reasons"><li>' + res.reasons.join('</li><li>') + '</li></ul>'
        + '👉 アサイン担当に確認はしましたか？';
    }
    box.innerHTML = html;
  }
  // 保存前の確認ポップアップ。危険日でなければそのまま true
  function confirmDanger() {
    const res = dangerForForm();
    if (!res || !res.danger) return true;
    const msg = '⚠ この開催日（' + res.iso + '）は「危険日（高負荷日）」かもしれません。\n\n'
      + '・' + res.reasons.join('\n・') + '\n\n'
      + 'スタッフの手が足りなくなる恐れがあります。\n'
      + '👉 アサイン担当に確認はしましたか？\n\n'
      + 'このまま登録しますか？';
    return confirm(msg);
  }

  // ===== サンプルで入力してみる（動きの確認用）=====
  // わざと危険日（既存の大型案件が重なる日）に合わせ、保存時の警告が見えるようにしています
  function loadFormSample() {
    // 案件名（コンテンツ）
    selectedContents.length = 0;
    oneOffContents.length = 0;
    selectedContents.push('水合戦');
    if (document.getElementById('contentTbd').checked) { document.getElementById('contentTbd').checked = false; onTbd(document.getElementById('contentTbd')); }
    renderContentTags();
    // 開催日＝既存の大型案件が重なる日（今日から12日後）に合わせる
    const d = ECS_caseDate(12);
    const iso = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    if (document.getElementById('dateTbd').checked) { document.getElementById('dateTbd').checked = false; onTbd(document.getElementById('dateTbd')); }
    document.getElementById('startDate').value = iso;
    // 案件規模＝大型
    const big = document.querySelector('input[name="scale"][value="大型"]'); if (big) big.checked = true;
    // 実施形態＝リアル
    document.getElementById('format').value = 'イベント東(リアル)';
    onFormatChange();
    // 運営人数＝18（仮チェックは外す）
    if (document.getElementById('countTentative').checked) { document.getElementById('countTentative').checked = false; onTbd(document.getElementById('countTentative')); }
    document.getElementById('requiredCount').value = '18';
    // その他の見本
    const setVal = function (id, v) { const el = document.getElementById(id); if (el) el.value = v; };
    setVal('client', 'サンプル株式会社');
    setVal('venue', '千葉県柏市柏の葉6-1 〇〇公園（屋外）');
    setVal('guestNum', '120');
    setVal('teamCount', '8');
    setVal('startTime', '08:00');
    setVal('endTime', '17:00');
    if (typeof updateDuration === 'function') updateDuration();
    // 色とヒントを更新
    refreshAllNeed();
    updateDangerHint();
    alert('✓ サンプルを入力しました。\nこの日は既存の大型案件と重なる「危険日」です。\n開催日の下の赤い表示と、保存時の警告を確認してみてください。');
    document.getElementById('startDate').scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
  function check() {
    const date = document.getElementById('startDate').value;
    const count = parseInt(document.getElementById('requiredCount').value) || 0;
    if (selectedContents.length === 0 && !document.getElementById('contentTbd').checked) {
      alert('案件名（コンテンツ）を選ぶか、「コンテンツ未定」にチェックを入れてください。'); return false;
    }
    if (!date && !document.getElementById('dateTbd').checked) {
      alert('開催日を入力するか、「日付未定」にチェックを入れてください。'); return false;
    }
    if (count < 1 && !document.getElementById('countTentative').checked) {
      alert('運営人数を入力するか、「人数は仮（未定）」にチェックを入れてください。'); return false;
    }
    return true;
  }

  // ===== M-2 入力欄の3段階色分け（赤＝必須が空／黄＝後で必要が空・未定／白＝入力済み）=====
  function isFilled(f) {
    if (f.id === 'contentBox') return selectedContents.length > 0;
    return (f.value || '').trim() !== '';
  }
  function refreshNeed(f) {
    const level = f.getAttribute('data-need'); // 'req'（赤） or 'later'（黄）
    if (!level) return;
    f.classList.remove('need-red', 'need-yellow');
    if (f.dataset.tbd === '1') { f.classList.add('need-yellow'); return; } // 未定にした欄は黄色
    if (isFilled(f)) return;                                                // 入力済み＝白（クラスなし）
    f.classList.add(level === 'req' ? 'need-red' : 'need-yellow');          // 空＝赤 or 黄
  }
  function refreshNeedById(id) { const f = document.getElementById(id); if (f) refreshNeed(f); }
  function refreshAllNeed() { document.querySelectorAll('[data-need]').forEach(refreshNeed); }

  // 「未定」チェックの動作＝欄を休みにして黄色に（開催日はメモ欄も開く）
  function onTbd(cb) {
    const f = document.getElementById(cb.getAttribute('data-tbd-for'));
    if (!f) return;
    f.dataset.tbd = cb.checked ? '1' : '';
    if (f.id === 'contentBox') {
      const s = document.getElementById('contentSearch');
      if (s) s.disabled = cb.checked;
      f.classList.toggle('contentBox-tbd', cb.checked);
    } else {
      f.disabled = cb.checked;
    }
    const memoId = cb.getAttribute('data-memo');
    if (memoId) { const memo = document.getElementById(memoId); if (memo) memo.style.display = cb.checked ? '' : 'none'; }
    refreshNeed(f);
  }

  // 入力・選択のたびに色を更新
  document.querySelectorAll('[data-need]').forEach(function (f) {
    const ev = (f.tagName === 'SELECT') ? 'change' : 'input';
    f.addEventListener(ev, function () { refreshNeed(f); });
  });
  document.querySelectorAll('.tbd-check').forEach(function (cb) {
    cb.addEventListener('change', function () { onTbd(cb); });
  });
  refreshAllNeed(); // 初期表示

  // ===== M-7 危険日ヒントの更新タイミング（開催日・規模・実施形態・運営人数の変更時）=====
  ['startDate', 'dateTbd', 'format', 'requiredCount'].forEach(function (id) {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', updateDangerHint);
  });
  document.querySelectorAll('input[name="scale"]').forEach(function (r) {
    r.addEventListener('change', updateDangerHint);
  });
  updateDangerHint(); // 初期表示

  // ===== 編集モード：保存済みの値を各欄に流し込む =====
  // window.ECS_EDIT（Bladeが埋め込み）に案件データが入っていれば、その内容で上書きする。
  // 流し込むのは store() が実際に保存している項目だけ（ロゴ等のモック項目は対象外）。
  function applyEdit() {
    const E = window.ECS_EDIT;
    if (!E) return; // 新規登録のときは何もしない

    // --- コンテンツ（タグ入力）---
    if (Array.isArray(E.content_names)) {
      selectedContents.length = 0;
      oneOffContents.length = 0;
      E.content_names.forEach(function (n) { if (n) selectedContents.push(n); });
      // 台帳に無いもの＝単発コンテンツ。印を復元して、保存し直しても台帳に登録されないようにする。
      if (Array.isArray(E.oneoff_content_names)) {
        E.oneoff_content_names.forEach(function (n) { if (n) oneOffContents.push(n); });
      }
      renderContentTags();
    }

    // --- そのまま値を入れる欄（id指定）---
    const setVal = function (id, v) { const el = document.getElementById(id); if (el && v != null) el.value = v; };
    setVal('startDate', E.start_date);
    setVal('format', E.format);
    setVal('client', E.client);
    setVal('venue', E.location);
    setVal('startTime', E.start_time);
    setVal('endTime', E.end_time);
    setVal('requiredCount', E.required_count);
    setVal('guestNum', E.guest_count);
    setVal('teamCount', E.team_count);

    // --- そのまま値を入れる欄（name指定）---
    const setByName = function (name, v) { const el = document.querySelector('[name="' + name + '"]'); if (el && v != null) el.value = v; };
    setByName('event_enter_time', E.event_enter_time);
    setByName('event_start_time', E.event_start_time);
    setByName('event_end_time', E.event_end_time);
    setByName('lodging', E.lodging);
    setByName('operation_place', E.operation_place);
    setByName('staff_role', E.staff_role);
    setByName('assembly_type', E.assembly_type);
    setByName('catering', E.catering);
    setByName('audio_equipment', E.audio_equipment);
    setByName('transport', E.transport);
    setByName('note', E.note);
    // 営業担当は buildSalesOptions で扱うのでここでは触らない（プルダウン＋手入力のため）。
    setByName('yomi_expected', E.yomi_expected);
    setByName('agency', E.agency);
    setByName('pub_logo', E.pub_logo);
    setByName('pub_camera', E.pub_camera);
    setByName('pub_article', E.pub_article);
    setByName('pub_video', E.pub_video);
    setByName('ops_sheet_url', E.ops_sheet_url);

    // --- ラジオ（値が一致するものを選ぶ）---
    const setRadio = function (name, v) {
      if (v == null || v === '') return;
      const el = document.querySelector('input[name="' + name + '"][value="' + v + '"]');
      if (el) el.checked = true;
    };
    setRadio('addtl', E.category);
    setRadio('yomi', E.yomi);
    setRadio('scale', E.scale);
    setRadio('broadcast', E.broadcast);
    setRadio('guestCount', E.guest_count_type);
    setRadio('multi', E.is_multi ? 'あり' : 'なし');
    if (E.is_outdoor === true) setRadio('outdoor', '屋外');
    else if (E.is_outdoor === false) setRadio('outdoor', '屋内');
    if (E.alcohol === true) setRadio('alcohol', 'あり');
    else if (E.alcohol === false) setRadio('alcohol', 'なし');
    setRadio('onlineTool', E.online_tool);
    // ARENA詳細（保存があれば各ラジオに反映）
    if (E.arena_options) {
      setRadio('arenaSetupPrev', E.arena_options.setup_prev);
      setRadio('arenaLightSetup', E.arena_options.light_setup);
      setRadio('arenaMc', E.arena_options.mc);
      setRadio('arenaAvStaff', E.arena_options.av_staff);
      setRadio('arenaLayout', E.arena_options.layout);
      setRadio('arenaBroadcast', E.arena_options.broadcast);
      setRadio('arenaMeal', E.arena_options.meal);
    }

    // --- チェックボックス ---
    const setCheck = function (id, on) { const el = document.getElementById(id); if (el) el.checked = !!on; };
    setCheck('noRecruit', !E.is_recruiting);   // 募集する＝noRecruit外す
    setCheck('isToc', E.is_toc);               // toC（一般消費者向け）
    setCheck('countTentative', E.count_tentative);
    setCheck('teamTentative', E.team_tentative);
    setCheck('isRepeat', E.is_repeat);

    // --- 対象拠点（複数チェック）---
    if (Array.isArray(E.base_locations)) {
      document.querySelectorAll('input[name="baseLocation[]"]').forEach(function (cb) {
        cb.checked = E.base_locations.indexOf(cb.value) !== -1;
      });
    }

    // --- 日程種別（本番以外＝予備日/リハとして登録されている）---
    if (E.date_type && E.date_type !== '本番') {
      const hasSub = document.getElementById('hasSub');
      if (hasSub) { hasSub.checked = true; toggleSub(); }
      setByName('date_type_sub', E.date_type);          // 種別（予備日/リハ日）
      setByName('parent_project_id', E.parent_project_id); // 紐づく本番案件（選択肢は下で先に作る）
    }

    // --- 表示の追従処理を呼び直す（展開ボックス・色・拘束時間・危険日ヒント）---
    manualAddtl = true;                                        // 編集時は保存済みの区分を尊重（自動判定で上書きしない）
    if (typeof updateAddtlNote === 'function') updateAddtlNote();
    if (typeof onFormatChange === 'function') onFormatChange();
    if (typeof toggleYomi === 'function') toggleYomi();
    if (typeof onMultiChange === 'function') onMultiChange();
    if (typeof updateDuration === 'function') updateDuration();
    if (typeof refreshAllNeed === 'function') refreshAllNeed();
    if (typeof updateDangerHint === 'function') updateDangerHint();
  }
  // ===== 「紐づく本番案件」の選択肢を本物の案件一覧（window.ECS_PARENTS）から作る =====
  // 新規でも編集でも使うので、applyEdit より先に作っておく。
  (function buildParentOptions() {
    const sel = document.getElementById('parentProject');
    if (!sel || !Array.isArray(window.ECS_PARENTS)) return;
    window.ECS_PARENTS.forEach(function (p) {
      const opt = document.createElement('option');
      opt.value = p.id;
      opt.textContent = p.label;
      sel.appendChild(opt);
    });
  })();

  // ===== 営業担当：プルダウン（社員一覧）＋「その他（直接入力）」で手入力も可 =====
  // 「その他」を選ぶと手入力欄が出る。送信は選んだ方だけ name="sales_owner" になる。
  function onSalesChange() {
    const sel = document.getElementById('salesOwnerSel');
    const other = document.getElementById('salesOwnerOther');
    if (!sel || !other) return;
    if (sel.value === '__other__') {
      other.style.display = '';
      other.setAttribute('name', 'sales_owner');
      sel.removeAttribute('name');           // 送信は手入力欄の値だけにする
      if (!other.value) other.focus();
    } else {
      other.style.display = 'none';
      other.removeAttribute('name');
      sel.setAttribute('name', 'sales_owner'); // 送信はプルダウンの値
    }
    if (typeof refreshAllNeed === 'function') refreshAllNeed();
  }
  // 社員一覧を「その他」の前に並べ、初期値（編集＝保存値／新規＝baba）を入れる。
  (function buildSalesOptions() {
    const sel = document.getElementById('salesOwnerSel');
    if (!sel || !Array.isArray(window.ECS_SALES)) return;
    const otherOpt = sel.querySelector('option[value="__other__"]');
    window.ECS_SALES.forEach(function (n) {
      const opt = document.createElement('option');
      opt.value = n; opt.textContent = n;
      sel.insertBefore(opt, otherOpt);       // 「その他（直接入力）」の前に社員を並べる
    });
    const init = window.ECS_EDIT ? (window.ECS_EDIT.sales_owner || '') : 'baba';
    if (init && window.ECS_SALES.indexOf(init) !== -1) {
      sel.value = init;                      // 一覧にいる人＝そのまま選択
    } else if (init) {
      sel.value = '__other__';               // 一覧に無い名前＝その他（手入力）へ
      const other = document.getElementById('salesOwnerOther');
      if (other) other.value = init;
    }
    onSalesChange();                         // 手入力欄の表示・name付け替えを反映
  })();

  // 登録拠点プルダウン：拠点マスタを並べ、初期値（編集＝保存値／新規＝ログイン者の拠点）を選ぶ。
  (function buildOfficeOptions() {
    const sel = document.getElementById('officeSel');
    if (!sel || !Array.isArray(window.ECS_OFFICES)) return;
    sel.innerHTML = '';
    window.ECS_OFFICES.forEach(function (name) {
      const opt = document.createElement('option');
      opt.value = name; opt.textContent = name;
      sel.appendChild(opt);
    });
    const init = (window.ECS_EDIT && window.ECS_EDIT.office)
      ? window.ECS_EDIT.office
      : (window.ECS_DEFAULT_OFFICE || '東京');
    if (init && window.ECS_OFFICES.indexOf(init) !== -1) {
      sel.value = init;
    }
  })();

  // ===== リピート（常連）クライアントの照会 =====
  // クライアント欄の入力／フォーカスアウトで /clients/lookup を呼び、常連なら過去案件を控えめに出す。
  // 既存に同名クライアントがあれば「常連」（新規登録中の案件はまだ保存されていない前提）。GETなのでCSRF不要。
  (function setupClientRepeat() {
    const input = document.getElementById('client');
    const note  = document.getElementById('clientRepeatNote');
    if (!input || !note) return;

    let lastQueried = null;

    const esc = function (s) {
      return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
        return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;' }[c];
      });
    };

    function render(data) {
      if (!data || !data.isRepeat) { note.style.display = 'none'; note.innerHTML = ''; return; }
      let html = '<div style="color:var(--brand-dark,#8a5a2b);font-weight:700;">🔁 リピートのお客様です（過去' + data.count + '件）</div>';
      if (Array.isArray(data.entries) && data.entries.length) {
        html += '<ul style="margin:4px 0 0;padding-left:18px;line-height:1.6;">';
        data.entries.forEach(function (e) {
          html += '<li>' + (esc(e.date) || '日付未定') + '　担当D：' + esc(e.director) + '</li>';
        });
        html += '</ul>';
      }
      note.innerHTML = html;
      note.style.display = '';
    }

    function query() {
      const c = input.value.trim();
      if (c === '') { render(null); lastQueried = ''; return; }
      if (c === lastQueried) return;   // 同じ値の二重問い合わせを避ける
      lastQueried = c;
      fetch('/clients/lookup?client=' + encodeURIComponent(c), { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) { if (input.value.trim() === c) render(data); })
        .catch(function () { /* 照会失敗時は注釈を出さないだけ（入力の邪魔はしない） */ });
    }

    let t = null;
    input.addEventListener('input', function () { clearTimeout(t); t = setTimeout(query, 400); });
    input.addEventListener('blur', query);
    window._checkClientRepeat = query;   // 編集モードの初期表示用に外へ出す
  })();

  applyEdit(); // 編集モードなら値を流し込む（新規は素通り）
  if (window._checkClientRepeat) window._checkClientRepeat(); // 編集で既存クライアントが入っていれば履歴を表示

  // CSV取込のエラー行から来たときの案内を表示（applyEdit で値を入れたあと）。
  if (window.ECS_FROM_CSV) {
    const note = document.getElementById('csvPrefillNote');
    if (note) {
      const ln = (window.ECS_EDIT && window.ECS_EDIT._csvLine) ? '（CSVの' + window.ECS_EDIT._csvLine + '行目）' : '';
      note.textContent = '⬆ CSVの行から取り込みました' + ln + '。赤かった箇所（案件名・開催日・運営人数など）を直して「確定」で登録してください。登録後はこのタブを閉じ、取込画面で次のエラー行をクリックしてください。';
      note.style.display = '';
    }
  }

</script>
@endverbatim
@endpush
