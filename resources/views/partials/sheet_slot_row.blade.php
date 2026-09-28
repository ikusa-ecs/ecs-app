{{--
  アサイン表のブロックの「まだ人が決まっていない行」（2026-09-28 baba要望）。

  使い方：@include('partials.sheet_slot_row', ['c' => $c, 'no' => 3, 's' => $slot|null])
    ・$s が null ＝まだ何も書いていない空き行。書いた時点で枠（project_slots）ができる。
    ・$s があれば ＝すでに書いてある枠（ポジション／担当／巡回／備考／イベプラの印）。

  ⚠ 見るだけのときは、書いてある内容だけを出す（空き行は罫線だけ）。
    編集モード（カードに .editing）のときに入力欄が出る＝他の行と同じ考え方。
  ⚠ 名前の欄から入れられるのは**社員だけ**。スタッフは日別ボード・案件別アサインから
    （希望や上限を見ながら決める場所なので、ここでは入れない）。
  ⚠ 人を入れる保存先は assignments（/entries/assign）＝誰がアサインされているかの正本は1つ。
--}}
@php
  $slotId = $s['id'] ?? '';
  $sRole = $s['role'] ?? '';
  $sNote = $s['note'] ?? '';
  $sPatrol = $s['patrol'] ?? null;
  $sRemark = $s['remark'] ?? '';
  $sMark = $s['placeholder'] ?? '';
  $blank = ($sRole === '' && $sNote === '' && $sPatrol === null && $sRemark === '' && $sMark === '');
  // 運営人数が入っている案件で、その人数までの行に名前が無い＝まだ埋まっていない（2026-09-28 baba要望）。
  // 名前の欄を黄色くして気づけるようにする。⚠ 「6〜8」のように幅があるときは多いほう（need_i）まで。
  $needEmpty = ($c['need_i'] ?? 0) > 0 && $no <= ($c['need_i'] ?? 0);
@endphp
<div class="mrow mblk slot {{ $blank ? 'vacant' : '' }} {{ $needEmpty ? 'need-empty' : '' }}"
     data-project="{{ $c['id'] }}" data-slot="{{ $slotId }}">
  <span class="c-no">{{ $no }}</span>
  <span class="c-nm">
    @if ($sMark !== '')<span class="slot-mark">{{ $sMark }}</span>@endif
    {{-- 社員をここから入れる。選ぶと assignments に「仮」で入り、この行は人の行に変わる。 --}}
    <select class="m-edit m-addname" title="社員を入れます（仮で入ります）" onchange="ecsSheetAddMember(this)">
      <option value="">＋社員</option>
      {{-- 拠点ごとのまとまり（いま見ている拠点が上）。他拠点の社員も入れられる（新入社員の研修など・2026-09-28）。 --}}
      @foreach ($employeeGroups as $g)
        <optgroup label="{{ $g['office'] }}">
          @foreach ($g['people'] as $e)
            <option value="{{ $e['id'] }}">{{ $e['name'] }}</option>
          @endforeach
        </optgroup>
      @endforeach
    </select>
  </span>
  <span class="c-p">
    @if ($sRole !== '')<span class="pos-badge slot-pos">{{ $sRole }}</span>@endif
    <select class="m-edit m-role" title="ポジション（選ぶと保存）" onchange="ecsSheetSaveSlot(this,'role',this.value)">
      <option value="">—</option>
      @foreach ($roleOptions as $code => $label)
        <option value="{{ $code }}" {{ $sRole === $code ? 'selected' : '' }}>{{ $code }}</option>
      @endforeach
    </select>
  </span>
  <span class="c-jn">
    @if ($sNote !== '' || $sPatrol !== null)<span class="m-tag">{{ $sNote }}@if ($sPatrol !== null) {{ $sNote !== '' ? ' ' : '' }}巡{{ $sPatrol }}@endif</span>@endif
    <input class="m-edit m-note" list="sheetNoteOpts" placeholder="担当" value="{{ $sNote }}" title="担当メモ（軍師/サポ等・入力で保存）" onchange="ecsSheetSaveSlot(this,'note',this.value)">
    <input class="m-edit m-patrol" type="number" min="0" placeholder="巡" value="{{ $sPatrol ?? '' }}" title="巡回数（入力で保存）" onchange="ecsSheetSaveSlot(this,'patrol',this.value)">
  </span>
  <span class="c-rm">
    @if ($sRemark !== '')<span class="m-remark-tag">{{ $sRemark }}</span>@endif
    <input class="m-edit m-remark" type="text" placeholder="IKUSAマスト等" value="{{ $sRemark }}" title="この枠への備考（例：IKUSAマスト／派遣でOK）。入力すると保存されます" onchange="ecsSheetSaveSlot(this,'remark',this.value)">
  </span>
</div>
