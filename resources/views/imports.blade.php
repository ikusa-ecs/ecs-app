@extends('layouts.app')
@section('title', 'CSV一括取込')
@section('h1', 'CSV一括取込')
@php $active = 'imports'; @endphp

@push('head')
<style>
  .im-wrap { max-width: 820px; }
  .im-lead { font-size: 13px; color: #6b5c49; line-height: 1.7; margin: 0 0 16px; }
  .im-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px; }
  .im-card { display: flex; flex-direction: column; background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 18px; }
  .im-card .im-icon { font-size: 26px; margin-bottom: 8px; }
  .im-card h2 { font-size: 15px; margin: 0 0 6px; color: var(--ink); }
  .im-card p { font-size: 12.5px; color: #6b5c49; line-height: 1.7; margin: 0 0 14px; flex: 1; }
  .im-card .btn { align-self: flex-start; }
</style>
@endpush

@section('content')
<div class="im-wrap">
  <p class="im-lead">
    CSV（Excelで開ける表ファイル）を使って、まとめて登録できます。<br>
    どれも同じ操作です：テンプレートをダウンロード → Excelで記入 → 読み込み → プレビューで確認 → OK行だけ登録。
  </p>

  <div class="im-grid">
    <div class="im-card">
      <div class="im-icon">🧑‍🤝‍🧑</div>
      <h2>名簿（社員・スタッフ）</h2>
      <p>社員・スタッフをまとめて名簿に登録します。種別／氏名／メール／事務所／所属／入社日など。</p>
      <a class="btn primary" href="/person-import">開く</a>
    </div>

    <div class="im-card">
      <div class="im-icon">🎪</div>
      <h2>コンテンツ</h2>
      <p>催し物の種類（水合戦・謎解きなど）をまとめて登録します。分類・体力系・紙が必要かなど。</p>
      <a class="btn primary" href="/content-import">開く</a>
    </div>

    <div class="im-card">
      <div class="im-icon">📋</div>
      <h2>案件</h2>
      <p>イベント案件をまとめて登録します。案件名・日付・拠点・実施形態など。</p>
      <a class="btn primary" href="/project-import">開く</a>
    </div>
  </div>

  <p class="im-lead" style="margin-top:18px;">
    ※ 拠点（事務所）はそう頻繁に増えないため、CSVではなく<a href="/settings" style="color:#a08a73; text-decoration:underline;">共通設定</a>のマスタ管理から手入力で追加・並び替えができます。
  </p>
</div>
@endsection
