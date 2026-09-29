@extends('layouts.app')
@section('title', 'コンテンツの片づけ')
@section('h1', 'コンテンツの片づけ')
@php($active = 'settings')

@push('head')
<style>
  .cc-intro { font-size: 13px; color: var(--muted); line-height: 1.8; margin-bottom: 14px; }
  .cc-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .cc-table th, .cc-table td { padding: 7px 9px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
  .cc-table th { font-size: 11.5px; color: var(--muted); font-weight: 700; }
  .cc-kind { font-size: 11px; font-weight: 800; padding: 1px 7px; border-radius: 999px; background: var(--info-soft); color: var(--info-ink); }
  .cc-kind.same { background: var(--warn-soft); color: var(--warn-ink); }
  .cc-to { font-weight: 700; color: var(--ink); }
  .cc-note { font-size: 11.5px; color: var(--muted); }
  .cc-bar { margin-top: 14px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
  .cc-flash { padding: 10px 12px; border-radius: 8px; background: var(--ok-soft); color: var(--ok-ink); margin-bottom: 12px; font-size: 13px; }
</style>
@endpush

@section('content')
{{-- 取込で増えてしまったコンテンツの片づけ（2026-09-29 baba「コンテンツは量産しないでほしい」）。
     候補の見つけ方・つなぎ直し方の正本＝App\Support\ContentCleanup（ここで判定し直さない）。 --}}
@if (session('status'))
  <div class="cc-flash">{{ session('status') }}</div>
@endif

<div class="panel">
  <p class="cc-intro">
    アサイン表などの取込で、コンテンツ台帳に<b>いらないコンテンツ</b>が増えていないかを探します。<br>
    ・<b>まとめ</b>＝「謎パ・格付けバトル」のように、台帳にある複数のコンテンツをつないだ名前 → 案件を<b>それぞれのコンテンツにつなぎ直して</b>、まとめの方を消します。<br>
    ・<b>同じ</b>＝書き方だけ違う（全角と半角・空白・「・」）同じ名前が前からある → <b>前からあるほう</b>につなぎ直して、あとからできたほうを消します。<br>
    <b>チェックを付けたものだけ</b>直します。案件は消えません（コンテンツのつながりだけ変わります・編集履歴にも残ります）。
    <span class="cc-note">※ 謎解きの紙の在庫が入っているコンテンツは候補に出しません。</span>
  </p>

  @if ($candidates === [])
    <p>片づける候補はありません。</p>
  @else
    <form method="POST" action="/masters/content-cleanup"
          onsubmit="return confirm('チェックを付けたコンテンツを、つなぎ直してから消します。よろしいですか？（元に戻せません）');">
      @csrf
      <table class="cc-table">
        <thead>
          <tr><th>直す</th><th>種類</th><th>いまのコンテンツ</th><th>つなぎ直す先</th><th>使っている案件</th></tr>
        </thead>
        <tbody>
          @foreach ($candidates as $c)
            <tr>
              <td><input type="checkbox" name="ids[]" value="{{ $c['id'] }}"></td>
              <td><span class="cc-kind {{ $c['kind'] === '同じ' ? 'same' : '' }}">{{ $c['kind'] }}</span></td>
              <td>{{ $c['name'] }} <span class="cc-note">（{{ $c['id'] }}）</span>
                @if ($c['hasReq'])<div class="cc-note">⚠ 必要人数の設定があります（消すと一緒に消えます）</div>@endif
              </td>
              <td class="cc-to">{{ implode('／', array_column($c['targets'], 'name')) }}</td>
              <td>{{ $c['projects'] }}件</td>
            </tr>
          @endforeach
        </tbody>
      </table>
      <div class="cc-bar">
        <button type="submit" class="btn primary">チェックしたものを片づける</button>
        <a href="/masters#contents">マスタ管理へ戻る</a>
      </div>
    </form>
  @endif
</div>
@endsection
