<?php

namespace App\Support;

use App\Models\Content;
use App\Models\ContentRoleRequirement;
use Illuminate\Support\Facades\DB;

/**
 * 「必要アサイン人数リスト」CSV の読み取り・反映の**正本**（2026-09-07）。
 *
 * もともとこの解析は artisan コマンド（ecs:import-role-requirements）の中にだけあり、
 * **画面から取り込めなかった**（＝リストが更新されるたびにエンジニア作業が必要だった）。
 * そこで解析と反映をここへ移し、コマンドと取込画面（/role-requirement-import）の
 * **両方がこの1か所を呼ぶ**形にした。書き写しを作らないこと（片方だけ直す事故を防ぐ）。
 *
 * 【CSVの構造】コンテンツ1ブロックにつき、参加人数の3段階（～49名／50～99名／100～150名）が
 *   横並び。各段の列： NO / 名前 / P(ポジション) / 巡回 / 備考 / サイズ。
 *   ・規模の対応： ～49名=小型／50～99名=中型／100～150名=大型
 *   ・「軍＝チーム」＝規模で決まるものとして、チャンバラ系の 2軍/3軍/4軍 は
 *     規模の対角線で取る（2軍→小型／3軍→中型／4軍→大型）。
 *   ・備考の 軍師/サポ→SP・チェッカー→CK・受付→RP はアプリの既存ポジションに置換。
 *     それ以外（ディーラー/ミッション/採点/巡回/役名 等）と巡回数は note / patrol にそのまま保持。
 */
class RoleRequirementCsv
{
    /** 3段（規模）の列位置： 規模ラベルの列と [P, 巡回, 備考]。 */
    private const BRACKETS = [
        ['label' => 1,  'p' => 5,  'patrol' => 6,  'biko' => 7],
        ['label' => 11, 'p' => 15, 'patrol' => 16, 'biko' => 17],
        ['label' => 21, 'p' => 25, 'patrol' => 26, 'biko' => 27],
    ];

    /** 規模の並び順（画面・DB投入ともこの順で扱う）。 */
    public const SCALES = ['小型', '中型', '大型'];

    /**
     * CSVの本文 → 行（各行は列の配列）。
     *
     * ⚠ ここは CsvText::rows を使わない。理由が2つある。
     *   ① escape="" が肝（既定のバックスラッシュ escape だと、コンテンツ名の複数行セルを
     *      誤って結合／分割してブロックが丸ごと欠落する）。
     *   ② **空行を捨ててはいけない**。このCSVは「空行＝コンテンツのブロックの終わり」なので、
     *      空行を飛ばすと次のコンテンツの枠が前のコンテンツにくっついてしまう。
     *   文字コードそろえ（ExcelのShift_JIS対策）だけは CsvText に任せる。
     *
     * @return list<list<string>>
     */
    public static function rows(string $raw): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, CsvText::toUtf8($raw));
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = array_map(fn ($v) => (string) ($v ?? ''), $row);
        }
        fclose($handle);

        return $rows;
    }

    /**
     * 行 → 「商品名 => 規模 => 枠の一覧」。
     *
     * @param  list<list<string>>  $rows
     * @param  callable|null  $debug  商品名を1つも読めなかったブロックの知らせ先（コマンドの --debug 用）
     * @return array<string, array<string, list<array{pos: string, note: string, patrol: ?int, rawP: string}>>>
     */
    public static function parse(array $rows, ?callable $debug = null): array
    {
        $n = count($rows);
        $out = [];

        $cell = fn (array $row, int $c) => trim((string) ($row[$c] ?? ''));
        $rowEmpty = function (array $row) {
            foreach ($row as $v) {
                if (trim((string) $v) !== '') {
                    return false;
                }
            }

            return true;
        };

        $i = 0;
        while ($i < $n) {
            $row = $rows[$i];
            $name0 = $cell($row, 0);
            $hasLabel = mb_strpos($cell($row, 1), '参加人数') !== false
                || mb_strpos($cell($row, 11), '参加人数') !== false
                || mb_strpos($cell($row, 21), '参加人数') !== false;
            $nextIsNo = ($i + 1 < $n) && $cell($rows[$i + 1], 1) === 'NO';
            $isHeader = $name0 !== '' && ($hasLabel || $nextIsNo);

            if (! $isHeader) {
                $i++;
                continue;
            }

            $products = self::parseProducts($name0);
            $gun = self::parseGun($name0);
            if ($debug && empty($products)) {
                $debug($i, $name0);
            }

            // 各段の規模（ラベルから）。ラベルが無いブロックは「規模非依存」＝全規模に適用。
            $scaleByBracket = [];
            $anyLabel = false;
            foreach (self::BRACKETS as $bi => $b) {
                $s = self::scaleOf($cell($row, $b['label']));
                $scaleByBracket[$bi] = $s;
                if ($s !== null) {
                    $anyLabel = true;
                }
            }

            // NOヘッダー行を探し、その次からデータ。
            $j = $i + 1;
            while ($j < $n && $cell($rows[$j], 1) !== 'NO') {
                $j++;
            }
            if ($j >= $n) {
                break;
            }
            $k = $j + 1;

            // このブロックのデータを段ごとに集める。
            $blockSlots = [0 => [], 1 => [], 2 => []];
            while ($k < $n) {
                $r = $rows[$k];
                if ($cell($r, 0) !== '') {
                    break;   // 次ブロック（または「全コンテンツ共通」）
                }
                if ($rowEmpty($r)) {
                    break;   // 空行＝ブロック終わり
                }
                foreach (self::BRACKETS as $bi => $b) {
                    $p = $cell($r, $b['p']);
                    if ($p === '') {
                        continue;   // その段は空きスロット（人数パディング）
                    }
                    $biko = $cell($r, $b['biko']);
                    $patrolRaw = $cell($r, $b['patrol']);
                    $blockSlots[$bi][] = [
                        'pos'    => self::mapPosition($p, $biko),
                        'note'   => $biko,
                        'patrol' => is_numeric($patrolRaw) ? (int) $patrolRaw : null,
                        'rawP'   => $p,
                    ];
                }
                $k++;
            }

            // 段→規模を確定し、product×scale へ格納。
            foreach (self::BRACKETS as $bi => $b) {
                if (empty($blockSlots[$bi])) {
                    continue;
                }
                if (! $anyLabel) {
                    // ラベル無し＝規模非依存 → 小中大すべてに同じ枠を入れる
                    $scales = self::SCALES;
                } else {
                    $s = $scaleByBracket[$bi];
                    if ($s === null) {
                        continue;
                    }
                    // 軍がある（チャンバラ系）→ 対角線のみ採用
                    if ($gun > 0 && self::scaleForGun($gun) !== $s) {
                        continue;
                    }
                    $scales = [$s];
                }
                foreach ($products as $prod) {
                    foreach ($scales as $s) {
                        foreach ($blockSlots[$bi] as $slot) {
                            $out[$prod][$s][] = $slot;
                        }
                    }
                }
            }

            $i = $k;
        }

        ksort($out);

        return $out;
    }

    /**
     * 名前の見比べ用に整えた形（**この形が同じなら「同じコンテンツ」とみなす**）。
     *
     * なぜ要るか（2026-09-07 baba報告「案件名がちょっとちがうだけで、登録済みのやつも新規登録される」）：
     *   台帳の名前は手入力・案件CSV経由で増えてきたので、公式リストと**書き方だけ**違うことが多い
     *   （全角と半角／空白の有無／「・」の有無／大文字小文字）。名前が1字でも違えば別物として
     *   台帳に足していたため、**似た名前のコンテンツが2つできてしまう**。
     *
     * ⚠ **意味が変わる文字は消さない。** 長音（ー）と数字はそのまま残す
     *   （「謎パ」と「謎パ2」・「ワールド」と「ワルド」は**別物**なので混ぜてはいけない）。
     */
    public static function normalizeName(string $name): string
    {
        // 全角英数→半角／半角カナ→全角／濁点をひとまとめ／全角スペース→半角
        $s = mb_convert_kana(trim($name), 'asKV');
        // かっこ類は半角に寄せ、かぎかっこは無いものとして扱う
        $s = str_replace(['（', '）', '［', '］', '｛', '｝', '「', '」', '『', '』'],
            ['(', ')', '[', ']', '{', '}', '', '', '', ''], $s);
        // 区切りの記号・空白は無いものとして比べる（「ヒラメキ・クエスト」＝「ヒラメキクエスト」）
        // ⚠ 長音「ー」は文字なので消さない。ここで消すのはダッシュ類だけ。
        $s = (string) preg_replace('/[\s・\x{2010}-\x{2015}\x{2212}\-_\/]+/u', '', $s);

        return mb_strtolower($s, 'UTF-8');
    }

    /**
     * CSVの商品名 → 台帳のどのコンテンツに入れるか。**プレビューと反映で必ずこれを通す**。
     *
     * 決め方（上から順に）：
     *  ① 画面で人が選んだ指定（$overrides）があればそれに従う
     *  ② 名前が**完全一致**する台帳のコンテンツ
     *  ③ **書き方だけ違う**一致（normalizeName が同じ）が1件だけ → それに入れる
     *  ④ 書き方だけ違う一致が2件以上 → いちばん古い（IDが小さい）ものを既定にし、画面で選び直せる
     *  ⑤ 一致なしだが**似ている**台帳がある（片方がもう片方を含む）→ 既定は「新規で作る」。画面で選び直せる
     *  ⑥ 何も無い → 新規で作る
     *
     * @param  list<string>  $products
     * @param  array<string, string>  $overrides  商品名のキー(key())→ コンテンツID（''＝新規で作る）
     * @return array<string, array{contentId: ?string, matchType: string, candidates: list<array{id: string, name: string}>, forcedNew: bool}>
     */
    public static function matchProducts(array $products, array $overrides = []): array
    {
        $contents = Content::orderBy('id')->get(['id', 'content_name']);

        $exact = [];      // 名前 => id（同名が複数なら古い方）
        $byNorm = [];     // 整えた名前 => [['id'=>, 'name'=>], ...]
        foreach ($contents as $c) {
            $name = (string) $c->content_name;
            if (! isset($exact[$name])) {
                $exact[$name] = (string) $c->id;
            }
            $byNorm[self::normalizeName($name)][] = ['id' => (string) $c->id, 'name' => $name];
        }

        $result = [];
        foreach ($products as $prod) {
            $key = self::key($prod);
            $norm = self::normalizeName($prod);
            $same = $byNorm[$norm] ?? [];

            // 似ている台帳（片方がもう片方を含む）。人に見せる候補としてだけ使う。
            $candidates = [];
            if ($same === []) {
                foreach ($byNorm as $dbNorm => $list) {
                    if (mb_strlen($dbNorm) < 2 || mb_strlen($norm) < 2) {
                        continue;
                    }
                    if (mb_strpos($dbNorm, $norm) !== false || mb_strpos($norm, $dbNorm) !== false) {
                        foreach ($list as $c) {
                            $candidates[] = $c;
                        }
                    }
                }
                // 長さが近いものから見せる（似ている度合いの代わり）
                usort($candidates, fn ($a, $b) => abs(mb_strlen(self::normalizeName($a['name'])) - mb_strlen($norm))
                    <=> abs(mb_strlen(self::normalizeName($b['name'])) - mb_strlen($norm)));
                $candidates = array_slice($candidates, 0, 5);
            }

            // ① 人が選んだ指定を最優先（''＝新規で作る）
            if (array_key_exists($key, $overrides)) {
                $chosen = trim((string) $overrides[$key]);
                if ($chosen === '') {
                    $result[$prod] = [
                        'contentId' => null, 'matchType' => 'chosen-new',
                        'candidates' => $same ?: $candidates, 'forcedNew' => true,
                    ];
                    continue;
                }
                if ($contents->firstWhere('id', $chosen)) {
                    $result[$prod] = [
                        'contentId' => $chosen, 'matchType' => 'chosen',
                        'candidates' => $same ?: $candidates, 'forcedNew' => false,
                    ];
                    continue;
                }
                // 台帳に無いIDが来たら、指定は無かったものとして通常の判定に落とす。
            }

            // ② 完全一致
            if (isset($exact[$prod])) {
                $result[$prod] = [
                    'contentId' => $exact[$prod], 'matchType' => 'exact',
                    'candidates' => [], 'forcedNew' => false,
                ];
                continue;
            }

            // ③④ 書き方だけ違う一致
            if (count($same) === 1) {
                $result[$prod] = [
                    'contentId' => $same[0]['id'], 'matchType' => 'normalized',
                    'candidates' => $same, 'forcedNew' => false,
                ];
                continue;
            }
            if (count($same) > 1) {
                $result[$prod] = [
                    'contentId' => $same[0]['id'], 'matchType' => 'ambiguous',
                    'candidates' => $same, 'forcedNew' => false,
                ];
                continue;
            }

            // ⑤⑥ 一致なし
            $result[$prod] = [
                'contentId' => null,
                'matchType' => $candidates === [] ? 'new' : 'maybe',
                'candidates' => $candidates,
                'forcedNew' => false,
            ];
        }

        return $result;
    }

    /** 商品名 → 画面の入力欄で使う短いキー（日本語をそのまま name 属性にしないため）。 */
    public static function key(string $product): string
    {
        return md5($product);
    }

    /**
     * 解析結果 → 画面・コマンドで見せる一覧（「何がどう入るか」）。
     *
     * プレビューと反映で同じものを使う＝**見た内容と入る内容が食い違わない**ようにする。
     *
     * @param  array<string, array<string, list<array>>>  $out
     * @param  array<string, string>  $overrides  画面で人が選んだ「どの台帳に入れるか」
     * @return array{items: list<array>, contentCount: int, newCount: int, slotTotal: int, matchedCount: int, needsChoiceCount: int}
     */
    public static function summary(array $out, array $overrides = []): array
    {
        $match = self::matchProducts(array_keys($out), $overrides);

        $items = [];
        $newCount = 0;
        $slotTotal = 0;
        $matchedCount = 0;        // 書き方だけ違う一致で拾えたもの（これまでは新規になっていた分）
        $needsChoiceCount = 0;   // 人に選んでほしいもの

        foreach ($out as $prod => $byScale) {
            $m = $match[$prod];
            $contentId = $m['contentId'];
            if ($contentId === null) {
                $newCount++;
            }
            if (in_array($m['matchType'], ['normalized', 'ambiguous'], true)) {
                $matchedCount++;
            }
            if (in_array($m['matchType'], ['ambiguous', 'maybe'], true)) {
                $needsChoiceCount++;
            }

            $scales = [];
            foreach (self::SCALES as $s) {
                if (empty($byScale[$s])) {
                    continue;
                }
                $byPos = [];
                foreach ($byScale[$s] as $slot) {
                    $byPos[$slot['pos']] = ($byPos[$slot['pos']] ?? 0) + 1;
                    $slotTotal++;
                }
                $scales[$s] = [
                    'byPos' => $byPos,
                    'total' => count($byScale[$s]),
                    'slots' => $byScale[$s],
                ];
            }

            // 入れる先の台帳の名前（書き方だけ違う一致のとき、画面に出して見てもらう）。
            $matchedName = null;
            if ($contentId !== null) {
                foreach ($m['candidates'] as $c) {
                    if ($c['id'] === $contentId) {
                        $matchedName = $c['name'];
                        break;
                    }
                }
            }

            $items[] = [
                'product'     => $prod,
                'key'         => self::key($prod),
                'contentId'   => $contentId,
                'isNew'       => $contentId === null,
                'matchType'   => $m['matchType'],
                'matchedName' => $matchedName,
                'candidates'  => $m['candidates'],
                'scales'      => $scales,
            ];
        }

        return [
            'items'            => $items,
            'contentCount'     => count($items),
            'newCount'         => $newCount,
            'slotTotal'        => $slotTotal,
            'matchedCount'     => $matchedCount,
            'needsChoiceCount' => $needsChoiceCount,
        ];
    }

    /**
     * 解析結果をDBへ反映する。
     *
     * ・CSVに無いコンテンツは触らない（消さない）。
     * ・CSVにあるコンテンツは、そのコンテンツの必要人数を**入れ替える**
     *   （消して入れ直す＝何度取り込んでも重複しない）。
     * ・名簿に無い商品名は、コンテンツ台帳に CT-### で新しく作る。
     *
     * @param  array<string, array<string, list<array>>>  $out
     * @param  array<string, string>  $overrides  画面で人が選んだ「どの台帳に入れるか」
     * @return array{contents: int, new: int, slots: int, newIds: list<string>}
     */
    public static function apply(array $out, array $overrides = []): array
    {
        // ⚠ 入れる先の決め方は matchProducts の1か所だけ＝**プレビューで見た先とずれない**。
        $match = self::matchProducts(array_keys($out), $overrides);

        // 新規コンテンツID採番の起点（CT-\d+ の最大＋1）。
        $maxNum = 0;
        foreach (Content::pluck('id') as $id) {
            if (preg_match('/^CT-(\d+)$/', (string) $id, $m)) {
                $maxNum = max($maxNum, (int) $m[1]);
            }
        }

        $newIds = [];
        $slots = 0;

        $cleared = [];   // この取込で「消して入れ直し」を済ませた台帳（2回消さないため）

        DB::transaction(function () use ($out, $match, &$maxNum, &$newIds, &$slots, &$cleared) {
            foreach ($out as $prod => $byScale) {
                $contentId = $match[$prod]['contentId'];
                if ($contentId === null) {
                    $maxNum++;
                    $contentId = 'CT-'.str_pad((string) $maxNum, 3, '0', STR_PAD_LEFT);
                    Content::create([
                        'id'           => $contentId,
                        'content_name' => $prod,
                        'active'       => true,
                    ]);
                    $newIds[] = $contentId;
                }

                // このコンテンツの既存必要人数を消して入れ直す（再実行で重複しない）。
                // ⚠ ただし1回の取込で同じ台帳に2つの行を入れるときは、2回目は消さない
                //   （消すと1つ目に入れたぶんが消え、あとの行だけが残ってしまう）。
                if (! isset($cleared[$contentId])) {
                    ContentRoleRequirement::where('content_id', $contentId)->delete();
                    $cleared[$contentId] = true;
                }

                foreach (self::SCALES as $s) {
                    if (empty($byScale[$s])) {
                        continue;
                    }
                    $order = ContentRoleRequirement::where('content_id', $contentId)
                        ->where('scale', $s)->count();
                    foreach ($byScale[$s] as $slot) {
                        ContentRoleRequirement::create([
                            'content_id' => $contentId,
                            'scale'      => $s,
                            'position'   => $slot['pos'],
                            'count'      => 1,
                            'note'       => $slot['note'] !== '' ? $slot['note'] : null,
                            'patrol'     => $slot['patrol'],
                            'sort_order' => $order++,
                        ]);
                        $slots++;
                    }
                }
            }
        });

        return [
            'contents' => count($out),
            'new'      => count($newIds),
            'slots'    => $slots,
            'newIds'   => $newIds,
        ];
    }

    /** 参加人数ラベル → 規模。判定順に注意（100を先に見る＝150の"50"に引っかからないため）。 */
    private static function scaleOf(string $label): ?string
    {
        if (mb_strpos($label, '100') !== false) {
            return '大型';
        }
        if (mb_strpos($label, '49') !== false || mb_strpos($label, '～4') !== false) {
            return '小型';
        }
        if (mb_strpos($label, '50') !== false) {
            return '中型';
        }

        return null;
    }

    /** 軍数 → 規模（対角線）。 */
    private static function scaleForGun(int $gun): ?string
    {
        return [2 => '小型', 3 => '中型', 4 => '大型'][$gun] ?? null;
    }

    /** P（ポジション）＋備考 → アプリの役割コード。 */
    private static function mapPosition(string $p, string $biko): string
    {
        $p = trim($p);
        $b = trim($biko);

        if ($p === 'D' || $p === 'D/OP') {
            return AssignmentRole::D;
        }
        if ($p === 'MC') {
            return AssignmentRole::MC;
        }
        if ($p === 'OP') {
            return AssignmentRole::OP;
        }
        // FC は備考で細分：軍師/サポ→SP・チェッカー→CK・受付→RP・それ以外→FC
        if ($p === 'FC') {
            if (mb_strpos($b, 'チェッカー') !== false) {
                return AssignmentRole::CK;
            }
            if (mb_strpos($b, '受付') !== false) {
                return AssignmentRole::RP;
            }
            foreach (['軍師', 'サポ', 'サブ'] as $kw) {
                if (mb_strpos($b, $kw) !== false) {
                    return AssignmentRole::SP;
                }
            }

            return AssignmentRole::FC;
        }

        // 想定外の P はそのまま（正規コードでなければ FC 扱い）
        return AssignmentRole::isValid($p) ? $p : AssignmentRole::FC;
    }

    /** コンテンツ名セル（複数行・箇条書き・注記混在）→ 商品名の配列。 */
    private static function parseProducts(string $cell): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/u', $cell) as $line) {
            $line = trim($line);
            // ※注記が始まったら、それ以降は全て説明文（複数行）＝商品名ではないので打ち切る。
            if (mb_substr($line, 0, 1) === '※') {
                break;
            }
            if ($line === '') {
                continue;
            }
            // 「（2軍）」「（3競技想定）」のような注記行だけは除く
            if (preg_match('/^（.*）$/u', $line)) {
                continue;
            }
            // 先頭の「・」や空白（全角含む）を除く。※ltrim(char list) はバイト単位で
            // カタカナ等の先頭バイトを壊すため使わない（マルチバイト安全に preg で除去）。
            $line = preg_replace('/^[・　\s]+/u', '', $line);
            // 末尾の（…）注記を落とす
            $line = preg_replace('/（[^）]*）\s*$/u', '', $line);
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /** コンテンツ名セルから軍数（2/3/4）を拾う。無ければ 0。 */
    private static function parseGun(string $cell): int
    {
        if (preg_match('/(\d)\s*軍/u', $cell, $m)) {
            return (int) $m[1];
        }

        return 0;
    }
}
