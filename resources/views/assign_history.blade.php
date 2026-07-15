@extends('layouts.app')
@section('title', 'クライアント別アサイン履歴')
@section('h1', 'クライアント別アサイン履歴')
@php $active = 'assign_history'; @endphp

@push('head')
<style>
  /* ===== クライアント別アサイン履歴 専用スタイル（くすみ暖色テーマに合わせる） ===== */

  /* 上部の説明・絞り込みバー */
  .hist-controls {
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    background: var(--panel); border: 1px solid var(--line); border-radius: 12px;
    padding: 10px 14px; margin-bottom: 14px;
  }
  .hist-controls label { font-size: 12.5px; font-weight: 600; color: var(--muted); }
  .hist-controls select {
    padding: 7px 10px; border: 1px solid var(--line); border-radius: 8px;
    font-size: 13.5px; font-family: inherit; background: #fff; color: var(--ink); min-width: 180px;
  }
  .hist-controls .spacer { flex: 1; }
  .hist-controls .count { font-size: 12.5px; color: var(--muted); }

  /* クライアント1件＝1カード */
  .client-card {
    background: #fff; border: 1px solid var(--line); border-radius: 12px;
    box-shadow: 0 1px 2px rgba(60,45,30,.06); margin-bottom: 16px; overflow: hidden;
  }
  .client-head {
    display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap;
    background: var(--brand-soft); color: var(--brand-dark);
    padding: 11px 16px; border-bottom: 1px solid var(--line);
  }
  .client-head .cname { font-size: 15.5px; font-weight: 800; }
  .client-head .cmeta { font-size: 12px; color: #7a6a58; }

  /* 常連スタッフ */
  .regulars { padding: 11px 16px; border-bottom: 1px solid var(--line); background: #faf6ee; }
  .regulars .rtitle { font-size: 11.5px; font-weight: 700; color: var(--muted); margin-bottom: 7px; }
  .reg-chips { display: flex; gap: 7px; flex-wrap: wrap; }
  .reg-chip {
    display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px;
    background: #fff; border: 1px solid var(--line); border-radius: 999px; padding: 3px 11px;
  }
  .reg-chip .rname { color: var(--ink); font-weight: 600; }
  .reg-chip .rcount { font-size: 11px; font-weight: 700; color: #fff; background: var(--brand);
    border-radius: 999px; padding: 0 7px; }
  .reg-empty { font-size: 12.5px; color: var(--muted); }

  /* 過去案件の一覧 */
  .proj-list { padding: 6px 16px 14px; }
  .proj-item { padding: 10px 0; border-bottom: 1px solid #f2ece3; }
  .proj-item:last-child { border-bottom: none; }
  .proj-item .pline { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; margin-bottom: 6px; }
  .proj-item .pdate { font-size: 12.5px; font-variant-numeric: tabular-nums; color: var(--muted);
    min-width: 118px; }
  .proj-item .ptitle { font-size: 14px; font-weight: 700; color: var(--ink); }
  .mem-chips { display: flex; gap: 6px; flex-wrap: wrap; }
  .mem-chip {
    display: inline-flex; align-items: center; gap: 5px; font-size: 12px;
    background: #f6f1ea; border: 1px solid var(--line); border-radius: 6px; padding: 2px 8px;
  }
  .mem-chip .mrole { font-size: 10px; font-weight: 800; color: #4a6484; }
  .mem-chip .mname { color: var(--ink); }
  .mem-empty { font-size: 12px; color: var(--muted); }

  /* 履歴が空のときの穏やかな表示 */
  .hist-empty {
    background: #fff; border: 1px solid var(--line); border-radius: 12px;
    padding: 44px 20px; text-align: center; color: #a08a73; font-size: 14px;
  }
</style>
@endpush

@section('content')

<div class="mock-note">
  お客様（クライアント）ごとに、これまで<b>誰が何回そのお客様の案件に入ったか</b>を一覧にしています。
  リピートのお客様へ「前回と同じ顔ぶれ」を送りたいときの参考にどうぞ。（キャンセルは数えていません。表示のみで変更はしません。）
</div>

{{-- 絞り込み（GETで開き直す＝確実で分かりやすい）。 --}}
<div class="hist-controls">
  <form method="GET" action="/assign-history" style="margin:0; display:flex; align-items:center; gap:10px;">
    <label for="clientSel">お客様で絞り込み</label>
    <select name="client" id="clientSel" onchange="this.form.submit()">
      <option value="">すべてのお客様</option>
      @foreach ($clientNames as $c)
        <option value="{{ $c['value'] }}" @selected($selectedClient === $c['value'])>{{ $c['label'] }}</option>
      @endforeach
    </select>
    @if ($selectedClient !== '')
      <a class="btn" href="/assign-history">絞り込み解除</a>
    @endif
  </form>
  <span class="spacer"></span>
  <span class="count">{{ count($clients) }} 社を表示</span>
</div>

@if (count($clients) === 0)
  <div class="hist-empty">
    まだアサイン履歴がありません。<br>
    案件にスタッフをアサインすると、ここにお客様ごとの履歴が表示されます。
  </div>
@else
  @foreach ($clients as $client)
    <div class="client-card">
      <div class="client-head">
        <span class="cname">{{ $client['label'] }}</span>
        <span class="cmeta">案件 {{ count($client['projects']) }} 件</span>
      </div>

      {{-- 常連スタッフ（このお客様の案件に入った案件数の多い順） --}}
      <div class="regulars">
        <div class="rtitle">常連スタッフ（入った案件数の多い順）</div>
        @if (count($client['regulars']) === 0)
          <div class="reg-empty">まだ集計できるスタッフがいません。</div>
        @else
          <div class="reg-chips">
            @foreach ($client['regulars'] as $r)
              <span class="reg-chip"><span class="rname">{{ $r['name'] }}</span><span class="rcount">{{ $r['count'] }}件</span></span>
            @endforeach
          </div>
        @endif
      </div>

      {{-- 過去案件（新しい順）とアサイン済みメンバー --}}
      <div class="proj-list">
        @foreach ($client['projects'] as $p)
          <div class="proj-item">
            <div class="pline">
              <span class="pdate">{{ $p['date'] !== '' ? $p['date'] : '日付未定' }}</span>
              <span class="ptitle">{{ $p['title'] }}</span>
            </div>
            @if (count($p['members']) === 0)
              <div class="mem-empty">アサイン済みメンバーはいません。</div>
            @else
              <div class="mem-chips">
                @foreach ($p['members'] as $m)
                  <span class="mem-chip">
                    @if ($m['role'] !== '')<span class="mrole">{{ $m['roleLabel'] }}</span>@endif
                    <span class="mname">{{ $m['name'] }}</span>
                  </span>
                @endforeach
              </div>
            @endif
          </div>
        @endforeach
      </div>
    </div>
  @endforeach
@endif

@endsection
