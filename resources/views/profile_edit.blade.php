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

          {{-- ① 共通項目（社員・スタッフ両方） --}}
          <h2>基本情報</h2>

          <div class="form-row">
            <label>氏名<span class="req">必須</span></label>
            <input type="text" name="name" value="{{ $me->name }}" required>
          </div>

          {{-- ふりがな＝名簿やプルダウンを五十音順に並べるために使う（漢字だけでは並べられない）。 --}}
          <div class="form-row">
            <label>ふりがな</label>
            <input type="text" name="name_kana" value="{{ $me->name_kana }}" placeholder="例）やまだ たろう">
            <span class="hint">ひらがなで入れてください。名前を五十音順に並べるために使います。</span>
          </div>

          <div class="form-row">
            <label>メールアドレス</label>
            <input type="email" name="email" value="{{ $me->email }}" placeholder="you@example.com">
            <span class="hint">ログインにも使うアドレスです。</span>
          </div>

          <div class="form-row">
            <label>事務所</label>
            <select name="office">
              <option value="">選択してください</option>
              @foreach (['東京','大阪','名古屋','福岡','東北','北海道'] as $opt)
                <option value="{{ $opt }}" @selected($me->office === $opt)>{{ $opt }}</option>
              @endforeach
            </select>
            <span class="hint">所属している地域の事務所を選んでください。</span>
          </div>

          {{-- 身長・靴・服（衣装）サイズ・都道府県・最寄り駅：当日のユニフォーム／衣装の準備の参考。
               もともと新規登録で聞いていた項目を、本人がここ（＝初回ログインの初期設定）で入れる。社員・スタッフ共通。 --}}
          <div class="form-row">
            <label>身長</label>
            <input type="text" name="height" value="{{ $me->height }}" placeholder="例）170（cm）">
          </div>

          <div class="form-row">
            <label>靴のサイズ</label>
            <input type="text" name="shoe_size" value="{{ $me->shoe_size }}" placeholder="例）26.5cm">
          </div>

          <div class="form-row">
            <label>服（衣装）のサイズ</label>
            <select name="shirt_size">
              <option value="">選択してください</option>
              @foreach (['SS','S','M','L','LL','3L'] as $opt)
                <option value="{{ $opt }}" @selected($me->shirt_size === $opt)>{{ $opt }}</option>
              @endforeach
            </select>
            <span class="hint">当日の衣装・ユニフォームの準備に使います。</span>
          </div>

          <div class="form-row">
            <label>都道府県</label>
            <input type="text" name="prefecture" value="{{ $me->prefecture }}" placeholder="例）千葉県">
          </div>

          <div class="form-row">
            <label>最寄り駅</label>
            <input type="text" name="nearest_station" value="{{ $me->nearest_station }}" placeholder="例）JR千葉駅">
          </div>

          {{-- ② 社員（employee）だけの項目 --}}
          @if ($me->role === 'employee')
            <h2 style="margin-top:24px;">所属（社員）</h2>

            <div class="form-row">
              <label>所属</label>
              <select name="department">
                <option value="">選択してください</option>
                @foreach (['イベプラ','セールス','クリエイティブ'] as $opt)
                  <option value="{{ $opt }}" @selected($me->department === $opt)>{{ $opt }}</option>
                @endforeach
              </select>
              <span class="hint">あなたの主な担当を選んでください。</span>
            </div>
          @endif

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
