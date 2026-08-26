<?php

namespace App\Support;

/**
 * 月ごとのアサイン表（1案件＝横1ブロック）のCSVを読む部品（2026-08-25 baba要望）。
 *
 * 【なぜ要るか】
 * 他拠点は「list」シート（1案件＝1行）を使っていない。月ごとのシート
 * （例：スプレッドシートの 202701 タブ）をそのまま取り込みたい。
 *
 * 【この形の見た目】
 *   ・1案件＝横に10列のブロック。1件目から右へ10列ずつ並ぶ（実物で97案件ぶん）。
 *   ・行が項目。ブロックの中に「項目名のセル」があり、その右に値が入る。
 *   ・下の方の行が「アサインされた人」。氏名の右に役割（D/MC/OP/FC…）が入る。
 *
 * 【位置を固定しない理由】
 * ⚠ 拠点によって項目の場所が少し違う（2026-08-25 baba）。
 *   そこで「何行目の何列目」を決め打ちにせず、**項目名そのものを探して**読む。
 *   ・ブロックの中を左から右へ見ていき、知っている項目名に当たったら、
 *     その右にある値をその項目の値として拾う（次の項目名に当たるまで）。
 *   ・「集合/解散/拘束時間」のように名前がスラッシュで区切られている項目は、
 *     区切った順に値を割り当てる（集合→9:00、解散→17:00、拘束→8:00）。
 *   これで、多少ずれても正しく読める。
 */
final class MonthlySheetReader
{
    /** 1案件ぶんの横幅（列数）。 */
    private const BLOCK_WIDTH = 10;

    /**
     * 項目名 → list形式の見出し名。
     * 「/」で区切られた項目名は、区切った順に値を入れる。
     * ⚠ 項目を増やすときはここに1行足す。画面やDBの列名は増やさなくてよい。
     *
     * @var array<string, list<string>>
     */
    private const LABELS = [
        '日程' => ['日程'],
        '宿泊' => ['宿泊'],
        'コンテンツ' => ['コンテンツ'],
        '案件規模' => ['案件規模'],
        '営業担当' => ['営業担当'],
        'オンラインツール' => ['オンラインツール'],
        '配信種別' => ['配信種別'],
        '顧客名(代理店名)' => ['顧客名(代理店名)'],
        '運営場所' => ['運営場所'],
        '複数開催' => ['複数開催'],
        '集合/解散/拘束時間' => ['集合', '解散', '拘束'],
        '入場/開始/終了' => ['入場', '開始', '終了'],
        '顧客(代理店)担当名' => ['顧客担当名'],
        '人数/チーム数' => ['人数', 'チーム数'],
        '運営人数/形式' => ['運営人数', '形式'],
        // ⚠ 「形式」は実施形態ではない（中身は「イベプラD＋メンバー」など＝運営のしかた。2026-08-26 baba確認）。
        //   実施形態は下の readFormat() で「種別」から読む。ここに書いても実施形態にはならない。
        '種別' => ['種別'],
        '運営方式/担当' => ['運営方式', '担当'],
        'LINE作成/LINE概要送付' => ['LINE作成', 'LINE概要送付'],
        '引継/ダブチェ' => ['引継', 'ダブチェ'],
        '運営シート' => ['運営シート'],
        'シート期日' => ['シート期日'],
        '台本' => ['台本'],
        '台本期日' => ['台本期日'],
        '音響' => ['音響'],
        'ロゴ' => ['ロゴ'],
        '記事' => ['記事'],
        '動画' => ['動画'],
        '会場住所(〒なし)' => ['会場場所'],
        '集合形式' => ['集合形式'],
        'お酒' => ['お酒'],
        '物品担当' => ['物品担当'],
        'ケータリング' => ['ケータリング'],
        '移動方法' => ['移動方法'],
        '会場種別' => ['会場種別'],
        '備考' => ['備考'],
        'その他・カスタム・開催場所等' => ['その他'],
    ];

    /** アサインの見出し（この行から下が「アサインされた人」）。 */
    private const ASSIGN_HEADER = 'NO';

    /** アサイン行の見出し（氏名の列・役割の列を探すのに使う）。 */
    private const ASSIGN_NAME_LABEL = '名前';

    /** 役割の列の見出し。実物では「P」（ポジション）。 */
    private const ASSIGN_ROLE_LABELS = ['P', 'ポジション', '役割'];

    /**
     * 実施形態（＝月シートの「種別」）として認める書き方。
     * 案件登録画面（`resources/views/project_form.blade.php` の実施形態）と同じ5つ。
     * ⚠ ここに無い書き方は**入れずに知らせる**（勘で実施形態を決めると集計が狂う）。
     */
    private const FORMATS = ['リアル', 'リアルロング', 'オンライン', 'ARENA場所貸し', '体験会'];

    /** 種別のセルを、日程の項目名から何行ぶん上まで探すか。 */
    private const FORMAT_ROWS_ABOVE = 3;

    /**
     * 日程の上にある「項目名の付いていない印」→ ECS側の項目名と値（2026-08-26 baba要望）。
     *
     * 実物の月シートでは、種別と一緒にこういう印が同じあたりに書かれている
     * （1つのセルに「追加案件, メンバー募集なし」と2つ入ることもある）。
     * ⚠ 見つけた印は、どれも**もう ECS に入れる場所がある**のでそこへ入れる。
     */
    private const MARKS = [
        '追加案件' => ['区分' => '追加案件'],
        'キャンセル' => ['キャンセル' => 'あり'],
        'Aヨミ' => ['確度' => 'Aヨミ'],
        'Bヨミ' => ['確度' => 'Bヨミ'],
        'Cヨミ' => ['確度' => 'Cヨミ'],
    ];

    /**
     * 読むけれど入れる場所が要らない印。
     * ・メンバー募集なし … 過去案件は必ず「募集しない」で入れる（スタッフ画面に募集中として
     *   出さないため）ので、書いてあってもなくても結果は同じ。
     * ⚠ ここに載せておかないと「取り込まなかった項目」に毎回出てしまい、本当に見落とした
     *   ものが埋もれる。
     */
    private const IGNORED_MARKS = ['メンバー募集なし', 'メンバー募集無し'];

    /**
     * 拠点をまたいだ関わり。カッコの中に入る（例「イベント他拠点東(巻き取り)」）。
     * ⚠ どの拠点から来たのかはシートに書かれていないので、拠点間共有（project_shares）は
     *   作らない（拠点を勘で決めると集計が狂う）。備考に書き残すだけにする。
     */
    private const CROSS_OFFICE = ['巻き取り', 'ヘルプ'];

    /**
     * この表が「月ごとのシート」かどうか。
     * 見分け方＝どこかの列に「日程」「コンテンツ」「顧客名」の項目名が縦に並んでいるか。
     * ⚠ list形式（1行目が見出しの行）と取り違えないよう、3つそろって初めて YES にする。
     */
    public static function looksLikeMonthlySheet(array $rows): bool
    {
        return self::findFirstBlockColumn($rows) !== null;
    }

    /**
     * 月ごとのシートを「1案件＝1件」の配列に変える。
     *
     * @return array{cases: list<array{fields: array<string,string>, people: list<array{name:string,role:string}>}>,
     *               blocks: int, unknownLabels: list<string>}
     */
    public static function read(array $rows): array
    {
        $start = self::findFirstBlockColumn($rows);
        if ($start === null) {
            return ['cases' => [], 'blocks' => 0, 'unknownLabels' => []];
        }

        $assignRow = self::findAssignHeaderRow($rows, $start);
        $width = self::widestRow($rows);

        $cases = [];
        $blocks = 0;
        $unknown = [];

        for ($col = $start; $col < $width; $col += self::BLOCK_WIDTH) {
            $blocks++;

            $fields = self::readFields($rows, $col, $assignRow, $unknown);

            // 実施形態（種別）や「追加案件・キャンセル」などは、項目名が付いていない
            // セルに入っているので別で読む。
            foreach (self::readAbove($rows, $col, $unknown) as $name => $value) {
                if (($fields[$name] ?? '') === '') {
                    $fields[$name] = $value;
                }
            }

            // コンテンツも日程も無いブロックは「まだ書かれていない枠」＝飛ばす。
            if (($fields['コンテンツ'] ?? '') === '' && ($fields['日程'] ?? '') === '') {
                continue;
            }

            $cases[] = [
                'fields' => $fields,
                'people' => $assignRow === null ? [] : self::readPeople($rows, $col, $assignRow),
            ];
        }

        return [
            'cases' => $cases,
            'blocks' => $blocks,
            'unknownLabels' => array_values(array_unique($unknown)),
        ];
    }

    /**
     * 1ブロックぶんの項目を読む。
     * ブロックの中を左から右・上から下へ見ていき、知っている項目名に当たったら
     * その右の値を拾う（次の項目名に当たるまで）。
     */
    private static function readFields(array $rows, int $col, ?int $assignRow, array &$unknown): array
    {
        $fields = [];
        $lastRow = $assignRow !== null ? $assignRow - 1 : count($rows) - 1;

        for ($r = 0; $r <= $lastRow; $r++) {
            $current = null;          // いま見ている項目名（分解済み）
            $values = [];

            for ($off = 0; $off < self::BLOCK_WIDTH; $off++) {
                $value = self::cell($rows, $r, $col + $off);
                if ($value === '') {
                    continue;
                }

                $key = self::matchLabel($value);
                if ($key !== null) {
                    // 前の項目をここで確定させてから、新しい項目に切り替える。
                    self::assign($fields, $current, $values);
                    $current = self::LABELS[$key];
                    $values = [];

                    continue;
                }

                if ($current !== null) {
                    $values[] = $value;
                }
            }

            self::assign($fields, $current, $values);
        }

        return $fields;
    }

    /** 拾った値を、項目名（分解済み）の順に入れる。 */
    private static function assign(array &$fields, ?array $names, array $values): void
    {
        if ($names === null || $values === []) {
            return;
        }
        foreach ($names as $i => $name) {
            if (isset($values[$i]) && $values[$i] !== '' && ! isset($fields[$name])) {
                $fields[$name] = $values[$i];
            }
        }
    }

    /**
     * 実施形態（月シートの「種別」）を読む。
     *
     * ⚠ 実物では、この値だけ**項目名が付いていない**（東京アサイン表では「日程」のすぐ上の行に
     *   「イベント東(リアルロング)」と入っている。2026-08-26 baba確認）。
     *   そのため他の項目と同じやり方（項目名を探す）では拾えず、ここだけ別で読む。
     *   ただし行を決め打ちにはせず、「日程」の項目名から数行ぶん上を探す。
     *
     * カッコの前（イベント東）は**捨てる**。2026-07-31 の全拠点対応で拠点は
     * `projects.office`（画面で選ぶ登録拠点）が正になり、実施形態は「リアル/オンライン」だけを
     * 持つ決まりになったため（カッコ付きの古い形を入れると、拠点を二重に持つことになる）。
     */
    private static function readAbove(array $rows, int $col, array &$unknown): array
    {
        $dateRow = null;
        for ($r = 0; $r < min(count($rows), 40); $r++) {
            if (self::matchLabel(self::cell($rows, $r, $col)) === '日程') {
                $dateRow = $r;
                break;
            }
        }
        if ($dateRow === null) {
            return [];
        }

        $found = [];
        for ($r = $dateRow - 1; $r >= 0 && $r >= $dateRow - self::FORMAT_ROWS_ABOVE; $r--) {
            $value = self::cell($rows, $r, $col);
            // 空・案件番号（1,2,3…）・他の項目名は印ではない。
            if ($value === '' || is_numeric($value) || self::matchLabel($value) !== null) {
                continue;
            }

            // 1つのセルに「追加案件, メンバー募集なし」と2つ入っていることがある。
            foreach (preg_split('/[,、，]+/u', $value) as $piece) {
                $piece = trim((string) $piece);
                if ($piece !== '') {
                    self::classifyMark($piece, $found, $unknown);
                }
            }
        }

        return $found;
    }

    /**
     * 「日程の上に書かれていた1つの印」を、ECS側の項目名と値に振り分ける。
     * どれにも当てはまらないものは**入れずに知らせる**（勘で入れると集計が静かに狂う）。
     */
    private static function classifyMark(string $piece, array &$found, array &$unknown): void
    {
        // ① 実施形態（種別）＝「イベント東(リアル)」など。
        $format = self::matchFormat($piece);
        if ($format !== null) {
            $found['種別'] ??= $format;

            return;
        }

        // ② 拠点をまたいだ関わり＝「イベント他拠点東(巻き取り)」など。
        $inner = self::insideParen($piece);
        foreach (self::CROSS_OFFICE as $kind) {
            if (self::norm($inner) === self::norm($kind)) {
                $found['拠点間の関わり'] ??= $kind;

                return;
            }
        }

        // ③ そのほかの印（追加案件・キャンセル・ヨミ）。
        foreach (self::MARKS as $mark => $set) {
            if (self::norm($piece) === self::norm($mark)) {
                foreach ($set as $name => $value) {
                    $found[$name] ??= $value;
                }

                return;
            }
        }

        // ④ 読むけれど入れる場所が要らない印。
        foreach (self::IGNORED_MARKS as $mark) {
            if (self::norm($piece) === self::norm($mark)) {
                return;
            }
        }

        $unknown[] = $piece;
    }

    /** 「イベント東(リアルロング)」→「リアルロング」。カッコが無ければそのまま。 */
    private static function insideParen(string $value): string
    {
        return preg_match('/[（(]\s*([^）)]+?)\s*[）)]/u', $value, $m) ? $m[1] : $value;
    }

    /** 「イベント東(リアルロング)」→「リアルロング」。決まった実施形態でなければ null。 */
    private static function matchFormat(string $value): ?string
    {
        $inner = self::insideParen($value);

        foreach (self::FORMATS as $format) {
            if (self::norm($inner) === self::norm($format)) {
                return $format;
            }
        }

        return null;
    }

    /** アサインされた人（氏名＋役割）を読む。 */
    private static function readPeople(array $rows, int $col, int $assignRow): array
    {
        // 見出しの行から「氏名の列」「役割の列」を探す（拠点で位置が違っても当たるように）。
        $nameOffset = null;
        $roleOffset = null;
        for ($off = 0; $off < self::BLOCK_WIDTH; $off++) {
            $v = self::norm(self::cell($rows, $assignRow, $col + $off));
            if ($v === self::norm(self::ASSIGN_NAME_LABEL)) {
                $nameOffset = $off;
            }
            foreach (self::ASSIGN_ROLE_LABELS as $label) {
                if ($v === self::norm($label)) {
                    $roleOffset = $off;
                }
            }
        }

        if ($nameOffset === null) {
            return [];
        }

        $people = [];
        for ($r = $assignRow + 1; $r < count($rows); $r++) {
            $name = self::cell($rows, $r, $col + $nameOffset);
            // 名前の欄が空の行が続いたら、そこから下はアサインではない（収支など）。
            if ($name === '') {
                // 連続して空でも、途中の空きを飛ばせるよう少しだけ先を見る。
                if (self::restIsEmpty($rows, $r, $col + $nameOffset, 3)) {
                    break;
                }

                continue;
            }
            $role = $roleOffset !== null ? self::cell($rows, $r, $col + $roleOffset) : '';
            $people[] = ['name' => $name, 'role' => $role];
        }

        return $people;
    }

    /** その位置から下が $n 行ぶん全部空か。 */
    private static function restIsEmpty(array $rows, int $from, int $col, int $n): bool
    {
        for ($i = 0; $i < $n; $i++) {
            if (self::cell($rows, $from + $i, $col) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * 1件目のブロックが始まる列を探す。
     * 「日程」「コンテンツ」「顧客名」の項目名が同じ列に縦に並んでいるところが先頭。
     */
    private static function findFirstBlockColumn(array $rows): ?int
    {
        $need = ['日程', 'コンテンツ', '顧客名'];
        $width = self::widestRow($rows);

        for ($col = 0; $col < $width; $col++) {
            $hit = [];
            for ($r = 0; $r < min(count($rows), 40); $r++) {
                $v = self::norm(self::cell($rows, $r, $col));
                foreach ($need as $n) {
                    if ($v !== '' && str_starts_with($v, self::norm($n))) {
                        $hit[$n] = true;
                    }
                }
            }
            if (count($hit) === count($need)) {
                return $col;
            }
        }

        return null;
    }

    /** 「NO」「名前」が並ぶ行＝アサインの見出し行を探す。 */
    private static function findAssignHeaderRow(array $rows, int $col): ?int
    {
        for ($r = 0; $r < count($rows); $r++) {
            $v = self::norm(self::cell($rows, $r, $col));
            if ($v === self::norm(self::ASSIGN_HEADER)) {
                return $r;
            }
        }

        return null;
    }

    /** そのセルが項目名かどうか（そうなら LABELS のキーを返す）。 */
    private static function matchLabel(string $value): ?string
    {
        $v = self::norm($value);
        foreach (self::LABELS as $key => $_) {
            if ($v === self::norm($key)) {
                return $key;
            }
        }

        return null;
    }

    /** 一番長い行の列数。 */
    private static function widestRow(array $rows): int
    {
        $max = 0;
        foreach ($rows as $r) {
            $max = max($max, count($r));
        }

        return $max;
    }

    /** 指定した位置の値（無ければ空文字）。 */
    private static function cell(array $rows, int $row, int $col): string
    {
        return isset($rows[$row][$col]) ? trim((string) $rows[$row][$col]) : '';
    }

    /** 項目名の言い方をそろえる（全角カッコ・空白・記号の違いを無視する）。 */
    private static function norm(string $s): string
    {
        $s = str_replace(
            ['（', '）', '／', ' ', '　', '　', '・'],
            ['(', ')', '/', '', '', '', '・'],
            $s
        );

        return mb_strtolower(trim($s));
    }

    /**
     * ファイル名から「その月シートが何年何月ぶんか」を読み取る。
     *
     * なぜ要るか＝月シートの日程は「9月1日(火)」のように**年が書かれていない**。
     * スプレッドシートからCSVを落とすとファイル名にシート名が入る
     * （例：【雛形】東京アサイン表 - 202701.csv）ので、そこから年を補う（2026-08-25 baba）。
     *
     * 受け付ける形：202701 / 2027-01 / 2027_01 / 2027年1月
     *
     * @return array{year:int, month:int}|null 読めなければ null
     */
    public static function periodFromFilename(string $filename): ?array
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);

        // 2027年1月 / 2027-01 / 2027_01
        if (preg_match('/(20\d{2})\s*[年\-_\/\.]\s*(\d{1,2})/u', $name, $m)) {
            $y = (int) $m[1];
            $mo = (int) $m[2];
            if ($mo >= 1 && $mo <= 12) {
                return ['year' => $y, 'month' => $mo];
            }
        }

        // 202701 のような6桁。年月として読めるものだけ採用する。
        if (preg_match_all('/(20\d{2})(0[1-9]|1[0-2])/u', $name, $all, PREG_SET_ORDER)) {
            $last = end($all);   // 「東京アサイン表 - 202701」のように後ろにある方を使う

            return ['year' => (int) $last[1], 'month' => (int) $last[2]];
        }

        return null;
    }

    /**
     * 「9月1日(火)」のように年が無い日付に、シートの年を補って Y-m-d にする。
     *
     * ⚠ 月がシートと違っていても、年はシートのものを使う（baba確定）。
     *   例：202701 のシートに「9月1日」→ 2027-09-01。
     *   ただし12月のシートに1月の日付がある場合だけは翌年とみなす（年末年始の案件のため）。
     */
    public static function completeDate(string $value, ?array $period): string
    {
        $v = trim($value);
        if ($v === '' || $period === null) {
            return $v;
        }

        // すでに年が入っていれば、そのまま（ProjectImportColumns が読める形）。
        if (preg_match('/20\d{2}/u', $v)) {
            return $v;
        }

        if (! preg_match('/(\d{1,2})\s*[月\/\-\.]\s*(\d{1,2})/u', $v, $m)) {
            return $v;   // 月日として読めない＝そのまま返して、呼ぶ側でエラーにしてもらう
        }

        $mo = (int) $m[1];
        $d = (int) $m[2];
        $y = $period['year'];

        // 12月のシートに1月の日付＝年末年始をまたぐ案件とみなして翌年にする。
        if ($period['month'] === 12 && $mo === 1) {
            $y++;
        }

        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }
}
