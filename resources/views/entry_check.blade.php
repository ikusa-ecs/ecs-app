@extends('layouts.app')
@section('title', 'エントリーの点検')
@section('h1', 'エントリーの点検')
@php($active = 'settings')

@push('head')
<style>
  .ec-intro { font-size: 13px; color: var(--muted); line-height: 1.8; margin-bottom: 14px; }
  .ec-form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 6px; font-size: 13px; }
  .ec-h { font-size: 15px; font-weight: 800; margin: 18px 0 6px; color: var(--ink); }
  .ec-count { font-size: 12px; color: var(--muted); font-weight: 600; margin-left: 6px; }
  .ec-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .ec-table th, .ec-table td { padding: 7px 9px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
  .ec-table th { font-size: 11.5px; color: var(--muted); font-weight: 700; }
  .ec-note { font-size: 11.5px; color: var(--muted); }
  .ec-old { text-decoration: line-through; color: var(--muted); }
  .ec-new { font-weight: 700; color: var(--warn-ink); }
  .ec-none { font-size: 13px; color: var(--ok-ink); }
  .ec-scroll { overflow-x: auto; }
  .ec-big { display: inline-block; font-size: 11px; font-weight: 800; padding: 1px 7px; border-radius: 999px; background: var(--warn-soft); color: var(--warn-ink); }
  .ec-announce { width: 100%; min-height: 180px; font-size: 13px; line-height: 1.6; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--panel); color: var(--ink); font-family: inherit; margin-bottom: 6px; box-sizing: border-box; }
  .ec-grp { border: 1px solid var(--line); border-radius: 10px; padding: 8px 10px; margin-bottom: 10px; }
  .ec-grp-h { font-weight: 800; font-size: 13px; color: var(--ink); margin-bottom: 4px; }
</style>
@endpush

@section('content')
{{-- 取込でIDがずれた件（9/30ごろ）の点検。判定の正本＝App\Support\EntryCheck。見るだけでデータは変えない。 --}}
<div class="panel">
  <p class="ec-intro">
    スタッフのエントリーが、<b>本人が押したときとは別の案件</b>を指してしまっていないかを探します。<b>見るだけ</b>で、データは変えません。<br>
    ここに出た人だけ、本人に「この案件で合っていますか？」と確かめれば足ります。
    エントリーしたあとに本人が押し直していれば、変わったあとの中身を見て押しているので出てきません。
  </p>
  <form method="GET" action="/entry-check" class="ec-form">
    <label>この日より後の変更を見る：<input type="date" name="from" value="{{ $from }}"></label>
    <label><input type="checkbox" name="big" value="1" {{ $bigOnly ? 'checked' : '' }}> ①は案件名がガラッと変わったものだけ</label>
    <button type="submit" class="btn">表示</button>
  </form>

  {{-- 全体LINEに貼る一覧（2026-10-02 baba）。作り方の正本＝EntryCheck::announceText。 --}}
  <div class="ec-h">📱 全体LINEに貼る一覧（案件名ガラッとの案件だけ）<span class="ec-count">{{ $announce === '' ? 0 : substr_count($announce, "\n\n") + 1 }}件</span></div>
  @if ($announce === '')
    <p class="ec-none">ありません。</p>
  @else
    <p class="ec-note">元＝エントリーしたときの中身／今＝いまの中身。日付・案件名・クライアントだけです（スタッフ名は出しません）。前後の文章は自由に書き足してください。</p>
    <textarea id="ecAnnounce" class="ec-announce" readonly>{{ $announce }}</textarea>
    <button type="button" class="btn primary" onclick="copyAnnounce(this)">📋 コピー</button>
  @endif

  <div class="ec-h">① エントリーしたあとに、案件の中身が書き換わった<span class="ec-count">{{ count($changed) }}件</span></div>
  <p class="ec-note">開催日・案件名・クライアント・コンテンツのどれかが、エントリーより後に変わったもの。
    <span class="ec-big">案件名ガラッと</span>＝付け足し・言い回しの違いではなく、別の案件の名前になっているもの。</p>
  @if (count($changed) === 0)
    <p class="ec-none">ありません。</p>
  @else
    <div class="ec-scroll"><table class="ec-table">
      <thead><tr><th>スタッフ</th><th>いまの案件</th><th>エントリーした日時</th><th>そのあと変わったところ</th></tr></thead>
      <tbody>
        @foreach ($changed as $r)
          <tr>
            <td>{{ $r['staff_name'] }} <span class="ec-note">（{{ $r['staff_id'] }}）</span></td>
            <td>{{ $r['start_date'] }} {{ $r['project_name'] }}<div class="ec-note">{{ $r['project_id'] }}</div></td>
            <td>{{ $r['applied_at'] }}</td>
            <td>
              @if ($r['big_name'])
                <span class="ec-big">案件名ガラッと</span>
              @endif
              @foreach ($r['diffs'] as $d)
                <div>{{ $d['label'] }}：<span class="ec-old">{{ $d['old'] }}</span> → <span class="ec-new">{{ $d['new'] }}</span>
                  <span class="ec-note">（{{ $d['at'] }}・{{ $d['by'] }}）</span></div>
              @endforeach
            </td>
          </tr>
        @endforeach
      </tbody>
    </table></div>
  @endif

  <div class="ec-h">② 同じ日に、あとから別の番号で同じ案件ができた<span class="ec-count">{{ count($twins) }}件</span></div>
  <p class="ec-note">本人は古い方にエントリーしたまま。新しい方にはエントリーしていない。</p>
  @if (count($twins) === 0)
    <p class="ec-none">ありません。</p>
  @else
    <div class="ec-scroll"><table class="ec-table">
      <thead><tr><th>スタッフ</th><th>エントリーしている案件</th><th>エントリーした日時</th><th>あとからできた案件</th></tr></thead>
      <tbody>
        @foreach ($twins as $r)
          <tr>
            <td>{{ $r['staff_name'] }} <span class="ec-note">（{{ $r['staff_id'] }}）</span></td>
            <td>{{ $r['start_date'] }} {{ $r['project_name'] }}<div class="ec-note">{{ $r['project_id'] }}</div></td>
            <td>{{ $r['applied_at'] }}</td>
            <td>{{ $r['twin_name'] }}<div class="ec-note">{{ $r['twin_id'] }}（{{ $r['twin_created'] }} にできた）</div></td>
          </tr>
        @endforeach
      </tbody>
    </table></div>
  @endif

  <div class="ec-h">③ エントリー先の案件が、もう無い<span class="ec-count">{{ count($missing) }}件</span></div>
  @if (count($missing) === 0)
    <p class="ec-none">ありません。</p>
  @else
    <div class="ec-scroll"><table class="ec-table">
      <thead><tr><th>スタッフ</th><th>消えた案件</th><th>エントリーした日時</th></tr></thead>
      <tbody>
        @foreach ($missing as $r)
          <tr>
            <td>{{ $r['staff_name'] }} <span class="ec-note">（{{ $r['staff_id'] }}）</span></td>
            <td>{{ $r['project_name'] }}<div class="ec-note">{{ $r['project_id'] }}</div></td>
            <td>{{ $r['applied_at'] }}</td>
          </tr>
        @endforeach
      </tbody>
    </table></div>
  @endif

  <div class="ec-h">④ 同じ日に、同じ企業・同じコンテンツの案件が2つ以上ある<span class="ec-count">{{ count($dups) }}組</span></div>
  <p class="ec-note">{{ $from }} 以降に開催する案件から探します（エントリーの有無は問いません）。午前・午後の2回公演などもここに出るので、時間と人数を見て見分けてください。</p>
  @if (count($dups) === 0)
    <p class="ec-none">ありません。</p>
  @else
    @foreach ($dups as $g)
      <div class="ec-grp">
        <div class="ec-grp-h">{{ $g['date'] }}　{{ $g['client'] }}　／　{{ $g['content'] }}</div>
        <div class="ec-scroll"><table class="ec-table">
          <thead><tr><th>案件</th><th>拠点</th><th>集合・開始</th><th>状態</th><th>登録した日時</th><th>エントリー</th><th>アサイン</th></tr></thead>
          <tbody>
            @foreach ($g['projects'] as $p)
              <tr>
                <td>{{ $p['name'] }}<div class="ec-note">{{ $p['id'] }}</div></td>
                <td>{{ $p['office'] }}</td>
                <td>{{ $p['time'] }}</td>
                <td>{{ $p['status'] }}</td>
                <td>{{ $p['created'] }}</td>
                <td>{{ $p['entries'] }}人</td>
                <td>{{ $p['assigns'] }}人</td>
              </tr>
            @endforeach
          </tbody>
        </table></div>
      </div>
    @endforeach
  @endif
</div>
<script>
  // 一覧をクリップボードへ。navigator.clipboard は https か 127.0.0.1 でしか使えないので、古いやり方を控えに用意する。
  function copyAnnounce(btn){
    var el = document.getElementById('ecAnnounce');
    if (!el) return;
    var label = btn.textContent;
    var done = function(){ btn.textContent = '✓ コピーしました'; setTimeout(function(){ btn.textContent = label; }, 1600); };
    var fallback = function(){
      try { el.focus(); el.select(); if (document.execCommand('copy')) { done(); return; } } catch (e) {}
      alert('コピーできませんでした。枠の中を選んで、手でコピーしてください。');
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(el.value).then(done).catch(fallback);
    } else {
      fallback();
    }
  }
</script>
@endsection
