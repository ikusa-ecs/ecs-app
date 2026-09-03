@once
  <script>
    // 入社年月日の「勤続◯年◯か月」を出す（partials/hire_date_selects と対）。
    //
    // ⚠ なぜ要るか：2026-09-03、注意書きを書いてもなお**生年月日を入れる方が出た**。
    //   文章での注意には限りがあるので、選んだ内容を「勤続◯年◯か月」に言い換えて見せる。
    //   生年月日を選ぶと「勤続21年」のように出て、ひと目でおかしいと分かる＝いちばん効く歯止め。
    //
    // ⚠ 入れられる画面が5つあるので、この見せ方は**ここ1か所**にまとめる。
    //   JavaScript が動かなくても、案内が出なくなるだけで入力そのものは今までどおりできる。
    (function () {
      var SUSPICIOUS_YEARS = 15;   // これ以上は「生年月日を選んでいませんか？」と聞く

      function paint(box) {
        if (!box) return;
        var y = box.querySelector('[name="hire_y"]');
        var m = box.querySelector('[name="hire_m"]');
        var d = box.querySelector('[name="hire_d"]');
        var out = box.querySelector('.hire-date-echo');
        if (!y || !m || !out) return;

        function say(text, bad) {
          out.textContent = text;
          out.style.color = bad ? '#a12121' : '#7a6f63';
          out.style.fontWeight = bad ? '700' : 'normal';
        }

        if (!y.value) { say('', false); return; }
        if (!m.value) { say('「月」も選んでください。', true); return; }

        var now = new Date();
        var months = (now.getFullYear() - Number(y.value)) * 12 + (now.getMonth() + 1 - Number(m.value));
        if (months < 0) months = 0;
        var yy = Math.floor(months / 12);
        var mm = months % 12;
        var day = (d && d.value) ? d.value : '1';
        var when = y.value + '年' + m.value + '月' + day + '日';
        var span = yy + '年' + mm + 'か月';

        if (yy >= SUSPICIOUS_YEARS) {
          say('⚠ ' + when + '＝勤続' + span + 'になります。生年月日を選んでいませんか？', true);
        } else {
          say('選んだ日：' + when + '（勤続' + span + '）', false);
        }
      }

      // 名簿は詳細を開いたときに欄を作る＝あとから出てくる欄にも効くよう、まとめて受ける。
      document.addEventListener('change', function (e) {
        var t = e.target;
        if (!t || !t.name || t.name.indexOf('hire_') !== 0) return;
        paint(t.closest ? t.closest('.hire-date-field') : null);
      });

      function refresh() {
        Array.prototype.forEach.call(document.querySelectorAll('.hire-date-field'), paint);
      }

      window.ecsHireDateRefresh = refresh;   // あとから欄を作った画面が呼ぶ
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refresh);
      } else {
        refresh();
      }
    })();
  </script>
@endonce
