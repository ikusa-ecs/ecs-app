@extends('layouts.app')
@section('title', '共通設定')
@section('h1', '共通設定')
@php($active = 'settings')

@push('head')
@verbatim
<style>
    .settings-wrap { max-width: 640px; }

    /* 設定の1項目（ラベル＋説明） */
    .set-row {
      display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;
      padding: 14px 2px; border-bottom: 1px solid var(--line);
    }
    .set-row:last-child { border-bottom: none; }
    .set-row .set-label { font-size: 14px; font-weight: 700; color: var(--ink); }
    .set-row .set-note { display: block; font-size: 12px; font-weight: 400; color: var(--muted); margin-top: 3px; }
    .set-row .set-control { flex-shrink: 0; }

    /* アカウント欄 */
    .acct-value { font-size: 14px; color: var(--ink); font-weight: 600; overflow-wrap: anywhere; }
    .line-btn {
      background: #fff; color: var(--brand-dark); border: 1px solid var(--line);
      border-radius: 10px; padding: 11px 16px; font-size: 14px; font-weight: 700; cursor: pointer;
      font-family: inherit;
    }
    .line-btn:hover { background: #f3ece0; }
    .line-btn.danger { color: var(--danger); border-color: #f0b9b9; }
    .line-btn.danger:hover { background: var(--danger-soft); }

    /* 日付入力 */
    .date-input {
      padding: 10px 12px; border: 1px solid var(--line); border-radius: 10px;
      font-size: 15px; font-family: inherit; background: #fff;
    }

    /* ON/OFFスイッチ（見た目だけのトグル） */
    .switch { position: relative; width: 46px; height: 26px; flex-shrink: 0; display: inline-block; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .switch .track {
      position: absolute; inset: 0; background: #d8cfc0; border-radius: 999px;
      transition: background .15s; cursor: pointer;
    }
    .switch .track::before {
      content: ''; position: absolute; left: 3px; top: 3px; width: 20px; height: 20px;
      background: #fff; border-radius: 50%; transition: transform .15s; box-shadow: 0 1px 2px rgba(0,0,0,.2);
    }
    .switch input:checked + .track { background: var(--brand); }
    .switch input:checked + .track::before { transform: translateX(20px); }

    /* 保存ボタンと「保存しました」 */
    .save-bar { display: flex; align-items: center; gap: 14px; margin-top: 16px; }
    .saved-msg { display: none; color: #2e7d32; font-weight: 700; font-size: 13px; }
  </style>
@endverbatim
@endpush

@section('content')
@verbatim
      <div class="mock-note">これは見た目確認用のモックです。ここはアサイン担当が触る「全員に効く設定」です。保存内容はこのブラウザにだけ記憶されます（本番ではサーバに保存します）。</div>

      <!-- ① 直近のアサインMTG日 -->
      <div class="panel settings-wrap">
        <div class="panel-head"><h2>直近のアサインMTG日</h2></div>
        <p class="muted" style="font-size:12.5px; margin:0 0 6px;">
          ここで設定した日<strong>より後</strong>に登録された案件は、自動で「追加案件」として扱われます。
          毎月のアサインMTGが終わったら、その日付に更新してください。
        </p>

        <div class="set-row">
          <div>
            <span class="set-label">直近のアサインMTG開催日</span>
            <span class="set-note">例：今月のMTGが 6/2 だった → 6/2 を設定</span>
          </div>
          <div class="set-control">
            <input type="date" id="mtgDate" class="date-input">
          </div>
        </div>

        <div class="save-bar">
          <button class="btn primary" onclick="saveMtgDate()">この日付で保存する</button>
          <span class="saved-msg" id="mtgSaved">✓ 保存しました</span>
        </div>
        <p class="muted" style="font-size:11.5px; margin:12px 0 0;">
          ※ 現在のモックでは案件登録画面のMTG日は仮固定です。本番ではこの設定が案件登録の「追加案件」判定に使われます。
        </p>
      </div>

      <!-- ② 通知設定は「個人ごとの設定」なので マイページ へ移動（2026-07-01 baba） -->

      <!-- ③ マスタ管理 -->
      <div class="panel settings-wrap" style="margin-top:20px;">
        <div class="panel-head"><h2>マスタ管理</h2></div>
        <p class="muted" style="font-size:12.5px; margin:0 0 6px;">
          案件登録やアサインで使う「選択肢の元データ」をまとめて管理します。ここを直すと、全画面の選択肢に反映されます。
        </p>

        <div class="set-row">
          <div>
            <span class="set-label">拠点（事務所）</span>
            <span class="set-note"><span id="mcOfficeEx">東京／大阪 など</span>（現在 <span id="mcOffice">-</span>件）</span>
          </div>
          <div class="set-control"><button class="line-btn" onclick="location.href='/masters#offices'">管理する</button></div>
        </div>

        <div class="set-row">
          <div>
            <span class="set-label">コンテンツ</span>
            <span class="set-note">水合戦／運動会／縁日 など、案件名に使うコンテンツ（現在 <span id="mcContent">-</span>件）</span>
          </div>
          <div class="set-control"><button class="line-btn" onclick="location.href='/masters#contents'">管理する</button></div>
        </div>

        <div class="set-row">
          <div>
            <span class="set-label">ポジション（役割）</span>
            <span class="set-note"><span id="mcPosEx">D／SD／OP／MC／FC／CK／軍師・サポーター／受付</span>（現在 <span id="mcPos">-</span>件）</span>
          </div>
          <div class="set-control"><button class="line-btn" onclick="location.href='/masters#positions'">一覧を見る</button></div>
        </div>

        <p class="muted" style="font-size:11.5px; margin:12px 0 0;">
          ※ コンテンツ・拠点は「管理する」から追加・編集・削除できます。ポジション（役割）はシステムの土台のため一覧表示のみです。
        </p>
      </div>
@endverbatim
@endsection

@push('scripts')
<script>
  // マスタ件数（SettingsController が DB から数えた実データ）。
  window.ECS_SETTINGS_COUNTS = @json($masterCounts ?? null);
</script>
@verbatim
<script>
  // ===== 保存先のキー（このブラウザに記憶） =====
  const MTG_KEY    = 'ecs_emp_mtgdate';

  // --- 「保存しました」を一定時間だけ表示 ---
  function flashSaved(id) {
    const el = document.getElementById(id);
    el.style.display = 'inline';
    setTimeout(() => { el.style.display = 'none'; }, 2500);
  }

  // --- ② 直近MTG日 ---
  function loadMtgDate() {
    const v = localStorage.getItem(MTG_KEY);
    if (v) document.getElementById('mtgDate').value = v;
  }
  function saveMtgDate() {
    const v = document.getElementById('mtgDate').value;
    if (!v) { alert('日付を選んでください。'); return; }
    try { localStorage.setItem(MTG_KEY, v); } catch (e) {}
    flashSaved('mtgSaved');
  }

  // --- マスタ件数を DB の実データで表示（嘘の固定件数を置き換え）---
  function fillMasterCounts() {
    const mc = window.ECS_SETTINGS_COUNTS;
    if (!mc) return;
    const setText = (id, v) => { const el = document.getElementById(id); if (el && v != null && v !== '') el.textContent = v; };
    if (mc.offices)   { setText('mcOffice', mc.offices.count);   setText('mcOfficeEx', mc.offices.examples); }
    if (mc.contents)  { setText('mcContent', mc.contents.count); }
    if (mc.positions) { setText('mcPos', mc.positions.count);    setText('mcPosEx', mc.positions.examples); }
  }

  // 初期表示
  loadMtgDate();
  fillMasterCounts();
</script>
@endverbatim
@endpush
