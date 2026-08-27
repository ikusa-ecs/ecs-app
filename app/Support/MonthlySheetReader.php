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

    /**
     * 「日程」の項目名が2つのセルに割れている書き方（2026-08-27 名古屋のシートで判明）。
     *
     * 名古屋：`日 | 1/2(火) | 程 | 7/1(水)`   ← 「日」と「程」が別のセル・あいだに別の値
     * 東京　：`日程 |  |  | 9月1日(火)`
     *
     * ⚠ これを見落とすと**案件がまるごと入らない**。ECSは「日程・コンテンツ・顧客名」が
     *   揃う列を1件目の始まりにするので、割れていると1件目の位置を取り違え、
     *   その左にある案件が全部無視される（実際に名古屋の7月シートで12件が消えた）。
     *
     * 「程」までのあいだにある値は**捨てる**（実物では日程と関係ない日付が入っている）。
     */
    private const DATE_LABEL_HEAD = '日';

    private const DATE_LABEL_TAIL = '程';

    /** アサインの見出し（この行から下が「アサインされた人」）。 */
    private const ASSIGN_HEADER = 'NO';

    /** アサイン行の見出し（氏名の列・役割の列を探すのに使う）。 */
    private const ASSIGN_NAME_LABEL = '名前';

    /** 役割の列の見出し。実物では「P」（ポジション）。 */
    private const ASSIGN_ROLE_LABELS = ['P', 'ポジション', '役割'];

    /**
     * 氏名の欄に書かれる「まだ決まっていない枠」の書き方（2026-08-27 baba）。
     *
     * 名古屋のシートは、スタッフで埋める予定の枠に**「メンバー」と書いて場所を取ってある**。
     * ⚠ これを人として取り込むと、名簿に居ない「メンバーさん」を探しに行って
     *   「見つかりません」が並び、しかも実在しない人がアサインされたことになる。
     *   人としては入れず、**運営人数を数えるときの「空き枠」として数える**。
     */
    private const SLOT_NAMES = ['メンバー', 'メンバー募集', 'スタッフ', '未定'];

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
        // 社内メンバーだけで回す案件＝スタッフの募集をしない。
        // ⚠ 「過去の実績として」取り込むときは、どの案件も募集しないので結果は変わらない。
        //   効くのは「これからの予定として」取り込むとき（募集ONで入るのを止める）。
        'メンバー募集なし' => ['スタッフ募集' => '募集しない'],
        'メンバー募集無し' => ['スタッフ募集' => '募集しない'],
        // 名古屋のシートでの書き方（2026-08-27）。意味は「メンバー募集なし」と同じ。
        '募集不要' => ['スタッフ募集' => '募集しない'],
        '募集なし' => ['スタッフ募集' => '募集しない'],
        // 営業案件＝イベント数に数えないもの（体験会・EXPO と同じ扱い。2026-08-27 baba）。
        // ⚠ 実施形態（リアル／オンライン等）ではないので、実施形態は空のままにする。
        '営業案件' => ['イベント数' => '数えない'],
    ];

    /**
     * 拠点をまたいだ関わり。カッコの中に入る（例「イベント他拠点東(巻き取り)」）。
     * ⚠ どの拠点から来たのかはシートに書かれていないので、拠点間共有（project_shares）は
     *   作らない（拠点を勘で決めると集計が狂う）。備考に書き残すだけにする。
     */
    private const CROSS_OFFICE = ['巻き取り', 'ヘルプ', 'ヘルプのみ'];

    /**
     * 見つけても「取り込まなかった項目」に出さない印。
     *
     * ⚠ ここに足してよいのは「見て、入れなくてよいと決めたもの」だけ。
     *   知らない印を黙って捨てると、**本当の見落としに気づけなくなる**。
     *
     * 名古屋のシートは日程の上に「アサイン状況」の行がある（2026-08-27）。
     * アサインの進み具合はECS側で持っている（仮／確定・取込のときのモードで決まる）ので入れない。
     */
    private const IGNORED_MARKS = [
        // 原本ブロックに項目名そのものが残っているもの
        'アサイン状況', '確度',
        // アサイン状況の値
        'LINE登録済', 'LINE未登録', '確定', '未確定', '出勤依頼中', '打診中',
    ];

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

        // 横に並ぶ欄（集合/解散/拘束・入場/開始/終了 など）が「どの位置に入るか」を、
        // この表そのものから先に学習しておく＝途中が空の案件で値がずれないようにするため。
        $layout = self::learnLayout($rows, $start, $assignRow, $width);

        $cases = [];
        $blocks = 0;
        $unknown = [];

        for ($col = $start; $col < $width; $col += self::BLOCK_WIDTH) {
            $blocks++;

            $fields = self::readFields($rows, $col, $assignRow, $unknown, $layout);

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

            $read = $assignRow === null
                ? ['people' => [], 'slots' => 0]
                : self::readPeople($rows, $col, $assignRow);

            $cases[] = [
                'fields' => $fields,
                'people' => $read['people'],
                // 「メンバー」と書いてあるだけの空き枠の数。運営人数が空のときに使う。
                'slots' => $read['slots'],
            ];
        }

        return [
            'cases' => $cases,
            'blocks' => $blocks,
            'unknownLabels' => array_values(array_unique($unknown)),
        ];
    }

    /**
     * 1行を左から右へ見て、「項目名 → その右にあった値（どの位置にあったかも一緒に）」に分ける。
     *
     * 読み取りの本体はここ1か所。項目を読むときも、値の位置を学習するときも同じものを使う
     * ＝2つ書くと片方だけ直して食い違う（この取込で何度も踏んでいる事故）。
     *
     * @return list<array{key:string, values:list<array{off:int, value:string}>}>
     */
    private static function scanRow(array $rows, int $r, int $col): array
    {
        $out = [];
        $key = null;
        $values = [];

        $labelOff = 0;

        $flush = function () use (&$out, &$key, &$values, &$labelOff) {
            if ($key !== null && $values !== []) {
                // 項目名がブロックの何列目にあったかも持つ（位置の学習に使う）。
                $out[] = ['key' => $key, 'labelOff' => $labelOff, 'values' => $values];
            }
            $values = [];
        };

        for ($off = 0; $off < self::BLOCK_WIDTH; $off++) {
            $value = self::cell($rows, $r, $col + $off);
            if ($value === '') {
                continue;
            }

            // 「日」＋（曜日を出すための値）＋「程」に割れている書き方。
            // 「程」まで飛ばして、その右を日程として読む（あいだの値は捨てる）。
            $tail = self::splitDateLabelTail($rows, $r, $col, $off);
            if ($tail !== null) {
                $flush();
                $key = '日程';
                $labelOff = $off;
                $off = $tail;   // for の増分でこの次（＝「程」の右）から値を読む

                continue;
            }

            $found = self::matchLabel($value);
            if ($found !== null) {
                $flush();
                $key = $found;
                $labelOff = $off;

                continue;
            }

            if ($key !== null) {
                $values[] = ['off' => $off, 'value' => $value];
            }
        }

        $flush();

        return $out;
    }

    /**
     * 1ブロックぶんの項目を読む。
     *
     * @param  array<string, list<int>>  $layout 項目名 => 値が入る位置（learnLayout で作る）
     */
    private static function readFields(array $rows, int $col, ?int $assignRow, array &$unknown, array $layout = []): array
    {
        $fields = [];
        $lastRow = $assignRow !== null ? $assignRow - 1 : count($rows) - 1;

        for ($r = 0; $r <= $lastRow; $r++) {
            foreach (self::scanRow($rows, $r, $col) as $seg) {
                $names = self::LABELS[$seg['key']];
                self::assign($fields, $names, $seg['values'],
                    self::layoutFor($layout, $seg['key'], $seg['labelOff'], count($names)));
            }
        }

        return $fields;
    }

    /**
     * 「集合/解散/拘束時間」のように欄が横に並ぶ項目について、
     * **値がどの位置に入るか**を、この表そのものから学習する。
     *
     * 【なぜ要るか】
     * ⚠ 途中の欄が空だと、拾った順に入れる作りでは**値が1つずつずれる**。
     *   名古屋の7月シートに「入場は空・開始14:00・終了16:30」の案件があり、
     *   これが「入場14:00・開始16:30」として入っていた＝**本番の時間が違って入る**（2026-08-27）。
     *
     * 【どう学習するか】
     * 同じ表の中には、3つとも埋まっている案件がだいたいある。そこでの位置（例：3・5・7列目）を
     * 覚えておき、欠けている案件はその位置に合わせて入れる。
     * ⚠ 埋まっている案件が1つも無ければ学習しない＝これまでどおり順番に入れる（前と同じ結果）。
     *   位置を勘で決めるより「前と同じ」で止めるほうが安全。
     *
     * @return array<string, list<int>> 項目名 => 値が入る位置
     */
    private static function learnLayout(array $rows, int $start, ?int $assignRow, int $width): array
    {
        $byKey = [];
        $lastRow = $assignRow !== null ? $assignRow - 1 : count($rows) - 1;

        for ($col = $start; $col < $width; $col += self::BLOCK_WIDTH) {
            for ($r = 0; $r <= $lastRow; $r++) {
                foreach (self::scanRow($rows, $r, $col) as $seg) {
                    $names = self::LABELS[$seg['key']];
                    if (count($names) < 2 || isset($byKey[$seg['key']])) {
                        continue;
                    }
                    // 欄の数ぴったりに埋まっているブロックだけを見本にする。
                    if (count($seg['values']) !== count($names)) {
                        continue;
                    }
                    $byKey[$seg['key']] = [
                        'labelOff' => $seg['labelOff'],
                        'offsets' => array_map(fn ($v) => $v['off'], $seg['values']),
                    ];
                }
            }
        }

        // 【位置の貸し借り】その項目が1つも埋まっていない表もある（名古屋の「入場/開始/終了」）。
        // そこで「同じ数だけ欄が並ぶ項目」の位置を借りる。
        // ⚠ 借りてよいのは**行の先頭にある項目どうし**だけ。行の途中から始まる項目
        //   （例「引継 / ダブチェ」）は位置が違うので混ぜない。
        // ⚠ さらに、借り元どうしで位置が食い違うときは借りない（勘で決めない）。
        $byCount = [];
        foreach ($byKey as $info) {
            if ($info['labelOff'] !== 0) {
                continue;
            }
            $n = count($info['offsets']);
            if (! array_key_exists($n, $byCount)) {
                $byCount[$n] = $info['offsets'];

                continue;
            }
            if ($byCount[$n] !== $info['offsets']) {
                $byCount[$n] = null;   // 食い違い＝使わない
            }
        }

        return ['byKey' => $byKey, 'byCount' => $byCount];
    }

    /**
     * その項目の「値が入る位置」。自分の分が学習できていなければ、
     * 同じ数だけ欄が並ぶ項目の位置を借りる（行の先頭にある項目どうしだけ）。
     *
     * @return list<int>|null
     */
    private static function layoutFor(array $layout, string $key, int $labelOff, int $nameCount): ?array
    {
        $own = $layout['byKey'][$key] ?? null;
        if ($own !== null) {
            return $own['offsets'];
        }
        if ($labelOff !== 0) {
            return null;
        }

        return $layout['byCount'][$nameCount] ?? null;
    }

    /**
     * 拾った値を、項目名（分解済み）に入れる。
     *
     * @param  list<array{off:int, value:string}>  $values
     * @param  list<int>|null  $layout 値が入る位置（learnLayout で学習したもの）
     */
    private static function assign(array &$fields, ?array $names, array $values, ?array $layout = null): void
    {
        if ($names === null || $values === []) {
            return;
        }

        $slots = self::slotsFor($names, $values, $layout);

        foreach ($names as $i => $name) {
            if (isset($slots[$i]) && $slots[$i] !== '' && ! isset($fields[$name])) {
                $fields[$name] = $slots[$i];
            }
        }
    }

    /**
     * 拾った値を「何番目の欄のものか」に振り分ける。
     *
     * ・欄の数ぴったりに拾えていれば、そのまま順番に入れる（今までどおり）。
     * ・足りないときだけ、学習した位置（learnLayout）に近いほうへ入れる＝ずれを防ぐ。
     * ・学習できていない・当てはまらないときは、今までどおり順番に入れる。
     *
     * @param  list<string>  $names
     * @param  list<array{off:int, value:string}>  $values
     * @param  list<int>|null  $layout
     * @return array<int, string>
     */
    private static function slotsFor(array $names, array $values, ?array $layout): array
    {
        $plain = array_map(fn ($v) => $v['value'], $values);

        if (count($values) >= count($names) || $layout === null || count($layout) !== count($names)) {
            return $plain;
        }

        $out = [];
        foreach ($values as $v) {
            // 学習した位置のうち、いちばん近いものの番号に入れる。
            $best = null;
            $bestDist = null;
            foreach ($layout as $i => $off) {
                $dist = abs($v['off'] - $off);
                if ($bestDist === null || $dist < $bestDist) {
                    $best = $i;
                    $bestDist = $dist;
                }
            }
            // 同じ番号に2つ来たら位置での割り当てはあきらめる（順番に戻す）。
            if ($best === null || isset($out[$best])) {
                return $plain;
            }
            $out[$best] = $v['value'];
        }

        return $out;
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
            // 「日程」1セルでも「日」＋「程」に割れていても、日程の行として扱う。
            if (self::hasDateLabel($rows, $r, $col)) {
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
        // ⓪ 見て「入れなくてよい」と決めた印は、知らせずに飛ばす。
        foreach (self::IGNORED_MARKS as $ignored) {
            if (self::norm($piece) === self::norm($ignored)) {
                return;
            }
        }

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

        $unknown[] = $piece;
    }

    /**
     * その位置から「日」＋（別の値）＋「程」の並びが始まっていれば、「程」の位置（ブロック内の何列目か）を返す。
     * 始まっていなければ null。
     */
    private static function splitDateLabelTail(array $rows, int $row, int $col, int $off): ?int
    {
        if (self::norm(self::cell($rows, $row, $col + $off)) !== self::norm(self::DATE_LABEL_HEAD)) {
            return null;
        }

        for ($i = $off + 1; $i < self::BLOCK_WIDTH; $i++) {
            $v = self::cell($rows, $row, $col + $i);
            if ($v === '') {
                continue;
            }
            if (self::norm($v) === self::norm(self::DATE_LABEL_TAIL)) {
                return $i;
            }
            // 「程」より先に別の項目名が来たら、これは日程の割れではない。
            if (self::matchLabel($v) !== null) {
                return null;
            }
        }

        return null;
    }

    /** その位置に「日程」の項目名があるか（1セルでも「日」＋「程」に割れていても true）。 */
    private static function hasDateLabel(array $rows, int $row, int $col): bool
    {
        $v = self::norm(self::cell($rows, $row, $col));
        if ($v !== '' && str_starts_with($v, self::norm('日程'))) {
            return true;
        }

        return self::splitDateLabelTail($rows, $row, $col, 0) !== null;
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

    /**
     * アサインされた人（氏名＋役割）と、まだ決まっていない枠の数を読む。
     *
     * @return array{people: list<array{name:string, role:string}>, slots:int}
     */
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
            return ['people' => [], 'slots' => 0];
        }

        $people = [];
        $slots = 0;
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
            // 「メンバー」＝スタッフで埋める予定の空き枠。人ではないので数だけ数える。
            if (self::isSlotName($name)) {
                $slots++;

                continue;
            }
            $role = $roleOffset !== null ? self::cell($rows, $r, $col + $roleOffset) : '';
            $people[] = ['name' => $name, 'role' => $role];
        }

        return ['people' => $people, 'slots' => $slots];
    }

    /** 氏名の欄が「まだ決まっていない枠」か。 */
    private static function isSlotName(string $name): bool
    {
        foreach (self::SLOT_NAMES as $slot) {
            if (self::norm($name) === self::norm($slot)) {
                return true;
            }
        }

        return false;
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
                // 「日」＋「程」に割れている書き方（名古屋）も「日程あり」として数える。
                if (self::hasDateLabel($rows, $r, $col)) {
                    $hit['日程'] = true;
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
