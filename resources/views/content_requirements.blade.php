@extends('layouts.app')
@section('title', '必要人数の設定')
@section('h1', '必要人数の設定')
@php($active = 'settings')

@push('head')
<style>
    .r-wrap { max-width: 720px; }
    .r-intro { font-size: 12.5px; color: var(--muted); margin: 0 0 12px; }
    .r-title { font-size: 16px; font-weight: 800; color: var(--ink); margin: 0 0 2px; }
    .r-nav { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
    .r-nav a {
      padding: 6px 14px; border: 1px solid var(--line); border-radius: 999px;
      background: #fff; font-size: 13px; font-weight: 700; color: var(--brand-dark); text-decoration: none;
    }
    .r-nav a:hover { background: #f3ece0; }
    .flash {
      background: var(--ok-soft); border: 1px solid #bbe3c6; color: #15803d;
      border-radius: 10px; padding: 10px 14px; font-size: 13px; font-weight: 700; margin-bottom: 14px;
    }
    table.r-grid { border-collapse: collapse; width: 100%; }
    .r-grid th, .r-grid td { border: 1px solid var(--line); padding: 7px 8px; text-align: center; font-size: 13px; }
    .r-grid thead th { background: #f3ece0; color: var(--ink); }
    .r-grid th.pos { text-align: left; white-space: nowrap; }
    .r-grid td .cnt {
      width: 56px; padding: 6px 6px; border: 1px solid var(--line); border-radius: 7px;
      font-size: 13px; font-family: inherit; text-align: center; background: #fff;
    }
    .r-save { display: flex; align-items: center; gap: 12px; margin-top: 16px; }
    .r-btn {
      padding: 10px 22px; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer;
      font-family: inherit; border: 1px solid var(--brand-dark); background: var(--brand); color: #fff;
    }
    .r-btn:hover { filter: brightness(.97); }
    .r-note { font-size: 11.5px; color: var(--muted); margin: 12px 0 0; }
</style>
@endpush

@section('content')
  @if (session('status'))
    <div class="flash">✓ {{ session('status') }}</div>
  @endif

  <div class="r-nav">
    <a href="/masters#contents">← コンテンツ一覧に戻る</a>
  </div>

  <div class="panel r-wrap">
    <div class="r-title">{{ $content->content_name }} <span style="font-size:12px;color:var(--muted);">（{{ $content->id }}）</span></div>
    <p class="r-intro">参加者の規模ごとに、必要な役割の人数を登録します。0のままなら「不要」です。<br>
      ここで決めた人数は、将来アサイン画面の「必要人数」の目安に使えます。</p>

    <form method="POST" action="/masters/contents/{{ $content->id }}/requirements">
      @csrf
      <table class="r-grid">
        <thead>
          <tr>
            <th class="pos">ポジション（役割）</th>
            @foreach ($scales as $scale)
              <th>{{ $scale }}</th>
            @endforeach
          </tr>
        </thead>
        <tbody>
          @foreach ($positions as $code => $label)
            <tr>
              <th class="pos">{{ $label }}</th>
              @foreach ($scales as $scale)
                <td>
                  <input class="cnt" type="number" min="0" max="99"
                         name="req[{{ $scale }}][{{ $code }}]"
                         value="{{ $saved[$scale . '|' . $code] ?? 0 }}">
                </td>
              @endforeach
            </tr>
          @endforeach
        </tbody>
      </table>

      <div class="r-save">
        <button type="submit" class="r-btn">保存する</button>
        <span class="r-note" style="margin:0;">規模（小型／中型／大型）× 役割 の必要人数をまとめて保存します。</span>
      </div>
    </form>

    <p class="r-note">
      ※ 役割の並びは共通のポジション定義（{{ implode('／', array_keys($positions)) }}）です。ポジションを見直すと、ここの列も自動で変わります。
    </p>
  </div>
@endsection
