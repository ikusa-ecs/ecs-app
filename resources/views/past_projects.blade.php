@extends('layouts.app')
@section('title', '過去案件')
@section('h1', '過去案件')
@php $active = 'past_projects'; @endphp

@push('head')
<style>
  /* ===== 過去案件（見るだけ）。色は共通の変数だけで書く＝3つのテーマぜんぶで読める ===== */
  .pp-wrap { display: flex; gap: 16px; align-items: flex-start; }

  /* 左：年 → 月のフォルダ */
  .pp-tree {
    flex: 0 0 200px; position: sticky; top: 12px;
    background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 8px;
  }
  .pp-year { margin-bottom: 2px; }
  .pp-year > summary {
    list-style: none; cursor: pointer; display: flex; align-items: center; gap: 6px;
    padding: 7px 8px; border-radius: 8px; font-weight: 800; font-size: 14px; color: var(--ink);
  }
  .pp-year > summary::-webkit-details-marker { display: none; }
  .pp-year > summary::before { content: '📁'; }
  .pp-year[open] > summary::before { content: '📂'; }
  .pp-year > summary:hover { background: var(--brand-soft); }
  .pp-year .pp-n { margin-left: auto; font-size: 11.5px; font-weight: 600; color: var(--muted); }
  .pp-months { padding: 2px 0 6px 18px; }
  .pp-month {
    display: flex; align-items: center; padding: 5px 10px; border-radius: 8px;
    text-decoration: none; font-size: 13.5px; color: var(--ink);
  }
  .pp-month:hover { background: var(--brand-soft); }
  .pp-month.on { background: var(--brand-fill); color: #fff; font-weight: 700; }
  .pp-month.on .pp-n { color: #fff; }

  /* 右：その月の案件 */
  .pp-main { flex: 1; min-width: 0; }
  .pp-head { display: flex; align-items: baseline; gap: 10px; margin: 2px 0 12px; }
  .pp-head .pp-title { font-size: 18px; font-weight: 800; color: var(--ink); }
  .pp-head .pp-sub { font-size: 12.5px; color: var(--muted); }

  .pp-case {
    background: var(--panel); border: 1px solid var(--line); border-radius: 12px;
    padding: 11px 14px; margin-bottom: 10px;
  }
  .pp-case.cancel { opacity: .55; }
  .pp-line { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; }
  .pp-date { font-weight: 800; font-variant-numeric: tabular-nums; color: var(--ink); min-width: 74px; }
  .pp-client { font-weight: 800; font-size: 14.5px; color: var(--ink); }
  .pp-content { font-size: 14px; color: var(--brand); font-weight: 700; }
  .pp-meta { font-size: 12px; color: var(--muted); margin: 4px 0 8px; }
  .pp-tag {
    display: inline-block; font-size: 11px; font-weight: 700; border-radius: 999px;
    padding: 1px 8px; border: 1px solid var(--line); color: var(--muted);
  }
  .pp-tag.cancel { border-color: currentColor; }

  .pp-mems { display: flex; gap: 6px; flex-wrap: wrap; }
  .pp-mem {
    display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px;
    background: var(--surface-2, var(--brand-soft)); border: 1px solid var(--line);
    border-radius: 6px; padding: 2px 8px; color: var(--ink);
  }
  .pp-mem .r { font-size: 10.5px; font-weight: 800; color: var(--brand); }
  .pp-mem.tent { border-style: dashed; color: var(--muted); }
  .pp-none { font-size: 12.5px; color: var(--muted); }

  .pp-empty {
    background: var(--panel); border: 1px solid var(--line); border-radius: 12px;
    padding: 40px 20px; text-align: center; color: var(--muted);
  }

  /* スマホ＝フォルダを上、案件を下に積む */
  @media (max-width: 720px) {
    .pp-wrap { flex-direction: column; }
    .pp-tree { position: static; width: 100%; flex-basis: auto; box-sizing: border-box; }
  }
</style>
@endpush

@section('content')

<div class="mock-note">
  開催日が過ぎた案件を、<b>年 → 月</b>の順に開いて見る画面です（<b>見るだけ</b>で、ここからは直せません）。
  メンバーはキャンセル以外を出しています。点線のメンバーは「仮」のまま終わったアサインです。
  ここに出る案件とアサインは、経験回数・クライアント別アサイン履歴・集計にもそのまま数えられます。
</div>

@include('partials.office_switch')

@if (empty($years))
  <div class="pp-empty">過去の案件はまだありません。</div>
@else
  <div class="pp-wrap">
    <nav class="pp-tree" aria-label="年と月">
      @foreach ($years as $y)
        <details class="pp-year" {{ str_starts_with($ym, $y['year'].'-') ? 'open' : '' }}>
          <summary>{{ $y['year'] }}年 <span class="pp-n">{{ $y['total'] }}件</span></summary>
          <div class="pp-months">
            @foreach ($y['months'] as $mo)
              <a class="pp-month {{ $mo['ym'] === $ym ? 'on' : '' }}"
                 href="/past-projects?ym={{ $mo['ym'] }}&office={{ urlencode($officeParam) }}">
                {{ $mo['month'] }}月 <span class="pp-n">{{ $mo['count'] }}件</span>
              </a>
            @endforeach
          </div>
        </details>
      @endforeach
    </nav>

    <div class="pp-main">
      <div class="pp-head">
        <span class="pp-title">{{ $ymLabel }}</span>
        <span class="pp-sub">{{ count($cases) }}件</span>
      </div>

      @forelse ($cases as $c)
        <div class="pp-case {{ $c['cancelled'] ? 'cancel' : '' }}">
          <div class="pp-line">
            <span class="pp-date">{{ $c['dateLabel'] }}{{ $c['dowLabel'] }}</span>
            <span class="pp-client">{{ $c['client'] !== '' ? $c['client'] : '（会社名なし）' }}</span>
            <span class="pp-content">{{ $c['content'] }}</span>
            @if ($c['cancelled'])
              <span class="pp-tag cancel">キャンセル</span>
            @endif
          </div>
          <div class="pp-meta">
            @if ($c['title'] !== '')
              {{ $c['title'] }}
            @endif
            @if ($c['time'] !== '')
              集合 {{ $c['time'] }}
            @endif
            @if ($c['place'] !== '')
              📍 {{ $c['place'] }}
            @endif
            @if ($c['scale'] !== '')
              <span class="pp-tag">{{ $c['scale'] }}</span>
            @endif
            @if ($c['office'] !== '' && $officeScope === null)
              <span class="pp-tag">{{ $c['office'] }}</span>
            @endif
          </div>
          @if (! empty($c['members']))
            <div class="pp-mems">
              @foreach ($c['members'] as $mem)
                <span class="pp-mem {{ $mem['tentative'] ? 'tent' : '' }}" title="{{ $mem['tentative'] ? '仮のまま' : '確定' }}">
                  @if ($mem['role'] !== '')
                    <span class="r">{{ $mem['role'] }}</span>
                  @endif
                  {{ $mem['name'] }}
                </span>
              @endforeach
            </div>
          @elseif (empty($c['dispatches']))
            <div class="pp-none">メンバーの記録はありません。</div>
          @endif
          @include('partials.dispatch_rows', ['dispatches' => $c['dispatches']])
        </div>
      @empty
        <div class="pp-empty">この月の案件はありません。</div>
      @endforelse
    </div>
  </div>
@endif

@endsection
