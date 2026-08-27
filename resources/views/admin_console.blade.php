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
</style>
@endpush

@section('content')
      {{-- なぜ／使い方：Administrator（全権）だけができる作業を、この1画面に集約しています。 --}}
      <div class="mock-note">
        <b>Administrator（全権）専用</b>の画面です。ほかの人（管理者・社員・スタッフ）には表示されません。<br>
        「Administratorだけができること」＝<b>権限の変更</b>と、マスタの削除・システム設定などをここにまとめています。
      </div>

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

      {{-- ② Administrator専用の作業への集約リンク --}}
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
