<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * コンテンツの「種類（リアル／オンライン）」と「分類（謎解き脱出・合戦系 など）」（2026-11-11 小沼さん）。
 *
 * もとにしたもの＝スプシ「【管理】マニュアル・台本雛形/イベントシステムURL情報 運営シート」の
 *   「リアルイベント関連資料」「オンラインイベント関連資料」タブの左の分類（小沼さん「スプシをもとにしてほしい」）。
 * 保存先＝contents.kind / contents.genre。台帳（/masters）はこの順に まとめて並べる。
 * ⚠ 台帳にもともとある contents.category（盛り上げ系・真面目系）は別物。消さない・ここでは使わない。
 */
class ContentGenre
{
    public const REAL = 'リアル';

    public const ONLINE = 'オンライン';

    public const KINDS = [self::REAL, self::ONLINE];

    /** 分類の並び（スプシの上から順）。ここに無い分類は各種類の最後に名前順で並ぶ。 */
    public const GENRES = [
        self::REAL => ['謎解き脱出', '周遊謎', '合戦系', '運動会', 'WS', '研修系', '謎パ', 'ハイブリッド', 'イマミス',
            '探偵チームビルディング', 'リアルコンセンサス', 'ビジネスパーティー', 'お化け屋敷'],
        self::ONLINE => ['リモ謎', 'リモBako', 'リモ探', 'コンセンサス', 'オンライン運動会', 'おうち防災', 'クイズ&ビンゴ',
            'ハイブリッド', '謎パ', 'SDGS', 'フードエンタメ'],
    ];

    /**
     * 本番の台帳に最初に入れる分（2026-11-11 本番の台帳123件とスプシを突き合わせた結果）。
     * id => [台帳の名前, 種類, 分類]。名前が一致したときだけ入れる（手元のDBはIDの中身が違うことがあるため）。
     * スプシに無いもの（式典運営・社員総会 など）は入れない＝台帳で「未分類」に出るので、画面で選ぶ。
     */
    public const INITIAL = [
        'CT-001' => ['会議室', self::REAL, '謎解き脱出'], 'CT-002' => ['秘密会議', self::REAL, '謎解き脱出'],
        'CT-003' => ['本能寺', self::REAL, '謎解き脱出'], 'CT-004' => ['魔法学校', self::REAL, '謎解き脱出'],
        'CT-005' => ['先が見えない防災訓練', self::REAL, '謎解き脱出'], 'CT-006' => ['捜査会議', self::REAL, '謎解き脱出'],
        'CT-007' => ['ミッションスクランブル', self::REAL, '謎解き脱出'], 'CT-008' => ['学校脱出', self::REAL, '謎解き脱出'],
        'CT-009' => ['シークレットレター', self::REAL, '謎解き脱出'],
        'CT-010' => ['宝探し', self::REAL, '周遊謎'], 'CT-011' => ['SDGsナゾトキ探検隊', self::REAL, '周遊謎'],
        'CT-012' => ['災害都市からの大脱出', self::REAL, '周遊謎'], 'CT-013' => ['25HUNT', self::REAL, '周遊謎'],
        'CT-014' => ['ヒラメキクエスト', self::REAL, '周遊謎'],
        'CT-015' => ['チャンバラ', self::REAL, '合戦系'], 'CT-016' => ['OODAチャンバラ', self::REAL, '合戦系'],
        'CT-017' => ['城攻め', self::REAL, '合戦系'], 'CT-018' => ['水合戦', self::REAL, '合戦系'],
        'CT-019' => ['忍者合戦', self::REAL, '合戦系'], 'CT-023' => ['ミッションラッシュ', self::REAL, '合戦系'],
        'CT-020' => ['戦国運動会', self::REAL, '運動会'], 'CT-021' => ['防災運動会', self::REAL, '運動会'],
        'CT-022' => ['NEW運動会', self::REAL, '運動会'], 'CT-024' => ['HADO', self::REAL, '運動会'],
        'CT-112' => ['運動会', self::REAL, '運動会'],
        'CT-025' => ['防災ヒーロー', self::REAL, 'WS'], 'CT-026' => ['防災クイズ', self::REAL, 'WS'],
        'CT-027' => ['戦国WS', self::REAL, 'WS'], 'CT-028' => ['SDGsAD', self::REAL, 'WS'],
        'CT-029' => ['キッズタウンビルダーズ', self::REAL, 'WS'], 'CT-161' => ['科学ワークショップ', self::REAL, 'WS'],
        'CT-201' => ['オリジナルWS', self::REAL, 'WS'],
        'CT-030' => ['SDGsWL', self::REAL, '研修系'], 'CT-031' => ['ロケットPDCA', self::REAL, '研修系'],
        'CT-032' => ['サバ研', self::REAL, '研修系'], 'CT-033' => ['マダミス（青い月）', self::REAL, '研修系'],
        'CT-034' => ['マダミス（赤いトランク）', self::REAL, '研修系'], 'CT-038' => ['カイジ', self::REAL, '研修系'],
        'CT-052' => ['AI研修', self::REAL, '研修系'], 'CT-158' => ['グレートチーム', self::REAL, '研修系'],
        'CT-035' => ['謎パ', self::REAL, '謎パ'], 'CT-036' => ['電脳謎パ', self::REAL, '謎パ'],
        'CT-037' => ['ハイブリット', self::REAL, 'ハイブリッド'],
        'CT-039' => ['大軍議', self::REAL, '探偵チームビルディング'], 'CT-040' => ['消えた資料', self::REAL, '探偵チームビルディング'],
        'CT-041' => ['ジャンサバ', self::REAL, 'リアルコンセンサス'], 'CT-042' => ['帰宅困難', self::REAL, 'リアルコンセンサス'],
        'CT-043' => ['ゾンビパンデミック', self::REAL, 'リアルコンセンサス'],
        'CT-044' => ['ゴチ', self::REAL, 'ビジネスパーティー'], 'CT-045' => ['格付け', self::REAL, 'ビジネスパーティー'],
        'CT-047' => ['BBQ', self::REAL, 'ビジネスパーティー'], 'CT-048' => ['オール社員感謝祭', self::REAL, 'ビジネスパーティー'],
        'CT-049' => ['ケータリング', self::REAL, 'ビジネスパーティー'], 'CT-051' => ['キングオブラスベガス', self::REAL, 'ビジネスパーティー'],
        'CT-105' => ['キラナガーデンBBQ', self::REAL, 'ビジネスパーティー'], 'CT-109' => ['クイズ大会', self::REAL, 'ビジネスパーティー'],
        'CT-144' => ['マグロ解体ショー', self::REAL, 'ビジネスパーティー'], 'CT-151' => ['縁日', self::REAL, 'ビジネスパーティー'],
        'CT-167' => ['懇親会', self::REAL, 'ビジネスパーティー'], 'CT-174' => ['クルージング', self::REAL, 'ビジネスパーティー'],
        'CT-149' => ['ひきこさん', self::REAL, 'お化け屋敷'], 'CT-206' => ['お化け屋敷(ピエロ)', self::REAL, 'お化け屋敷'],
        'CT-063' => ['【オンライン】電脳都市', self::ONLINE, 'リモ謎'], 'CT-064' => ['【オンライン】電脳light', self::ONLINE, 'リモ謎'],
        'CT-065' => ['【オンライン】電脳英語', self::ONLINE, 'リモ謎'], 'CT-066' => ['【オンライン】電脳short', self::ONLINE, 'リモ謎'],
        'CT-067' => ['【オンライン】本能寺(忍者無)', self::ONLINE, 'リモ謎'], 'CT-068' => ['【オンライン】本能寺(忍者有)', self::ONLINE, 'リモ謎'],
        'CT-069' => ['【オンライン】リモ会', self::ONLINE, 'リモ謎'], 'CT-070' => ['【オンライン】リモ会light', self::ONLINE, 'リモ謎'],
        'CT-071' => ['【オンライン】リモ会short', self::ONLINE, 'リモ謎'], 'CT-072' => ['【オンライン】電脳迷宮', self::ONLINE, 'リモ謎'],
        'CT-073' => ['【オンライン】崩れゆく', self::ONLINE, 'リモ謎'], 'CT-074' => ['【オンライン】崩れゆくshort', self::ONLINE, 'リモ謎'],
        'CT-075' => ['【オンライン】ふたパラ', self::ONLINE, 'リモ謎'], 'CT-202' => ['リモ謎', self::ONLINE, 'リモ謎'],
        'CT-076' => ['【オンライン】消えた資料', self::ONLINE, 'リモ探'], 'CT-077' => ['【オンライン】消えた資料light', self::ONLINE, 'リモ探'],
        'CT-078' => ['【オンライン】大軍議', self::ONLINE, 'リモ探'],
        'CT-079' => ['【オンライン】ジャンサバ', self::ONLINE, 'コンセンサス'], 'CT-080' => ['【オンライン】帰宅困難', self::ONLINE, 'コンセンサス'],
        'CT-081' => ['【オンライン】ゾンビパンデミック', self::ONLINE, 'コンセンサス'],
        'CT-082' => ['【オンライン】リモリンピック', self::ONLINE, 'オンライン運動会'], 'CT-083' => ['【オンライン】ビジトレ', self::ONLINE, 'オンライン運動会'],
        'CT-084' => ['【オンライン】おうち防災', self::ONLINE, 'おうち防災'], 'CT-085' => ['【オンライン】おうち防災（ファミリー）', self::ONLINE, 'おうち防災'],
        'CT-086' => ['【オンライン】クイズ', self::ONLINE, 'クイズ&ビンゴ'], 'CT-087' => ['【オンライン】ビンゴ', self::ONLINE, 'クイズ&ビンゴ'],
        'CT-088' => ['【オンライン】謎パ', self::ONLINE, '謎パ'], 'CT-089' => ['【オンライン】ある惑星からのSOS', self::ONLINE, 'SDGS'],
        'CT-090' => ['【オンライン】ゴチ', self::ONLINE, 'フードエンタメ'], 'CT-091' => ['【オンライン】格付け', self::ONLINE, 'フードエンタメ'],
    ];

    /**
     * 台帳の並び：種類（リアル→オンライン→未分類）→ 分類（スプシの順）→ 今までの並び順。
     * 戻り値＝[['kind' => 'リアル', 'genre' => '謎解き脱出', 'items' => Collection], …]
     */
    public static function group(Collection $contents): array
    {
        $kindRank = fn ($k) => ($i = array_search($k, self::KINDS, true)) === false ? 9 : $i;
        $genreRank = function ($k, $g) {
            $i = array_search($g, self::GENRES[$k] ?? [], true);

            return $i === false ? 99 : $i;
        };
        $groups = [];
        foreach ($contents as $c) {
            $k = in_array($c->kind, self::KINDS, true) ? $c->kind : '';
            $g = $k === '' ? '' : (string) ($c->genre ?? '');
            $groups[$k.'|'.$g]['kind'] = $k;
            $groups[$k.'|'.$g]['genre'] = $g;
            $groups[$k.'|'.$g]['items'][] = $c;
        }
        uasort($groups, function ($a, $b) use ($kindRank, $genreRank) {
            return [$kindRank($a['kind']), $a['genre'] === '' ? 1 : 0, $genreRank($a['kind'], $a['genre']), $a['genre']]
                <=> [$kindRank($b['kind']), $b['genre'] === '' ? 1 : 0, $genreRank($b['kind'], $b['genre']), $b['genre']];
        });

        return array_values(array_map(fn ($g) => array_merge($g, ['items' => collect($g['items'])]), $groups));
    }

    /** 分類の候補（入力欄の datalist 用）。 */
    public static function genreOptions(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::GENRES))));
    }
}
