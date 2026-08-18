@extends('layouts.app')
@section('title', '案件の編集履歴')
@section('h1', '案件の編集履歴')
@php($active = 'project_history')

@push('head')
@verbatim
<style>
  .ph-intro { font-size: 13px; color: var(--muted); line-height: 1.8; margin-bottom: 14px; }
  .ph-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
  .ph-bar label { font-size: 12.5px; color: var(--muted); font-weight: 600; }
  .ph-bar select {
    padding: 8px 11px; border: 1px solid var(--line); border-radius: 8px;
    font-size: 13.5px; font-family: inherit; background: #fff; max-width: 320px;
  }
  .ph-count { font-size: 12.5px; color: var(--muted); margin-left: auto; }
  .ph-pager { display: flex; gap: 10px; align-items: center; margin-top: 16px; font-size: 13px; }
  .ph-pager .spacer { flex: 1; }
  /* スマホでは絞り込みを縦に積む（横並びだと1行に収まらず押しにくいため） */
  @media (max-width: 720px) {
    .ph-bar { flex-direction: column; align-items: stretch; }
    .ph-bar select { max-width: none; }
    .ph-count { margin-left: 0; }
  }
</style>
@endverbatim
@endpush

@section('content')

<p class="ph-intro">
  案件の内容を「いつ・誰が・何を・何から何に」変えたかの記録です。<br>
  案件登録画面・案件一覧・アサイン表・公開ボード・ディレクター決めのどこから変えても、ここに残ります。<br>
  ※ 見るだけの画面です。記録は消せません（消せると記録の意味が無くなるため）。
</p>

@if ($needsSetup)
  {{-- 履歴の保存先テーブルがまだ無いサーバー（`php artisan migrate` 未実行）。
       エラー画面にせず、何をすれば記録が始まるかだけ伝える。 --}}
  <div class="panel">
    <p class="hist-empty">
      まだ変更の記録はありません。<br>
      <small>※ このサーバーでは履歴の保存場所がまだ作られていません。サーバーで <code>php artisan migrate</code> を1回実行すると、そのあとの変更がここに残るようになります。</small>
    </p>
  </div>
@else

{{-- 絞り込み。選ぶとそのまま送信する（「表示」ボタンを押させない）。 --}}
<div class="panel">
  <form method="get" action="/project-history" class="ph-bar">
    <label for="ph-project">案件</label>
    <select name="project" id="ph-project" onchange="this.form.submit()">
      <option value="">すべての案件</option>
      @foreach ($projectOptions as $opt)
        <option value="{{ $opt->project_id }}" @selected($projectId === $opt->project_id)>
          {{ $opt->project_name ?: $opt->project_id }}
        </option>
      @endforeach
    </select>

    <label for="ph-person">変えた人</label>
    <select name="person" id="ph-person" onchange="this.form.submit()">
      <option value="">全員</option>
      @foreach ($peopleOptions as $opt)
        <option value="{{ $opt->person_id }}" @selected($personId === $opt->person_id)>
          {{ $opt->person_name ?: $opt->person_id }}
        </option>
      @endforeach
    </select>

    <label for="ph-period">期間</label>
    <select name="period" id="ph-period" onchange="this.form.submit()">
      @foreach ($periods as $value => $label)
        <option value="{{ $value }}" @selected($period === $value)>{{ $label }}</option>
      @endforeach
    </select>

    <span class="ph-count">{{ number_format($histories->total()) }}件</span>
  </form>
</div>

<div class="panel">
  @if ($projectLabel)
    <div class="sec-title">{{ $projectLabel }} の編集履歴</div>
  @endif

  @if ($histories->isEmpty())
    <p class="hist-empty">この条件に当てはまる記録はありません。期間を「すべて」にすると、もっと古いところまで見られます。</p>
  @else
    <ul class="hist-list">
      @foreach ($histories as $h)
        <li class="hist-item">
          <div class="hist-meta">
            <span class="hist-when">{{ $h->created_at?->format('Y/m/d H:i') }}</span>
            <span class="hist-who">{{ $h->person_name ?: 'システム' }}</span>
            @if ($projectId === '')
              <span class="hist-project">
                <a href="/project-form?project={{ urlencode($h->project_id) }}">{{ $h->project_name ?: $h->project_id }}</a>
              </span>
            @endif
          </div>
          @if ($h->action === 'created')
            <div class="hist-body"><span class="hist-tag new">新規登録</span>この案件を登録しました。</div>
          @elseif ($h->action === 'deleted')
            <div class="hist-body"><span class="hist-tag del">削除</span>この案件を削除しました。</div>
          @else
            <div class="hist-body">
              <span class="hist-field">{{ $h->field_label ?: $h->field }}</span>
              <span class="hist-old">{{ $h->old_value }}</span>
              <span class="hist-arrow">→</span>
              <span class="hist-new">{{ $h->new_value }}</span>
            </div>
          @endif
        </li>
      @endforeach
    </ul>

    {{-- ページ送り。Laravel標準の見た目は他の画面と揃わないので、前後リンクだけ自分で置く。 --}}
    @if ($histories->hasPages())
      <div class="ph-pager">
        @if ($histories->previousPageUrl())
          <a class="btn sm" href="{{ $histories->previousPageUrl() }}">← 新しい{{ $histories->perPage() }}件</a>
        @endif
        <span class="spacer"></span>
        <span class="ph-count">{{ $histories->currentPage() }} / {{ $histories->lastPage() }} ページ</span>
        <span class="spacer"></span>
        @if ($histories->nextPageUrl())
          <a class="btn sm" href="{{ $histories->nextPageUrl() }}">古い{{ $histories->perPage() }}件 →</a>
        @endif
      </div>
    @endif
  @endif
</div>

@endif

@endsection
