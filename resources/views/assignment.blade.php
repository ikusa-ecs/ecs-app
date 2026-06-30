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
    table.tbl td.chk { width: 36px; text-align: center; }
    table.tbl input[type=checkbox] { width: 17px; height: 17px; accent-color: var(--brand); cursor: pointer; }
    .ng-note { color: var(--danger); font-size: 11.5px; }

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
        <div class="spacer"></div>
        <input type="text" id="staffSearch" placeholder="名前でしぼり込み" oninput="filterStaff()">
      </div>

      {{-- NGペア（相性が悪い2人）が両方チェックされたら警告（同席を止めはしない＝警告のみ） --}}
      <div class="alert danger" id="ngWarn" style="display:none; margin-bottom:12px;">
        <span class="ico">⚠</span>
        <div>NGペアが同席しています：<span class="ng-list"></span><br>
          <span class="muted" style="font-size:11.5px;">相性の悪い組み合わせです。どちらかを外すか、問題ないか確認してください（保存は止めません）。</span>
        </div>
      </div>

      <div class="panel" style="padding-top:6px;">
        <table class="tbl">
          <thead>
            <tr>
              <th class="chk">☑</th>
              <th>スタッフ</th>
              <th>区分</th>
              <th>できる役割</th>
              <th class="num">経験</th>
              <th>この案件での役割</th>
              <th>NG</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($staff as $s)
              @php($ex = $existing[$s['id']] ?? null)
              <tr class="staff-row" data-name="{{ $s['name'] }}" data-ng="{{ implode('|', $s['ng']) }}">
                <td class="chk">
                  <input type="checkbox" name="staff_ids[]" value="{{ $s['id'] }}" {{ $ex ? 'checked' : '' }} onchange="updateCount()">
                </td>
                <td>
                  <strong>{{ $s['name'] }}</strong>
                  <span class="muted" style="font-size:11.5px;">{{ $s['id'] }}</span>
                  @if ($s['exclusive'])<span class="badge ok" style="font-size:10px;">専属</span>@endif
                  @if ($ex && $ex['status'] === '確定')<span class="badge ok" style="font-size:10px;">確定済</span>@endif
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
                </td>
                <td>
                  @if (count($s['ng']))<span class="ng-note">NG: {{ implode('、', $s['ng']) }}</span>@endif
                </td>
              </tr>
            @empty
              <tr><td colspan="7" class="muted" style="text-align:center; padding:20px;">スタッフが登録されていません。</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>

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
    ngPairs();   // NGペアの警告も更新
  }
  function filterStaff() {
    const q = document.getElementById('staffSearch').value.trim();
    document.querySelectorAll('.staff-row').forEach(tr => {
      tr.style.display = (!q || (tr.dataset.name || '').includes(q)) ? '' : 'none';
    });
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
</script>
@endverbatim
@endpush
