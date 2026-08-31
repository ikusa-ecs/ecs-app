@extends('layouts.app')
@section('title', '出勤可能日の取込')
@section('h1', '社員の出勤可能日をまとめて取り込む')
@php($active = 'imports')

@push('head')
<style>
  .a-wrap { max-width: 1100px; }
  .a-note { font-size: 12.5px; color: var(--muted); line-height: 1.9; margin: 0 0 14px; }
  .a-field { margin-bottom: 16px; }
  .a-field label { display: block; font-size: 12.5px; font-weight: 700; margin-bottom: 6px; }
  .a-field textarea { width: 100%; min-height: 160px; font-family: ui-monospace, monospace; font-size: 12px; }
  .a-flash { border-radius: 10px; padding: 10px 14px; font-size: 13px; font-weight: 700; margin-bottom: 14px; }
  .a-ok { background: var(--ok-soft, #e7f6ec); border: 1px solid #bbe3c6; color: #15803d; }
  .a-err { background: var(--danger-soft, #fdecec); border: 1px solid #f0b9b9; color: #b91c1c; }
  .a-warn { background: #fdf3e2; border: 1px solid #ecd7a8; color: #8a5a10; border-radius: 10px;
            padding: 10px 14px; font-size: 12.5px; line-height: 1.9; margin-bottom: 14px; }
  .a-scroll { overflow-x: auto; }
  .a-table { border-collapse: collapse; font-size: 12px; }
  .a-table th, .a-table td { border: 1px solid var(--line); padding: 4px 6px; text-align: center; white-space: nowrap; }
  .a-table th.name, .a-table td.name { text-align: left; position: sticky; left: 0; background: #fbf8f3; z-index: 1; }
  .a-ok-cell { background: #e7f6ec; }
  .a-ng-cell { background: #fdecec; color: #b91c1c; }
  .a-maybe-cell { background: #fdf3e2; color: #8a5a10; }
  .a-off-cell { background: #eef2ff; color: #4338ca; }
  .a-skip { opacity: .45; }
  .a-memo { display: block; font-size: 10px; color: var(--muted); max-width: 90px; overflow: hidden; text-overflow: ellipsis; }
</style>
@endpush

@section('content')
  <div class="mock-note">
    月別の出勤可能日シート（スプレッドシート）を、そのままECSに流し込む画面です。
    <b>本人が <a href="/employee-availability">出勤可能日</a> で入れてくれるのを待たずに、まとめて登録できます。</b>
  </div>

  @if (session('status'))
    <div class="a-flash a-ok">{{ session('status') }}</div>
  @endif
  @if (session('import_error'))
    <div class="a-flash a-err">{{ session('import_error') }}</div>
  @endif
  @if ($errors->any())
    <div class="a-flash a-err">{{ $errors->first() }}</div>
  @endif

  <div class="panel a-wrap">
    <p class="a-note">
      <b>使い方</b>＝①どの月ぶんかを選ぶ → ②シートをコピーして貼り付ける（またはCSVを選ぶ）→
      ③<b>「内容を確認する」で中身を見てから</b>「この内容で取り込む」。<br>
      ⚠ <b>年月は必ず選んでください。</b>シートの見出しは「9/6」のように年が書いていないため、
      選ばないと<b>去年の日付として入ってしまいます</b>。<br>
      ⚠ 貼り付けるときは、<b>「項目」と日付が並んだ見出しの行から下</b>をまとめて選んでコピーしてください。
    </p>

    <form method="POST" action="/availability-import/preview" enctype="multipart/form-data">
      @csrf
      <div class="a-field">
        <label>どの月ぶんですか</label>
        <input type="month" name="period" value="{{ old('period', $period) }}" required style="width:200px;">
      </div>

      <div class="a-field">
        <label>スプレッドシートから貼り付ける</label>
        <textarea name="pasted" placeholder="スプレッドシートで見出しの行から下を選んでコピーし、ここに貼り付けてください。">{{ old('pasted', $pasted ?? '') }}</textarea>
      </div>

      <div class="a-field">
        <label>または、CSVファイルを選ぶ</label>
        <input type="file" name="csv" accept=".csv,.txt">
      </div>

      <div class="a-field">
        <label style="display:flex; align-items:center; gap:8px; font-weight:600;">
          <input type="checkbox" name="overwrite" value="1" style="width:auto;" @checked($overwrite ?? false)>
          本人がすでに入れた日も、シートの内容で上書きする
        </label>
        <span class="a-note" style="margin:4px 0 0;">
          ふだんは<b>チェックしないでください</b>。本人が自分で入れた予定を消してしまわないよう、
          既定では<b>空いている日だけ</b>を埋めます。
        </span>
      </div>

      <button class="btn primary" type="submit">内容を確認する</button>
    </form>
  </div>

  @isset($preview)
    @if ($preview['errors'])
      <div class="panel a-wrap" style="margin-top:16px;">
        <div class="a-warn">
          <b>読めなかった見出しがあります</b><br>
          @foreach ($preview['errors'] as $e)
            ・{{ $e }}<br>
          @endforeach
        </div>
      </div>
    @endif

    <div class="panel a-wrap" style="margin-top:16px;">
      <div class="panel-head"><h2>この内容で入ります（{{ $period }}）</h2></div>

      @if ($preview['missing'] || $preview['ambiguous'] || $preview['notEmployee'])
        <div class="a-warn">
          @if ($preview['missing'])
            <b>名簿に見つからなかったので取り込まない人</b>：{{ implode('／', $preview['missing']) }}<br>
            <span style="font-size:12px;">※ 名前の書き方が名簿と違う可能性があります。似た名前へ勝手に寄せることはしません。</span><br>
          @endif
          @if ($preview['ambiguous'])
            <b>同じ名前の人が名簿に複数いるので取り込まない人</b>：{{ implode('／', $preview['ambiguous']) }}<br>
          @endif
          @if ($preview['notEmployee'])
            <b>社員ではないので取り込まない人</b>：{{ implode('／', $preview['notEmployee']) }}<br>
            <span style="font-size:12px;">※ この画面は社員の出勤可能日ぶんです。</span><br>
          @endif
        </div>
      @endif

      @if (empty($preview['rows']))
        <p class="a-note">取り込める行がありませんでした。貼り付けた範囲に、見出しの行が入っているか確かめてください。</p>
      @else
        <p class="a-note">
          <b>{{ count($preview['rows']) }}人</b>ぶんが読めました。
          <span class="a-ok-cell" style="padding:2px 6px;">出勤可</span>
          <span class="a-ng-cell" style="padding:2px 6px;">不可</span>
          <span class="a-maybe-cell" style="padding:2px 6px;">条件つき</span>
          <span class="a-off-cell" style="padding:2px 6px;">希望休</span>
          <span class="a-skip" style="padding:2px 6px;">うすい色＝本人がすでに入れているので飛ばす</span><br>
          ⚠ シートに<b>予定名（「三菱商事様」「合宿」など）</b>が書いてあったマスは、
          <b>条件つき（△）にして、その文字をその日のメモに残しています</b>。勝手に「不可」にはしません。
        </p>

        <div class="a-scroll">
          <table class="a-table">
            <tr>
              <th class="name">氏名</th>
              @foreach ($preview['dates'] as $d)
                <th>{{ \Illuminate\Support\Carbon::parse($d)->format('n/j') }}</th>
              @endforeach
              <th>希望休など</th>
              <th class="name">その月の備考</th>
            </tr>
            @foreach ($preview['rows'] as $r)
              <tr>
                <td class="name">{{ $r['name'] }}<span class="a-memo">{{ $r['personId'] }}</span></td>
                @foreach ($preview['dates'] as $d)
                  @php($cell = $r['days'][$d] ?? null)
                  @if (! $cell)
                    <td>—</td>
                  @else
                    <td class="{{ ['稼働可'=>'a-ok-cell','NG'=>'a-ng-cell','未定'=>'a-maybe-cell','希望休'=>'a-off-cell'][$cell['availability']] }} {{ $cell['skipped'] && ! ($overwrite ?? false) ? 'a-skip' : '' }}">
                      {{ ['稼働可'=>'〇','NG'=>'×','未定'=>'△','希望休'=>'休'][$cell['availability']] }}
                      @if ($cell['memo'])
                        <span class="a-memo" title="{{ $cell['memo'] }}">{{ $cell['memo'] }}</span>
                      @endif
                    </td>
                  @endif
                @endforeach
                <td>
                  @php($offs = collect($r['days'])->filter(fn ($d) => $d['availability'] === '希望休'))
                  {{ $offs->isEmpty() ? '—' : $offs->keys()->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('n/j'))->implode('・') }}
                </td>
                <td class="name" style="max-width:220px; white-space:normal;">{{ $r['note'] ?: '—' }}</td>
              </tr>
            @endforeach
          </table>
        </div>

        <form method="POST" action="/availability-import" style="margin-top:16px;">
          @csrf
          <input type="hidden" name="period" value="{{ $period }}">
          {{-- 読んだ中身をそのまま持ち回す。⚠ hidden ではなく隠したテキストエリアにする理由＝
               改行を含む文字を確実に運ぶため（属性に入れると環境によって改行が落ちる）。 --}}
          <textarea name="pasted" style="display:none;">{{ $pasted ?? '' }}</textarea>
          @if ($overwrite ?? false)
            <input type="hidden" name="overwrite" value="1">
          @endif
          <button class="btn primary" type="submit"
                  onclick="return confirm('この内容で {{ $period }} の出勤可能日を取り込みます。よろしいですか？');">
            この内容で取り込む
          </button>
          <span class="a-note" style="margin-left:10px;">
            ※ ここで押すのは、上に出ている内容そのままです（読み直しません）。
          </span>
        </form>
      @endif
    </div>
  @endisset
@endsection
