<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * 「月別出勤可能日」シートを読む（2026-08-31 baba要望）。
 *
 * 元データ＝スプレッドシート「【東京社員】月別出勤可能日/新人社員管理シート」。
 * 社員が月ごとに、土日祝・長期休暇・大型案件の日について〇×を書いている表。
 *
 * 【表の形】
 *   |    | 項目     | 9/6 | 9/7 | 9/13 | … | 平日希望休日 | 参加したいイベント…その他備考 |
 *   |    | 中村淳司 | 〇  | 〇  | ✗    | … |              |                               |
 *   |    | 小田 紅  | 〇  | 〇  | アイシン配信 | … | 9/29,30 | 大型ないので小型大量に入ります |
 *
 * ・見出しの行＝「項目」と書いてあるマスがある行。**氏名の列＝その「項目」の列**
 *   （左端に空の列があるので、位置を決め打ちにしない）。
 * ・日付の列＝見出しが「9/6」のような M/D の形のもの。
 *   ⚠ 年が書いていないので、取り込む人が画面で年月を選ぶ。勘で決めない。
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
            return ['dates' => [], 'people' => [], 'errors' => [
                '見出しの行が見つかりませんでした。「項目」と書いてある行と、「9/6」のような日付の列がある表を入れてください。',
            ]];
        }

        $index = PersonLookup::index();
        $people = [];

        foreach (array_slice($rows, $headerIndex + 1) as $row) {
            $name = trim((string) ($row[$nameCol] ?? ''));
            if ($name === '' || $name === '項目') {
                continue;
            }

            $days = [];
            foreach ($dateCols as $col => $date) {
                $cell = trim((string) ($row[$col] ?? ''));
                if ($cell === '') {
                    continue;   // 未入力＝触らない
                }
                $days[$date] = self::readCell($cell);
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

            $dateCols = [];
            $offCol = null;
            $noteCol = null;
            foreach ($row as $c => $cell) {
                $text = trim((string) $cell);
                if ($text === '') {
                    continue;
                }
                if ($date = self::readHeaderDate($text, $base, $errors)) {
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
     * 見出しの「9/6」を YYYY-MM-DD にする。日付でなければ null。
     *
     * ⚠ 受け付けるのは **その月と、その次の月** だけ。
     *   12月のシートに「1/1」が入ることがあるため次の月まで許すが、
     *   それ以外の月はシートの取り違えを疑って読まない（勘で年を決めない）。
     */
    private static function readHeaderDate(string $text, Carbon $base, array &$errors): ?string
    {
        if (! preg_match('~^(\d{1,2})\s*/\s*(\d{1,2})~u', $text, $m)) {
            return null;
        }
        $month = (int) $m[1];
        $day = (int) $m[2];

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
     * 読めるのは「9/29,30」「9/22」「9/1,9,16,17,」のような書き方だけ。
     * 「9/1休み 9/24-26は…」のような文はそのまま返して、備考へ回してもらう。
     *
     * @return array{0: list<string>, 1: string}  [読めた日付, 読めなかった文字]
     */
    private static function readOffDays(string $raw, Carbon $base): array
    {
        $text = trim($raw);
        if ($text === '') {
            return [[], ''];
        }

        // 全体が「日付とカンマだけ」でなければ、勝手に日付を拾わない。
        if (! preg_match('~^\d{1,2}\s*/\s*\d{1,2}(\s*[,、]\s*\d{1,2}(\s*/\s*\d{1,2})?)*\s*[,、]?$~u', $text)) {
            return [[], $text];
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
            } elseif ($month !== null && preg_match('~^(\d{1,2})$~u', $part, $m)) {
                // 「9/29,30」の「30」＝直前と同じ月
                $day = (int) $m[1];
            } else {
                return [[], $text];
            }

            $next = $base->copy()->addMonth();
            $year = $month === $base->month ? $base->year : ($month === $next->month ? $next->year : null);
            if ($year === null || ! checkdate($month, $day, $year)) {
                return [[], $text];   // 選んだ年月と合わない＝勝手に決めない
            }
            $days[] = sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        return [array_values(array_unique($days)), ''];
    }
}
