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

      <!-- ② 通知設定 -->
      <div class="panel settings-wrap" style="margin-top:20px;">
        <div class="panel-head"><h2>通知設定</h2></div>
        <p class="muted" style="font-size:12.5px; margin:0 0 6px;">
          システムから送る通知のオン・オフです（社員全員に適用されます）。
        </p>

        <div class="set-row">
          <div>
            <span class="set-label">新人の初回フォロー所感が共有されたとき</span>
            <span class="set-note">新人が初めて入った案件で、イベプラが入力した所感のお知らせ（F-014）。</span>
          </div>
          <div class="set-control">
            <label class="switch"><input type="checkbox" id="ntFollow"><span class="track"></span></label>
          </div>
        </div>

        <div class="set-row">
          <div>
            <span class="set-label">案件のアサインが確定したとき</span>
            <span class="set-note">案件のメンバーが確定したら、その案件の担当者へお知らせします。</span>
          </div>
          <div class="set-control">
            <label class="switch"><input type="checkbox" id="ntAssign"><span class="track"></span></label>
          </div>
        </div>

        <div class="set-row">
          <div>
            <span class="set-label">エントリー（応募）の締切が近いとき</span>
            <span class="set-note">募集中の案件で、締切が近づいたらお知らせします。</span>
          </div>
          <div class="set-control">
            <label class="switch"><input type="checkbox" id="ntDeadline"><span class="track"></span></label>
          </div>
        </div>

        <div class="save-bar">
          <button class="btn primary" onclick="saveNotify()">この内容で保存する</button>
          <span class="saved-msg" id="ntSaved">✓ 保存しました</span>
        </div>
        <p class="muted" style="font-size:11.5px; margin:12px 0 0;">
          ※ 通知の届け方（メール／チャットワーク等）は今後決めます。今はオン・オフだけ保存します。
        </p>
      </div>

      <!-- ③ マスタ管理 -->
      <div class="panel settings-wrap" style="margin-top:20px;">
        <div class="panel-head"><h2>マスタ管理</h2></div>
        <p class="muted" style="font-size:12.5px; margin:0 0 6px;">
          案件登録やアサインで使う「選択肢の元データ」をまとめて管理します。ここを直すと、全画面の選択肢に反映されます。
        </p>

        <div class="set-row">
          <div>
            <span class="set-label">拠点</span>
            <span class="set-note">イベント東／東北／他拠点 など（現在 5件）</span>
          </div>
          <div class="set-control"><button class="line-btn" onclick="alert('拠点マスタの管理画面を開きます（モックのためダミーです）。')">管理する</button></div>
        </div>

        <div class="set-row">
          <div>
            <span class="set-label">コンテンツ</span>
            <span class="set-note">水合戦／運動会／縁日 など、案件名に使うコンテンツ（現在 12件）</span>
          </div>
          <div class="set-control"><button class="line-btn" onclick="alert('コンテンツマスタの管理画面を開きます（モックのためダミーです）。')">管理する</button></div>
        </div>

        <div class="set-row">
          <div>
            <span class="set-label">ポジション（役割）</span>
            <span class="set-note">D／SD／OP／MC／FC／CK／軍師・サポーター／受付／カメラマン／運営（現在 10件）</span>
          </div>
          <div class="set-control"><button class="line-btn" onclick="alert('ポジションマスタの管理画面を開きます（モックのためダミーです）。')">管理する</button></div>
        </div>

        <p class="muted" style="font-size:11.5px; margin:12px 0 0;">
          ※ いまは入口だけのモックです。各マスタの追加・編集画面は今後作ります。
        </p>
      </div>
@endverbatim
@endsection

@push('scripts')
@verbatim
<script>
  // ===== 保存先のキー（このブラウザに記憶） =====
  const MTG_KEY    = 'ecs_emp_mtgdate';
  const NOTIFY_KEY = 'ecs_emp_notify';

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

  // --- ③ 通知設定 ---
  const NOTIFY_IDS = ['ntFollow', 'ntAssign', 'ntDeadline'];
  function loadNotify() {
    let saved = {};
    try { saved = JSON.parse(localStorage.getItem(NOTIFY_KEY) || '{}'); } catch (e) {}
    NOTIFY_IDS.forEach(id => {
      // 既定はすべてオン。保存があればそれに従う
      document.getElementById(id).checked = (id in saved) ? !!saved[id] : true;
    });
  }
  function saveNotify() {
    const sel = {};
    NOTIFY_IDS.forEach(id => { sel[id] = document.getElementById(id).checked; });
    try { localStorage.setItem(NOTIFY_KEY, JSON.stringify(sel)); } catch (e) {}
    flashSaved('ntSaved');
  }

  // 初期表示
  loadMtgDate();
  loadNotify();
</script>
@endverbatim
@endpush
