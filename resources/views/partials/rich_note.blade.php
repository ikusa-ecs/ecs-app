{{--
  案件の備考の「赤」「太字」（2026-09-28 baba要望）。正本＝App\Support\RichNote。
  ・window.ecsRichNote(文字)  … 無害化してから印を色・太字に置き換えたHTMLを返す（JSで描く画面用）
  ・window.ecsRichWrap(ボタン, 種類) … 入力欄で選んだ文字を印で挟む（'b'＝太字／'red'＝赤／'clear'＝印を消す）
  ⚠ 置き換えの決まりは RichNote::html と同じにする。
--}}
@once
  @push('head')
    <style>
      .rn-red { color: #c62828; }
      html[data-theme="dark"] .rn-red { color: #ff8a80; }
      .rn-tools { display: inline-flex; gap: 4px; margin: 0 0 4px; }
      .rn-tools button {
        font-family: inherit; font-size: 11px; font-weight: 700; cursor: pointer; line-height: 1.4;
        padding: 1px 8px; border-radius: 6px; border: 1px solid var(--line); background: var(--panel); color: var(--ink);
      }
      .rn-tools button.red { color: #c62828; }
      html[data-theme="dark"] .rn-tools button.red { color: #ff8a80; }
    </style>
    @verbatim
      <script>
        (function () {
          function esc(s) {
            return String(s == null ? '' : s)
              .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
              .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
          }
          window.ecsRichNote = function (text) {
            return esc(text)
              .replace(/\[赤\]([\s\S]+?)\[\/赤\]/g, '<span class="rn-red">$1</span>')
              .replace(/\*\*([\s\S]+?)\*\*/g, '<b>$1</b>')
              .replace(/\r?\n/g, '<br>');
          };
          // ボタンのすぐ後ろ（同じ枠の中）にある入力欄の、選んでいる文字を印で挟む。
          // 何も選んでいないときは、印だけ入れてその間にカーソルを置く。
          window.ecsRichWrap = function (btn, kind) {
            var box = btn.closest('.rn-wrap');
            var ta = box && box.querySelector('textarea, input');
            if (!ta) return;
            var a = ta.selectionStart, b = ta.selectionEnd, v = ta.value, sel = v.slice(a, b);
            var out;
            if (kind === 'clear') {
              out = sel.replace(/\[\/?赤\]/g, '').replace(/\*\*/g, '');
              if (a === b) { alert('印を消したい文字を選んでから押してください。'); return; }
            } else {
              var open = kind === 'red' ? '[赤]' : '**', close = kind === 'red' ? '[/赤]' : '**';
              out = open + sel + close;
            }
            ta.value = v.slice(0, a) + out + v.slice(b);
            ta.focus();
            if (a === b && kind !== 'clear') {
              var p = a + (kind === 'red' ? 3 : 2);
              ta.setSelectionRange(p, p);
            } else {
              ta.setSelectionRange(a, a + out.length);
            }
          };
        })();
      </script>
    @endverbatim
  @endpush
@endonce
