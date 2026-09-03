{{--
  入社年月日の入力欄（年・月・日の3つのプルダウン）。2026-09-03 baba要望「入力しにくい」。

  ⚠ もとは `<input type="date">` 1個だった。カレンダーが今月から開くので
    2018年まで戻すのに「前の月」を何十回も押すことになっていた。
  ⚠ 見出し（ラベル）と説明文は**呼ぶ側**が書く（画面ごとに見た目の決まりが違うため）。
    ここは入力欄だけを出す。

  使い方： @include('partials.hire_date_selects', ['value' => $me->hire_date, 'required' => true])
    value    … 保存済みの値（Carbon でも 'Y-m-d' の文字列でも可・未入力なら null）
    required … 必ず選んでほしいとき true（省略時 false）
    idPrefix … JavaScript から読み書きする画面用。'pfHire' を渡すと id が pfHireY/pfHireM/pfHireD になる

  送られる名前は hire_y / hire_m / hire_d。
  これを App\Http\Middleware\NormalizeHireDate が hire_date に組み立て直すので、
  受け取る側（コントローラ）は今までどおり hire_date を見るだけでよい。
--}}
@php
    $hdRaw = $value ?? null;
    $hdStr = $hdRaw instanceof \DateTimeInterface ? $hdRaw->format('Y-m-d') : (string) $hdRaw;
    // 入力し直しのとき（エラーで戻ってきたとき）は、打ち直さなくて済むように送った値を優先する。
    $hdParts = \App\Support\HireDate::parts($hdStr);
    $hdY = (string) old('hire_y', $hdParts['y']);
    $hdM = (string) old('hire_m', $hdParts['m']);
    $hdD = (string) old('hire_d', $hdParts['d']);
    $hdReq = $required ?? false;
    $hdId = $idPrefix ?? '';
@endphp
<span class="hire-date-selects" style="display:inline-flex; align-items:center; gap:4px; flex-wrap:wrap;">
  <select name="hire_y" @if ($hdId) id="{{ $hdId }}Y" @endif style="width:auto; min-width:96px;" @if ($hdReq) required @endif>
    <option value="">— 年 —</option>
    @foreach (\App\Support\HireDate::years() as $y)
      <option value="{{ $y }}" @selected($hdY === (string) $y)>{{ $y }}年</option>
    @endforeach
  </select>
  <select name="hire_m" @if ($hdId) id="{{ $hdId }}M" @endif style="width:auto; min-width:76px;" @if ($hdReq) required @endif>
    <option value="">— 月 —</option>
    @for ($m = 1; $m <= 12; $m++)
      <option value="{{ $m }}" @selected($hdM === (string) $m)>{{ $m }}月</option>
    @endfor
  </select>
  <select name="hire_d" @if ($hdId) id="{{ $hdId }}D" @endif style="width:auto; min-width:76px;">
    {{-- ⚠ 日は「分からなければ1日」でよい運用。最初から1日を選んだ状態にして、迷わせない。 --}}
    @for ($d = 1; $d <= 31; $d++)
      <option value="{{ $d }}" @selected(($hdD !== '' ? $hdD : '1') === (string) $d)>{{ $d }}日</option>
    @endfor
  </select>
</span>
