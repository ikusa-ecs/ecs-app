<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * 「月別出勤可能日」シートを読む（2026-08-31 baba要望）。
 *
 * 元データ＝スプレッドシート「【東京社員】月別出勤可能日/新人社員管理シート」。
 * 社員が月ごとに、土日祝・長期休暇・大型案件の日について〇×を書いている表。
 *
 * 【表の形（2つある。どちらも読める）】
 *   ①「9/6」の形（月が日付のマスに入っている）
 *   |    | 項目     | 9/6 | 9/7 | 9/13 | … | 平日希望休日 | 参加したいイベント…その他備考 |
 *   |    | 中村淳司 | 〇  | 〇  | ✗    | … |              |                               |
 *
 *   ②「9月」＋「5(土)」の形（**実物の東京シートはこちら**・2026-08-31 に対応）
 *   |    | 9月      | 5(土) | 6(日) | 10(木) | … | 平日希望休日 | …その他備考 |
 *   |    | 小田 紅  | 〇    | ✕     | アイシン配信 | … | 9/29,30 | 大型ないので… |
 *
 * ・見出しの行＝「項目」と書いてあるマスがある行。**氏名の列＝その「項目」の列**
 *   （左端に空の列があるので、位置を決め打ちにしない）。
 *   ⚠ ②のシートには「項目」が無く、そこに「9月」と書いてある。そのため
 *     **日付の列が3つ以上ある行**も見出しとみなし、氏名は日付の1つ左とする。
 * ・日付の列＝見出しが「9/6」または「5(土)」の形のもの。
 *   ⚠ 年が書いていないので、取り込む人が画面で年月を選ぶ。勘で決めない。
 *   ⚠ 「5(土)」は月も書いていない。**カッコの中の曜日が、選んだ年月の曜日と合うかを必ず確かめる。**
 *     合わなければ読まずにエラーにする＝別の月のシートを取り違えたのに気づけるようにするため。
 *
 * 【マスの読み方（2026-08-31 baba決定）】
 * ・〇 ○ ◯ ◎ → 出勤可 ／ × ✕ ✗ ✖ → 不可 ／ △ ▲ → 条件つき ／ 空欄 → 未入力（飛ばす）
 * ・**記号でない文字（「三菱商事様」「合宿」など予定名）→ 条件つき（△）にして、
 *   その文字はその日のメモに残す。** 勝手に×にしない＝「なぜ×なのか」が分からなくなるため。
 *   「〇→✖」「13:00～18:00が〇」のような書き足しも同じ扱い（人が見て直せる形で残す）。
 */
class AvailabilitySheetReader
{
    /** 出勤可を表す記号。 */
    private const MARK_OK = ['〇', '○', '◯', '◎', 'O', 'o', '@'];

    /** 不可を表す記号。 */
    private const MARK_NG = ['×', '✕', '✗', '✖', '☓', 'x', 'X'];

    /** 条件つきを表す記号。 */
    private const MARK_MAYBE = ['△', '▲'];

    /**
     * 表のいちばん下にある凡例の行（「出勤可能 〇」「出勤不可 ✕」「応相談 △」）。
     * ⚠ 人として読むと「名簿に見つかりません」が3件並び、**本当の見落としが埋もれる**。
     */
    private const LEGEND_NAMES = ['項目', '凡例', '記号', '出勤可能', '出勤不可', '応相談', '要相談', '未定'];

    /** 曜日の文字 → Carbon の dayOfWeek（0=日）。「5(土)」の確かめに使う。 */
    private const WEEKDAYS = ['日' => 0, '月' => 1, '火' => 2, '水' => 3, '木' => 4, '金' => 5, '土' => 6];

    /** 記号 → shift_preferences.availability に入れる文字。 */
    public const TO_DB = [
        'ok' => '稼働可',
        'ng' => 'NG',
        'maybe' => '未定',
        'off' => '希望休',
    ];

    /**
     * 表を読んで、人ごと・日ごとの結果にする。
     *
     * @param  array<int, array<int, string>>  $rows  CSV／貼り付けを行×列にしたもの
     * @param  string  $period  取り込む年月（'2026-09'）
     * @return array{
     *   dates: list<string>,
     *   people: list<array{name:string, ids:list<string>, days:array<string,array{code:string, memo:string}>, offDays:list<string>, note:string}>,
     *   errors: list<string>
     * }
     */
    public static function read(array $rows, string $period): array
    {
        $base = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfDay();

        [$headerIndex, $nameCol, $dateCols, $offCol, $noteCol, $errors] = self::findHeader($rows, $base);

        if ($headerIndex === null) {
            // ⚠ ここで $errors を捨てない。「シートには9月と書いてあります」のような
            //   **なぜ読めなかったかの手がかり**が入っている（捨てると原因が分からなくなる）。
            return ['dates' => [], 'people' => [], 'errors' => array_merge($errors, [
                '見出しの行が見つかりませんでした。日付の列（「9/6」または「5(土)」の形）が3つ以上ある行が要ります。',
            ])];
        }

        $index = PersonLookup::index();
        $people = [];

        foreach (array_slice($rows, $headerIndex + 1) as $row) {
            $name = trim((string) ($row[$nameCol] ?? ''));
            // ⚠ 表のいちばん下の凡例（「出勤可能 〇」など）を人として読まない
            //   ＝「名簿に見つかりません」に並んで、本当の見落としが埋もれるため。
            if ($name === '' || in_array($name, self::LEGEND_NAMES, true)) {
                continue;
            }

            // ⚠ 同じ日付の列が2つ以上あることがある（実物の東京シートは 9/5 が2列あった）。
            //   片方が空／両方同じなら問題ないが、**中身が食い違うときはどちらが正か決められない**。
            //   勝手に選ぶと出勤できない日を「〇」で入れてしまうので、その日だけ取り込まずに知らせる。
            $byDate = [];
            foreach ($dateCols as $col => $date) {
                $cell = trim((string) ($row[$col] ?? ''));
                if ($cell === '') {
                    continue;   // 未入力＝触らない
                }
                $byDate[$date][] = $cell;
            }

            $days = [];
            foreach ($byDate as $date => $cells) {
                $uniq = array_values(array_unique($cells));
                if (count($uniq) > 1) {
                    $errors[] = sprintf(
                        '%s さんの %s は、同じ日の列が%d つあって中身が違います（%s）。'
                            .'どちらが正しいか決められないので、この日は取り込みませんでした。手で入れてください。',
                        $name,
                        Carbon::parse($date)->format('n月j日'),
                        count($cells),
                        implode(' / ', $uniq)
                    );

                    continue;
                }
                $days[$date] = self::readCell($uniq[0]);
            }

            // 平日希望休の欄。日付として読めたものだけ「希望休」にする。
            // ⚠ 読めない文字（「4日が大型なので前後厳しいです…」）は勝手に日付にせず備考へ回す。
            $offRaw = trim((string) ($row[$offCol] ?? ''));
            [$offDays, $offLeftover] = self::readOffDays($offRaw, $base);

            $note = trim((string) ($row[$noteCol] ?? ''));
            if ($offLeftover !== '') {
                $note = trim($note === '' ? $offLeftover : $offLeftover.' / '.$note);
            }

            // 何も書いていない行（名前だけ）は取り込まない＝空で上書きしてしまわないように。
            if ($days === [] && $offDays === [] && $note === '') {
                continue;
            }

            $key = PersonLookup::normName($name);

            $people[] = [
                'name' => PersonLookup::displayName($name),
                'ids' => array_column($index[$key] ?? [], 'id'),
                'days' => $days,
                'offDays' => $offDays,
                'note' => $note,
            ];
        }

        return ['dates' => array_values($dateCols), 'people' => $people, 'errors' => $errors];
    }

    /**
     * 見出しの行を探して、氏名／日付／平日希望休／備考の列の位置を決める。
     *
     * @return array{0: ?int, 1: int, 2: array<int,string>, 3: ?int, 4: ?int, 5: list<string>}
     */
    private static function findHeader(array $rows, Carbon $base): array
    {
        $errors = [];

        foreach ($rows as $i => $row) {
            $nameCol = null;
            foreach ($row as $c => $cell) {
                if (trim((string) $cell) === '項目') {
                    $nameCol = $c;
                    break;
                }
            }

            // その行に「9月」と書いてあれば、日だけの見出し（「5(土)」）はその月として読む。
            // ⚠ 選んだ年月と食い違うときは、シートの取り違えを疑って**その行では読まない**。
            $sheetMonth = self::sheetMonth($row);
            if ($sheetMonth !== null && ! in_array($sheetMonth, [$base->month, $base->copy()->addMonth()->month], true)) {
                $errors[] = "シートには「{$sheetMonth}月」と書いてありますが、選んだ年月は "
                    .$base->format('Y年n月').' です。年月を選び直してください。';

                continue;
            }

            $dateCols = [];
            $offCol = null;
            $noteCol = null;
            foreach ($row as $c => $cell) {
                $text = trim((string) $cell);
                if ($text === '') {
                    continue;
                }
                if ($date = self::readHeaderDate($text, $base, $errors, $sheetMonth)) {
                    $dateCols[$c] = $date;
                } elseif (str_contains($text, '平日') && str_contains($text, '希望休')) {
                    $offCol = $c;
                } elseif (str_contains($text, '備考')) {
                    $noteCol = $c;
                }
            }

            // 「項目」が無くても、日付の列が3つ以上あればその行を見出しとみなす
            // （シートによって左端の書き方が違うため）。氏名は日付の1つ左。
            if ($dateCols !== [] && count($dateCols) >= 3) {
                if ($nameCol === null) {
                    $nameCol = max(0, min(array_keys($dateCols)) - 1);
                }

                return [$i, $nameCol, $dateCols, $offCol, $noteCol, $errors];
            }
        }

        return [null, 0, [], null, null, $errors];
    }

    /**
     * その行に「9月」と書いてあるマスがあれば、その月（1〜12）。無ければ null。
     * 実物の東京シートは、氏名の列の見出しに「9月」と入っている。
     */
    private static function sheetMonth(array $row): ?int
    {
        foreach ($row as $cell) {
            if (preg_match('~^\s*(\d{1,2})\s*月\s*$~u', (string) $cell, $m)) {
                $month = (int) $m[1];
                if ($month >= 1 && $month <= 12) {
                    return $month;
                }
            }
        }

        return null;
    }

    /**
     * 見出しのマスを YYYY-MM-DD にする。日付でなければ null。
     *
     * 読める形は2つ：
     *  ・「9/6」「9/6(土)」… 月がマスに入っている
     *  ・「5(土)」        … 日と曜日だけ。月は $sheetMonth（無ければ選んだ年月）を使う
     *
     * ⚠ 受け付けるのは **その月と、その次の月** だけ。
     *   12月のシートに「1/1」が入ることがあるため次の月まで許すが、
     *   それ以外の月はシートの取り違えを疑って読まない（勘で年を決めない）。
     * ⚠ カッコに曜日が書いてあれば**必ず突き合わせる**。合わなければ読まない
     *   ＝ 去年のシート・別の月のシートを入れたことに、その場で気づけるようにする。
     */
    private static function readHeaderDate(string $text, Carbon $base, array &$errors, ?int $sheetMonth = null): ?string
    {
        if (preg_match('~^(\d{1,2})\s*/\s*(\d{1,2})~u', $text, $m)) {
            $month = (int) $m[1];
            $day = (int) $m[2];
        } elseif (preg_match('~^(\d{1,2})\s*[(（]\s*(.)~u', $text, $m)) {
            // 「5(土)」＝日と曜日だけ。月はシートの「9月」か、選んだ年月から補う。
            $month = $sheetMonth ?? $base->month;
            $day = (int) $m[1];
        } else {
            return null;
        }

        $next = $base->copy()->addMonth();
        if ($month === $base->month) {
            $year = $base->year;
        } elseif ($month === $next->month) {
            $year = $next->year;
        } else {
            $errors[] = "見出しの「{$text}」は、選んだ年月（{$base->format('Y年n月')}）と合わないので読みませんでした。";

            return null;
        }

        if (! checkdate($month, $day, $year)) {
            $errors[] = "見出しの「{$text}」は存在しない日付なので読みませんでした。";

            return null;
        }

        // カッコの中に曜日が書いてあれば突き合わせる。
        // ⚠ ここが合わない＝**別の年・別の月のシートを入れている**。勝手に読むと去年の日付に入る。
        if (preg_match('~[(（]\s*([日月火水木金土])~u', $text, $w)) {
            $expected = Carbon::create($year, $month, $day)->dayOfWeek;
            if (self::WEEKDAYS[$w[1]] !== $expected) {
                $actual = array_search($expected, self::WEEKDAYS, true);
                $errors[] = "見出しの「{$text}」は、{$year}年{$month}月{$day}日だと{$actual}曜日です。"
                    .'別の月のシートではありませんか（年月が合っていないので読みませんでした）。';

                return null;
            }
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * マス1つを読む。
     *
     * @return array{code:string, memo:string}
     */
    private static function readCell(string $cell): array
    {
        // セル結合の印（スプレッドシートから読んだときに付くことがある）は外す。
        $text = trim(str_replace(['[merged]', '\\[merged\\]'], '', $cell));

        if (in_array($text, self::MARK_OK, true)) {
            return ['code' => 'ok', 'memo' => ''];
        }
        if (in_array($text, self::MARK_NG, true)) {
            return ['code' => 'ng', 'memo' => ''];
        }
        if (in_array($text, self::MARK_MAYBE, true)) {
            return ['code' => 'maybe', 'memo' => ''];
        }

        // 記号だけではないもの（予定名・時間の書き足しなど）＝条件つきにして、中身をメモに残す。
        return ['code' => 'maybe', 'memo' => $text];
    }

    /**
     * 「平日希望休日」の欄を日付にする。
     *
     * 読めるのは「9/29,30」「9/22」「9/1,9,16,17,」「29,30」「１０，１１」のような書き方だけ。
     * 「9/1休み 9/24-26は…」「水曜日」のような文はそのまま返して、備考へ回してもらう。
     *
     * ⚠ 月を書いていない数字（「29,30」）は**選んだ年月の日**として読む（2026-08-31 baba決定）。
     *   月別のシートなので月に迷いようが無く、これまでは全部が備考に流れて希望休が1件も入らなかった。
     *   ただし**数字とカンマだけで出来ている欄に限る**＝文章から数字を拾ったりはしない。
     *
     * @return array{0: list<string>, 1: string}  [読めた日付, 読めなかった文字]
     */
    private static function readOffDays(string $raw, Carbon $base): array
    {
        // 全角の数字・読点・スラッシュを半角にそろえてから見る（「１０，１１」がそのまま入っていた）。
        $text = trim(str_replace(['，', '／', '　'], [',', '/', ' '], mb_convert_kana($raw, 'n')));
        if ($text === '') {
            return [[], ''];
        }

        // 全体が「数字・スラッシュ・カンマだけ」でなければ、勝手に日付を拾わない。
        if (! preg_match('~^\d{1,2}(\s*/\s*\d{1,2})?(\s*[,、]\s*\d{1,2}(\s*/\s*\d{1,2})?)*\s*[,、]?$~u', $text)) {
            return [[], trim($raw)];
        }

        $days = [];
        $month = null;
        foreach (preg_split('~[,、]~u', $text) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('~^(\d{1,2})\s*/\s*(\d{1,2})$~u', $part, $m)) {
                $month = (int) $m[1];
                $day = (int) $m[2];
            } elseif (preg_match('~^(\d{1,2})$~u', $part, $m)) {
                // 「9/29,30」の「30」＝直前と同じ月。月がまだ出ていなければ選んだ年月の月。
                $month ??= $base->month;
                $day = (int) $m[1];
            } else {
                return [[], trim($raw)];
            }

            $next = $base->copy()->addMonth();
            $year = $month === $base->month ? $base->year : ($month === $next->month ? $next->year : null);
            if ($year === null || ! checkdate($month, $day, $year)) {
                return [[], trim($raw)];   // 選んだ年月と合わない＝勝手に決めない（元の文字のまま備考へ）
            }
            $days[] = sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        return [array_values(array_unique($days)), ''];
    }
}
