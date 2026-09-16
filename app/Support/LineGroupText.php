<?php

namespace App\Support;

use App\Models\Content;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * LINEグループを作るときにコピーする文章を作る（2026-09-16 baba要望）。
 *
 * 【なぜ要るか】
 * 10月ぶんのLINEグループを1件ずつ手で作るのが重い、という相談から。
 * LINEは外からグループを作れない（グループ作成のAPIが公開されていない）ので、
 * 作る作業そのものは自動にできない。代わりに「毎回書いている文字」をECSが作って、
 * コピーボタン1つで貼れるようにする。
 *
 * 作るものは3つ。
 *   ① アイコン用の3行  … LINEの「テキストプロフィールを作成」に貼る
 *        261001 / 謎パ / 東京水道株式会社
 *   ② グループ名       … 261001謎パ＠東京水道株式会社
 *   ③ 概要文           … いまアサイン表からコピペしている内容＋ポジション＋定型文
 *
 * ⚠ この3つの作り方を書くのはここ1か所だけ。画面（Blade）側では文字を組み立てない。
 *   画面のJSで組み立てると、画面を増やしたときに片方だけ直して食い違う。
 *   （Blade内のJSは置換で壊れやすいという事故も繰り返している＝BladeのJSでエスケープが化ける罠）
 *
 * ⚠ 会社名は「様」を外すだけにする（2026-09-16 baba）。
 *   LINEのアイコン・グループ名は文字数が限られるので「様」を省いている。
 *   「株式会社→(株)」のような短縮は**しない**＝取引先の名前を勝手に変えると事故るため、
 *   短くしたいときは貼ったあとに手で消す。
 *
 * ⚠ ポジションは「@」までしか出さない（2026-09-16 baba）。
 *   LINEのメンションは手で打たないと有効にならない（文字で「@山田」と書いてもただの文字）。
 *   さらに、手で打ちながら「その人がグループに入っているか」を確かめる作業も兼ねている。
 */
class LineGroupText
{
    /** 概要文のいちばん下に付ける定型文の保存先（共通設定 /settings で編集できる）。 */
    public const NOTICE_KEY = 'line_group_notice';

    /** 定型文の文字数上限（画面の入力欄と合わせる）。 */
    public const NOTICE_MAX = 4000;

    /**
     * 定型文の初期値（2026-09-16 時点で baba が実際に送っている文面）。
     * ⚠ URLは変わるので、直すのは共通設定の画面から。ここは「一度も設定していないとき」だけ使う。
     */
    public const NOTICE_DEFAULT = <<<'TXT'
※前泊や同日案件がないか確認お願いします！
※初めてのコンテンツの場合･･･
①動画チェック
https://drive.google.com/drive/folders/1uUIMoJBkMTsWXmS9jGr_1yl63rIE7cCv?usp=drive_link
②スプシでレクチャー要否確認
https://docs.google.com/spreadsheets/d/1lj0mW8SxoyjKnHkaPrzJt7JwUSb3TO3_y3OT1jBbHww/edit#gid=159976919
③必要ならフォーム送信
・この時点でレクチャーフォームをお送りください
・ポジションが不明の場合もその旨をフォームにご記載ください
※確認表に記載がないものは事前レクチャーが不要のものとなります※
https://forms.gle/VHUe97Ccabto2tJ17
☆フォームが必要ないコンテンツもlineグループ上で初めての旨をディレクターにお伝えください☆
TXT;

    /**
     * 概要文に出すポジションの並び。
     *   code   … assignments.role の値
     *   label  … LINEに出す名前（アサイン表・LINEの書き方に合わせる）
     *   always … 誰も入っていなくても1行出すか（入れる予定の枠として残す）
     *
     * ⚠ 役割コードの正本は App\Support\AssignmentRole。ここは「LINEでの呼び名と並び順」だけ。
     */
    private const POSITIONS = [
        ['code' => 'D',  'label' => 'ディレクター', 'always' => true],
        ['code' => 'SD', 'label' => 'SD予定',       'always' => false],
        ['code' => 'OP', 'label' => 'OP予定',       'always' => true],
        ['code' => 'MC', 'label' => 'MC予定',       'always' => true],
        ['code' => 'FC', 'label' => 'FC予定',       'always' => true],
        ['code' => 'SP', 'label' => '軍師・サポーター予定', 'always' => false],
        ['code' => 'RP', 'label' => '受付予定',     'always' => false],
        ['code' => 'CK', 'label' => 'チェッカー予定', 'always' => false],
    ];

    /** 曜日（Carbon の dayOfWeek＝0:日 〜 6:土 に対応）。 */
    private const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

    // ------------------------------------------------------------------
    // 定型文（共通設定）
    // ------------------------------------------------------------------

    /** 概要文の下に付ける定型文。未設定なら初期値を返す。 */
    public static function notice(): string
    {
        $raw = Setting::get(self::NOTICE_KEY);

        // 「一度も設定していない」と「わざと空にした」を区別する。
        // null＝未設定なので初期値、空文字＝本人が消したのでそのまま空。
        if ($raw === null) {
            return self::NOTICE_DEFAULT;
        }

        return self::normalizeNewlines((string) $raw);
    }

    /** 定型文を保存する。保存した中身を返す。 */
    public static function saveNotice(string $text): string
    {
        $clean = mb_substr(self::normalizeNewlines($text), 0, self::NOTICE_MAX);
        Setting::put(self::NOTICE_KEY, $clean);

        return $clean;
    }

    // ------------------------------------------------------------------
    // ① アイコン用の3行
    // ------------------------------------------------------------------

    /**
     * LINEの「テキストプロフィールを作成」に貼る3行。
     *   1行目＝日付（yymmdd）／2行目＝コンテンツ名／3行目＝会社名（様なし）
     */
    public static function icon(Project $project, array $contentMaster = []): string
    {
        return implode("\n", [
            self::ymd($project),
            self::contentName($project, $contentMaster),
            self::company($project),
        ]);
    }

    // ------------------------------------------------------------------
    // ② グループ名
    // ------------------------------------------------------------------

    /**
     * グループ名＝ 261001謎パ＠東京水道株式会社様
     *
     * ⚠ グループ名は「様」を付ける（2026-09-16 baba）。
     *   様を省くのは**アイコン（テキストプロフィール）だけ**＝あちらは文字数がきついため。
     */
    public static function groupName(Project $project, array $contentMaster = []): string
    {
        return self::ymd($project)
            .self::contentName($project, $contentMaster)
            .'＠'
            .self::companyWithSama($project);
    }

    // ------------------------------------------------------------------
    // ③ 概要文
    // ------------------------------------------------------------------

    /**
     * グループに貼る概要文。
     *
     * @param  array<int, array<string, mixed>>  $assigned  この案件の割当メンバー。
     *         日別ボードのカードが持っている形（['roleCode' => 'OP', ...]）をそのまま渡せる。
     *         人数ぶんだけ「@」の行を出すのに使う（名前は出さない＝手打ちで確認するため）。
     * @param  array<string, string>  $contentMaster  コンテンツID → 名前（台帳を引き直さないための表）
     */
    public static function summary(Project $project, array $assigned = [], array $contentMaster = []): string
    {
        $lines = [];

        // --- 上半分＝アサイン表からコピペしている内容 ---
        $lines[] = self::pair('日程', self::dateLabel($project)).self::gap().self::pair('宿泊', self::lodging($project));

        // 前泊があるときだけ1行足す。出発時間はECSに列が無いので空欄にして手で足してもらう（2026-09-16 baba）。
        if (self::hasPreStay($project)) {
            $lines[] = self::pair('前泊集合', self::val($project->stay_pre_meet_time)).self::gap().self::pair('前泊出発', '');
        }

        $lines[] = self::pair('コンテンツ', self::contentName($project, $contentMaster));
        $lines[] = self::pair('案件規模', self::val($project->scale)).self::gap().self::pair('営業担当', self::salesOwners($project));
        $lines[] = self::pair('オンラインツール', self::val($project->online_tool)).self::gap().self::pair('配信種別', self::val($project->broadcast));
        $lines[] = self::pair('顧客名（代理店名）', self::clientWithAgency($project));
        $lines[] = self::pair('運営場所', self::val($project->operation_place)).self::gap().self::pair('複数開催', $project->is_multi ? 'あり' : 'なし');
        $lines[] = '集合/解散/拘束時間'.self::gap().self::meetLeaveDuration($project);
        $lines[] = '入場 / 開始 / 終了'.self::gap().self::eventTimes($project);
        $lines[] = self::pair('会場住所（〒なし）', self::val($project->location));
        $lines[] = self::pair('集合形式', self::assembly($project)).self::gap().self::pair('お酒', self::alcohol($project));

        // --- ポジション ---
        $lines[] = '';
        $lines[] = 'ポジション';
        foreach (self::positionLines($assigned) as $line) {
            $lines[] = $line;
        }

        // --- 定型文 ---
        $notice = trim(self::notice());
        if ($notice !== '') {
            $lines[] = '';
            $lines[] = $notice;
        }

        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------
    // 部品
    // ------------------------------------------------------------------

    /** 日付（yymmdd）。開催日が無ければ空。 */
    public static function ymd(Project $project): string
    {
        return $project->start_date ? $project->start_date->format('ymd') : '';
    }

    /** 日程の表示（10月1日(木)）。 */
    public static function dateLabel(Project $project): string
    {
        $d = $project->start_date;
        if (! $d) {
            return '—';
        }

        $d = Carbon::parse($d);

        return $d->format('n').'月'.$d->format('j').'日('.self::WEEKDAYS[$d->dayOfWeek].')';
    }

    /**
     * コンテンツ名。
     * 入力された名前（content_names）をそのまま使い、無ければ content_ids から台帳名を復元する。
     * ⚠ 案件登録と同じ考え方（ProjectController の編集画面と揃える）。
     * 複数あるときは「・」でつなぐ。
     *
     * @param  array<string, string>  $master  コンテンツID → 名前。渡すと台帳を引き直さない。
     */
    public static function contentName(Project $project, array $master = []): string
    {
        $names = [];

        if (is_array($project->content_names) && $project->content_names) {
            $names = array_values(array_filter(array_map('trim', $project->content_names)));
        }

        if (! $names && is_array($project->content_ids) && $project->content_ids) {
            foreach ($project->content_ids as $cid) {
                if (isset($master[$cid])) {
                    $names[] = $master[$cid];
                }
            }

            // 表を渡してもらえなかったときだけ台帳を引く（1件だけ作るときの保険）。
            if (! $names && ! $master) {
                $map = Content::whereIn('id', $project->content_ids)->pluck('content_name', 'id');
                foreach ($project->content_ids as $cid) {
                    if (isset($map[$cid])) {
                        $names[] = $map[$cid];
                    }
                }
            }
        }

        // コンテンツが分からない案件は案件名で代用する（カードの表示と同じ考え方）。
        if (! $names) {
            return trim((string) $project->project_name);
        }

        return implode('・', $names);
    }

    /**
     * 会社名（アイコン・グループ名に使う）。末尾の「様」「御中」だけ外す。
     * ⚠ 短縮はしない（理由はクラスの説明を参照）。
     */
    public static function company(Project $project): string
    {
        $name = trim((string) ($project->client ?? ''));

        // 「様」「御中」が何個付いていても外す（「御中様」のような入力もあるため）。
        while (preg_match('/(様|御中)$/u', $name)) {
            $name = trim(preg_replace('/(様|御中)$/u', '', $name));
        }

        return $name;
    }

    /**
     * 会社名に「様」を付けたもの（グループ名に使う）。
     * すでに「様」「御中」が付いていればそのまま＝「様様」や「御中様」にしない。
     * ⚠ 案件のクライアント名は入れ方がまちまち（様あり・なしが混ざる）ので、
     *   ここで必ず付けてそろえる。会社名が空のときは何も付けない。
     */
    public static function companyWithSama(Project $project): string
    {
        $name = self::company($project);

        return $name === '' ? '' : $name.'様';
    }

    /** 概要文の「顧客名（代理店名）」欄。代理店があれば括弧で添える。 */
    private static function clientWithAgency(Project $project): string
    {
        // ⚠ ここは概要文なので、baba が送っている文面どおり「様」を付けたまま出す
        //   （様を外すのはアイコンとグループ名だけ＝文字数の都合）。
        $client = trim((string) ($project->client ?? ''));
        $agency = trim((string) ($project->agency ?? ''));

        if ($client === '' && $agency === '') {
            return '—';
        }
        if ($agency === '') {
            return $client;
        }
        if ($client === '') {
            return '（'.$agency.'）';
        }

        return $client.'（'.$agency.'）';
    }

    /** 宿泊（無／前泊有 など）。空なら「無」。 */
    private static function lodging(Project $project): string
    {
        $v = trim((string) ($project->lodging ?? ''));

        return $v !== '' ? $v : '無';
    }

    /** 前泊ありか。 */
    private static function hasPreStay(Project $project): bool
    {
        return str_contains((string) ($project->lodging ?? ''), '前泊');
    }

    /** 営業担当（複数なら「・」でつなぐ）。 */
    private static function salesOwners(Project $project): string
    {
        $owners = is_array($project->sales_owners) ? array_filter($project->sales_owners) : [];

        return $owners ? implode('・', $owners) : '—';
    }

    /**
     * 集合／解散／拘束時間。
     * ⚠ LINEグループはスタッフに向けたものなので、スタッフ向けの時間があればそれを使う
     *   （空なら社員の時間＝案件登録画面・スタッフ画面と同じ考え方）。
     */
    private static function meetLeaveDuration(Project $project): string
    {
        $meet = trim((string) ($project->staff_meet_time ?: $project->start_time ?: ''));
        $leave = trim((string) ($project->staff_leave_time ?: $project->end_time ?: ''));

        return implode(self::gap(), [
            $meet !== '' ? $meet : '—',
            $leave !== '' ? $leave : '—',
            self::duration($meet, $leave),
        ]);
    }

    /** 入場／開始／終了。 */
    private static function eventTimes(Project $project): string
    {
        return implode(self::gap(), [
            self::val($project->event_enter_time),
            self::val($project->event_start_time),
            self::val($project->event_end_time),
        ]);
    }

    /**
     * 拘束時間（H:MM）。集合〜解散の差。
     * ⚠ 日をまたぐ案件（夜から翌朝）は、そのままだとマイナスになるので24時間足す。
     */
    public static function duration(string $meet, string $leave): string
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $meet, $m) || ! preg_match('/^(\d{1,2}):(\d{2})$/', $leave, $l)) {
            return '—';
        }

        $mins = ((int) $l[1] * 60 + (int) $l[2]) - ((int) $m[1] * 60 + (int) $m[2]);
        if ($mins < 0) {
            $mins += 24 * 60;
        }
        if ($mins === 0) {
            return '—';
        }

        return intdiv($mins, 60).':'.str_pad((string) ($mins % 60), 2, '0', STR_PAD_LEFT);
    }

    /** 集合形式（詳細があれば添える）。 */
    private static function assembly(Project $project): string
    {
        $type = trim((string) ($project->assembly_type ?? ''));
        $detail = trim((string) ($project->assembly_detail ?? ''));

        if ($type === '' && $detail === '') {
            return '—';
        }
        if ($detail === '') {
            return $type;
        }
        if ($type === '') {
            return $detail;
        }

        return $type.'（'.$detail.'）';
    }

    /** お酒（未入力は「—」＝「なし」と言い切らない）。 */
    private static function alcohol(Project $project): string
    {
        if ($project->alcohol === null) {
            return '—';
        }

        return $project->alcohol ? 'あり' : 'なし';
    }

    /**
     * ポジションの行（「ディレクター @」など）。
     * 割当が2人以上いるポジションは、その人数ぶん行を出す＝何人ぶん打てばよいか分かる。
     *
     * @param  array<int, array<string, mixed>>  $assigned
     * @return array<int, string>
     */
    private static function positionLines(array $assigned): array
    {
        // 役割コード → 人数。兼任（roleCode2）は数えない＝行が二重に出ると人数を読み違えるため。
        $counts = [];
        foreach ($assigned as $a) {
            $code = trim((string) ($a['roleCode'] ?? ''));
            if ($code === '') {
                continue;
            }
            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }

        $lines = [];
        foreach (self::POSITIONS as $pos) {
            $n = $counts[$pos['code']] ?? 0;

            if ($n === 0 && ! $pos['always']) {
                continue;
            }

            foreach (range(1, max(1, $n)) as $ignored) {
                $lines[] = $pos['label'].' @';
            }
        }

        return $lines;
    }

    /** 「ラベル　値」の組。 */
    private static function pair(string $label, string $value): string
    {
        return $label.'　'.$value;
    }

    /** 組と組のあいだの空き（LINEで読みやすいよう全角2つ）。 */
    private static function gap(): string
    {
        return '　　';
    }

    /** 空欄は「—」にして、何も書いていないのか空なのか分かるようにする。 */
    private static function val($value): string
    {
        $v = trim((string) ($value ?? ''));

        return $v !== '' ? $v : '—';
    }

    /** 改行コードを \n にそろえる（コピーした文章に \r が混ざると余計な空行に見える）。 */
    private static function normalizeNewlines(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }
}
