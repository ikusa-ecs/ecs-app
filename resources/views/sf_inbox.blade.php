@extends('layouts.app')
@section('title', 'Salesforce受信箱')
@section('h1', 'Salesforce受信箱')
@php($active = 'sf_inbox')

{{-- Salesforceの商談の受信箱（2026-11-16）。
     ⚠ 届いただけでは案件は1文字も変わらない。「案件として登録」を押したときだけ案件が1件できる。
     ⚠ 見送りは消さずに残す。下見の読み替えは App\Support\SfOpportunityMap（登録と同じもの）。
     ⚠ 画面で計算しない＝値はコントローラで作って渡す（Bladeの罠よけ）。 --}}

@push('head')
<style>
  .si-wrap { max-width: 1000px; }
  .si-lead { font-size: 13px; color: var(--muted); line-height: 1.8; margin: 0 0 14px; }
  .si-lead b { color: var(--ink); }
  .si-flash { border-radius: 10px; padding: 12px 14px; font-size: 13px; margin-bottom: 14px; line-height: 1.8; border: 1px solid var(--line); }
  .si-flash.ok { background: var(--ok-soft); color: var(--ok-ink); }
  .si-flash.err { background: var(--danger-soft); color: var(--danger-ink, #b91c1c); font-weight: 700; }
  .si-flash.warn { background: var(--warn-soft); color: var(--warn-ink); }
  .si-tabs { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 12px; }
  .si-tabs a { font-size: 12.5px; padding: 5px 11px; border: 1px solid var(--line); border-radius: 999px; color: var(--ink); text-decoration: none; background: var(--panel); }
  .si-tabs a.on { background: var(--brand-soft); border-color: var(--brand); font-weight: 700; }
  .si-card { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px; margin-bottom: 14px; }
  .si-head { display: flex; flex-wrap: wrap; gap: 8px; align-items: baseline; margin-bottom: 8px; }
  .si-title { font-size: 14.5px; font-weight: 700; color: var(--ink); }
  .si-sub { font-size: 11.5px; color: var(--muted); }
  .si-badge { display: inline-block; font-size: 11.5px; padding: 2px 8px; border-radius: 999px; white-space: nowrap; background: var(--surface-2); color: var(--muted); }
  .si-badge.sample { background: var(--warn-soft); color: var(--warn-ink); font-weight: 700; }
  .si-badge.waiting { background: var(--brand-soft); color: var(--ink); font-weight: 700; }
  .si-badge.registered { background: var(--ok-soft); color: var(--ok-ink); }
  table.si-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 8px; }
  table.si-table th, table.si-table td { border: 1px solid var(--line); padding: 6px 9px; text-align: left; vertical-align: top; }
  table.si-table th { background: var(--surface-2); font-size: 12px; width: 120px; white-space: nowrap; }
  .si-red { color: var(--danger-ink, #b91c1c); font-weight: 700; }
  .si-dim { color: var(--muted); }
  .si-unmatched { display: inline-block; margin: 2px 4px 2px 0; padding: 2px 8px; border-radius: 6px; background: var(--danger-soft); color: var(--danger-ink, #b91c1c); font-weight: 700; font-size: 12.5px; }
  .si-content { display: inline-block; margin: 2px 4px 2px 0; padding: 2px 8px; border-radius: 6px; background: var(--ok-soft); color: var(--ok-ink); font-size: 12.5px; }
  .si-warn { background: var(--warn-soft); color: var(--warn-ink); border-radius: 8px; padding: 8px 10px; font-size: 12.5px; line-height: 1.7; margin-bottom: 8px; }
  .si-warn ul { margin: 0; padding-left: 18px; }
  .si-ops { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; font-size: 13px; }
  .si-ops select { padding: 5px 8px; border: 1px solid var(--line); border-radius: 6px; background: var(--panel); color: var(--ink); }
  .si-empty { font-size: 13px; color: var(--muted); line-height: 1.9; }
</style>
@endpush

@section('content')
<div class="si-wrap">
  <p class="si-lead">
    Salesforceの商談は、<b>まずこの受信箱に入ります。</b>入っただけでは、ECSの案件は何も変わりません。<br>
    1件ずつ「ECSに入れるとこうなる」を見て、<b>「案件として登録」</b>か<b>「見送り」</b>を選んでください。見送りにしても消えずに残ります。<br>
    <span class="si-sub">※ いまはまだSalesforceにつないでいません（自動では届きません）。見本で動きを試せます。</span>
  </p>

  @if (session('status'))
    <div class="si-flash ok">{{ session('status') }}</div>
  @endif
  @if (session('error'))
    <div class="si-flash err">{{ session('error') }}</div>
  @endif
  @if ($pendingProducts > 0)
    <div class="si-flash warn">
      Salesforceの商品のうち <b>{{ $pendingProducts }}件</b> は、まだECSのどのコンテンツか決まっていません（赤く「未対応」と出ます）。
      <a href="/sf-products">Salesforce商品の対応表</a> で決めると、ここにコンテンツ名で出るようになります。
    </div>
  @endif

  <div class="si-tabs">
    @foreach (['waiting' => '取り込み待ち', 'registered' => '登録ずみ', 'skipped' => '見送り', 'all' => 'ぜんぶ'] as $key => $label)
      <a class="{{ $show === $key ? 'on' : '' }}" href="/sf-inbox?show={{ $key }}">{{ $label }}（{{ $counts[$key] }}）</a>
    @endforeach
  </div>

  @if ($rows->isEmpty())
    <div class="si-card"><p class="si-empty">ここに出す商談はありません。</p></div>
  @endif

  @foreach ($rows as $row)
    @php($item = $row['item'])
    @php($p = $row['p'])
    <div class="si-card">
      <div class="si-head">
        <span class="si-badge {{ $item->status }}">{{ \App\Models\SfInboxItem::LABELS[$item->status] ?? $item->status }}</span>
        @if ($item->is_sample)<span class="si-badge sample">見本</span>@endif
        <span class="si-title">{{ $p['sf_name'] ?? '（商談名なし）' }}</span>
        <span class="si-sub">商談Id {{ $item->sf_opportunity_id }}／受け取り {{ optional($item->received_at)->format('Y-m-d H:i') ?? '—' }}@if ($p && $p['stage'])／フェーズ {{ $p['stage'] }}@endif</span>
      </div>

      @if (! $p)
        <p class="si-red">この商談の中身が読めませんでした。Salesforceの担当に確認してください。</p>
      @else
        @if ($p['warnings'])
          <div class="si-warn">
            <ul>
              @foreach ($p['warnings'] as $w)
                <li>{{ $w }}</li>
              @endforeach
            </ul>
          </div>
        @endif

        <table class="si-table">
          <tr><th>開催日</th><td>{!! $p['date'] ? e($p['date']) : '<span class="si-red">空欄（未定）</span>' !!}@if ($p['days'] && $p['days'] >= 2) <span class="si-red">（{{ $p['days'] }}日間）</span>@endif</td></tr>
          <tr><th>お客様名</th><td>{!! $p['client'] ? e($p['client']) : '<span class="si-dim">空欄</span>' !!}@if ($p['agency'])<span class="si-sub">（代理店：{{ $p['agency'] }}）</span>@endif</td></tr>
          <tr><th>拠点</th><td>
            @if ($p['office'])
              {{ $p['office'] }} <span class="si-sub">（Salesforce：{{ $p['office_raw'] }}）</span>
            @else
              <span class="si-red">要確認</span> <span class="si-sub">（Salesforce：{{ $p['office_raw'] ?? '空欄' }}）登録するときに拠点を選んでください。</span>
            @endif
          </td></tr>
          <tr><th>参加人数</th><td>{{ $p['guest_count'] !== null ? $p['guest_count'].'名' : '空欄' }}</td></tr>
          <tr><th>運営人数</th><td>{!! $p['staff_count'] !== null ? e($p['staff_count']).'名' : '<span class="si-dim">空欄（登録すると「人数は仮（未定）」になります）</span>' !!}</td></tr>
          <tr><th>コンテンツ</th><td>
            @foreach ($p['contents'] as $c)
              <span class="si-content">{{ $c['content_name'] }}</span>
            @endforeach
            @foreach ($p['unmatched'] as $u)
              <span class="si-unmatched" title="対応表でまだ決めていない商品">未対応：{{ $u['name'] }}</span>
            @endforeach
            @if (! $p['contents'] && ! $p['unmatched'])
              <span class="si-dim">なし</span>
            @endif
          </td></tr>
          <tr><th>その他</th><td class="si-sub">
            規模 {{ $p['scale'] ?? '—' }}／実施形態 {{ $p['format'] ?? '—' }}／会場 {{ $p['location'] ?? '—' }}／営業 {{ $p['sales_owner'] ?? ($p['owner_email'] ?? '—') }}@if ($p['yomi'])／確度 {{ $p['yomi'] }}@endif
          </td></tr>
        </table>
      @endif

      <div class="si-ops">
        @if ($item->status === 'waiting')
          <form method="POST" action="/sf-inbox/{{ $item->id }}/register" class="si-ops" onsubmit="return confirm('この商談をECSの案件として登録します。よろしいですか？');">
            @csrf
            <label>拠点
              <select name="office" @if (! ($p['office'] ?? null)) required @endif>
                @if (! ($p['office'] ?? null))<option value="">選んでください</option>@endif
                @foreach ($offices as $o)
                  <option value="{{ $o }}" @selected(($p['office'] ?? null) === $o)>{{ $o }}</option>
                @endforeach
              </select>
            </label>
            <button class="btn sm primary" type="submit">案件として登録</button>
          </form>
          <form method="POST" action="/sf-inbox/{{ $item->id }}/skip">
            @csrf
            <button class="btn sm" type="submit">見送り</button>
          </form>
        @elseif ($item->status === 'skipped')
          <form method="POST" action="/sf-inbox/{{ $item->id }}/reopen">
            @csrf
            <button class="btn sm" type="submit">取り込み待ちに戻す</button>
          </form>
        @elseif ($item->status === 'registered' && $item->project_id)
          <a class="btn sm" href="/project-form?project={{ urlencode($item->project_id) }}">登録した案件を開く（{{ $item->project_id }}）</a>
        @endif
      </div>
    </div>
  @endforeach
</div>
@endsection
