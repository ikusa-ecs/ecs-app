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
      ⚠ ExcelでCSVを開いて上書き保存したもの（Shift_JIS）でも、そのまま読めます。
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

      <div class="rr-warn">
        ⚠ 取り込むと、<b>ここに出ているコンテンツの必要人数は入れ替わります</b>（今の内容は消えて、CSVの内容になります）。<br>
        ⚠ <b>この一覧に出ていないコンテンツは、何も変わりません。</b><br>
        @if ($summary['newCount'] > 0)
          ⚠ <span class="rr-new">新規</span> が付いたコンテンツは、<b>コンテンツ台帳に新しく登録されます</b>
          （分類・体力系などは空のままなので、あとで<a href="/masters">マスタ管理</a>で埋めてください）。
        @endif
      </div>

      <div class="rr-scroll">
        <table class="rr-table">
          <thead>
            <tr>
              <th>コンテンツ</th>
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
                  @if ($item['isNew'])
                    <span class="rr-new">新規</span>
                  @else
                    <span class="rr-pos">（{{ $item['contentId'] }}）</span>
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

      <form method="POST" action="/role-requirement-import" style="margin-top:16px;">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <button type="submit" class="btn primary">この内容で取り込む</button>
        <a class="btn" href="/imports" style="margin-left:8px;">やめる</a>
      </form>
    </div>
  @endisset
@endsection
