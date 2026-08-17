<?php

namespace Database\Seeders;

use App\Models\Project;
use Database\Seeders\Concerns\DemoOnly;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * 案件の見本データ。モックの cases.js を元にしている。
 * 開催日は cases.js の off（今日からの相対日数）から計算してセットする。
 * ディレクター・物品担当は社員の姓から people の ID に解決する。
 * ※開発・動作確認用のダミー。本番には入れない。
 */
class ProjectSeeder extends Seeder
{
    use DemoOnly;

    public function run(): void
    {
        // 本番など、見本データを入れてはいけない環境では何もしない（安全装置）。
        if ($this->demoBlocked()) {
            return;
        }

        $today = Carbon::today();

        // 社員の姓 → people ID（cases.js の dir/goods は姓だけなので解決する）
        $dirMap = [
            '田中' => 'E-001', '鈴木' => 'E-002', '佐藤' => 'E-003',
            '高橋' => 'E-004', '山本' => 'E-005', '中村' => 'E-006',
        ];

        // コンテンツ名（部分一致）→ contents ID
        $contentMap = [
            '運動会' => 'CT-001', '水合戦' => 'CT-002', '縁日' => 'CT-003',
            '懇親会' => 'CT-004', '表彰式' => 'CT-005', '周年式典' => 'CT-006',
            'ボードゲーム' => 'CT-007', 'クイズ' => 'CT-008', '新歓' => 'CT-009',
            '防災フェス' => 'CT-010', 'フェス設営' => 'CT-011', 'ワークショップ' => 'CT-012',
        ];

        // 名前→ID解決（未定・— などは null）
        $person = fn (?string $n) => $dirMap[$n] ?? null;
        // コンテンツ名→ID（見つからなければ その他 CT-099）
        $content = function (string $name) use ($contentMap) {
            foreach ($contentMap as $key => $id) {
                if (mb_strpos($name, $key) !== false) {
                    return $id;
                }
            }
            return 'CT-099';
        };
        // 時刻の正規化（'—'や空は null に）
        $t = fn (?string $v) => in_array($v, ['—', 'ー', '', null], true) ? null : $v;
        // 数値の正規化（数字以外は null に）
        $n = fn ($v) => is_numeric($v) ? (int) $v : null;

        // [id, name, content, client, site(現場種別), category, off, need, dir, goods, place,
        //  meet, leave, enter, evStart, evEnd, format, scale, lodging, recruit, repeat,
        //  dayType, parentId, state, status, yomi, guests, teams, transport, sound,
        //  line, hand, script, archived, draft, note]
        $cases = [
            ['past_anniv', '■■社 周年式典', '周年式典', '■■株式会社', '安定重視', '通常案件', -22, 14, '山本', '鈴木', '東京都港区台場1-1 ■■ホテル 宴会場', '12:00', '20:00', '12:30', '13:00', '19:30', 'イベント東(リアル)', '中型', '無', true, false, '本番', null, 'pub', '確定', '確定', 200, '—', '電車', '会場音響', true, true, true, true, false, ''],
            ['past_fes', '▲▲フェス 設営・運営', '▲▲フェス', '▲▲株式会社', '通常', '通常案件', -10, 12, '田中', '高橋', '東京都江東区有明3-1 ▲▲ホール', '08:00', '18:00', '09:00', '09:30', '17:30', 'イベント東(リアル)', '中型', '無', true, false, '本番', null, 'pub', '確定', '確定', 180, 8, 'IKUSAカー', '会場音響', true, true, true, true, false, ''],
            ['enni_school', '▲▲小学校 縁日', '縁日', '▲▲小学校', '育成', '通常案件', -6, 6, '佐藤', '田中', '千葉県市川市八幡1-1-1 ▲▲小学校', '09:00', '15:00', '09:30', '10:00', '14:30', 'イベント東(リアル)', '中型', '無', true, true, '本番', null, 'pub', '確定', '確定', 90, 6, 'IKUSAカー', 'SANWA', true, true, true, true, false, ''],
            ['board', '☆☆社 ボードゲーム大会', 'ボードゲーム大会', '☆☆株式会社', '通常', '通常案件', 9, 8, '未定', '未定', '（未定）', '09:00', '17:00', '—', '—', '—', 'イベント東(リアル)', '中型', '無', true, false, '本番', null, 'todo', '未着手', 'Cヨミ', '—', '—', 'ー', '会場音響', false, false, false, false, true, ''],
            ['undo_setup', '●●社 運動会 前日設営', '運動会', '●●株式会社', '通常', '通常案件', 9, 4, '佐藤', '山本', '千葉県千葉市美浜区中瀬1-2-3 総合運動公園', '07:00', '18:00', '—', '—', '—', 'イベント東(リアル)', '中型', '無', true, false, '前日設営', 'undo_d1', 'fix', '確定', '確定', '—', '—', 'IKUSAカー', 'クラシックプロ大', true, true, true, false, false, ''],
            ['undo_d1', '●●社 運動会（1日目）', '運動会', '●●株式会社', '体力', '通常案件', 10, 18, '佐藤', '山本', '千葉県千葉市美浜区中瀬1-2-3 総合運動公園', '07:30', '17:30', '09:00', '09:30', '16:30', 'イベント東(リアル)', '大型', '前泊有', true, false, '本番', null, 'pub', '確定', '確定', 300, 12, 'IKUSAカー', 'クラシックプロ大', true, true, true, false, false, ''],
            ['undo_d2', '●●社 運動会（2日目）', '運動会', '●●株式会社', '体力', '通常案件', 11, 18, '佐藤', '山本', '千葉県千葉市美浜区中瀬1-2-3 総合運動公園', '07:30', '17:30', '09:00', '09:30', '16:30', 'イベント東(リアル)', '大型', '無', true, false, '本番', 'undo_d1', 'adj', '調整中', '確定', 300, 12, 'IKUSAカー', 'クラシックプロ大', true, false, false, false, false, ''],
            ['undo_d3', '●●社 運動会（3日目）', '運動会', '●●株式会社', '体力', '通常案件', 12, 18, '佐藤', '山本', '千葉県千葉市美浜区中瀬1-2-3 総合運動公園', '07:30', '17:30', '09:00', '09:30', '16:30', 'イベント東(リアル)', '大型', '無', true, false, '本番', 'undo_d1', 'todo', '未着手', '確定', 300, 12, 'IKUSAカー', 'クラシックプロ大', false, false, false, false, false, ''],
            ['mizu', '〇〇社 水合戦', '水合戦', '〇〇株式会社', '体力', '通常案件', 12, 16, '鈴木', '佐藤', '千葉県柏市柏の葉6-1 〇〇公園（屋外）', '08:00', '17:00', '09:30', '10:00', '16:00', 'イベント東(リアルロング)', '大型', '前泊有', true, true, '本番', null, 'todo', '未着手', '確定', 120, 8, 'IKUSAカー2台', 'クラシックプロ中', false, false, false, false, false, '集合は南口ロータリー。前泊ありのため前日入りの宿手配を要確認。'],
            ['enni1', '□□商店街 縁日', '縁日', '□□商店街振興組合', '育成', '追加案件', 12, 6, '田中', '山本', '東京都台東区浅草2-1 □□商店街 一帯', '10:00', '16:00', '10:30', '11:00', '15:30', 'イベント東北(リアル)', '中型', '前泊有', true, false, '本番', null, 'fix', '確定', 'Bヨミ', 80, 4, 'レンタカー', 'クラシックプロ小', false, false, false, false, false, ''],
            ['shinkan', '△△大学 新歓イベント', '新歓イベント', '△△大学', '安定重視', '通常案件', 14, 20, '高橋', '佐藤', '東京都世田谷区桜上水2-3-4 △△大学 体育館', '09:30', '18:00', '10:00', '10:30', '17:30', 'イベント東(オンライン)', '大型', '無', true, false, '本番', null, 'adj', '調整中', 'Aヨミ', 200, 10, '電車', '不要', true, true, false, false, false, ''],
            ['shinkan_yobi', '△△大学 新歓イベント（予備日）', '新歓イベント', '△△大学', '安定重視', '通常案件', 16, 20, '高橋', '佐藤', '東京都世田谷区桜上水2-3-4 △△大学 体育館', '09:30', '18:00', '10:00', '10:30', '17:30', 'イベント東(オンライン)', '大型', '無', true, false, '予備日', 'shinkan', 'todo', '未着手', 'Aヨミ', 200, 10, '電車', '不要', false, false, false, false, false, ''],
            ['konshin', '◇◇社 懇親会運営', '懇親会', '◇◇株式会社', '通常', '通常案件', 17, 8, '未定', '未定', '東京都港区六本木6-1-1 ARENA', '16:00', '21:00', '17:00', '18:00', '20:30', 'イベント東(リアル)', '中型', '無', true, false, '本番', null, 'todo', '未着手', 'Cヨミ', 60, '—', 'ー', '会場音響', false, false, false, false, false, ''],
            ['hyosho', '☆☆社 表彰式', '表彰式', '☆☆株式会社', '通常', '追加案件', 17, 10, '山本', '鈴木', '東京都千代田区丸の内1-1 グランドホテル', '15:00', '20:00', '15:30', '16:00', '19:30', 'イベント東(リアル)', '大型', '無', true, false, '本番', null, 'todo', '調整中', 'Aヨミ', 150, 8, '電車+レンタカー', '会場音響', true, false, true, false, false, ''],
            ['fes_reha', '◎◎ フェス設営リハ', 'フェス設営リハ', '◎◎実行委員会', '通常', '通常案件', 18, 8, '鈴木', '高橋', '東京都立川市曙町2-1 市民広場', '09:00', '15:00', '—', '—', '—', 'イベント東(リアル)', '中型', '無', true, false, 'リハ', 'fes_setup', 'adj', '調整中', '確定', '—', '—', '電車', '会場音響', true, false, false, false, false, ''],
            ['mizu_yobi', '〇〇社 水合戦（予備日）', '水合戦', '〇〇株式会社', '体力', '通常案件', 19, 8, '鈴木', '佐藤', '千葉県柏市柏の葉6-1 〇〇公園（屋外）', '08:00', '17:00', '09:30', '10:00', '16:00', 'イベント東(リアルロング)', '大型', '前泊有', true, true, '予備日', 'mizu', 'todo', '未着手', '確定', 120, 8, 'IKUSAカー2台', 'クラシックプロ中', false, false, false, false, false, ''],
            ['bousai', '◇◇市 防災フェス', '防災フェス', '◇◇市役所', '体力', '通常案件', 30, 20, '未定', '高橋', '千葉県柏市柏5-10 市役所前広場', '08:00', '16:00', '09:00', '09:30', '15:30', 'イベント東(リアル)', '大型', '前泊有', true, false, '本番', null, 'todo', '未着手', 'Bヨミ', 500, '—', 'IKUSAカー', 'クラシックプロ大', false, false, false, false, false, ''],
            ['quiz', '大阪◇◇社 クイズ大会', 'クイズ大会', '大阪◇◇株式会社', '通常', '通常案件', 40, 6, '未定', '未定', '大阪府大阪市北区梅田3-2-1 △△ホール', '12:00', '18:00', '13:00', '13:30', '17:00', 'イベント他拠点(ヘルプのみ)', '中型', '無', true, false, '本番', null, 'todo', '未着手', 'Bヨミ', 100, 6, '電車', '会場音響', false, false, false, false, false, ''],
            ['fes_setup', '◎◎ フェス設営', 'フェス設営', '◎◎実行委員会', '通常', '通常案件', 58, 12, '田中', '高橋', '東京都立川市曙町2-1 市民広場', '07:00', '12:00', '—', '—', '—', 'イベント東(リアル)', '中型', '前泊有', true, false, '本番', null, 'fix', '確定', '確定', '—', '—', 'IKUSAカー', '不要', true, true, true, false, false, ''],
        ];

        foreach ($cases as $c) {
            [$id, $name, $contentName, $client, $site, $category, $off, $need, $dir, $goods, $place,
             $meet, $leave, $enter, $evStart, $evEnd, $format, $scale, $lodging, $recruit, $repeat,
             $dayType, $parentId, $state, $status, $yomi, $guests, $teams, $transport, $sound,
             $line, $hand, $script, $archived, $draft, $note] = $c;

            // ステータス：下書き・完了を優先表示
            $statusLabel = $draft ? '下書き' : ($archived ? '完了' : $status);

            Project::updateOrCreate(['id' => $id], [
                'project_name' => $name,
                'content_ids' => [$content($contentName)],
                'client' => $client,
                'site_category' => $site,
                'category' => $category,
                'start_date' => $today->copy()->addDays($off),
                'required_count' => $need,
                'director_id' => $person($dir),
                'goods_owner_id' => $person($goods),
                'location' => $place,
                'start_time' => $t($meet),
                'end_time' => $t($leave),
                'event_enter_time' => $t($enter),
                'event_start_time' => $t($evStart),
                'event_end_time' => $t($evEnd),
                'format' => $format,
                'scale' => $scale,
                'lodging' => $lodging,
                'is_recruiting' => $recruit,
                'is_repeat' => $repeat,
                'date_type' => $dayType,
                'parent_project_id' => $parentId,
                'yomi' => $yomi,
                'status' => $statusLabel,
                'staff_published' => ($state === 'pub' && ! $archived),
                'guest_count' => $n($guests),
                'team_count' => $n($teams),
                'transport' => $t($transport),
                'audio_equipment' => $sound,
                'prep_line_sent' => $line,
                'prep_handover' => $hand,
                'prep_script' => $script,
                'note' => $note,
            ]);
        }
    }
}
