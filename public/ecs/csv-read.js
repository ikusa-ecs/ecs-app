/*
 * CSV取込画面の「プレビュー」でファイルを読むときの共通処理（2026-08-18）。
 *
 * なぜ必要か：
 *   ECSが配るテンプレートはUTF-8だが、Excelで開いて上書き保存するとShift_JIS(CP932)になる。
 *   これまでプレビューは UTF-8 と決め打ちで読んでいたため、Shift_JISのCSVだと見出しが読めず、
 *   中身が入っているのに「コンテンツ名が空です」と表示されていた。
 *   （サーバー側の取込は App\Support\CsvText で同じことをしている。画面とサーバーで揃えるためのもの）
 *
 * 使い方：
 *   ECS_readCsvFile(file, function (text) { ... テキストを使う ... });
 */
(function () {
  /** 選ばれたCSVファイルを読み、文字コードを見分けて文字列にして渡す。 */
  window.ECS_readCsvFile = function (file, onDone) {
    if (!file) return;

    var reader = new FileReader();
    reader.onload = function (e) {
      var buffer = e.target.result;
      var text;

      try {
        // まずUTF-8として読む。fatal:true ＝ UTF-8として不正なら例外を出す。
        text = new TextDecoder('utf-8', { fatal: true }).decode(buffer);
      } catch (err) {
        try {
          // UTF-8として読めない＝ExcelがShift_JISで保存したCSV。
          text = new TextDecoder('shift_jis').decode(buffer);
        } catch (err2) {
          // どちらでもないときは、化けても止めずにUTF-8で読む（画面がエラーで固まらないように）。
          text = new TextDecoder('utf-8').decode(buffer);
        }
      }

      // 先頭のBOM（Excelが付ける見えない目印）が残っていたら落とす。
      if (text.charCodeAt(0) === 0xFEFF) {
        text = text.slice(1);
      }

      onDone(text);
    };

    reader.readAsArrayBuffer(file);
  };
})();
