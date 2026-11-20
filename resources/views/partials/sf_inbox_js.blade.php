{{-- SFの商談の画面（マイページ・受信箱）で使う小さな動き（2026-11-20 小沼さん）。1つの画面に1回だけ include する。
     1. 「▼ ぜんぶ見る」：残りの項目を開け閉めする（partials/sf_opp_more）。
     2. 「案件として登録」を押すと、その場で「登録しますか？［はい］［やめる］」に変わる。
        ブラウザの確認の窓（画面の上に出る）は、どの商談の話か分かりにくいので使わない。
        ⚠ このJSが動かなくても、ボタンは普通に送信できる（確認なしで登録になるだけ・画面が固まらない）。
     3. 登録・見送りを押したあと、画面がいちばん上に戻らないように、押す直前の縦の位置を覚えて戻す
        （マスタ画面 2026-11-18 と同じやり方・15分より古い位置は使わない＝あとで開いたときに勝手に飛ばないように）。
        覚えるのは data-sfx-keep-scroll の付いたフォームを送ったときだけ（ほかのボタンでは飛ばさない）。
     $scrollKey＝位置を覚える名前（画面ごとに別にする）。 --}}
<style>
  .sfx-toggle-cell { padding: 2px 8px !important; background: transparent; }
  .sfx-toggle { border: 0; background: none; color: var(--brand, #2563eb); font-size: 12px; cursor: pointer; padding: 2px 0; }
  .sfx-dim { color: var(--muted); font-size: 11.5px; margin-left: 4px; }
  .sfx-ask { display: inline-flex; gap: 6px; align-items: center; font-weight: 700; }
  /* ボタン（.btn）は display を持っているので、hidden を確実に効かせる */
  .sfx-confirm [hidden], .sfx-ask[hidden] { display: none !important; }
</style>
<script>
(function () {
  var KEY = @json($scrollKey ?? 'ecs_sf_scroll');
  function remember() {
    try { sessionStorage.setItem(KEY, JSON.stringify({ y: window.scrollY || window.pageYOffset || 0, t: Date.now() })); } catch (e) {}
  }
  function restore() {
    var saved = null;
    try { saved = JSON.parse(sessionStorage.getItem(KEY) || 'null'); sessionStorage.removeItem(KEY); } catch (e) {}
    if (!saved || typeof saved.y !== 'number' || Date.now() - saved.t > 15 * 60 * 1000) return;
    // 一覧をJSで描く画面（マイページ）は、描き終わってから高さが決まるので、読み込み後にも何回か戻す
    var go = function () { window.scrollTo(0, saved.y); };
    go();
    window.addEventListener('load', function () { go(); setTimeout(go, 50); setTimeout(go, 300); });
  }
  function closest(el, sel) {
    return el && el.closest ? el.closest(sel) : null;
  }
  try {
    document.addEventListener('submit', function (ev) {
      if (closest(ev.target, 'form[data-sfx-keep-scroll]')) remember();
    }, true);

    document.addEventListener('click', function (ev) {
      var t = ev.target;
      // 1. ぜんぶ見る／閉じる
      var tg = closest(t, '[data-sfx-toggle]');
      if (tg) {
        var table = closest(tg, 'table');
        var more = table ? table.querySelector('.sfx-more') : null;
        if (more) {
          more.hidden = !more.hidden;
          tg.textContent = more.hidden ? '▼ ぜんぶ見る' : '▲ 閉じる';
          tg.setAttribute('aria-expanded', more.hidden ? 'false' : 'true');
        }
        return;
      }
      // 2. 登録の確認をその場で
      var ask = closest(t, '[data-sfx-ask]');
      if (ask) {
        ev.preventDefault();
        var form = closest(ask, 'form');
        // 拠点が未選択なら、ここでブラウザの「選んでください」を出して止める
        if (form && form.reportValidity && !form.reportValidity()) return;
        var box = ask.parentNode.querySelector('.sfx-ask');
        if (!box) { if (form) { remember(); form.submit(); } return; }
        ask.hidden = true;
        box.hidden = false;
        var yes = box.querySelector('[data-sfx-yes]');
        if (yes) yes.focus();
        return;
      }
      var no = closest(t, '[data-sfx-no]');
      if (no) {
        var wrap = closest(no, '.sfx-confirm');
        if (wrap) {
          var b = wrap.querySelector('.sfx-ask');
          var a = wrap.querySelector('[data-sfx-ask]');
          if (b) b.hidden = true;
          if (a) { a.hidden = false; a.focus(); }
        }
      }
    });
    restore();
  } catch (e) {}
})();
</script>
