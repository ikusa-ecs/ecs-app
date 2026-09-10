<?php

namespace App\Support;

use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;

/**
 * アサイン表を取り込むと「ECSの今の中身がどう変わるか」を出す係（2026-09-10 baba要望）。
 *
 * 【なぜ要るか】
 * ECSとアサイン表の二重管理をしていて、**どこまでECSに反映したか分からない**のが困りごと。
 * これまでの下見（プレビュー）は「新規／上書き」までは出せたが、
 * 「上書き」と出ている案件が **ECSと同じ中身なのか、違うのか** は分からなかった。
 * ＝毎日読み込んでも「何が変わったのか」を人が目で探すことになっていた。
 *
 * ここでは「**取込を押したら実際に書き換わる項目だけ**」を並べる。
 *   ・変化なし … 押しても何も変わらない（＝もう反映済み）
 *   ・変わる   … 「集合時間 9:00 → 9:30」のように、前と後を並べる
 *   ・新規     … ECSにまだ無い案件
 *
 * 【なぜ「前回シートを読んだ内容」を覚えないのか】
 * 指紋（前回の中身）を保存して比べる作り方もあるが、それだと
 * **ECS側で人が直した分**が見えない（「反映済み」と言いながら中身が違う）。
 * ECSの現物と直接くらべるほうが正確なので、覚えごとを持たない。
 *
 * ⚠ 比べるのは FIELDS に並べた「シートに書かれている項目」だけ。
 *   案件の状態・公開・募集・登録拠点は**取込のときの選択（過去／これから・拠点）で決まる値**で、
 *   シートには書かれていない。混ぜると毎回「変わります」と出て、本当の差分が埋もれる。
 */
final class SheetDiff
{
    /**
     * くらべる項目（DBの列名）。並び順はそのまま画面に出る順。
     *
     * ⚠ ここに無い項目は「変化なし」の判定に入らない。
     *   PastProjectImportController::projectAttributes に列を足したら、ここにも足すこと
     *   （足し忘れると「変化なし」と出たのに中身が書き換わる＝いちばん困る食い違いになる）。
     *
     * ⚠ 入れていない項目とその理由：
     *   ・project_name / client / start_date … 同じ案件かどうかを見分ける鍵なので、そもそも違わない
     *   ・content_ids / content_names       … コンテンツ名から作るので project_name と一緒に決まる
     *   ・office / status / staff_published / is_recruiting … シートではなく取込の選択で決まる
     */
    private const FIELDS = [
        'start_time', 'end_time',
        'event_enter_time', 'event_start_time', 'event_end_time',
        'required_count', 'required_count_min', 'count_tentative',
        'guest_count', 'team_count',
        'scale', 'category', 'yomi', 'date_type',
        'format', 'online_tool', 'broadcast',
        'operation_place', 'location', 'assembly_type', 'lodging', 'is_outdoor',
        'agency', 'sales_owners', 'staff_role',
        'is_multi', 'is_repeat',
        'catering', 'alcohol', 'audio_equipment', 'transport',
        'pub_logo', 'pub_camera', 'pub_article', 'pub_video',
        'goods_owner_id', 'ops_sheet_url',
        'prep_line_created', 'prep_line_sent', 'prep_line_double_check',
        'prep_handover', 'prep_script',
        'count_as_event', 'note',
    ];

    /**
     * 時刻の列。「08:00」と「8:00」を同じものとして比べる。
     * ⚠ そろえないと、同じCSVを入れ直しただけで「変わります」と出る
     *   （案件の重なり判定 findExisting でも同じ理由で normalizeTime を通している）。
     */
    private const TIME_FIELDS = [
        'start_time', 'end_time', 'event_enter_time', 'event_start_time', 'event_end_time',
    ];

    /**
     * 空（null）に意味がある列。
     * ここに並べた列は「null」と「なし(false)」を別ものとして扱う
     * （count_as_event の null＝「自動で決める」で、「数えない」とは違う）。
     * それ以外の列は null と空文字を同じ扱いにする＝画面によって空の入り方が違うだけで
     * 「変わります」と出さないため。
     */
    private const NULL_MEANS_SOMETHING = ['count_as_event', 'is_outdoor', 'alcohol'];

    /** 氏名の控え（ID => 氏名）。同じ人を何度も引かないための覚え書き。 */
    private static array $names = [];

    /**
     * 取込先の案件を決める（見つかった候補のうち、実際に上書きされるもの）。
     *
     * ⚠ 下見と取込で必ず同じものを指すように、ここ1か所で決める。
     *   ・ぴったり同じ案件があれば、それ
     *   ・集合時間だけ違う「似た案件」は、人が「別の案件」を選んでいなければ、それ
     *   ・どちらも無ければ null（＝新しく登録される）
     *
     * @param  array{exact: ?Project, similar: ?Project, similarCount: int}  $found
     * @param  array<string, mixed>  $edit  下見の画面で人が直した内容
     */
    public static function target(array $found, array $edit = []): ?Project
    {
        if ($found['exact']) {
            return $found['exact'];
        }

        return empty($edit['asNew']) ? $found['similar'] : null;
    }

    /**
     * 1案件ぶんの差分。
     *
     * @param  array<string, mixed>  $attrs       これから入れる中身（projectAttributes が作ったもの）
     * @param  ?Project  $existing                上書きされる案件（null＝新規）
     * @param  list<array{id: string, role: string}>  $assignments  これから入れる人
     * @param  ?string  $date                     開催日（Y-m-d）
     * @return array{kind: string, changes: list<array{label: string, was: string, now: string}>,
     *               people: array{add: list<string>, remove: list<string>, role: list<string>}}
     */
    public static function forCase(array $attrs, ?Project $existing, array $assignments, ?string $date): array
    {
        $empty = ['add' => [], 'remove' => [], 'role' => []];

        if ($existing === null) {
            return ['kind' => 'new', 'changes' => [], 'people' => $empty];
        }

        $changes = [];
        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $attrs)) {
                continue;
            }

            $was = $existing->getAttribute($field);
            $now = $attrs[$field];
            if (self::same($field, $was, $now)) {
                continue;
            }

            $changes[] = [
                'label' => ProjectFieldLabels::label($field),
                'was' => ProjectFieldLabels::display($field, $was),
                'now' => ProjectFieldLabels::display($field, $now),
            ];
        }

        $people = $date === null
            ? $empty
            : self::peopleDiff((string) $existing->id, $date, $assignments);

        $moved = $changes !== [] || $people['add'] !== [] || $people['remove'] !== [] || $people['role'] !== [];

        return ['kind' => $moved ? 'changed' : 'same', 'changes' => $changes, 'people' => $people];
    }

    /**
     * 人の差分（増える人・消える人・役割が変わる人）。
     *
     * ⚠ くらべるのは「シートに書かれている役割」だけ。取込は
     *   `whereIn('role', シートの役割) の行を消してから入れ直す`ので、
     *   シートに出てこない役割（例：D決め画面で入れたSD）は消えない。
     *   ここで全部の役割をくらべると「この人が消えます」と嘘の警告を出してしまう。
     *
     * @param  list<array{id: string, role: string}>  $assignments
     * @return array{add: list<string>, remove: list<string>, role: list<string>}
     */
    private static function peopleDiff(string $projectId, string $date, array $assignments): array
    {
        $now = [];
        foreach ($assignments as $a) {
            $now[(string) $a['id']] = (string) $a['role'];
        }

        $roles = array_values(array_unique(array_values($now)));
        if ($roles === []) {
            // シートに人が1人も並んでいない＝取込は誰も消さないし入れない。
            return ['add' => [], 'remove' => [], 'role' => []];
        }

        $was = [];
        $rows = Assignment::where('project_id', $projectId)
            ->whereDate('date', $date)
            ->whereIn('role', $roles)
            ->get();
        foreach ($rows as $row) {
            $was[(string) $row->staff_id] = (string) $row->role;
        }

        $add = [];
        $remove = [];
        $role = [];
        foreach ($now as $id => $r) {
            if (! isset($was[$id])) {
                $add[] = self::personLabel($id).'（'.AssignmentRole::label($r).'）';
            } elseif ($was[$id] !== $r) {
                $role[] = self::personLabel($id).'：'.AssignmentRole::label($was[$id]).' → '.AssignmentRole::label($r);
            }
        }
        foreach ($was as $id => $r) {
            if (! isset($now[$id])) {
                $remove[] = self::personLabel($id).'（'.AssignmentRole::label($r).'）';
            }
        }

        return ['add' => $add, 'remove' => $remove, 'role' => $role];
    }

    /** 2つの値を「同じ中身」と見るか。 */
    private static function same(string $field, $was, $now): bool
    {
        return self::norm($field, $was) === self::norm($field, $now);
    }

    /** くらべるための形にそろえる。 */
    private static function norm(string $field, $value): ?string
    {
        if (in_array($field, self::TIME_FIELDS, true)) {
            return ProjectImportColumns::normalizeTime(is_scalar($value) ? (string) $value : '');
        }

        if ($value === null) {
            // 空に意味がある列だけ、null をそのまま残す（「未設定」と「なし」を混ぜない）。
            return in_array($field, self::NULL_MEANS_SOMETHING, true) ? null : '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            $parts = array_values(array_filter(
                array_map(static fn ($v) => trim((string) $v), $value),
                static fn ($v) => $v !== ''
            ));

            return implode('｜', $parts);
        }

        $text = trim((string) $value);

        // 0/1 で持っている列（DBから来る側）と true/false（これから入れる側）をそろえる。
        return $text;
    }

    /** IDから氏名。名簿に無いIDはそのまま出す（黙って消さない）。 */
    private static function personLabel(string $id): string
    {
        if (self::$names === []) {
            self::$names = Person::query()->pluck('name', 'id')->all();
        }

        $name = self::$names[$id] ?? '';

        return trim((string) $name) !== '' ? (string) $name : $id;
    }
}
