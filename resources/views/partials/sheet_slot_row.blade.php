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
  // 必要ポジションの自動の空き枠（2026-11-11・AssignSlots::autoSlots）。保存しない枠なので、名前だけ入れられる
  // （名前を入れると、この役割で入る）。役割・担当・備考を書きたいときは、ふつうの空き行を使う。
  $auto = !empty($s['auto']);
  if ($auto) { $blank = false; }
@endphp
<div class="mrow mblk slot {{ $auto ? 'auto-slot' : '' }} {{ $blank ? 'vacant' : '' }} {{ $needEmpty ? 'need-empty' : '' }}"
     data-project="{{ $c['id'] }}" data-slot="{{ $slotId }}" @if ($auto) data-role="{{ $sRole }}" title="このコンテンツ・規模で必要な{{ $sRole }}の空き（マスタの必要人数から自動）。名前を入れると{{ $sRole }}で入ります" @endif>
  <span class="c-no">{{ $no }}</span>
  <span class="c-nm">
    @if ($sMark !== '')<span class="slot-mark">{{ $sMark }}</span>@endif
    @if ($auto)<span class="auto-mark">空き</span>@endif
    {{-- 社員をここから入れる。選ぶと assignments に「仮」で入り、この行は人の行に変わる。 --}}
    {{-- 名前を少し打つと候補が出る（2026-11-11 小沼さん）。候補は画面に1つの #sheetEmpList（全社員を空き行ごとに書かない＝軽さはそのまま）。 --}}
    <input class="m-edit m-addname" type="text" list="sheetEmpList" placeholder="＋社員（名前を打つ）" autocomplete="off" title="社員を入れます（仮で入ります）。名前を少し打つと候補が出ます" onchange="ecsSheetAddMember(this)">
  </span>
  <span class="c-p">
    @if ($sRole !== '')<span class="pos-badge slot-pos">{{ $sRole }}</span>@endif
    @unless ($auto)
    <select class="m-edit m-role" title="ポジション（選ぶと保存）" onchange="ecsSheetSaveSlot(this,'role',this.value)">
      <option value="">—</option>
      @foreach ($roleOptions as $code => $label)
        <option value="{{ $code }}" {{ $sRole === $code ? 'selected' : '' }}>{{ $code }}</option>
      @endforeach
    </select>
    @endunless
  </span>
  <span class="c-jn">
    @if ($sNote !== '' || $sPatrol !== null)<span class="m-tag">{{ $sNote }}@if ($sPatrol !== null) {{ $sNote !== '' ? ' ' : '' }}巡{{ $sPatrol }}@endif</span>@endif
    @unless ($auto)<input class="m-edit m-note" list="sheetNoteOpts" placeholder="担当" value="{{ $sNote }}" title="担当メモ（軍師/サポ等・入力で保存）" onchange="ecsSheetSaveSlot(this,'note',this.value)">
    <input class="m-edit m-patrol" type="number" min="0" placeholder="巡" value="{{ $sPatrol ?? '' }}" title="巡回数（入力で保存）" onchange="ecsSheetSaveSlot(this,'patrol',this.value)">@endunless
  </span>
  <span class="c-rm">
    @if ($sRemark !== '')<span class="m-remark-tag">{{ $sRemark }}</span>@endif
    @unless ($auto)<input class="m-edit m-remark" type="text" placeholder="IKUSAマスト等" value="{{ $sRemark }}" title="この枠への備考（例：IKUSAマスト／派遣でOK）。入力すると保存されます" onchange="ecsSheetSaveSlot(this,'remark',this.value)">@endunless
  </span>
</div>
