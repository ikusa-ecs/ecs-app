{{-- 氏名・所属・身長などの入力欄（2026-09-01）。

     マイプロフィール `/profile` と **マイページ** の2画面が、これを読み込んで同じ欄を出す。
     ⚠ ここを増やしたら、保存の正本 App\Support\ProfileBasics にも列を足すこと
       （画面に欄があるのに保存されない、が起きる）。
     ⚠ フォームの外枠（form・csrf・保存ボタン）は入れない＝呼ぶ側の画面が持つ。
       送り先が違うため（/profile は全部・マイページは氏名まわりだけ）。

     使う変数：$me（本人）。 --}}

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

{{-- チャットワークID＝リマインドを確実に本人へ届けるために使う。
     いまは氏名とチャットワークの表示名を突き合わせているので、表記ゆれで外れることがある。 --}}
<div class="form-row">
  <label>チャットワークID</label>
  <input type="text" name="chatwork_id" value="{{ $me->chatwork_id }}" placeholder="例）1234567">
  <span class="hint">
    チャットワークからのリマインド（人数確定・収支の締切など）を、あなた宛に確実に届けるために使います。<br>
    調べ方＝チャットワークの「マイチャット」右上のプロフィール、または自分のアカウント画面に出ている数字です。
  </span>
</div>

{{-- ⚠ 事務所の選択肢は拠点マスタ（offices）から出す。
     2026-09-01 まで画面に直書きだったので、マスタ管理で拠点を足しても
     ここに出てこなかった（拠点ごとの選択肢で何度も踏んでいる形）。
     マスタが空のときだけ、これまでの並びで代用する（選べる物がゼロになるのを防ぐ）。 --}}
@php($officeOptions = \App\Support\OfficeScope::options() ?: ['東京', '大阪', '名古屋', '福岡', '東北', '北海道'])
<div class="form-row">
  <label>事務所</label>
  <select name="office">
    <option value="">選択してください</option>
    @foreach ($officeOptions as $opt)
      <option value="{{ $opt }}" @selected($me->office === $opt)>{{ $opt }}</option>
    @endforeach
  </select>
  <span class="hint">所属している地域の事務所を選んでください。</span>
</div>

{{-- 身長・靴・服（衣装）サイズ・都道府県・最寄り駅：当日のユニフォーム／衣装の準備の参考。
     もともと新規登録で聞いていた項目。社員・スタッフ共通。 --}}
{{-- 入社年月日（2026-09-01 baba要望）。
     ⚠ これまで初回の初期設定（/onboarding）でしか入れられず、**間違えても本人が直せなかった**。
       実際に「入社年月日のつもりで生年月日を入れてしまった」方が出た。
     ⚠ 名簿の並び順（社歴の長い人が上）と、区分（新人／中堅／ベテラン）の計算に使う値。 --}}
<div class="form-row">
  <label>{{ $me->role === 'staff' ? 'IKUSAで働き始めた年月' : '入社年月日（IKUSAで働き始めた日）' }}</label>
  {{-- 2026-09-03 「入力しにくい」＝カレンダーが今月から開いて何年も戻すのが大変だったので、
       年・月・日のプルダウンに変えた。入力欄の中身は partials/hire_date_selects が正本。 --}}
  @include('partials.hire_date_selects', ['value' => $me->hire_date])
  <span class="hint">
    名簿の並び順（社歴の長い方が上）と、区分（新人／中堅／ベテラン）の計算に使います。日にちが分からなければ1日のままで構いません。
  </span>
</div>

<div class="form-row">
  <label>身長</label>
  <input type="text" name="height" value="{{ $me->height }}" placeholder="例）170（cm）">
</div>

<div class="form-row">
  <label>靴（足袋）のサイズ</label>
  <input type="text" name="shoe_size" value="{{ $me->shoe_size }}" placeholder="例）26.5cm">
</div>

<div class="form-row">
  <label>服（衣装）のサイズ</label>
  <select name="shirt_size">
    <option value="">選択してください</option>
    @foreach (['SS', 'S', 'M', 'L', 'LL', '3L'] as $opt)
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

@if ($me->role === 'employee')
  <div class="form-row">
    <label>主な所属</label>
    <select name="department">
      <option value="">選択してください</option>
      @foreach (\App\Support\Departments::ALL as $opt)
        <option value="{{ $opt }}" @selected($me->department === $opt)>{{ $opt }}</option>
      @endforeach
    </select>
    <span class="hint">いちばん主な所属を1つ選んでください。部署別の集計は、この所属で1回だけ数えます。</span>
  </div>

  {{-- 兼務＝所属を兼ねている人がいる（2026-08-24 baba）。集計の二重計上を避けるため、
       「主な所属」1つと「兼務を含む所属すべて」を分けて持つ。 --}}
  <div class="form-row">
    <label>兼務している所属</label>
    {{-- 「この欄を送りました」の印。チェックは選んだものしか送られないので、
         印が無いと全部外した状態を保存できない。決まりの正本＝App\Support\ProfileBasics。 --}}
    <input type="hidden" name="departments_sent" value="1">
    <div style="display:flex; flex-wrap:wrap; gap:6px 16px;">
      @foreach (\App\Support\Departments::ALL as $opt)
        <label style="display:inline-flex; align-items:center; gap:6px; font-weight:400; font-size:13.5px;">
          <input type="checkbox" name="departments[]" value="{{ $opt }}"
                 style="width:auto;" @checked(in_array($opt, $me->departmentList(), true))>
          {{ $opt }}
        </label>
      @endforeach
    </div>
    <span class="hint">兼務がある方は、兼ねている所属もチェックしてください（主な所属は自動で入ります）。兼務が無ければ何もしなくて大丈夫です。</span>
  </div>
@endif
