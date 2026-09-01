@extends('layouts.app')
@section('title', 'マイプロフィール')
@section('h1', 'マイプロフィール')
@php($active = 'profile')

@section('content')
      {{-- なぜ／使い方：ログイン中のあなた自身の情報を、自分で入力・更新する画面です。 --}}
      <div class="mock-note">
        あなた自身のプロフィールです。ここで入れた内容は当日の準備やメンバー決めの参考に使われます。いつでも直せます。
      </div>

      @if (session('status'))
        <div style="background:var(--ok-soft, #e7f6ec); color:#166534; border:1px solid #b7e0c2; border-radius:10px; padding:12px 14px; font-size:13px; margin-bottom:16px; max-width:560px;">
          {{ session('status') }}
        </div>
      @endif

      @if ($errors->any())
        <div style="background:#fdecec; color:#b91c1c; border:1px solid #f3c0c0; border-radius:10px; padding:12px 14px; font-size:13px; margin-bottom:16px; max-width:560px;">
          {{ $errors->first() }}
        </div>
      @endif

      <div class="panel" style="max-width:560px;">
        <form method="POST" action="/profile">
          @csrf

          {{-- ① 共通項目（社員・スタッフ両方）＋ 所属（社員）
               ⚠ 入力欄はマイページと同じものを出す＝共通部品にしてある（2026-09-01）。
                 書き写すと片方だけ直して食い違うため。列を増やすときは
                 partials/profile_basic_fields.blade.php と App\Support\ProfileBasics の両方。 --}}
          <h2>基本情報</h2>

          @include('partials.profile_basic_fields')

          {{-- ①-2 できること・やってみたいこと（社員・スタッフ共通・2026-08-31 baba要望）
               なぜ聞くか＝アサインを決めるときの材料。車を出せる人・英語で対応できる人を
               その都度聞いて回らなくて済むようにする。挑戦したい役割は「次に何を任せるか」の参考。
               選択肢の正本＝App\Support\ProfileOptions（画面に直書きしない）。 --}}
          <h2 style="margin-top:24px;">できること・やってみたいこと</h2>

          <div class="form-row">
            <label>運転</label>
            <select name="driving_level">
              @foreach (\App\Support\ProfileOptions::drivingChoices() as $opt)
                <option value="{{ $opt }}" @selected(($me->driving_level ?? \App\Support\ProfileOptions::NONE) === $opt)>{{ $opt }}</option>
              @endforeach
            </select>
            <span class="hint">機材を積むハイエースを運転できるかどうかで、当日の車の手配が変わります。</span>
          </div>

          <div class="form-row">
            <label>英語</label>
            <select name="english_level">
              @foreach (\App\Support\ProfileOptions::englishChoices() as $opt)
                <option value="{{ $opt }}" @selected(($me->english_level ?? \App\Support\ProfileOptions::NONE) === $opt)>{{ $opt }}</option>
              @endforeach
            </select>
            <span class="hint">英語で進行・対応する案件のときに参考にします。</span>
          </div>

          <div class="form-row">
            <label>その他話せる言語</label>
            <input type="text" name="other_languages" value="{{ $me->other_languages }}" placeholder="例）中国語（日常会話）・韓国語（片言）">
            <span class="hint">英語以外に話せる言語があれば、レベルも添えて書いてください。</span>
          </div>

          <div class="form-row">
            <label>チャレンジしたいポジション</label>
            {{-- ⚠ 「この欄を送りました」の印。チェックは選んだものしか送られないので、
                 印が無いと**全部外した状態を保存できない**（一度入れたら消せない画面になる）。
                 決まりの正本＝App\Support\ProfileExtras。 --}}
            <input type="hidden" name="challenge_positions_sent" value="1">
            <div style="display:flex; flex-wrap:wrap; gap:6px 16px;">
              @foreach (\App\Support\ProfileOptions::CHALLENGE_POSITIONS as $opt)
                <label style="display:inline-flex; align-items:center; gap:6px; font-weight:400; font-size:13.5px;">
                  <input type="checkbox" name="challenge_positions[]" value="{{ $opt }}"
                         style="width:auto;" @checked(in_array($opt, (array) $me->challenge_positions, true))>
                  {{ $opt }}
                </label>
              @endforeach
            </div>
            <span class="hint">今できるかどうかは気にせず、やってみたいものを選んでください。次に任せる役割を決めるときの参考にします。</span>
          </div>

          <div class="form-row">
            <label>日常で使っているオンラインツール</label>
            <input type="hidden" name="online_tools_sent" value="1">
            <div style="display:flex; flex-wrap:wrap; gap:6px 16px;">
              @foreach (\App\Support\ProfileOptions::ONLINE_TOOLS as $opt)
                <label style="display:inline-flex; align-items:center; gap:6px; font-weight:400; font-size:13.5px;">
                  <input type="checkbox" name="online_tools[]" value="{{ $opt }}"
                         style="width:auto;" @checked(in_array($opt, (array) $me->online_tools, true))>
                  {{ $opt }}
                </label>
              @endforeach
            </div>
            <span class="hint">ひととおり使えるものを選んでください。オンライン案件や資料づくりを頼むときの参考にします。</span>
          </div>

          <div class="form-row">
            <label>その他のオンラインツール</label>
            <input type="text" name="online_tools_other" value="{{ $me->online_tools_other }}" placeholder="例）Miro・Figma">
            <span class="hint">上の一覧に無いものがあれば書いてください。</span>
          </div>

          <div class="form-row">
            <label>その他備考</label>
            <textarea name="profile_note" placeholder="例）運転練習中です。簡単な動画編集ができます。">{{ $me->profile_note }}</textarea>
            <span class="hint">上のどれにも当てはまらないことで、伝えておきたいことがあれば自由に書いてください。</span>
          </div>

          {{-- ③ スタッフ（staff）だけの項目 --}}
          @if ($me->role === 'staff')
            <h2 style="margin-top:24px;">プロフィール（スタッフ）</h2>

            <div class="form-row">
              <label>一言アピール</label>
              <textarea name="appeal" placeholder="例）元気な進行が得意です！">{{ $me->appeal }}</textarea>
            </div>

            <div class="form-row">
              <label>好きなコンテンツ</label>
              <input type="text" name="liked_contents" value="{{ $me->liked_contents }}" placeholder="例）運動会・水合戦">
            </div>

            <div class="form-row">
              <label>苦手なコンテンツ</label>
              <input type="text" name="disliked_contents" value="{{ $me->disliked_contents }}" placeholder="例）オンライン配信">
            </div>

            <div class="form-row">
              <label>得意なポジション</label>
              <textarea name="strong_positions" placeholder="例）盛り上げ役が好きです。／裏方の段取りが得意です。">{{ $me->strong_positions }}</textarea>
            </div>

            <div class="form-row">
              <label>苦手なポジション</label>
              <textarea name="weak_positions" placeholder="例）細かい受付業務はやや苦手です。">{{ $me->weak_positions }}</textarea>
            </div>

            <div class="form-row">
              <label style="display:flex; align-items:center; gap:8px; font-weight:600;">
                <input type="checkbox" name="can_stay_over" value="1" style="width:auto;" @checked($me->can_stay_over)>
                前泊・後泊できる
              </label>
              <span class="hint">遠方の案件で前日入り・翌日帰りが可能なら入れてください。</span>
            </div>

            <div class="form-row">
              <label style="display:flex; align-items:center; gap:8px; font-weight:600;">
                <input type="checkbox" name="can_kigurumi" value="1" style="width:auto;" @checked($me->can_kigurumi)>
                着ぐるみOK
              </label>
              <span class="hint">着ぐるみを着ての進行・演出ができるなら入れてください。</span>
            </div>
          @endif

          <div style="margin-top:20px;">
            <button class="btn primary" type="submit">保存</button>
          </div>
          <p class="muted" style="font-size:12px; margin:12px 0 0;">
            「保存」を押すと、この内容がサーバに記録されます。あとから何度でも直せます。
          </p>
        </form>
      </div>
@endsection
