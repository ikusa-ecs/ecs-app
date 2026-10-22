@extends('layouts.app')
@section('title', 'Administrator（管理）')
@section('h1', 'Administrator（管理）')
@php($active = 'admin_console')

@push('head')
<style>
  /* 所属バッジ。色の正本＝App\Support\Departments（ここに色を直書きしない）。 */
  .ac-dept {
    display: inline-block; font-size: 11px; font-weight: 700;
    padding: 2px 8px; border-radius: 999px; white-space: nowrap;
  }
  {!! App\Support\Departments::badgeCss('.ac-dept') !!}
  .ac-dept.none { background: #efeae3; color: #8a7a66; }
  .ac-sub { color: #a08a73; font-size: 11px; }

  /* ===== 黒ベース（ダークモード）のときの読みやすさ調整 =====
     この画面は色が style="" に直接書かれているので、黒地のときだけ !important で読める色に差し替える。
     お知らせの色の意味（緑＝成功／赤＝失敗）は変えない。 */
  html[data-theme="dark"] .ac-dept.none { background: var(--chip-bg); color: var(--chip-ink); }
  html[data-theme="dark"] .ac-sub { color: var(--muted); }
  html[data-theme="dark"] div[style*="#e7f6ec"] { background: var(--ok-soft) !important; color: var(--ok-ink) !important; border-color: var(--ok-line) !important; }
  html[data-theme="dark"] div[style*="#fdecec"] { background: var(--danger-soft) !important; color: var(--danger-ink) !important; border-color: var(--danger-line) !important; }
  html[data-theme="dark"] tr[style*="#6e5b49"] { color: var(--muted) !important; border-bottom-color: var(--line) !important; }
  html[data-theme="dark"] tr[style*="#f0e8dd"] { border-bottom-color: var(--line) !important; }
  html[data-theme="dark"] td[style*="#a08a73"] { color: var(--muted) !important; }
  html[data-theme="dark"] [style*="#3a2d20"] { color: var(--ink) !important; }
  html[data-theme="dark"] select[style*="#d8c8b6"] { background: var(--surface-2) !important; color: var(--ink) !important; border-color: var(--field-line) !important; }
</style>
@endpush

@section('content')
      {{-- なぜ／使い方：Administrator（全権）だけができる作業を、この1画面に集約しています。 --}}
      <div class="mock-note">
        <b>Administrator（全権）専用</b>の画面です。ほかの人（管理者・社員・スタッフ）には表示されません。<br>
        「Administratorだけができること」＝<b>権限の変更</b>と、マスタの削除・システム設定などをここにまとめています。
      </div>

      {{-- このサーバーのPHPの版（2026-10-22 baba要望）。PHPに穴が見つかったとき、エンジニアに聞かずに
           「直った版になっているか」を確かめるため。⚠ 外からは見えない（Administratorだけ）＝攻撃の手がかりにしない。 --}}
      <p class="muted" id="phpVersion" style="font-size:12.5px; margin:-4px 0 16px;">
        このサーバーのPHP：<b>{{ PHP_VERSION }}</b>
        <span class="ac-sub">（PHPの安全のお知らせが出たら、ここが直った版以上になっているか見てください）</span>
      </p>

      @if (session('admin_status'))
        <div style="background:#e7f6ec; color:#166534; border:1px solid #b7e0c2; border-radius:10px; padding:12px 14px; font-size:13px; margin-bottom:16px; max-width:720px;">
          {{ session('admin_status') }}
        </div>
      @endif
      @if (session('admin_error'))
        <div style="background:#fdecec; color:#b91c1c; border:1px solid #f3c0c0; border-radius:10px; padding:12px 14px; font-size:13px; margin-bottom:16px; max-width:720px;">
          {{ session('admin_error') }}
        </div>
      @endif

      {{-- ① 権限変更（この画面のメイン機能） --}}
      <h2 style="margin:6px 0 4px;">権限の変更（昇格・降格）</h2>
      <p class="muted" style="font-size:12.5px; margin:0 0 12px;">
        社員の権限を変えます。例：一般の社員をアサイン担当（管理者）に昇格。<br>
        ※スタッフ（{{ $staffCount }}名）の権限は「スタッフ」固定のためここには出ません。<br>
        ※安全のため「自分自身のAdministrator解除」「最後のAdministratorの解除」はできません。
      </p>

      <div class="panel" style="max-width:720px; overflow-x:auto;">
        @if ($employees->isEmpty())
          <p class="muted" style="font-size:13px; padding:8px;">社員が登録されていません。（見本データ投入後に一覧が出ます）</p>
        @else
          <table style="width:100%; border-collapse:collapse; font-size:13px;">
            <thead>
              <tr style="text-align:left; color:#6e5b49; border-bottom:2px solid #e6dccf;">
                <th style="padding:8px 6px;">社員番号</th>
                <th style="padding:8px 6px;">氏名</th>
                <th style="padding:8px 6px;">所属</th>
                <th style="padding:8px 6px;">現在の権限</th>
                <th style="padding:8px 6px;">変更</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($employees as $emp)
                <tr style="border-bottom:1px solid #f0e8dd;">
                  <td style="padding:8px 6px; color:#a08a73; white-space:nowrap;">{{ $emp->id }}</td>
                  <td style="padding:8px 6px; font-weight:600; color:#3a2d20; white-space:nowrap;">{{ $emp->name }}</td>
                  {{-- 所属（2026-08-27 baba要望）。誰を昇格させるか決めるとき、氏名だけだと分かりにくいため。
                       兼務がある人は2つめ以降も小さく出す（主な所属が先頭）。 --}}
                  <td style="padding:8px 6px; white-space:nowrap;">
                    @php($depts = $emp->departmentList())
                    <span class="ac-dept {{ App\Support\Departments::code($depts[0] ?? null) }}">
                      {{ App\Support\Departments::label($depts[0] ?? null) }}
                    </span>
                    @if (count($depts) > 1)
                      <span class="ac-sub">＋{{ implode('・', array_slice($depts, 1)) }}</span>
                    @endif
                    @if ($emp->office)
                      <div class="ac-sub">{{ $emp->office }}</div>
                    @endif
                  </td>
                  <td style="padding:8px 6px; white-space:nowrap;">
                    <span style="font-size:11px; font-weight:700; color:#fff; background:{{ $permBadgeColor[$emp->permission] ?? '#6e5b49' }}; padding:2px 9px; border-radius:999px;">
                      {{ $permLabels[$emp->permission] ?? $emp->permission }}
                    </span>
                  </td>
                  <td style="padding:8px 6px;">
                    <form method="POST" action="/admin-console/permission" style="display:flex; gap:8px; align-items:center; margin:0;">
                      @csrf
                      <input type="hidden" name="id" value="{{ $emp->id }}">
                      <select name="permission" style="padding:6px 8px; border:1px solid #d8c8b6; border-radius:7px; font-size:13px;">
                        @foreach (['employee','manager','admin'] as $p)
                          <option value="{{ $p }}" @selected($emp->permission === $p)>{{ $permLabels[$p] }}</option>
                        @endforeach
                      </select>
                      <button class="btn ghost sm" type="submit">保存</button>
                    </form>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        @endif
      </div>

      {{-- ② お知らせメールの一斉送信（2026-10-22 baba要望）。正本＝App\Support\BroadcastMail。
           ⚠ 件名・本文はどこにも保存しない（共通パスワードなどを書くため）。 --}}
      <h2 style="margin:26px 0 4px;">📧 お知らせメールの一斉送信</h2>
      <p class="muted" style="font-size:12.5px; margin:0 0 12px;">
        ログインできる人（在籍中・メールあり・パスワード設定済み）に、<b>1人1通ずつ</b>送ります（ほかの人のアドレスは見えません）。臨時スタッフ・体験用は送りません。<br>
        宛名（○○ 様）は自動で付きます。件名・本文は<b>保存されません</b>。
      </p>
      <div class="panel" id="bcPanel" style="max-width:720px; padding:14px; display:grid; gap:10px; font-size:13px;">
        <label>送り先
          <select id="bcTarget" style="padding:6px 8px; border:1px solid #d8c8b6; border-radius:7px; font-size:13px;">
            <option value="all">社員とスタッフ全員</option>
            <option value="employee">社員だけ</option>
            <option value="staff">スタッフだけ</option>
          </select>
        </label>
        <label>件名<br>
          <input id="bcSubject" type="text" maxlength="200" style="width:100%; padding:6px 8px; border:1px solid #d8c8b6; border-radius:7px; font-size:13px;"
                 value="【重要】ECSの入り方が一時的に変わります（共通パスワードのお知らせ）">
        </label>
        <label>本文<br>
          <textarea id="bcBody" rows="16" style="width:100%; padding:8px; border:1px solid #d8c8b6; border-radius:7px; font-size:13px; font-family:inherit;">お疲れ様です。株式会社IKUSAです。

ECSを動かしている仕組みに安全上の問題が見つかったため、修正が終わるまでの間、ECSを開くときに「共通のID・パスワード」を入れていただく形に変わりました。

■ ECSを開くと、最初にIDとパスワードを聞かれます
以下を入力してください（スマホ・パソコンに覚えさせておけば、次からは入力不要です）。

　ID　　　：
　パスワード：

■ そのあとは、いつもどおり
ご自分のメールアドレスとパスワードでログインしてください。

ECS：{{ url('/') }}

※この共通パスワードは、社外の方には教えないでください。
※修正が終わったら、元の入り方に戻します（あらためてお知らせします）。

ご不便をおかけしますが、よろしくお願いいたします。</textarea>
        </label>
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
          <button class="btn" type="button" id="bcCheck">送り先を確認</button>
          <button class="btn" type="button" id="bcSend" style="display:none;">送信する</button>
          <span id="bcStatus" class="muted"></span>
        </div>
        <div id="bcResult" style="white-space:pre-wrap; font-size:12.5px;"></div>
      </div>
      <script>
      (function () {
        // 画面のJSが死んでも他の欄に影響しないよう、全体を try で包む。
        try {
          var csrf = '{{ csrf_token() }}';
          var $ = function (id) { return document.getElementById(id); };
          var ids = [], batch = 20, target = 'all';
          function post(url, payload) {
            return fetch(url, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
              body: JSON.stringify(payload)
            }).then(function (r) {
              return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || ('エラー ' + r.status)); return j; });
            });
          }
          // 送り先や文面を変えたら、もう一度「確認」からやり直す（確認した人数と違う相手に送らないため）。
          ['bcTarget', 'bcSubject', 'bcBody'].forEach(function (id) {
            $(id).addEventListener('input', function () { $('bcSend').style.display = 'none'; $('bcStatus').textContent = ''; });
          });
          $('bcCheck').addEventListener('click', function () {
            target = $('bcTarget').value;
            $('bcResult').textContent = '';
            post('/admin-console/broadcast/preview', { target: target }).then(function (j) {
              ids = j.ids; batch = j.batch;
              $('bcStatus').textContent = j.count + '人に送ります。本文を確認してから「送信する」を押してください。';
              $('bcSend').style.display = j.count ? '' : 'none';
            }).catch(function (e) { $('bcStatus').textContent = '確認できませんでした：' + e.message; });
          });
          $('bcSend').addEventListener('click', function () {
            var subject = $('bcSubject').value.trim(), body = $('bcBody').value.trim();
            if (!subject || !body) { alert('件名と本文を入れてください。'); return; }
            if (!confirm(ids.length + '人にメールを送ります。よろしいですか？\n（送ったメールは取り消せません）')) return;
            $('bcSend').style.display = 'none'; $('bcCheck').disabled = true;
            var sent = 0, skipped = [], i = 0;
            function next() {
              if (i >= ids.length) {
                $('bcStatus').textContent = '';
                $('bcResult').textContent = '✅ ' + sent + '人に送りました。' + (skipped.length ? '\n送れなかった人：\n' + skipped.join('\n') : '');
                $('bcCheck').disabled = false;
                return;
              }
              var chunk = ids.slice(i, i + batch);
              $('bcStatus').textContent = '送信中… ' + i + ' / ' + ids.length;
              post('/admin-console/broadcast/send', { target: target, ids: chunk, subject: subject, body: body }).then(function (j) {
                sent += j.sent; skipped = skipped.concat(j.skipped); i += batch; next();
              }).catch(function (e) {
                // 途中で止まったら、どこまで送れたかをはっきり出す（二重送信を防ぐため、続きは自動で送らない）。
                $('bcResult').textContent = '⚠ 途中で止まりました：' + e.message + '\n送れたのは ' + sent + '人（' + i + '人目まで処理）。続きを送る前に担当に相談してください。';
                $('bcStatus').textContent = ''; $('bcCheck').disabled = false;
              });
            }
            next();
          });
        } catch (e) { console.error(e); }
      })();
      </script>

      {{-- ③ Administrator専用の作業への集約リンク --}}
      <h2 style="margin:26px 0 4px;">その他のAdministrator向け作業</h2>
      <p class="muted" style="font-size:12.5px; margin:0 0 12px;">
        マスタの削除やシステム設定などは、下の画面で行います。
      </p>

      <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:14px; max-width:720px;">
        <a href="/settings" style="text-decoration:none;">
          <div class="panel" style="padding:16px; height:100%;">
            <div style="font-size:22px;">⚙️</div>
            <div style="font-weight:700; color:#3a2d20; margin-top:4px;">共通設定</div>
            <div class="muted" style="font-size:12px; margin-top:4px;">マスタ管理（コンテンツ・拠点）と<b>削除（Administratorのみ）</b>／システム設定。<br>※MTG日の変更は社員も可。</div>
          </div>
        </a>
        <a href="/account-new" style="text-decoration:none;">
          <div class="panel" style="padding:16px; height:100%;">
            <div style="font-size:22px;">🔑</div>
            <div style="font-weight:700; color:#3a2d20; margin-top:4px;">アカウント発行</div>
            <div class="muted" style="font-size:12px; margin-top:4px;">1人ずつログインアカウントを発行（管理者以上）。</div>
          </div>
        </a>
        <a href="/person-import" style="text-decoration:none;">
          <div class="panel" style="padding:16px; height:100%;">
            <div style="font-size:22px;">⬆</div>
            <div style="font-weight:700; color:#3a2d20; margin-top:4px;">名簿CSV取込</div>
            <div class="muted" style="font-size:12px; margin-top:4px;">最初の名簿をまとめて登録（管理者以上）。</div>
          </div>
        </a>
      </div>
@endsection
