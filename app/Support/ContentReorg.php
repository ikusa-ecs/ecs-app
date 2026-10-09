<?php

namespace App\Support;

use App\Models\Content;
use App\Models\ContentDifficulty;
use App\Models\ContentPaperStock;
use App\Models\ContentRoleRequirement;
use App\Models\Person;
use App\Models\Project;
use App\Models\StaffContentExperience;
use Illuminate\Support\Facades\DB;

/**
 * コンテンツ台帳の整理（2026-10-09 baba）。元＝`稼働管理\ECS\コンテンツ台帳の整理案_2026-10-09.txt`（babaが確認・加筆）。
 *
 * やること（1件ずつ）：
 *   ・分ける   … 組み合わせの名前（例「ロケットPDCA＋あそ研」）→ 案件を2つ以上のコンテンツにつなぎ直して、組み合わせのほうを消す
 *   ・まとめる … 書き方違い（例「【リアル】防災ヒーロー」）→ 1つにつなぎ直して、余ったほうを消す
 *   ・単発にする … 台帳からは消すが、案件の名前は残す（台帳に無い「単発のコンテンツ名」になる）
 *   ・消す     … 使っている案件が0件のもの
 * keep＝つなぎ先は変えるが、案件の名前はそのまま残す（例「板橋区役所_いたばし防災＋フェア」は区分を防災ヒーローに）。
 *
 * ⚠ 安全のため、IDと名前の**両方**が案と一致するときだけ直す（違う台帳＝手元や別の環境では何もしない）。
 * ⚠ つなぎ先の名前が台帳に無いとき・謎解きの紙の在庫が入っているときは、その1件だけ止めて知らせる。
 * ⚠ 一度直したものは台帳から消えるので、二度押しても同じことは起きない。
 */
final class ContentReorg
{
    /** 新しく台帳に足すもの（2026-10-09 決定）。 */
    public const CREATE = ['あそ研'];

    /** 分ける・まとめる：[ID, いまの名前, つなぎ先の名前の一覧, 名前を残すか] */
    public const MOVES = [
        // A. 分ける
        ['CT-053', 'ロケットPDCA＋あそ研', ['ロケットPDCA', 'あそ研']],
        ['CT-054', 'SDGsWL＋あそ研', ['SDGsWL', 'あそ研']],
        ['CT-055', 'ジャンサバ＋あそ研', ['ジャンサバ', 'あそ研']],
        ['CT-056', '帰宅困難＋あそ研', ['帰宅困難', 'あそ研']],
        ['CT-057', 'ゾンビパンデミック＋あそ研', ['ゾンビパンデミック', 'あそ研']],
        ['CT-096', '謎パ＋あそ研', ['謎パ', 'あそ研']],
        ['CT-129', 'コンセンサス＋あそ研', ['コンセンサス', 'あそ研']],
        ['CT-138', '会議室+あそ研', ['会議室', 'あそ研']],
        ['CT-147', '電脳謎パ＋あそ研', ['電脳謎パ', 'あそ研']],
        ['CT-139', 'オール社員感謝祭+ARENA場所貸し', ['オール社員感謝祭', 'ARENA場所貸し(用途記入)']],
        ['CT-145', '【リアル】ミショブル＋格付け＋オール社員', ['ミッションスクランブル', '格付け', 'オール社員感謝祭']],
        ['CT-153', '【リアル】縁日、BBQ、25hunt', ['縁日', 'BBQ', '25HUNT']],
        ['CT-186', '謎パ＋ケータ', ['謎パ', 'ケータリング']],
        ['CT-104', '運動会ケータリング', ['運動会', 'ケータリング']],
        ['CT-107', '複数拠点配信・ミショブル・懇親会', ['ミッションスクランブル', '懇親会']],
        ['CT-111', 'チャンバラ＋ＷＳ', ['チャンバラ', '戦国WS']],
        // B. まとめる
        ['CT-171', 'ある会議室からの脱出', ['会議室']],
        ['CT-146', '【体験会】会議室', ['会議室']],
        ['CT-180', '防災ヒーロー入団試験', ['防災ヒーロー']],
        ['CT-150', '【リアル】防災ヒーロー', ['防災ヒーロー']],
        ['CT-156', '【リアル】水合戦', ['水合戦']],
        ['CT-155', '格付けバトル', ['格付け']],
        ['CT-132', '縁日イベント', ['縁日']],
        ['CT-134', 'マッチングイベント（電脳謎パ）', ['電脳謎パ']],
        ['CT-103', 'オリジナル25Hunt（雨天時運動会）', ['25HUNT']],
        ['CT-114', '鷹狩りリハーサル', ['鷹狩り行列']],
        ['CT-154', '前日設営SDGsWL', ['SDGsWL']],
        ['CT-128', 'ARENA貸し出し', ['ARENA場所貸し(用途記入)']],
        ['CT-050', '縁日ケータリング', ['縁日']],
        ['CT-108', 'ロケットPDCA＋キックオフイベント', ['ロケットPDCA']],
        ['CT-157', '電脳謎パ＋先輩人狼', ['電脳謎パ']],
        ['CT-179', 'オール社員感謝祭/ビンゴ大会', ['オール社員感謝祭']],
        ['CT-123', 'オール社員感謝祭or格付け', ['オール社員感謝祭']],
        ['CT-163', '縁日WS', ['縁日']],
        ['CT-181', 'ハロウィンチャンバラ', ['チャンバラ']],
        ['CT-191', '板橋区役所_いたばし防災＋フェア', ['防災ヒーロー'], true],   // 区分は防災ヒーロー・案件名はそのまま
        ['CT-046', 'クイズ', ['クイズ大会']],
        ['CT-176', 'リアルクイズ大会', ['クイズ大会']],
        ['CT-148', 'リモ謎ショート（新卒採用説明会）', ['リモ謎']],
        // ARENA場所貸し(用途) の8件は1つにまとめる。⚠ 用途が分かるよう案件名はそのまま残す。
        ['CT-095', 'ARENA場所貸し(ライブリハ)', ['ARENA場所貸し(用途記入)'], true],
        ['CT-098', 'ARENA場所貸し(ダンスレッスン)', ['ARENA場所貸し(用途記入)'], true],
        ['CT-106', 'ARENA場所貸し(TP)', ['ARENA場所貸し(用途記入)'], true],
        ['CT-115', 'ARENA場所貸し(天下一武将会)', ['ARENA場所貸し(用途記入)'], true],
        ['CT-126', 'ARENA場所貸し(運動会)', ['ARENA場所貸し(用途記入)'], true],
        ['CT-131', 'ARENA場所貸し(街コンイベント)', ['ARENA場所貸し(用途記入)'], true],
        ['CT-133', 'ARENA場所貸し(マルシェ)', ['ARENA場所貸し(用途記入)'], true],
        ['CT-166', 'ARENA場所貸し(高校格闘技)', ['ARENA場所貸し(用途記入)'], true],
    ];

    /** 「前日設営」は案件ごとに行き先が違う：[案件ID => 本番のコンテンツ名（空＝単発のまま）] */
    public const PREP_ID = 'CT-172';

    public const PREP_NAME = '前日設営';

    public const PREP_PROJECTS = [
        'P-2026-0298' => '防災ヒーロー',      // 10/23 イオンモール熱田（翌日が防災ヒーロー）
        'P-2026-0273' => 'ファミリーフェス',  // 11/13 JERA
        'P-2026-0281' => 'ファミリーフェス',  // 11/28 本田技研 爽風会
        'P-2026-0462' => '',                 // 11/6 福井銀行（翌日の案件がコンテンツ未定）＝名前だけ残す
    ];

    /** 日程種別を「前日設営」に直す案件（前日設営なのに本番・予備日になっていた）。 */
    public const PREP_DATE_TYPE = ['P-2026-0223', 'P-2026-0298', 'P-2026-0462'];

    /** 台帳からは消すが、案件の名前は残す（単発にする）：[ID, 名前] */
    public const ONEOFF = [
        ['CT-101', '抽選会＋景品手配'],
        ['CT-100', 'AIワークショップ'],
        ['CT-200', 'サブコンテンツ'],
        ['CT-184', 'イベント運営'],
        ['CT-165', 'トータルイベント'],
    ];

    /** 消す（使っている案件が0件）：[ID, 名前] */
    public const DELETE = [
        ['CT-110', '謎パorコンセンサス'], ['CT-116', '綱引き大会(リハ)'], ['CT-118', '【リアル】AIワークショップ'],
        ['CT-119', '【リアル】AI研修'], ['CT-121', '社員総会＋格付けケータリング'], ['CT-124', '謎解き'],
        ['CT-142', 'ヒラクエ'], ['CT-162', 'ARENAイベント(運動会)'], ['CT-169', '原本'],
        ['CT-185', '【リアル】会場設営のみ'], ['CT-188', 'ピッチコンテスト（式典）＋懇親会'], ['CT-190', '【リアル】会場のみ'],
        ['CT-192', '６５周年記念（前日設営）'], ['CT-197', '【大型】運動会'],
        ['CT-058', '捜査会議＋あそ研'], ['CT-059', '大軍議＋あそ研'], ['CT-060', '消えた資料＋あそ研'],
    ];

    /**
     * 下見＝それぞれ実行できるか・何件の案件が変わるか。
     *
     * @return list<array{kind:string, id:string, name:string, to:string, projects:int, ok:bool, why:string}>
     */
    public static function preview(): array
    {
        $byId = Content::pluck('content_name', 'id')->map(fn ($n) => (string) $n)->all();
        $byName = array_flip($byId);
        $usage = self::usage();
        $stocked = ContentPaperStock::pluck('content_id')->map(fn ($v) => (string) $v)->flip()->all();
        $out = [];

        foreach (self::CREATE as $name) {
            $out[] = ['kind' => '足す', 'id' => '', 'name' => $name, 'to' => '', 'projects' => 0,
                'ok' => ! isset($byName[$name]), 'why' => isset($byName[$name]) ? 'もう台帳にあります' : ''];
        }
        foreach (self::MOVES as $m) {
            [$id, $name, $to] = $m;
            $why = self::check($id, $name, $byId, $stocked);
            $missing = array_filter($to, fn ($t) => ! isset($byName[$t]) && ! in_array($t, self::CREATE, true));
            if ($why === '' && $missing) {
                $why = 'つなぎ先が台帳にありません：'.implode('・', $missing);
            }
            $out[] = ['kind' => count($to) > 1 ? '分ける' : 'まとめる', 'id' => $id, 'name' => $name,
                'to' => implode(' ＋ ', $to).(! empty($m[3]) ? '（案件名はそのまま）' : ''),
                'projects' => $usage[$id] ?? 0, 'ok' => $why === '', 'why' => $why];
        }
        $why = self::check(self::PREP_ID, self::PREP_NAME, $byId, $stocked);
        $to = [];
        foreach (self::PREP_PROJECTS as $pid => $t) {
            $to[] = $pid.'→'.($t !== '' ? $t : '名前だけ残す');
            if ($why === '' && $t !== '' && ! isset($byName[$t])) {
                $why = 'つなぎ先が台帳にありません：'.$t;
            }
        }
        $out[] = ['kind' => '案件ごと', 'id' => self::PREP_ID, 'name' => self::PREP_NAME, 'to' => implode('／', $to),
            'projects' => $usage[self::PREP_ID] ?? 0, 'ok' => $why === '', 'why' => $why];
        foreach (self::ONEOFF as [$id, $name]) {
            $why = self::check($id, $name, $byId, $stocked);
            $out[] = ['kind' => '単発にする', 'id' => $id, 'name' => $name, 'to' => '（台帳から消す・案件名は残す）',
                'projects' => $usage[$id] ?? 0, 'ok' => $why === '', 'why' => $why];
        }
        foreach (self::DELETE as [$id, $name]) {
            $why = self::check($id, $name, $byId, $stocked);
            if ($why === '' && ($usage[$id] ?? 0) > 0) {
                $why = '案件で使われています（'.$usage[$id].'件）';
            }
            $out[] = ['kind' => '消す', 'id' => $id, 'name' => $name, 'to' => '', 'projects' => $usage[$id] ?? 0,
                'ok' => $why === '', 'why' => $why];
        }

        return $out;
    }

    /**
     * 実行する（下見で ok のものだけ）。
     *
     * @return array{done:int, skipped:int, projects:int}
     */
    public static function apply(): array
    {
        $done = 0;
        $skipped = 0;
        $touched = [];

        DB::transaction(function () use (&$done, &$skipped, &$touched) {
            foreach (self::CREATE as $name) {
                if (! Content::where('content_name', $name)->exists()) {
                    Content::create(['id' => self::nextId(), 'content_name' => $name, 'active' => true]);
                    $done++;
                }
            }
            $plan = collect(self::preview())->keyBy(fn ($r) => $r['kind'].'|'.$r['id'].'|'.$r['name']);
            $nameToId = fn (string $n) => (string) Content::where('content_name', $n)->value('id');

            foreach (self::MOVES as $m) {
                [$id, $name, $to] = $m;
                $keep = ! empty($m[3]);
                if (! ($plan->get((count($to) > 1 ? '分ける' : 'まとめる').'|'.$id.'|'.$name)['ok'] ?? false)) {
                    $skipped++;

                    continue;
                }
                $toIds = array_map($nameToId, $to);
                foreach (self::projectsWith($id) as $p) {
                    self::relink($p, $id, $name, $toIds, $to, $keep);
                    $touched[$p->id] = true;
                }
                self::moveRefs($id, $name, $toIds, $to);
                Content::whereKey($id)->delete();
                $done++;
            }

            if ($plan->get('案件ごと|'.self::PREP_ID.'|'.self::PREP_NAME)['ok'] ?? false) {
                foreach (self::projectsWith(self::PREP_ID) as $p) {
                    $t = self::PREP_PROJECTS[$p->id] ?? '';
                    if ($t !== '') {
                        self::relink($p, self::PREP_ID, self::PREP_NAME, [$nameToId($t)], [$t], false);
                    } else {
                        self::unlink($p, self::PREP_ID);
                    }
                    $touched[$p->id] = true;
                }
                self::moveRefs(self::PREP_ID, self::PREP_NAME, [], []);
                Content::whereKey(self::PREP_ID)->delete();
                $done++;
            } else {
                $skipped++;
            }
            foreach (self::PREP_DATE_TYPE as $pid) {
                $p = Project::find($pid);
                if ($p && $p->date_type !== '前日設営') {
                    $p->date_type = '前日設営';
                    $p->save();
                    $touched[$p->id] = true;
                }
            }

            foreach (self::ONEOFF as [$id, $name]) {
                if (! ($plan->get('単発にする|'.$id.'|'.$name)['ok'] ?? false)) {
                    $skipped++;

                    continue;
                }
                foreach (self::projectsWith($id) as $p) {
                    self::unlink($p, $id);
                    $touched[$p->id] = true;
                }
                self::moveRefs($id, $name, [], []);
                Content::whereKey($id)->delete();
                $done++;
            }
            foreach (self::DELETE as [$id, $name]) {
                if (! ($plan->get('消す|'.$id.'|'.$name)['ok'] ?? false)) {
                    $skipped++;

                    continue;
                }
                self::moveRefs($id, $name, [], []);
                Content::whereKey($id)->delete();
                $done++;
            }
        });

        return ['done' => $done, 'skipped' => $skipped, 'projects' => count($touched)];
    }

    /** IDと名前が案と一致するか・紙の在庫が無いか。問題なければ空文字。 */
    private static function check(string $id, string $name, array $byId, array $stocked): string
    {
        if (! isset($byId[$id])) {
            return '台帳にありません（もう直したか、別の台帳です）';
        }
        if ($byId[$id] !== $name) {
            return 'IDの名前が案と違います（いま「'.$byId[$id].'」）';
        }
        if (isset($stocked[$id])) {
            return '謎解きの紙の在庫が入っています（どこへ寄せるか相談が要ります）';
        }

        return '';
    }

    /** @return iterable<Project> */
    private static function projectsWith(string $id): iterable
    {
        return Project::whereNotNull('content_ids')->get()
            ->filter(fn (Project $p) => in_array($id, array_map('strval', (array) $p->content_ids), true));
    }

    /** 案件のつなぎ先を付け替える（keep＝案件に出る名前はそのまま）。 */
    private static function relink(Project $p, string $id, string $name, array $toIds, array $toNames, bool $keep): void
    {
        $newIds = [];
        foreach (array_map('strval', (array) $p->content_ids) as $x) {
            foreach ($x === $id ? $toIds : [$x] as $y) {
                if ($y !== '' && ! in_array($y, $newIds, true)) {
                    $newIds[] = $y;
                }
            }
        }
        $p->content_ids = $newIds;
        if (! $keep) {
            $names = array_map('strval', (array) ($p->content_names ?? []));
            $oldJoined = implode(ProjectContentName::SEPARATOR, $names);
            $newNames = [];
            foreach ($names === [] ? [$name] : $names as $n) {
                foreach ($n === $name ? $toNames : [$n] as $mName) {
                    if (! in_array($mName, $newNames, true)) {
                        $newNames[] = $mName;
                    }
                }
            }
            $p->content_names = $newNames;
            // 案件の正式名が、前の名前そのもの（または前のつなぎ方）だったときだけ作り直す。
            if (in_array((string) $p->project_name, [$name, $oldJoined], true)) {
                $p->project_name = implode(ProjectContentName::SEPARATOR, $newNames);
            }
        } elseif (! $p->content_names) {
            // 名前を残す＝台帳から消えても今の名前が出るように、名前を案件に持たせておく。
            $p->content_names = [$name];
        }
        $p->save();   // ⚠ 保存イベント経由で編集履歴にも残る
    }

    /** 台帳から外すだけ（名前は案件に残る＝単発のコンテンツ名になる）。 */
    private static function unlink(Project $p, string $id): void
    {
        $names = array_map('strval', (array) ($p->content_names ?? []));
        if ($names === []) {
            // 名前を持っていない古い案件＝消える前の台帳の名前を写しておく。
            $names = ProjectContentName::names($p);
        }
        $p->content_ids = array_values(array_filter(array_map('strval', (array) $p->content_ids), fn ($x) => $x !== $id));
        $p->content_names = $names;
        $p->save();
    }

    /** 案件以外でコンテンツを指しているもの（必要人数・経験・難易度表・名簿の経験欄）を付け替える。 */
    private static function moveRefs(string $id, string $name, array $toIds, array $toNames): void
    {
        $first = $toIds[0] ?? null;
        // 必要人数：つなぎ先にまだ無ければ移す（格付けバトル→格付け）。あれば消す。
        if ($first !== null && ! ContentRoleRequirement::where('content_id', $first)->exists()) {
            ContentRoleRequirement::where('content_id', $id)->update(['content_id' => $first]);
        } else {
            ContentRoleRequirement::where('content_id', $id)->delete();
        }
        foreach (StaffContentExperience::where('content_id', $id)->get() as $e) {
            $dup = $first !== null && StaffContentExperience::where('staff_id', $e->staff_id)->where('content_id', $first)->exists();
            ($first === null || $dup) ? $e->delete() : $e->update(['content_id' => $first]);
        }
        ContentDifficulty::where('content_id', $id)->update(['content_id' => $first]);
        // 名簿の「経験したコンテンツ」は名前で持っている＝名前を付け替える。
        if ($toNames) {
            foreach (['experienced_contents', 'director_contents'] as $col) {
                Person::whereNotNull($col)->get()->each(function (Person $person) use ($col, $name, $toNames) {
                    $list = (array) $person->{$col};
                    if (! in_array($name, $list, true)) {
                        return;
                    }
                    $new = [];
                    foreach ($list as $n) {
                        foreach ($n === $name ? $toNames : [$n] as $x) {
                            if (! in_array($x, $new, true)) {
                                $new[] = $x;
                            }
                        }
                    }
                    $person->{$col} = $new;
                    $person->save();
                });
            }
        }
    }

    /** コンテンツID → 使っている案件の数。 */
    private static function usage(): array
    {
        $n = [];
        foreach (Project::whereNotNull('content_ids')->pluck('content_ids') as $ids) {
            foreach (array_unique(array_map('strval', (array) $ids)) as $id) {
                $n[$id] = ($n[$id] ?? 0) + 1;
            }
        }

        return $n;
    }

    private static function nextId(): string
    {
        $max = Content::where('id', 'like', 'CT-%')->pluck('id')->map(fn ($id) => (int) substr($id, 3))->max();

        return 'CT-'.str_pad((string) (($max ?? 0) + 1), 3, '0', STR_PAD_LEFT);
    }
}
