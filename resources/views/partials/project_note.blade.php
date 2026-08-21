{{--
  案件の備考（projects.note）を「見る＋その場で直す」小さな帯。

  なぜ共通部品にしたか
  ・同じ備考が 日別ボード／案件別アサイン／ピックアップ／案件一覧 と、いくつもの画面に出る。
    見た目と保存のしかたを1か所にまとめておけば、直すときもここだけで済む（2026-08-21 baba要望）。
  ・保存先は POST /projects/cells（案件一覧のセル保存と同じ入口）。
    → 拠点チェック（他拠点の案件は書けない）と編集履歴（誰がいつ何に変えたか）が自動で効く。

  使い方（画面側）
  ・JSでカードを組む画面： ecsNoteHtml(案件ID, 備考, 直せるか) が返すHTMLを差し込む。
  ・Bladeで組む画面    ： <div class="pnote-slot" data-id="…" data-note="…"></div> を置くだけ（自動で中身が入る）。
  ・保存できたあと画面のデータも直したいときは、画面側で window.ecsNoteApplied = function(id, 備考){…} を定義する
    （これをやらないと、再描画で古い備考に戻って見える）。
--}}
@once
  @push('head')
    <style>
      /* 備考の帯。見落とすと事故るので、うすい黄色で目立たせる（未記入のときはグレーで控えめ）。 */
      .pnote {
        display: flex; align-items: flex-start; gap: 8px; flex-wrap: wrap;
        margin: 8px 0; padding: 7px 10px; border-radius: 8px; line-height: 1.6;
        background: #fdf6e3; border: 1px solid #f0dfae; color: #8a6d1a;
        font-size: 12px; font-weight: 600;
      }
      .pnote.is-empty { background: #f7f5f2; border-color: #e7e2db; color: #8d857b; }
      .pnote .pn-lbl  { white-space: nowrap; }
      .pnote .pn-body { flex: 1 1 200px; min-width: 120px; overflow-wrap: anywhere; }
      .pnote .pn-body.empty { font-weight: 500; color: #a09a91; }
      .pnote .pn-btn {
        flex: 0 0 auto; font-family: inherit; font-size: 11px; font-weight: 700; cursor: pointer;
        padding: 2px 8px; border-radius: 6px; border: 1px solid #e0d3b0; background: #fff; color: #8a6d1a;
      }
      .pnote .pn-btn:hover { background: #fffaf0; }
      .pnote .pn-btn.save { border-color: #cfa04a; background: #cfa04a; color: #fff; }
      .pnote .pn-input {
        flex: 1 1 260px; min-width: 180px; font-family: inherit; font-size: 12px; line-height: 1.6;
        padding: 5px 7px; border-radius: 6px; border: 1px solid #e0d3b0; color: #4a4238; resize: vertical;
      }
      .pnote .pn-msg    { flex: 0 0 auto; font-size: 11px; font-weight: 700; color: #b45309; }
      .pnote .pn-msg.ok { color: #15803d; }
    </style>
    <script>window.ECS_NOTE_CSRF = '{{ csrf_token() }}';</script>
    @verbatim
      <script>
        // ===== 案件の備考（projects.note）＝表示とその場編集 =====
        (function () {
          function esc(s) {
            return String(s == null ? '' : s)
              .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
          }

          // 帯の中身（ラベル＋本文＋「直す」ボタン）。
          function inner(txt, canEdit) {
            var body = txt !== ''
              ? '<span class="pn-body">' + esc(txt).replace(/\r?\n/g, '<br>') + '</span>'
              : '<span class="pn-body empty">未記入</span>';
            var btn = canEdit ? '<button type="button" class="pn-btn" onclick="ecsNoteEdit(this)">✎ 直す</button>' : '';
            return '<span class="pn-lbl">📌 備考</span>' + body + btn;
          }

          // 帯まるごとのHTML。canEdit が false（見本データ）で備考も空なら、何も出さない。
          window.ecsNoteHtml = function (id, note, canEdit) {
            var txt = String(note == null ? '' : note).trim();
            if (!canEdit && txt === '') return '';
            return '<div class="pnote' + (txt === '' ? ' is-empty' : '') + '"'
                 + ' data-id="' + esc(id) + '" data-note="' + esc(txt) + '">'
                 + inner(txt, !!canEdit) + '</div>';
          };

          // 「直す」＝入力欄に差し替える。
          window.ecsNoteEdit = function (el) {
            var box = el.closest('.pnote');
            var cur = box.getAttribute('data-note') || '';
            box.innerHTML = '<span class="pn-lbl">📌 備考</span>'
              + '<textarea class="pn-input" rows="2" placeholder="社員だけが見るメモです（スタッフの画面には出ません）"></textarea>'
              + '<button type="button" class="pn-btn save" onclick="ecsNoteSave(this)">保存</button>'
              + '<button type="button" class="pn-btn" onclick="ecsNoteCancel(this)">やめる</button>'
              + '<span class="pn-msg"></span>';
            var ta = box.querySelector('.pn-input');
            ta.value = cur;
            ta.focus();
          };

          window.ecsNoteCancel = function (el) {
            var box = el.closest('.pnote');
            var txt = box.getAttribute('data-note') || '';
            box.className = 'pnote' + (txt === '' ? ' is-empty' : '');
            box.innerHTML = inner(txt, true);
          };

          // 保存＝案件一覧のセル保存と同じ入口へ送る（拠点チェックと編集履歴が自動で効く）。
          window.ecsNoteSave = function (el) {
            var box = el.closest('.pnote');
            var id  = box.getAttribute('data-id');
            var val = box.querySelector('.pn-input').value.replace(/\s+$/, '');
            var msg = box.querySelector('.pn-msg');
            msg.textContent = '保存中…';
            fetch('/projects/cells', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': window.ECS_NOTE_CSRF,
                'Accept': 'application/json'
              },
              body: JSON.stringify({ id: id, note: val })
            }).then(function (r) {
              if (!r.ok) throw new Error(r.status === 403 ? '他の拠点の案件は直せません' : ('通信エラー ' + r.status));
              return r.json();
            }).then(function () {
              box.setAttribute('data-note', val);
              box.className = 'pnote' + (val === '' ? ' is-empty' : '');
              box.innerHTML = inner(val, true) + '<span class="pn-msg ok">✓ 保存しました</span>';
              // 画面側が持っているデータにも反映する（再描画で古い備考に戻らないように）。
              if (typeof window.ecsNoteApplied === 'function') window.ecsNoteApplied(id, val);
            }).catch(function (e) {
              msg.textContent = '⚠ ' + (e && e.message ? e.message : '保存できませんでした') + '（もう一度お試しください）';
            });
          };

          // Bladeで組む画面用：<div class="pnote-slot" data-id data-note> を帯に置き換える。
          function mountSlots() {
            var list = document.querySelectorAll('.pnote-slot');
            for (var i = 0; i < list.length; i++) {
              var s = list[i];
              s.outerHTML = window.ecsNoteHtml(s.getAttribute('data-id'), s.getAttribute('data-note') || '', true);
            }
          }
          if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mountSlots);
          else mountSlots();
        })();
      </script>
    @endverbatim
  @endpush
@endonce
