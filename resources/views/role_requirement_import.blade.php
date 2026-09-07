@extends('layouts.app')
@section('title', '必要アサイン人数の取込')
@section('h1', '必要アサイン人数をまとめて取り込む')
@php($active = 'imports')

@push('head')
<style>
  .rr-wrap { max-width: 1000px; }
  .rr-note { font-size: 12.5px; color: var(--muted); line-height: 1.9; margin: 0 0 14px; }
  .rr-field { margin-bottom: 16px; }
  .rr-field label { display: block; font-size: 12.5px; font-weight: 700; margin-bottom: 6px; }
  .rr-flash { border-radius: 10px; padding: 10px 14px; font-size: 13px; font-weight: 700; margin-bottom: 14px; }
  .rr-ok { background: var(--ok-soft, #e7f6ec); border: 1px solid #bbe3c6; color: #15803d; }
  .rr-err { background: var(--danger-soft, #fdecec); border: 1px solid #f0b9b9; color: #b91c1c; }
  .rr-warn { background: #fdf3e2; border: 1px solid #ecd7a8; color: #8a5a10; border-radius: 10px;
             padding: 10px 14px; font-size: 12.5px; line-height: 1.9; margin-bottom: 14px; }
  .rr-sum { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 14px; }
  .rr-sum div { background: #fbf8f3; border: 1px solid var(--line); border-radius: 10px; padding: 8px 14px; font-size: 12.5px; }
  .rr-sum b { font-size: 17px; }
  .rr-scroll { overflow-x: auto; }
  .rr-table { border-collapse: collapse; font-size: 12px; width: 100%; }
  .rr-table th, .rr-table td { border: 1px solid var(--line); padding: 5px 8px; text-align: left; vertical-align: top; }
  .rr-table th { background: #fbf8f3; white-space: nowrap; }
  .rr-table td.num { text-align: center; white-space: nowrap; }
  .rr-new { display: inline-block; background: #fdf3e2; border: 1px solid #ecd7a8; color: #8a5a10;
            border-radius: 6px; padding: 1px 6px; font-size: 10.5px; font-weight: 700; margin-left: 6px; }
  /* 名前の書き方だけが違って同じものと分かった行（＝台帳を増やさずに済んだ行） */
  .rr-same { display: inline-block; background: #e7f6ec; border: 1px solid #bbe3c6; color: #15803d;
             border-radius: 6px; padding: 1px 6px; font-size: 10.5px; font-weight: 700; margin-left: 6px; }
  /* こちらでは決められず、人に選んでほしい行 */
  .rr-ask { display: inline-block; background: #fdecec; border: 1px solid #f0b9b9; color: #b91c1c;
            border-radius: 6px; padding: 1px 6px; font-size: 10.5px; font-weight: 700; margin-left: 6px; }
  .rr-table select { font-size: 12px; max-width: 320px; }
  .rr-pos { color: var(--muted); font-size: 11px; }
</style>
@endpush

@section('content')
  <div class="mock-note">
    「必要アサイン人数リスト」（コンテンツごと・規模ごとに、どのポジションが何人必要か）を、
    そのままECSに流し込む画面です。<b>入れた人数は、アサイン画面のポジション枠として出てきます。</b>
    取り込んだあとの中身は、<a href="/masters">マスタ管理</a>のコンテンツごとの「必要人数」で見られます。
  </div>

  @if (session('status'))
    <div class="rr-flash rr-ok">{{ session('status') }}</div>
  @endif
  @if (session('import_error'))
    <div class="rr-flash rr-err">{{ session('import_error') }}</div>
  @endif
  @if ($errors->any())
    <div class="rr-flash rr-err">{{ $errors->first() }}</div>
  @endif

  <div class="panel rr-wrap">
    <p class="rr-note">
      <b>使い方</b>＝①「必要アサイン人数リスト」のCSVファイルを選ぶ → ②<b>「内容を確認する」で中身を見てから</b>
      「この内容で取り込む」。<br>
      ⚠ <b>コピーして貼り付ける形にはしていません。</b>このCSVはコンテンツ名のマスの中で改行しているため、
      貼り付けると形が崩れて読めなくなります。<b>ファイルを選んでください。</b><br>
      ⚠ ExcelでCSVを開いて上書き保存したもの（Shift_JIS）でも、そのまま読めます。<br>
      ✅ <b>名前の書き方が少し違うだけのものは、同じコンテンツとして扱います</b>（全角と半角・空白の有無・「・」の有無・大文字小文字）。
      台帳に<b>似た名前が2つできてしまうのを防ぐため</b>です。決められないものは<b>確認画面で選んでいただきます</b>
      （勘で寄せません）。
    </p>

    <form method="POST" action="/role-requirement-import/preview" enctype="multipart/form-data">
      @csrf
      <div class="rr-field">
        <label>必要アサイン人数リストのCSVファイル</label>
        <input type="file" name="csv" accept=".csv,.txt" required>
      </div>
      <button type="submit" class="btn primary">内容を確認する</button>
    </form>
  </div>

  @isset($summary)
    <div class="panel rr-wrap" style="margin-top:16px;">
      <h2 style="font-size:15px; margin:0 0 10px;">この内容で入ります（{{ $fileName }}）</h2>

      <div class="rr-sum">
        <div>コンテンツ <b>{{ $summary['contentCount'] }}</b> 件</div>
        <div>うち新しく作る <b>{{ $summary['newCount'] }}</b> 件</div>
        <div>ポジション枠 <b>{{ $summary['slotTotal'] }}</b> 件</div>
      </div>

      @if ($collisions !== [])
        <div class="rr-flash rr-err" style="font-weight:400;">
          ⚠ <b>同じコンテンツを2つの行が指しています。取り込めません。</b>
          どちらかを「★ 新しく作る」に変えるか、別のコンテンツを選んでください。
          <ul style="margin:8px 0 0 18px;">
            @foreach ($collisions as $contentId => $products)
              <li><b>{{ $contentId }}</b> ← {{ implode('／', $products) }}</li>
            @endforeach
          </ul>
        </div>
      @endif

      <div class="rr-warn">
        ⚠ 取り込むと、<b>ここに出ているコンテンツの必要人数は入れ替わります</b>（今の内容は消えて、CSVの内容になります）。<br>
        ⚠ <b>この一覧に出ていないコンテンツは、何も変わりません。</b><br>
        @if ($summary['matchedCount'] > 0)
          ✅ <b>{{ $summary['matchedCount'] }}件</b>は、<b>名前の書き方だけが違う登録済みコンテンツ</b>に入れます
          （<span class="rr-same">書き方ちがい</span> の行）。台帳に似た名前が2つできないよう、こちらで寄せました。<br>
        @endif
        @if ($summary['needsChoiceCount'] > 0)
          ⚠ <b>{{ $summary['needsChoiceCount'] }}件</b>は<b>こちらでは決められませんでした</b>
          （<span class="rr-ask">要確認</span> の行）。<b>「入れる先」を選んでください。</b>
          違うコンテンツに入れてしまうと、まちがった人数が入ります。<br>
        @endif
        @if ($summary['newCount'] > 0)
          ⚠ <b>{{ $summary['newCount'] }}件</b>は<b>コンテンツ台帳に新しく登録されます</b>（「★ 新しく作る」の行）。
          分類・体力系などは空のままなので、あとで<a href="/masters">マスタ管理</a>で埋めてください。
        @endif
      </div>

      <form method="POST" action="/role-requirement-import">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="rr-scroll">
          <table class="rr-table">
            <thead>
              <tr>
                <th>CSVのコンテンツ名</th>
                <th>入れる先（コンテンツ台帳）</th>
                <th>小型（～49名）</th>
                <th>中型（50～99名）</th>
                <th>大型（100～150名）</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($summary['items'] as $item)
                <tr>
                  <td>
                    {{ $item['product'] }}
                    @if ($item['matchType'] === 'normalized')
                      <span class="rr-same">書き方ちがい</span>
                    @elseif (in_array($item['matchType'], ['ambiguous', 'maybe'], true))
                      <span class="rr-ask">要確認</span>
                    @endif
                  </td>
                  <td>
                    @if ($item['matchType'] === 'exact')
                      <span class="rr-pos">{{ $item['contentId'] }}</span> 登録済み（同じ名前）
                    @elseif ($item['candidates'] === [])
                      <span class="rr-new">★ 新しく作る</span>
                      <input type="hidden" name="map[{{ $item['key'] }}]" value="">
                    @else
                      <select name="map[{{ $item['key'] }}]">
                        <option value="" @selected($item['contentId'] === null)>★ 新しく作る</option>
                        @foreach ($item['candidates'] as $c)
                          <option value="{{ $c['id'] }}" @selected($item['contentId'] === $c['id'])>
                            {{ $c['id'] }}　{{ $c['name'] }}　に入れる
                          </option>
                        @endforeach
                      </select>
                      @if ($item['matchedName'] !== null && $item['matchType'] === 'normalized')
                        <br><span class="rr-pos">＝台帳の「{{ $item['matchedName'] }}」と同じものとして入れます</span>
                      @endif
                    @endif
                  </td>
                  @foreach (\App\Support\RoleRequirementCsv::SCALES as $scale)
                    @php($info = $item['scales'][$scale] ?? null)
                    <td class="num">
                      @if ($info)
                        <b>{{ $info['total'] }}名</b><br>
                        <span class="rr-pos">
                          @foreach ($info['byPos'] as $pos => $count){{ $pos }}×{{ $count }}@if (! $loop->last) / @endif @endforeach
                        </span>
                      @else
                        <span class="rr-pos">—</span>
                      @endif
                    </td>
                  @endforeach
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>

        <div style="margin-top:16px;">
          <button type="submit" class="btn primary">この内容で取り込む</button>
          <a class="btn" href="/imports" style="margin-left:8px;">やめる</a>
        </div>
      </form>
    </div>
  @endisset
@endsection
