<?php

namespace App\Support;

use App\Models\Content;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

/**
 * カレンダーの予定名（タイトル）の付け方＝命名規則の正本（2026-08-27 baba要望）。
 *
 * 【なぜ設定にするか】
 * ⚠ 命名規則はコードに書かない。書くと、ルールが変わるたびに開発を頼む必要が出る。
 *   差し込みタグでテンプレートを組み、共通設定の画面から直せるようにする。
 *
 * 【使えるタグ】
 *   {日付} {月日} {コンテンツ} {コンテンツ略} {顧客名} {顧客名様} {お客様人数} {拠点} {拠点略}
 *   {実施形態} {運営人数} {イベント時間} {集合解散} {D} {D姓} {営業担当} {営業担当姓}
 *   {案件ID} {区分} {確度}
 *
 * ⚠ 会場の住所は**予定名に入れない**。カレンダーの予定には「場所」欄があるので、そこに入れる。
 *
 * ⚠ 値が空のタグは**そのタグごと消える**（「（）」や「_」が浮かないよう、
 *   前後の区切り文字もあわせて片付ける）。空の括弧が並ぶ予定名は読みにくいため。
 */
final class CalendarTitle
{
    /** settings の保存キー。 */
    public const KEY = 'calendar_title_template';

    /**
     * 既定のテンプレート＝いま実際に使っている命名規則（2026-08-27 baba提供）。
     *   例）【確定東】会議室_コニカミノルタジャパン様45名_1540-1740_横山
     */
    public const DEFAULT = '【{確度}{拠点略}】{コンテンツ略}_{顧客名様}{お客様人数}_{イベント時間}_{営業担当姓}';

    /** 画面の説明に出すタグの一覧（タグ => 何が入るか）。 */
    public const TAGS = [
        '{日付}' => '2026-09-01',
        '{月日}' => '9/1',
        '{コンテンツ}' => '会議室（正式名）',
        '{コンテンツ略}' => '会議室（コンテンツ台帳の略称。空なら正式名）',
        '{顧客名}' => 'コニカミノルタジャパン（ECSは「様」を外して保存しています）',
        '{顧客名様}' => 'コニカミノルタジャパン様（「様」を付け直したもの）',
        '{お客様人数}' => '45名',
        '{拠点}' => '東京',
        '{拠点略}' => '東（拠点名の頭1文字）',
        '{実施形態}' => 'リアル',
        '{運営人数}' => '8名',
        '{イベント時間}' => '1540-1740（イベント開始〜終了）',
        '{集合解散}' => '1400-1900（社員の集合〜解散）',
        '{D}' => '田中 健一',
        '{D姓}' => '田中（姓だけ）',
        '{営業担当}' => '梅津 多威士',
        '{営業担当姓}' => '梅津（姓だけ）',
        '{案件ID}' => 'P-2026-0001',
        '{区分}' => '追加案件',
        '{確度}' => '確定 / Aヨミ など',
    ];

    /**
     * 拠点名 → 予定名に出す短い形。
     * ⚠ 「頭1文字」で作ると東京と東北がどちらも「東」になってしまうので、ここで決め打ちする。
     *   拠点が増えたらここに1行足す（載っていない拠点は頭1文字を使う）。
     */
    private const OFFICE_SHORT = [
        '東京' => '東',
        '名古屋' => '名',
        '東北' => '北',
    ];

    /** いまのテンプレート（未設定なら既定）。 */
    public static function template(): string
    {
        $t = trim((string) Setting::get(self::KEY, ''));

        return $t !== '' ? $t : self::DEFAULT;
    }

    /** テンプレートを保存する（空＝既定に戻す）。 */
    public static function putTemplate(string $template): void
    {
        Setting::put(self::KEY, trim($template));
    }

    /** その案件の予定名を作る。 */
    public static function for(Project $project, ?string $template = null): string
    {
        $values = self::valuesOf($project);
        $title = $template ?? self::template();

        foreach ($values as $tag => $value) {
            $title = self::replaceTag($title, $tag, $value);
        }

        // 置き換えたあとに残った区切り文字のゴミを片付ける。
        $title = preg_replace('/[（(]\s*[）)]/u', '', (string) $title);      // 空の括弧
        $title = preg_replace('/[_\/\-]{2,}/u', '_', (string) $title);        // 連続した区切り
        $title = preg_replace('/\s{2,}/u', ' ', (string) $title);             // 連続した空白
        // ⚠ trim($title, " 　_/-") と書かないこと。trim は**バイト単位**で削るため、
        //   全角スペース（E3 80 80）を渡すと「【」（E3 80 90）の先頭バイトまで削れて文字が壊れる
        //   （2026-08-27 にテストが検出）。日本語を含む前後の掃除は必ず preg_replace の /u で行う。
        $title = (string) preg_replace('/^[\s　_\/\-]+|[\s　_\/\-]+$/u', '', (string) $title);

        // 全部空なら、せめて何の案件か分かるようにする（無題の予定を作らない）。
        return $title !== '' ? $title : ($project->project_name ?: (string) $project->id);
    }

    /**
     * タグ1つを置き換える。値が空なら、タグの直前・直後にある区切り文字も一緒に消す。
     * ⚠ これをしないと「9/1 _水合戦（）」のような読みにくい予定名になる。
     */
    private static function replaceTag(string $title, string $tag, string $value): string
    {
        if ($value !== '') {
            return str_replace($tag, $value, $title);
        }

        $quoted = preg_quote($tag, '/');

        // 「（{タグ}）」のように括弧で包まれている場合は括弧ごと消す。
        $title = (string) preg_replace('/[（(]\s*'.$quoted.'\s*[）)]/u', '', $title);
        // 前後の区切り文字（_ / - と空白）とセットで消す。
        $title = (string) preg_replace('/\s*[_\/\-]?\s*'.$quoted.'\s*[_\/\-]?\s*/u', ' ', $title);

        return $title;
    }

    /**
     * タグ => その案件の値。
     * ⚠ 値の作り方はここ1か所。画面やテンプレートごとに作り方を変えないこと。
     *
     * @return array<string, string>
     */
    private static function valuesOf(Project $project): array
    {
        $date = $project->start_date;
        $contents = is_array($project->content_names) ? $project->content_names : [];
        $sales = is_array($project->sales_owners) ? $project->sales_owners : [];

        $client = trim((string) ($project->client ?? ''));
        $office = trim((string) ($project->office ?? ''));
        $director = trim((string) ($project->director->name ?? ''));
        $salesOwner = trim((string) ($sales[0] ?? ''));

        return [
            '{日付}' => $date ? $date->format('Y-m-d') : '',
            '{月日}' => $date ? ($date->month.'/'.$date->day) : '',
            // コンテンツが複数あれば「・」でつなぐ（案件名と同じつなぎ方）。
            '{コンテンツ}' => self::contentNames($project, short: false),
            // 略称（コンテンツ台帳の short_name）。空のコンテンツは正式名を使う。
            '{コンテンツ略}' => self::contentNames($project, short: true),
            '{顧客名}' => $client,
            // ⚠ ECSは末尾の「様・御中」を外して保存している（同じお客様が別々に数えられないように）。
            //   予定名では付け直す。すでに付いている場合は二重にしない。
            '{顧客名様}' => $client === '' ? '' : (preg_match('/(様|御中)$/u', $client) ? $client : $client.'様'),
            '{お客様人数}' => $project->guest_count ? $project->guest_count.'名' : '',
            '{拠点}' => $office,
            '{拠点略}' => self::officeShort($office),
            '{実施形態}' => (string) ($project->format ?? ''),
            // 「6〜8名」のような範囲もそのまま出せる形にする（正本＝Headcount）。
            '{運営人数}' => self::headcount($project),
            '{イベント時間}' => self::span($project->event_start_time, $project->event_end_time),
            '{集合解散}' => self::span($project->start_time, $project->end_time),
            '{D}' => $director,
            '{D姓}' => self::familyName($director),
            '{営業担当}' => $salesOwner,
            '{営業担当姓}' => self::familyName($salesOwner),
            '{案件ID}' => (string) $project->id,
            '{区分}' => (string) ($project->category ?? ''),
            '{確度}' => (string) ($project->yomi ?? ''),
        ];
    }

    /**
     * その案件のコンテンツ名（複数あれば「・」でつなぐ＝案件名と同じつなぎ方）。
     *
     * @param  bool  $short  true＝コンテンツ台帳の略称を使う（略称が空のものは正式名）
     */
    private static function contentNames(Project $project, bool $short): string
    {
        $names = is_array($project->content_names) ? $project->content_names : [];
        $names = array_values(array_filter(array_map('trim', $names), fn ($n) => $n !== ''));

        if ($names === []) {
            return (string) $project->project_name;
        }

        if ($short) {
            // ⚠ 略称の正本はコンテンツ台帳（contents.short_name）。ここに変換表を持たない。
            //   台帳に無い名前（単発コンテンツ）はそのまま使う。
            $map = self::shortNameMap($names);
            $names = array_map(fn ($n) => $map[$n] ?? $n, $names);
        }

        return implode('・', $names);
    }

    /**
     * コンテンツ名 => 略称（略称が入っているものだけ）。
     * ⚠ 1回のクエリでまとめて引く（コンテンツごとに引かない）。
     *
     * @param  list<string>  $names
     * @return array<string, string>
     */
    private static function shortNameMap(array $names): array
    {
        if (! Schema::hasColumn('contents', 'short_name')) {
            // まだ migrate していないサーバーでも予定名が作れるようにする（正式名になるだけ）。
            return [];
        }

        return Content::whereIn('content_name', $names)
            ->whereNotNull('short_name')
            ->where('short_name', '!=', '')
            ->pluck('short_name', 'content_name')
            ->all();
    }

    /** 拠点名 → 予定名に出す短い形（載っていない拠点は頭1文字）。 */
    private static function officeShort(string $office): string
    {
        if ($office === '') {
            return '';
        }

        return self::OFFICE_SHORT[$office] ?? mb_substr($office, 0, 1);
    }

    /**
     * 「15:40」「17:40」→「1540-1740」。片方でも空なら空文字（タグごと消える）。
     * ⚠ 「未定」のような文字が入っていることがあるので、数字が取れないときは空にする。
     */
    private static function span(?string $from, ?string $to): string
    {
        $a = self::hhmm($from);
        $b = self::hhmm($to);

        return ($a !== '' && $b !== '') ? $a.'-'.$b : '';
    }

    /** 「15:40」→「1540」。数字が4つ取れなければ空文字。 */
    private static function hhmm(?string $time): string
    {
        $digits = preg_replace('/[^0-9]/u', '', (string) $time);

        return (is_string($digits) && strlen($digits) === 4) ? $digits : '';
    }

    /**
     * 「田中 健一」→「田中」。空白で区切られた最初のかたまり。
     * ⚠ 空白が無い名前（「桑江常義」など）はそのまま返す＝勘で切らない
     *   （どこまでが姓かは分からないため）。
     */
    private static function familyName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $parts = preg_split('/[\s　]+/u', $name) ?: [];

        return count($parts) > 1 ? (string) $parts[0] : $name;
    }

    /** 運営人数（「6〜8名」のような範囲も出せる）。 */
    private static function headcount(Project $project): string
    {
        $label = Headcount::label($project->required_count_min, $project->required_count);

        return ($label === '' || $label === '—') ? '' : $label.'名';
    }
}
