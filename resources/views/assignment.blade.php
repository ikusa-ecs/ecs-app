@extends('layouts.app')
@section('title', 'アサイン（DB保存）')
@section('h1', 'アサイン')
@php($active = 'assign_detail')

@push('head')
<style>
    .asg-info { color: var(--muted); font-size: 12.5px; margin: 2px 0 14px; }

    /* 案件ヘッダー */
    .asg-head { display: flex; align-items: center; gap: 22px; flex-wrap: wrap; }
    .asg-head .pname { font-size: 18px; font-weight: 700; }
    .asg-head .meta { display: flex; gap: 20px; flex-wrap: wrap; color: var(--muted); font-size: 13px; margin-top: 6px; }
    .asg-head .meta b { color: var(--ink); font-weight: 600; }
    /* 案件の備考（見落とすと事故るので薄い黄色の帯で目立たせる） */
    .asg-note-band { display: flex; align-items: flex-start; gap: 8px; margin-top: 10px;
      background: #fdf6d8; border: 1px solid #e8dca0; border-radius: 8px; padding: 8px 12px;
      font-size: 13px; color: var(--ink); line-height: 1.6; }
    .asg-note-band .lbl { font-weight: 700; white-space: nowrap; color: #8a6d1a; }
    .asg-note-band .bd { word-break: break-word; }

    /* 操作バー（選択数・検索） */
    .asg-bar { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin: 4px 0 12px; }
    .asg-bar .selnum { font-size: 14px; font-weight: 700; }
    .asg-bar .selnum b { font-variant-numeric: tabular-nums; }
    .asg-bar .selnum .need { color: var(--muted); font-weight: 400; }
    .asg-bar input[type=text] {
      padding: 8px 11px; border: 1px solid var(--line); border-radius: 8px;
      font-size: 13.5px; font-family: inherit; background: #fff; min-width: 200px;
    }
    .asg-bar .spacer { flex: 1; }

    /* 区分バッジ */
    .lv { font-size: 11.5px; padding: 1px 8px; border-radius: 999px; font-weight: 700; white-space: nowrap; }
    .lv.新人 { background: var(--brand-soft); color: var(--brand-dark); }
    .lv.中堅 { background: #ece3d4; color: #7a6a58; }
    .lv.ベテラン { background: var(--ok-soft); color: #15803d; }

    /* できる役割タグ */
    .can-tags { display: flex; flex-wrap: wrap; gap: 3px; }
    .can-tag { font-size: 10.5px; font-weight: 700; padding: 1px 6px; border-radius: 6px;
               background: var(--ok-soft); color: #15803d; white-space: nowrap; }
    .role-sel {
      min-width: 130px; border: 1px solid var(--line); background: #fff; border-radius: 8px;
      padding: 5px 7px; font-family: inherit; font-size: 12.5px; color: var(--ink); cursor: pointer;
    }
    /* 兼任（サブ役割）：主役割の下に小さく。選ぶと枠を2つ埋める */
    .role2-sel { margin-top: 4px; font-size: 11.5px; color: #7a6a58; min-width: 130px; }
    /* 担当メモ・巡回数の入力（.role-sel と同じ雰囲気で・縦に並べる） */
    .rp-cell { display: flex; flex-direction: column; gap: 4px; }
    .note-in, .patrol-in {
      border: 1px solid var(--line); background: #fff; border-radius: 8px;
      padding: 5px 7px; font-family: inherit; font-size: 12.5px; color: var(--ink);
    }
    .note-in { width: 140px; }
    .patrol-in { width: 70px; }
    .remark-in {
      border: 1px solid var(--line); background: #fff; border-radius: 8px;
      padding: 5px 7px; font-family: inherit; font-size: 12.5px; color: var(--ink); width: 170px;
    }
    table.tbl td.chk { width: 36px; text-align: center; }
    table.tbl input[type=checkbox] { width: 17px; height: 17px; accent-color: var(--brand); cursor: pointer; }
    .ng-note { color: var(--danger); font-size: 11.5px; }

    /* この日の稼働希望バッジ（希望あり=強調・稼働可=緑・NG=赤・未定=グレー） */
    .wish { font-size: 10.5px; font-weight: 700; padding: 1px 8px; border-radius: 999px; white-space: nowrap; }
    /* エントリー（この案件に応募した人）＝一番の手がかりなので目立たせる */
    .wish.entry { background: #fde68a; color: #7a4a00; }
    /* エントリー時に本人が書いた一言 */
    .entry-note { font-size: 11.5px; color: #6b5544; margin-top: 3px; line-height: 1.5; overflow-wrap: anywhere; }
    .wish.希望 { background: var(--brand-soft); color: var(--brand-dark); }
    .wish.稼働可 { background: var(--ok-soft); color: #15803d; }
    .wish.NG { background: var(--danger-soft); color: #b91c1c; }
    .wish.未定 { background: #eef0f2; color: #8a8f98; }

    /* おすすめ度（自動アサインの頭脳がつけた点数と理由） */
    .score-pill { font-size: 12.5px; font-weight: 800; padding: 2px 10px; border-radius: 999px;
                  white-space: nowrap; font-variant-numeric: tabular-nums; }
    .score-pill.hi  { background: var(--brand); color: #fff; }
    .score-pill.mid { background: var(--brand-soft); color: var(--brand-dark); }
    .score-pill.lo  { background: #eef0f2; color: #8a8f98; }
    .score-reasons { font-size: 10.5px; color: var(--muted); margin-top: 3px; line-height: 1.35; max-width: 230px; }
    .score-warn { font-size: 10.5px; color: #b45309; margin-top: 2px; line-height: 1.35; }
    .block-note { font-size: 10.5px; color: #b91c1c; font-weight: 700; margin-top: 3px; }
    /* NG該当で自動候補から外れた行は薄く見せる（チェックは可能＝人が最終判断） */
    .staff-row.blocked > td { opacity: .5; }
    .staff-row.blocked .score-pill { background: var(--danger-soft); color: #b91c1c; opacity: 1; }
    .asg-legend { color: var(--muted); font-size: 12px; margin: 2px 0 10px; }
    .asg-legend b { color: var(--ink); }

    /* 警告バッジ（同日かぶり・月20件上限） */
    .dup-warn { font-size: 10.5px; font-weight: 700; padding: 1px 7px; border-radius: 999px;
                background: var(--danger-soft); color: #b91c1c; white-space: nowrap; }
    .capb { font-size: 10.5px; font-weight: 700; padding: 1px 7px; border-radius: 999px; white-space: nowrap; }
    .capb.over { background: var(--danger-soft); color: #b91c1c; }
    .capb.near { background: #fdecd2; color: #b45309; }
    /* 選択数の色（必要人数との差） */
    .selnum.under b { color: var(--danger); }
    .selnum.exact b { color: #15803d; }
    .selnum.over  b { color: #b45309; }

    /* NGペアが同席している行を赤くする */
    .staff-row.ng-hit > td { background: var(--danger-soft); }
    #ngWarn .ng-list { font-weight: 700; }

    /* ポジション雛型（必要ポジション人数の枠） */
    .pos-tpl { padding: 12px 16px; margin-top: 14px; }
    .pos-tpl .ttl { font-size: 13px; font-weight: 700; margin-bottom: 8px; }
    .pos-tpl .ttl .sub { color: var(--muted); font-weight: 400; font-size: 11.5px; margin-left: 6px; }
    .pos-slots { display: flex; flex-wrap: wrap; gap: 8px; }
    .pos-slot {
      display: inline-flex; align-items: center; gap: 8px;
      border: 1px solid var(--line); border-radius: 999px; padding: 5px 13px;
      font-size: 12.5px; background: #fff;
    }
    .pos-slot .nm { font-weight: 700; }
    .pos-slot .cnt { font-variant-numeric: tabular-nums; color: var(--muted); }
    .pos-slot .cnt b { font-size: 13.5px; }
    /* 過不足で色分け（不足=赤・ちょうど=緑・超過=橙） */
    .pos-slot.under { border-color: var(--danger); background: var(--danger-soft); }
    .pos-slot.under .cnt b { color: var(--danger); }
    .pos-slot.exact { border-color: #86c99a; background: var(--ok-soft); }
    .pos-slot.exact .cnt b { color: #15803d; }
    .pos-slot.over  { border-color: #e8b877; background: #fdecd2; }
    .pos-slot.over  .cnt b { color: #b45309; }
    .pos-tpl .note { color: var(--muted); font-size: 12px; }
    /* 担当の内訳（備考・巡回） */
    .role-detail { margin-top: 12px; padding-top: 10px; border-top: 1px dashed var(--line); }
    .role-detail .rd-ttl { font-size: 12.5px; font-weight: 700; margin-bottom: 6px; }
    .role-detail .rd-ttl .sub { color: var(--muted); font-weight: 400; font-size: 11px; margin-left: 6px; }
    .role-detail .rd-row { font-size: 12.5px; line-height: 1.9; }
    .role-detail .rd-row b { color: var(--ink); }
    .role-detail .rd-item { display: inline-block; background: #f4f1ec; border: 1px solid var(--line);
      border-radius: 6px; padding: 1px 8px; margin: 2px 4px 2px 0; font-size: 11.5px; white-space: nowrap; }

    /* 下部の保存バー（画面下に貼り付く） */
    .save-bar {
      position: sticky; bottom: 0; background: var(--panel); border: 1px solid var(--line);
      border-radius: 12px; box-shadow: 0 -2px 12px rgba(16,24,40,0.06);
      padding: 14px 20px; margin-top: 18px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    }
    .save-bar .spacer { flex: 1; }
    .save-bar .hint { font-size: 12px; color: var(--muted); }
</style>
@endpush

@section('content')

  {{-- 保存結果などのお知らせ --}}
  @if (session('status'))
    <div class="alert ok" style="margin-bottom:14px;"><span class="ico">✓</span><div>{{ session('status') }}</div></div>
  @endif

  <p class="asg-info">この画面は<b>本物のデータ</b>です。選んだスタッフは <code>assignments</code>（アサイン）テーブルにDB保存され、次に開くと選択済みで表示されます。既存のモック画面（日別ボード・案件詳細）とは別物です。</p>

  {{-- 案件ヘッダー --}}
  <div class="panel">
    <div class="asg-head">
      <div>
        <div class="pname">{{ $project->project_name }}</div>
        <div class="meta">
          <span>コンテンツ：<b>{{ $contentName }}</b></span>
          <span>日程：<b>{{ $date ? $date->format('Y/n/j') . '（' . ['日','月','火','水','木','金','土'][$date->dayOfWeek] . '）' : '未設定' }}</b></span>
          <span>必要人数：<b>{{ $project->required_count ?? '—' }}名</b></span>
          <span>会場：<b>{{ $project->location ?: '—' }}</b></span>
          <span>顧客：<b>{{ $project->client ?: '—' }}</b></span>
          <span>D：<b>{{ $project->director->name ?? '未定' }}</b></span>
          <span>状況：<b>{{ $project->status ?? '未着手' }}</b></span>
        </div>
        {{-- 案件の備考：担当が見落とすと事故るので、メタ情報の下に帯で目立たせる --}}
        @if (trim((string) $project->note) !== '')
          <div class="asg-note-band">
            <span class="lbl">📌 案件の備考</span>
            <span class="bd">{!! nl2br(e($project->note)) !!}</span>
          </div>
        @endif
      </div>
    </div>
  </div>

  @if (! $date)
    {{-- 日付が無いと assignments に保存できない（date は必須） --}}
    <div class="alert warn" style="margin-top:16px;">
      <span class="ico">⚠</span>
      <div>この案件は<b>開催日が未設定</b>です。アサインは日付ごとに保存するため、先に「案件登録」で日付を入れてください。</div>
    </div>
  @else
    <form method="POST" action="/project-assign/save" id="asgForm" data-need="{{ $project->required_count ?? '' }}">
      @csrf
      <input type="hidden" name="project_id" value="{{ $project->id }}">

      <div class="asg-bar">
        <span class="selnum" id="selnumWrap">選択 <b id="selCount">0</b> <span class="need">/ 必要 {{ $project->required_count ?? '—' }}名</span></span>
        <label style="font-size:13px; display:inline-flex; align-items:center; gap:6px; cursor:pointer; white-space:nowrap;"><input type="checkbox" id="onlyAvail" checked onchange="filterStaff()" style="width:16px; height:16px; accent-color:var(--brand); cursor:pointer;"> この日 希望・稼働可 の人だけ</label>
        <label style="font-size:13px; display:inline-flex; align-items:center; gap:6px; cursor:pointer; white-space:nowrap;"><input type="checkbox" id="sortByScore" checked onchange="sortRows()" style="width:16px; height:16px; accent-color:var(--brand); cursor:pointer;"> おすすめ順に並べる</label>
        <button type="button" class="btn primary" id="autoPlaceBtn" onclick="autoPlace()" title="空いている必要人数ぶん、おすすめ上位を自動でチェックします（保存はしません）">✨ おすすめを自動で仮置き</button>
        <div class="spacer"></div>
        <input type="text" id="staffSearch" placeholder="名前でしぼり込み" oninput="filterStaff()">
      </div>

      {{-- ポジション雛型：コンテンツ×規模の必要人数から「枠」を出し、下で選ぶと現在数が動く --}}
      @if (count($roleReq))
        <div class="panel pos-tpl">
          <div class="ttl">ポジション枠<span class="sub">コンテンツ×規模の必要人数（下で役割を選ぶと「現在」が動きます）</span></div>
          <div class="pos-slots">
            @foreach ($roleReq as $code => $need)
              <span class="pos-slot" data-role="{{ $code }}" data-need="{{ $need }}">
                <span class="nm">{{ $roleLabels[$code] ?? $code }}</span>
                <span class="cnt"><b class="cur">{{ $roleAssigned[$code] ?? 0 }}</b> / {{ $need }}</span>
              </span>
            @endforeach
          </div>
          @if (count($roleDetail))
            <div class="role-detail">
              <div class="rd-ttl">担当の内訳<span class="sub">（必要アサイン人数リストより・備考／巡回）</span></div>
              @foreach ($roleDetail as $d)
                <div class="rd-row"><b>{{ $d['label'] }}</b>：
                  @foreach ($d['items'] as $it)<span class="rd-item">{{ $it['note'] !== '' ? $it['note'] : '指定なし' }}@if ($it['patrol'] !== null)（巡回{{ $it['patrol'] }}）@endif ×{{ $it['count'] }}</span>@endforeach
                </div>
              @endforeach
            </div>
          @endif
        </div>
      @else
        <div class="panel pos-tpl">
          <span class="note">このコンテンツ・規模の<b>ポジション別人数が未登録</b>のため、枠は表示していません。
            目標は<b>{{ $needTotal }}名</b>（案件の運営人数{{ $needIsDefault ? 'が未入力のため既定の5名' : '' }}）です。下の一覧から選んで役割を指定してください。
            コンテンツマスタの「必要人数」を登録すると、ここに枠が出ます。</span>
        </div>
      @endif

      {{-- NGペア（相性が悪い2人）が両方チェックされたら警告（同席を止めはしない＝警告のみ） --}}
      <div class="alert danger" id="ngWarn" style="display:none; margin-bottom:12px;">
        <span class="ico">⚠</span>
        <div>NGペアが同席しています：<span class="ng-list"></span><br>
          <span class="muted" style="font-size:11.5px;">相性の悪い組み合わせです。どちらかを外すか、問題ないか確認してください（保存は止めません）。</span>
        </div>
      </div>

      @if ($officeScope)
        {{-- 拠点で絞っているときだけ出す注記（管理者が全拠点表示のときは出さない） --}}
        <p class="asg-legend" style="background:#fbf6ef;">
          候補は<b>{{ $officeScope }}のスタッフ</b>だけを表示しています。
          すでにこの案件に入っている人は、他拠点でもそのまま表示します（チェックを外さなければ担当は消えません）。
        </p>
      @endif

      <p class="asg-legend">「<b>おすすめ</b>」は、希望・この案件のコンテンツ経験・自社専属・人柄（場を良くする／新人フォロー／自分で動ける）・リピート継続などから<b>自動でつけた目安の点数</b>です（高いほどおすすめ）。あくまで参考で、<b>最終判断は人</b>が行います。この日NG・NGペア同席の人は「除外」として下に薄く表示します。</p>

      <div class="panel" style="padding-top:6px;">
        <div class="tbl-scroll" style="overflow-x:auto;">
        <table class="tbl">
          <thead>
            <tr>
              <th class="chk">☑</th>
              <th>スタッフ</th>
              <th>おすすめ</th>
              <th>区分</th>
              <th>できる役割</th>
              <th class="num">経験</th>
              <th>この案件での役割<span style="display:block; font-weight:400; font-size:10.5px; color:var(--muted);">選ぶと自動でアサイン</span></th>
              <th>担当・巡回</th>
              <th>備考（一言）</th>
              <th>NG</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($staff as $s)
              @php($ex = $existing[$s['id']] ?? null)
              @php($w = $s['wish'] ?? null)
              @php($isAvail = in_array($w, ['希望', '稼働可'], true))
              <tr class="staff-row @if ($s['blocked']) blocked @endif" data-name="{{ $s['name'] }}" data-score="{{ $s['score'] }}" data-pos="{{ implode('|', $s['posCodes']) }}" data-ng="{{ implode('|', $s['ng']) }}" data-avail="{{ $isAvail ? '1' : '0' }}" data-entry="{{ !empty($s['entry']) ? '1' : '0' }}" data-assigned="{{ $ex ? '1' : '0' }}">
                <td class="chk">
                  <input type="checkbox" name="staff_ids[]" value="{{ $s['id'] }}" {{ $ex ? 'checked' : '' }} onchange="updateCount()">
                </td>
                <td>
                  <strong>{{ $s['name'] }}</strong>
                  <span class="muted" style="font-size:11.5px;">{{ $s['id'] }}</span>
                  @if (!empty($s['entry']))<span class="wish entry" title="この案件にエントリー（応募）しています@if(!empty($s['entryNote']))：{{ $s['entryNote'] }}@endif">★エントリー</span>@endif
                  @if ($w === '希望')<span class="wish 希望">希望</span>@elseif ($w === '稼働可')<span class="wish 稼働可">稼働可</span>@elseif ($w === 'NG')<span class="wish NG">NG</span>@endif
                  @if ($s['exclusive'])<span class="badge ok" style="font-size:10px;">専属</span>@endif
                  @if ($ex && $ex['status'] === '確定')<span class="badge ok" style="font-size:10px;">確定済</span>@endif
                  @if (!empty($s['entryNote']))
                    <div class="entry-note" title="エントリーのときに本人が書いた一言">💬 {{ $s['entryNote'] }}</div>
                  @endif
                  @if (isset($sameDay[$s['id']]))
                    <span class="dup-warn" title="同じ日に別の案件へ割り当て済み（ダブルブッキングの可能性）">⚠ 同日: {{ implode('・', $sameDay[$s['id']]) }}</span>
                  @endif
                  @php($mc = $monthCount[$s['id']] ?? 0)
                  @if ($mc >= $monthCap)
                    <span class="capb over" title="今月のアサインが上限（{{ $monthCap }}件）に達しています">今月 {{ $mc }}/{{ $monthCap }} 上限</span>
                  @elseif ($mc >= $monthCap - 2)
                    <span class="capb near" title="今月のアサインが上限（{{ $monthCap }}件）に近づいています">今月 {{ $mc }}/{{ $monthCap }}</span>
                  @endif
                </td>
                <td>
                  <span class="score-pill {{ $s['score'] >= 50 ? 'hi' : ($s['score'] >= 25 ? 'mid' : 'lo') }}">{{ $s['score'] > 0 ? '+' : '' }}{{ (int) round($s['score']) }}</span>
                  @if ($s['blocked'])<div class="block-note">自動候補から除外：{{ $s['blockReason'] }}</div>@endif
                  @if (count($s['reasons']))<div class="score-reasons">{{ implode('／', $s['reasons']) }}</div>@endif
                  @if (count($s['warnings']))<div class="score-warn">⚠ {{ implode('／', $s['warnings']) }}</div>@endif
                </td>
                <td><span class="lv {{ $s['level'] }}">{{ $s['level'] }}</span></td>
                <td>
                  @if (count($s['posLabels']))
                    <div class="can-tags">
                      @foreach ($s['posLabels'] as $pl)<span class="can-tag">{{ $pl }}</span>@endforeach
                    </div>
                  @else
                    <span class="muted" style="font-size:11.5px;">—</span>
                  @endif
                </td>
                <td class="num">{{ $s['exp'] }}</td>
                <td>
                  <select name="role[{{ $s['id'] }}]" class="role-sel" title="この案件で担当する役割（任意）">
                    <option value="">—</option>
                    @foreach ($roleLabels as $k => $label)
                      <option value="{{ $k }}" {{ ($ex['role'] ?? '') === $k ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                  </select>
                  {{-- 兼任（サブ役割）：1人が2役こなす場合（D兼OP等）。枠は主役割＋兼任の両方に+1で数える --}}
                  <select name="role2[{{ $s['id'] }}]" class="role-sel role2-sel" title="兼任（サブ役割）＝1人で2役こなす場合に選ぶ">
                    <option value="">＋兼任なし</option>
                    @foreach ($roleLabels as $k => $label)
                      <option value="{{ $k }}" {{ ($ex['role2'] ?? '') === $k ? 'selected' : '' }}>兼 {{ $label }}</option>
                    @endforeach
                  </select>
                </td>
                <td>
                  {{-- 担当メモ（軍師/サポ等）と巡回数：フォーム内なので input を置くだけで save() が保存する --}}
                  <div class="rp-cell">
                    <input type="text" name="note[{{ $s['id'] }}]" list="asgNoteOpts" class="note-in" placeholder="担当（軍師/サポ等）" value="{{ $existing[$s['id']]['note'] ?? '' }}">
                    <input type="number" name="patrol[{{ $s['id'] }}]" min="0" class="patrol-in" placeholder="巡回" value="{{ $existing[$s['id']]['patrol'] ?? '' }}">
                  </div>
                </td>
                <td>
                  {{-- 人ごとの一言（自由記入）。フォーム内なので input を置くだけで save() が保存する --}}
                  <input type="text" name="remark[{{ $s['id'] }}]" class="remark-in" placeholder="例）昼から/初参加でフォロー" value="{{ $existing[$s['id']]['remark'] ?? '' }}">
                </td>
                <td>
                  @if (count($s['ng']))<span class="ng-note">NG: {{ implode('、', $s['ng']) }}</span>@endif
                </td>
              </tr>
            @empty
              <tr><td colspan="10" class="muted" style="text-align:center; padding:20px;">スタッフが登録されていません。</td></tr>
            @endforelse
          </tbody>
        </table>
        </div>
      </div>

      {{-- 担当メモの候補（軍師/サポ等）。表内で一度だけ置けば各行の note 入力から使える --}}
      <datalist id="asgNoteOpts">
        @foreach ($noteOptions as $opt)<option value="{{ $opt }}">@endforeach
      </datalist>

      <p id="noAvail" class="muted" style="display:none; font-size:12.5px; margin-top:8px;">この日に「希望・稼働可」の人がまだいません。上の「この日 希望・稼働可 の人だけ」のチェックを外すと、名簿の全員から選べます。</p>
      <div id="autoNote" class="alert ok" style="display:none; margin-top:10px;"><span class="ico">✨</span><div></div></div>

      <div class="save-bar">
        <span class="hint">「いま選んでいる人」で上書き保存します（チェックを外した人はアサインから消えます）。複数日の案件は本番・予備日・リハごとに別の案件として保存します。</span>
        <div class="spacer"></div>
        <button class="btn" type="submit" name="status" value="仮">仮で保存（調整中）</button>
        <button class="btn primary" type="submit" name="status" value="確定">確定で保存</button>
      </div>
    </form>
  @endif
@endsection

@push('scripts')
@verbatim
<script>
  var asgForm = document.getElementById('asgForm');
  var NEED = asgForm ? parseInt(asgForm.dataset.need || '', 10) : NaN;

  function checkedRows() {
    return Array.from(document.querySelectorAll('input[name="staff_ids[]"]:checked'))
      .map(cb => cb.closest('.staff-row')).filter(Boolean);
  }
  function selectedCount() {
    return document.querySelectorAll('input[name="staff_ids[]"]:checked').length;
  }
  // 選ばれている人どうしでNGペア（相性が悪い組み合わせ）を探す。該当行を赤くし、ペア名を返す。
  function ngPairs() {
    const rows = checkedRows();
    const names = new Set(rows.map(tr => tr.dataset.name));
    const pairs = [];
    document.querySelectorAll('.staff-row').forEach(tr => tr.classList.remove('ng-hit'));
    rows.forEach(tr => {
      (tr.dataset.ng || '').split('|').filter(Boolean).forEach(partner => {
        if (names.has(partner)) {
          tr.classList.add('ng-hit');
          const key = [tr.dataset.name, partner].sort().join(' × ');
          if (!pairs.includes(key)) pairs.push(key);
        }
      });
    });
    const warn = document.getElementById('ngWarn');
    if (warn) {
      if (pairs.length) { warn.style.display = ''; warn.querySelector('.ng-list').textContent = pairs.join('、'); }
      else warn.style.display = 'none';
    }
    return pairs;
  }
  // ポジション枠の「現在◯」を、チェック済みの人が選んでいる役割から数え直す。
  function updatePositions() {
    const counts = {};
    checkedRows().forEach(tr => {
      // 主役割＋兼任（サブ役割）の両方を枠に数える＝1人で2役カバーを反映。
      const r = (tr.querySelector('select.role-sel:not(.role2-sel)') || {}).value || '';
      if (r) counts[r] = (counts[r] || 0) + 1;
      const r2 = (tr.querySelector('select.role2-sel') || {}).value || '';
      if (r2) counts[r2] = (counts[r2] || 0) + 1;
    });
    document.querySelectorAll('.pos-slot').forEach(slot => {
      const role = slot.dataset.role;
      const need = parseInt(slot.dataset.need || '0', 10);
      const cur = counts[role] || 0;
      const b = slot.querySelector('.cur');
      if (b) b.textContent = cur;
      slot.classList.remove('under', 'exact', 'over');
      slot.classList.add(cur < need ? 'under' : (cur > need ? 'over' : 'exact'));
    });
  }
  function updateCount() {
    const n = selectedCount();
    const el = document.getElementById('selCount');
    if (el) el.textContent = n;
    // 必要人数との差で色分け（不足=赤／ちょうど=緑／超過=橙）
    const wrap = document.getElementById('selnumWrap');
    if (wrap) {
      wrap.classList.remove('under', 'exact', 'over');
      if (!isNaN(NEED)) wrap.classList.add(n < NEED ? 'under' : (n > NEED ? 'over' : 'exact'));
    }
    ngPairs();          // NGペアの警告も更新
    updatePositions();  // ポジション枠の現在数も更新
  }
  // 役割を選んだら＝その人を自動でアサイン（チェックを付ける）。「役割＝アサイン」で1アクション化。
  // ※役割なしでアサインしたいとき（ポジション枠の無い案件・役割未定で先に押さえる等）は、
  //   従来どおりチェックだけでOK。役割を「—」に戻してもチェックは自動では外さない（誤操作防止）。
  document.querySelectorAll('select.role-sel').forEach(sel => {
    sel.addEventListener('change', function () {
      if (this.value) {
        const cb = this.closest('.staff-row')?.querySelector('input[name="staff_ids[]"]');
        if (cb) cb.checked = true;
      }
      updateCount();   // 選択数・枠の現在数・NG警告をまとめて更新
    });
  });
  function filterStaff() {
    const q = document.getElementById('staffSearch').value.trim();
    const availToggle = document.getElementById('onlyAvail');
    const availOnly = availToggle ? availToggle.checked : false;
    let visible = 0;
    document.querySelectorAll('.staff-row').forEach(tr => {
      const nameOk = !q || (tr.dataset.name || '').includes(q);
      // 「希望者だけ」表示でも、すでにアサイン済みの人は隠さない（外す判断ができるように）
      const availOk = !availOnly || tr.dataset.avail === '1' || tr.dataset.assigned === '1';
      const show = nameOk && availOk;
      tr.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    const note = document.getElementById('noAvail');
    if (note) note.style.display = (visible === 0 && availOnly) ? '' : 'none';
  }
  // おすすめ順（点数の高い順・除外は末尾）と名前順を切り替える。行を並べ替えるだけ（表示/非表示は filterStaff が担当）。
  function sortRows() {
    const toggle = document.getElementById('sortByScore');
    const byScore = toggle ? toggle.checked : true;
    const tbody = document.querySelector('table.tbl tbody');
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr.staff-row'));
    rows.sort((a, b) => {
      if (byScore) {
        const ba = a.classList.contains('blocked') ? 1 : 0;
        const bb = b.classList.contains('blocked') ? 1 : 0;
        if (ba !== bb) return ba - bb;   // 除外は下へ
        return parseFloat(b.dataset.score || '0') - parseFloat(a.dataset.score || '0');
      }
      return (a.dataset.name || '').localeCompare(b.dataset.name || '', 'ja');
    });
    rows.forEach(r => tbody.appendChild(r));
  }
  // 「おすすめを自動で仮置き」：空いている必要人数ぶん、おすすめ上位を自動でチェックする。
  // すでに手で選んだ人は消さず、足りないぶんだけ足す（除外＝NG該当は対象外）。保存はしない。
  function autoPlace() {
    // おすすめ順（点数の高い順）・除外を外した候補リスト
    const rows = Array.from(document.querySelectorAll('tr.staff-row'))
      .filter(tr => !tr.classList.contains('blocked'))
      .sort((a, b) => parseFloat(b.dataset.score || '0') - parseFloat(a.dataset.score || '0'));
    // すでにチェック済みの人は「使用済み」として扱う（上書きしない）
    const used = new Set();
    rows.forEach(tr => {
      const cb = tr.querySelector('input[name="staff_ids[]"]');
      if (cb && cb.checked) used.add(tr);
    });

    const slots = Array.from(document.querySelectorAll('.pos-slot'));
    let placed = 0;

    if (slots.length) {
      // ポジション枠がある案件：役割ごとに、その役割ができるおすすめ上位で埋める
      slots.forEach(slot => {
        const role = slot.dataset.role;
        // 基本1案件につきDは1名＝Dだけは枠の必要数に関わらず上限1で自動配置する。
        const need = (role === 'D') ? Math.min(1, parseInt(slot.dataset.need || '0', 10)) : parseInt(slot.dataset.need || '0', 10);
        let cur = 0;
        used.forEach(tr => {
          const sel = tr.querySelector('select.role-sel:not(.role2-sel)');
          if (sel && sel.value === role) cur++;
        });
        for (const tr of rows) {
          if (cur >= need) break;
          if (used.has(tr)) continue;
          const canDo = (tr.dataset.pos || '').split('|').filter(Boolean);
          if (!canDo.includes(role)) continue;   // その役割ができない人は飛ばす
          const cb = tr.querySelector('input[name="staff_ids[]"]');
          const sel = tr.querySelector('select.role-sel:not(.role2-sel)');
          if (cb) cb.checked = true;
          if (sel) sel.value = role;
          used.add(tr);
          cur++;
          placed++;
        }
      });
    } else {
      // 枠が無い案件：必要人数ぶん、おすすめ上位から埋める（役割は付けない）
      const need = isNaN(NEED) ? 0 : NEED;
      for (const tr of rows) {
        if (used.size >= need) break;
        if (used.has(tr)) continue;
        const cb = tr.querySelector('input[name="staff_ids[]"]');
        if (cb) { cb.checked = true; used.add(tr); placed++; }
      }
    }

    updateCount();
    const note = document.getElementById('autoNote');
    if (note) {
      note.style.display = '';
      const msg = placed > 0
        ? ('おすすめ上位を自動で仮置きしました（' + placed + '名を追加）。内容を確認して、下の「保存」で確定してください。')
        : ((slots.length || !isNaN(NEED))
            ? '追加できる空き枠がありませんでした（必要人数はすでに埋まっています）。'
            : 'この案件は必要人数もポジション枠も未設定のため、自動で仮置きできませんでした。');
      const body = note.querySelector('div');
      if (body) body.textContent = msg;
    }
  }

  // 保存時：必要人数の不一致・NGペア同席があれば確認（警告であって保存は止めない）
  if (asgForm) {
    asgForm.addEventListener('submit', function (e) {
      const n = selectedCount();
      const warns = [];
      if (!isNaN(NEED) && n !== NEED) {
        warns.push('・必要 ' + NEED + '名 に対して選択は ' + n + '名です'
          + (n < NEED ? '（' + (NEED - n) + '名 不足）' : '（' + (n - NEED) + '名 超過）'));
      }
      const pairs = ngPairs();
      if (pairs.length) warns.push('・NGペアが同席します：' + pairs.join('、'));
      if (warns.length) {
        if (!confirm('次の点を確認してください。\n\n' + warns.join('\n') + '\n\nこのまま保存しますか？')) e.preventDefault();
      }
    });
  }
  updateCount();
  sortRows();
  filterStaff();
</script>
@endverbatim
@endpush
