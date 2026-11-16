@extends('layouts.app')
@section('title', 'Salesforce商品の対応表')
@section('h1', 'Salesforce商品の対応表')
@php($active = 'sf_products')

{{-- SFの商品（205件）を、ECSのコンテンツにつなぐ／無視する を1件ずつ決める画面（2026-11-16）。
     ⚠ 鍵は商品Id（18桁）。名前は表示用（括弧を外すと62件が同じ名前になるため）。
     ⚠ 名前が近いコンテンツは「候補」として出すだけ。自動では決めない。 --}}

@push('head')
<style>
  .sp-wrap { max-width: 1100px; }
  .sp-lead { font-size: 13px; color: var(--muted); line-height: 1.8; margin: 0 0 14px; }
  .sp-lead b { color: var(--ink); }
  .sp-flash { border-radius: 10px; padding: 12px 14px; font-size: 13px; margin-bottom: 14px; line-height: 1.8; background: var(--ok-soft); color: var(--ok-ink); border: 1px solid var(--line); }
  .sp-card { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px; margin-bottom: 14px; }
  .sp-left { font-size: 15px; font-weight: 700; color: var(--ink); }
  .sp-left .num { font-size: 22px; color: var(--warn-ink); margin: 0 4px; }
  .sp-tabs { display: flex; flex-wrap: wrap; gap: 6px; margin: 10px 0; }
  .sp-tabs a { font-size: 12.5px; padding: 5px 11px; border: 1px solid var(--line); border-radius: 999px; color: var(--ink); text-decoration: none; background: var(--panel); }
  .sp-tabs a.on { background: var(--brand-soft); border-color: var(--brand); font-weight: 700; }
  .sp-filter { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; font-size: 13px; }
  .sp-filter input, .sp-filter select { padding: 5px 8px; border: 1px solid var(--line); border-radius: 6px; background: var(--panel); color: var(--ink); }
  table.sp-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.sp-table th, table.sp-table td { border: 1px solid var(--line); padding: 7px 8px; text-align: left; vertical-align: top; }
  table.sp-table th { background: var(--surface-2); font-size: 12px; white-space: nowrap; }
  .sp-name { font-weight: 700; color: var(--ink); }
  .sp-sub { font-size: 11.5px; color: var(--muted); margin-top: 2px; }
  .sp-hint { font-size: 12px; color: var(--warn-ink); }
  .sp-badge { display: inline-block; font-size: 11.5px; padding: 2px 8px; border-radius: 999px; white-space: nowrap; }
  .sp-badge.pending { background: var(--warn-soft); color: var(--warn-ink); font-weight: 700; }
  .sp-badge.content { background: var(--ok-soft); color: var(--ok-ink); }
  .sp-badge.ignore { background: var(--surface-2); color: var(--muted); }
  .sp-cands { margin-top: 4px; display: flex; flex-wrap: wrap; gap: 4px; }
  .sp-cands button { font-size: 11.5px; padding: 2px 8px; border: 1px dashed var(--line); border-radius: 6px; background: var(--panel); color: var(--ink); cursor: pointer; }
  .sp-ops { display: flex; flex-direction: column; gap: 5px; min-width: 230px; }
  .sp-ops input[type=text] { width: 100%; padding: 5px 7px; border: 1px solid var(--line); border-radius: 6px; background: var(--panel); color: var(--ink); box-sizing: border-box; }
  .sp-ops .row { display: flex; gap: 4px; flex-wrap: wrap; }
  .sp-msg { font-size: 11.5px; min-height: 1em; }
  .sp-msg.err { color: var(--danger-ink, #b91c1c); font-weight: 700; }
  .sp-msg.ok { color: var(--ok-ink); }
  .sp-note { width: 100%; padding: 4px 6px; border: 1px solid var(--line); border-radius: 6px; font-size: 12px; background: var(--panel); color: var(--ink); box-sizing: border-box; }
  .sp-empty { font-size: 13px; color: var(--muted); line-height: 1.9; }
  details.sp-import summary { cursor: pointer; font-size: 13px; font-weight: 700; }
</style>
@endpush

@section('content')
<div class="sp-wrap">
  <p class="sp-lead">
    Salesforceでは、コンテンツを<b>「商品」</b>という名前で管理しています。商品名はECSのコンテンツ名と違うので、
    <b>この商品はECSのどのコンテンツか</b>をここで1件ずつ決めます。<br>
    商品には、オプション・機材・手配などの<b>コンテンツではないもの</b>も混ざっています。それは「無視する」にしてください。<br>
    ここで決めた内容は、Salesforce受信箱の下見と、案件として登録するときに使われます。<b>決めていない商品は「未対応」と目立つように出ます。</b>
  </p>

  @if (session('status'))
    <div class="sp-flash">{{ session('status') }}</div>
  @endif

  <div class="sp-card">
    <div class="sp-left">まだ決めていない商品：<span class="num" id="spPending">{{ $counts['pending'] }}</span>件 <span class="sp-sub">（全{{ $counts['all'] }}件）</span></div>

    <div class="sp-tabs">
      @foreach ($tabs as $key => $label)
        <a class="{{ $filter === $key ? 'on' : '' }}" href="/sf-products?show={{ $key }}{{ $family !== '' ? '&family='.urlencode($family) : '' }}{{ $q !== '' ? '&q='.urlencode($q) : '' }}">{{ $label }}（{{ $counts[$key] }}）</a>
      @endforeach
    </div>

    <form class="sp-filter" method="GET" action="/sf-products">
      <input type="hidden" name="show" value="{{ $filter }}">
      <label>ファミリ
        <select name="family">
          <option value="">すべて</option>
          @foreach ($families as $f)
            <option value="{{ $f }}" @selected($family === $f)>{{ $f }}</option>
          @endforeach
        </select>
      </label>
      <label>名前で探す <input type="text" name="q" value="{{ $q }}" placeholder="例）チャンバラ"></label>
      <button class="btn sm" type="submit">絞り込む</button>
    </form>
  </div>

  <div class="sp-card">
    @if ($counts['all'] === 0)
      <p class="sp-empty">
        まだ商品が入っていません。下の「商品一覧を取り込む」から、<b>ECSに入っている205件</b>を取り込んでください。
      </p>
    @elseif ($rows->isEmpty())
      <p class="sp-empty">この条件に当てはまる商品はありません。</p>
    @else
      <table class="sp-table">
        <thead>
          <tr>
            <th>Salesforceの商品</th>
            <th>いまの扱い</th>
            <th>ECSのコンテンツ（候補）</th>
            <th>決める</th>
            <th>メモ</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($rows as $r)
            <tr data-sf="{{ $r->sf_product_id }}">
              <td>
                <div class="sp-name">{{ $r->name }}</div>
                <div class="sp-sub">{{ $r->family ?? 'ファミリなし' }}{{ $r->is_active ? '' : '・Salesforceで無効' }}</div>
                @if ($r->hint)
                  <div class="sp-hint">候補：{{ $r->hint }}（推測です。決めるのは人）</div>
                @endif
              </td>
              <td><span class="sp-badge {{ $r->handling }}" data-role="badge">{{ \App\Models\SfProduct::LABELS[$r->handling] ?? $r->handling }}</span></td>
              <td>
                @if ($r->handling === 'content' && $r->content_id)
                  <div>{{ $contentNames[$r->content_id] ?? '（台帳から消えたコンテンツ '.$r->content_id.'）' }}</div>
                @endif
                @if (! empty($suggest[$r->sf_product_id]))
                  <div class="sp-sub">名前が近いコンテンツ（押すと下の欄に入ります）：</div>
                  <div class="sp-cands">
                    @foreach ($suggest[$r->sf_product_id] as $c)
                      <button type="button" data-pick="{{ $c['name'] }}">{{ $c['name'] }}</button>
                    @endforeach
                  </div>
                @endif
              </td>
              <td>
                <div class="sp-ops">
                  <input type="text" list="spContentList" data-role="content" placeholder="コンテンツ名を打つと候補が出ます"
                         value="{{ $r->handling === 'content' && $r->content_id ? ($contentNames[$r->content_id] ?? '') : '' }}">
                  <div class="row">
                    <button class="btn sm primary" type="button" data-act="content">このコンテンツにする</button>
                    <button class="btn sm" type="button" data-act="ignore">無視する</button>
                    <button class="btn sm ghost" type="button" data-act="pending">未対応に戻す</button>
                  </div>
                  <div class="sp-msg" data-role="msg"></div>
                </div>
              </td>
              <td><input type="text" class="sp-note" data-role="note" value="{{ $r->note }}" placeholder="メモ（任意）"></td>
            </tr>
          @endforeach
        </tbody>
      </table>
      <datalist id="spContentList">
        @foreach ($contents as $c)
          <option value="{{ $c['name'] }}"></option>
        @endforeach
      </datalist>
    @endif
  </div>

  <div class="sp-card">
    <details class="sp-import" @if ($counts['all'] === 0) open @endif>
      <summary>商品一覧を取り込む（Salesforceの商品が増えたとき）</summary>
      <p class="sp-lead" style="margin-top:8px;">
        同じ商品（同じ商品Id）は<b>名前などだけ新しくします。ここで決めた扱い・コンテンツ・メモは消えません。</b><br>
        CSVの最後の列（「△機材の可能性」など）は「候補」として写すだけで、<b>勝手に「無視」にはしません。</b>
      </p>
      <form method="POST" action="/sf-products/import" style="margin-bottom:8px;">
        @csrf
        <input type="hidden" name="bundled" value="1">
        <button class="btn" type="submit">ECSに入っている205件のCSVを取り込む</button>
      </form>
      <form method="POST" action="/sf-products/import" enctype="multipart/form-data" class="sp-filter">
        @csrf
        <input type="file" name="csv" accept=".csv,text/csv">
        <button class="btn" type="submit">選んだCSVを取り込む</button>
        <span class="sp-sub">列：Id, Name, ProductCode, Family, IsActive, （最後の列＝コンテンツ以外の可能性）</span>
      </form>
    </details>
  </div>
</div>
@endsection

@push('scripts')
<script>
  (function () {
    var token = @json(csrf_token());
    var contents = @json($contents);
    var labels = @json(\App\Models\SfProduct::LABELS);
    var byName = {};
    contents.forEach(function (c) { byName[c.name] = c.id; });

    function save(tr, handling) {
      var msg = tr.querySelector('[data-role=msg]');
      var nameInput = tr.querySelector('[data-role=content]');
      var noteInput = tr.querySelector('[data-role=note]');
      var body = new FormData();
      body.append('sf_product_id', tr.dataset.sf);
      body.append('handling', handling);
      body.append('note', noteInput ? noteInput.value : '');
      if (handling === 'content') {
        var id = byName[(nameInput.value || '').trim()];
        if (!id) {
          msg.className = 'sp-msg err';
          msg.textContent = 'コンテンツ名を候補から選んでください（ECSの台帳にある名前だけ選べます）。';
          return;
        }
        body.append('content_id', id);
      }
      msg.className = 'sp-msg';
      msg.textContent = '保存しています…';
      fetch('/sf-products/save', {
        method: 'POST', body: body, credentials: 'same-origin',
        headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (res) {
        return res.json().catch(function () { return { ok: false, message: '保存できませんでした（ログインが切れたかもしれません。画面を開き直してください）。' }; });
      }).then(function (data) {
        if (!data || !data.ok) {
          msg.className = 'sp-msg err';
          msg.textContent = (data && data.message) ? data.message : '保存できませんでした。';
          return;
        }
        var badge = tr.querySelector('[data-role=badge]');
        badge.className = 'sp-badge ' + data.handling;
        badge.textContent = labels[data.handling] || data.label;
        if (data.handling !== 'content') { nameInput.value = ''; }
        msg.className = 'sp-msg ok';
        msg.textContent = '保存しました。';
        var left = document.getElementById('spPending');
        if (left) left.textContent = data.pending;
      }).catch(function () {
        msg.className = 'sp-msg err';
        msg.textContent = '保存できませんでした（通信エラー）。もう一度押してください。';
      });
    }

    document.querySelectorAll('table.sp-table tbody tr').forEach(function (tr) {
      // ⚠ 1行の作りが崩れていても、ほかの行は動くように1行ずつ守る
      try {
        tr.querySelectorAll('[data-act]').forEach(function (btn) {
          btn.addEventListener('click', function () { save(tr, btn.dataset.act); });
        });
        tr.querySelectorAll('[data-pick]').forEach(function (btn) {
          btn.addEventListener('click', function () {
            tr.querySelector('[data-role=content]').value = btn.dataset.pick;
          });
        });
        var note = tr.querySelector('[data-role=note]');
        if (note) {
          note.addEventListener('change', function () {
            var badge = tr.querySelector('[data-role=badge]');
            var cur = (badge.className.match(/sp-badge (\w+)/) || [])[1] || 'pending';
            save(tr, cur);
          });
        }
      } catch (e) {}
    });
  })();
</script>
@endpush
