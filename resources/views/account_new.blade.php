@extends('layouts.app')
@section('title', 'アカウント発行')
@section('h1', 'アカウント発行（1人ずつ）')
@php($active = 'account_new')

@section('content')
      {{-- なぜ／使い方：最初の名簿はCSV一括、そのあと増える人はここで1人ずつ「ログインできる人」を発行します。 --}}
      <div class="mock-note">
        管理者が、ログインできるアカウントを1人ずつ発行する画面です。<br>
        発行すると<b>仮パスワード</b>が出ます。それを本人に伝えると、本人は初回ログインで<b>パスワード変更＋プロフィール入力</b>を行います。<br>
        （はじめの一括登録は「名簿CSV取込」をお使いください）
      </div>

      @if ($errors->any())
        <div style="background:#fdecec; color:#b91c1c; border:1px solid #f3c0c0; border-radius:10px; padding:12px 14px; font-size:13px; margin-bottom:16px; max-width:560px;">
          {{ $errors->first() }}
        </div>
      @endif

      {{-- 発行結果：メールと仮パスワードを本人に伝えるための表示 --}}
      @if (session('issued'))
        @php($issued = session('issued'))
        <div style="background:#e7f6ec; color:#166534; border:1px solid #b7e0c2; border-radius:12px; padding:16px 18px; margin-bottom:18px; max-width:560px;">
          <div style="font-weight:700; margin-bottom:8px;">✅ アカウントを発行しました（{{ $issued['id'] }}）</div>
          <div style="font-size:13px; line-height:1.9;">
            <div>氏名：<b>{{ $issued['name'] }}</b></div>
            <div>ログインID（メール）：<b>{{ $issued['email'] }}</b></div>
            <div>仮パスワード：<b style="font-size:16px; background:#fff; border:1px dashed #b7e0c2; padding:2px 10px; border-radius:6px; letter-spacing:1px;">{{ $issued['password'] }}</b></div>
          </div>
          <p style="font-size:12px; color:#166534; margin:10px 0 0;">
            この<b>メールとパスワード</b>を本人に伝えてください。本人が初回ログインすると、パスワード変更とプロフィール入力の画面が出ます。
          </p>
        </div>
      @endif

      <div class="panel" style="max-width:560px;">
        <form method="POST" action="/account-new">
          @csrf

          <div class="form-row">
            <label>種別<span class="req">必須</span></label>
            <select name="role" id="roleSel" required onchange="ECSonRole()">
              <option value="">選択してください</option>
              <option value="employee" @selected(old('role') === 'employee')>社員</option>
              <option value="staff" @selected(old('role') === 'staff')>スタッフ</option>
            </select>
          </div>

          <div class="form-row">
            <label>氏名<span class="req">必須</span></label>
            <input type="text" name="name" value="{{ old('name') }}" required placeholder="例）山田 太郎">
          </div>

          <div class="form-row">
            <label>メールアドレス（ログインID）<span class="req">必須</span></label>
            <input type="email" name="email" value="{{ old('email') }}" required placeholder="you@example.com">
            <span class="hint">このアドレスとパスワードでログインします。重複はできません。</span>
          </div>

          <div class="form-row" id="permRow">
            <label>権限<span class="req">必須</span></label>
            <select name="permission" id="permSel" required>
              <option value="staff" @selected(old('permission') === 'staff')>スタッフ（自分の希望・アサインのみ）</option>
              <option value="employee" @selected(old('permission') === 'employee')>社員（業務画面／削除・マスタ不可）</option>
              <option value="manager" @selected(old('permission') === 'manager')>管理者（アサイン担当／アカウント発行・名簿取込）</option>
              @if ($canGrantAdmin)
                <option value="admin" @selected(old('permission') === 'admin')>Administrator（全操作／削除・権限付与・システム設定）</option>
              @endif
            </select>
            <span class="hint" id="permHint"></span>
          </div>

          <div class="form-row">
            <label>事務所</label>
            <select name="office">
              <option value="">選択してください</option>
              @foreach (['東京','大阪','名古屋','福岡','東北','北海道'] as $opt)
                <option value="{{ $opt }}" @selected(old('office') === $opt)>{{ $opt }}</option>
              @endforeach
            </select>
          </div>

          <div class="form-row">
            <label>入社日／登録日</label>
            <input type="date" name="hire_date" value="{{ old('hire_date') }}">
            <span class="hint">区分（新人／中堅／ベテラン）の計算に使います。</span>
          </div>

          <div class="form-row">
            <label>仮パスワード</label>
            <input type="text" name="temp_password" value="{{ old('temp_password') }}" placeholder="空欄なら自動で作ります（6文字以上）">
            <span class="hint">本人に伝える最初のパスワードです。空欄のままなら、読み間違えにくいものを自動で発行します。</span>
          </div>

          <div style="margin-top:20px;">
            <button class="btn primary" type="submit">この内容でアカウントを発行</button>
          </div>
        </form>
      </div>

      <p class="muted" style="font-size:12px; margin:14px 0 0; max-width:560px;">
        ※ 権限＝「Administrator（全権）」を付けられるのは Administrator だけです。管理者はスタッフ／社員／管理者まで発行できます。
      </p>
@endsection

@push('scripts')
<script>window.ECS_CAN_GRANT_ADMIN = @json($canGrantAdmin);</script>
@verbatim
<script>
  // 種別（社員／スタッフ）に合わせて、選べる権限を出し分ける。
  //  ・スタッフ → 権限は「スタッフ」固定（選択不可）
  //  ・社員     → 社員／管理者（Administrator は発行者がAdministratorのときだけ）
  function ECSonRole(){
    var role = document.getElementById('roleSel').value;
    var sel  = document.getElementById('permSel');
    var hint = document.getElementById('permHint');
    var opts = sel.options;

    function setHidden(value, hidden){
      for (var i = 0; i < opts.length; i++){
        if (opts[i].value === value){ opts[i].hidden = hidden; opts[i].disabled = hidden; }
      }
    }

    // ⚠ 罠：グレーアウト（disabled）にした入力欄は、ブラウザが送信しない。
    //   スタッフを選ぶと権限欄をグレーにしていたため、画面に「スタッフ」と出ているのに
    //   サーバーには権限が空で届き、「権限は必ず入力してください」で弾かれていた（2026-08-21 修正）。
    //   見た目はグレーのまま残したいので、同じ値を見えない欄（hidden）で一緒に送る。
    function setPermFallback(value){
      var holder = document.getElementById('permHiddenHolder');
      if (!holder){
        holder = document.createElement('span');
        holder.id = 'permHiddenHolder';
        sel.parentNode.appendChild(holder);
      }
      holder.innerHTML = '';
      if (value !== null){
        var h = document.createElement('input');
        h.type  = 'hidden';
        h.name  = 'permission';
        h.value = value;
        holder.appendChild(h);
      }
    }

    if (role === 'staff'){
      setHidden('staff', false);
      setHidden('employee', true);
      setHidden('manager', true);
      setHidden('admin', true);
      sel.value = 'staff';
      sel.disabled = true;               // スタッフは権限=スタッフ固定（見た目だけ）
      setPermFallback('staff');          // 実際に送られるのはこちら
      hint.textContent = 'スタッフの権限は「スタッフ」になります。';
    } else if (role === 'employee'){
      setHidden('staff', true);
      setHidden('employee', false);
      setHidden('manager', false);
      setHidden('admin', !window.ECS_CAN_GRANT_ADMIN);
      sel.disabled = false;
      setPermFallback(null);             // 選択欄そのものが送られるので、hidden は外す
      if (sel.value === 'staff' || sel.value === '') sel.value = 'employee';
      hint.textContent = window.ECS_CAN_GRANT_ADMIN
        ? '社員のうち、アサイン担当は「管理者」、全権は「Administrator」を選びます。'
        : 'Administrator権限の付与は Administrator だけができます。';
    } else {
      sel.disabled = false;
      setPermFallback(null);
      hint.textContent = '';
    }
  }
  // 開いたとき・エラーで戻ったときにも状態を合わせる
  ECSonRole();
</script>
@endverbatim
@endpush
