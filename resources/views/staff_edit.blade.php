@extends('layouts.app')
@section('title', 'スタッフ編集')
@section('h1', 'スタッフ編集')
@php($active = 'staff')

@push('head')
<style>
    .se-info { color: var(--muted); font-size: 12.5px; margin: 2px 0 14px; }
    .se-head { font-size: 18px; font-weight: 700; }
    .se-head .id { color: var(--muted); font-size: 12.5px; font-weight: 400; margin-left: 8px; }
    .se-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    @media (max-width: 900px) { .se-grid { grid-template-columns: 1fr; } }
    .se-block h4 { margin: 0 0 8px; font-size: 14px; }
    .se-checks { display: flex; flex-wrap: wrap; gap: 8px 16px; }
    .se-checks label { display: inline-flex; align-items: center; gap: 6px; font-size: 13.5px; cursor: pointer; }
    .se-checks input[type=checkbox] { width: 17px; height: 17px; accent-color: var(--brand); cursor: pointer; }
    .se-block textarea {
      width: 100%; min-height: 90px; padding: 8px 10px; border: 1px solid var(--line);
      border-radius: 8px; font-family: inherit; font-size: 13.5px; background: #fff; resize: vertical;
    }
    .se-block .hint { color: var(--muted); font-size: 11.5px; margin-top: 4px; }
    .se-save { display: flex; align-items: center; gap: 12px; margin-top: 18px; }
</style>
@endpush

@section('content')

  @if (session('status'))
    <div class="alert ok" style="margin-bottom:14px;"><span class="ico">✓</span><div>{{ session('status') }}</div></div>
  @endif

  <p class="se-info">ここで保存した内容は<b>本物のデータ</b>としてDBに残ります（できるポジション・NGペア・専属・人柄・メモ）。アサイン画面の候補しぼり込みに使われます。</p>

  <div class="panel">
    <div class="se-head">{{ $person->name }}<span class="id">{{ $person->id }}</span></div>
  </div>

  <form method="POST" action="/staff/{{ urlencode($person->id) }}/edit">
    @csrf

    <div class="se-grid" style="margin-top:14px;">
      <div class="panel se-block">
        <h4>できるポジション（現場の役割）</h4>
        <div class="se-checks">
          @foreach ($positionLabels as $code => $label)
            <label>
              <input type="checkbox" name="positions[]" value="{{ $code }}" {{ in_array($code, $canPositions, true) ? 'checked' : '' }}>
              {{ $label }}
            </label>
          @endforeach
        </div>

        <h4 style="margin-top:16px;">区分</h4>
        <div class="se-checks">
          <label><input type="checkbox" name="exclusive" value="1" {{ $person->is_exclusive ? 'checked' : '' }}> 専属スタッフ</label>
        </div>

        <h4 style="margin-top:16px;">人柄・育成メモ</h4>
        <div class="se-checks" style="flex-direction:column; gap:6px;">
          <label><input type="checkbox" name="follow" value="1" {{ $person->can_follow_newbie ? 'checked' : '' }}> 新人フォローができる</label>
          <label><input type="checkbox" name="starter" value="1" {{ $person->self_starter ? 'checked' : '' }}> 自分で考えて動ける</label>
          <label><input type="checkbox" name="atmos" value="1" {{ $person->improves_atmosphere ? 'checked' : '' }}> 現場の空気を良くする</label>
        </div>
      </div>

      <div class="panel se-block">
        <h4>NGペア（同席を避ける組合せ）</h4>
        <textarea name="ng" placeholder="NGにしたい相手の氏名を1行に1名ずつ書いてください（例）&#10;山田 太郎&#10;佐藤 花子">{{ implode("\n", $ngNames) }}</textarea>
        <div class="hint">1行に1名。登録済みのスタッフ名と一致すれば自動でひも付きます（未登録の名前でも保存できます）。</div>

        <h4 style="margin-top:16px;">メモ（初回アサイン時のDアンケート要点など）</h4>
        <textarea name="impression" placeholder="このスタッフについてのメモ">{{ $person->planner_impression }}</textarea>
      </div>
    </div>

    <div class="se-save">
      <button class="btn primary" type="submit">保存する</button>
      <a class="btn" href="/staff">スタッフ一覧に戻る</a>
    </div>
  </form>

@endsection
