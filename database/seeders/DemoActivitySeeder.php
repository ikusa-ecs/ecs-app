<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\ShiftPreference;
use Database\Seeders\Concerns\DemoOnly;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * 稼働状況（/staff-status）の動作確認用の見本データ。
 * assignments（アサイン）・shift_preferences（希望）・applications（応募）の
 * 3テーブルに、2026-07を対象月とした現実的なダミーを入れる。
 *
 * ・基準日は 2026-06-24 想定。7月の本番案件にアサインを入れ、ご無沙汰（最終アサインからの
 *   日数）が出るよう6月の過去案件にも一部アサインを入れる。
 * ・updateOrCreate（キー＝各テーブルのunique）で書くので、何度流しても重複せず・他の行も消さない。
 * ・登録は `php artisan db:seed --class=DemoActivitySeeder`（DatabaseSeederには登録しない＝
 *   migrate:fresh時に自動では入らない見本専用シーダー）。
 *
 * 物語（画面の各警告が出るように作意）：
 *   ベテラン上位＝高稼働、松本/伊藤＝4連勤注意、山田＝よく希望を出すのに選ばれず低稼働、
 *   鈴木/佐藤/石川＝今月希望0件で非アクティブ、佐藤＝応募したのに選ばれた率0%。
 */
class DemoActivitySeeder extends Seeder
{
    use DemoOnly;

    public function run(): void
    {
        // 本番など、見本データを入れてはいけない環境では何もしない（安全装置）。
        if ($this->demoBlocked()) {
            return;
        }

        $period = '2026-07';

        // [staff_id, 7月の希望日数, 7月アサイン[[日付,案件ID]], 過去アサイン[[日付,案件ID]], 応募[案件ID]]
        $profiles = [
            ['S-007', 8, [['2026-07-02', 'board'], ['2026-07-03', 'undo_d1'], ['2026-07-04', 'undo_d2'], ['2026-07-05', 'undo_d3'], ['2026-07-07', 'shinkan'], ['2026-07-10', 'konshin']], [['2026-06-23', 'P-2026-0003']], ['board', 'shinkan', 'konshin', 'bousai']],
            ['S-001', 9, [['2026-07-03', 'undo_d1'], ['2026-07-04', 'undo_d2'], ['2026-07-05', 'undo_d3'], ['2026-07-07', 'shinkan'], ['2026-07-10', 'hyosho'], ['2026-07-23', 'bousai']], [['2026-06-17', 'enni_school']], ['undo_d1', 'shinkan', 'bousai']],
            ['S-003', 10, [['2026-07-02', 'board'], ['2026-07-05', 'mizu'], ['2026-07-07', 'shinkan'], ['2026-07-10', 'konshin'], ['2026-07-23', 'bousai']], [['2026-06-13', 'past_fes']], ['board', 'mizu', 'konshin']],
            ['S-027', 8, [['2026-07-04', 'undo_d2'], ['2026-07-05', 'undo_d3'], ['2026-07-10', 'konshin'], ['2026-07-23', 'bousai']], [['2026-06-23', 'P-2026-0003']], ['undo_d2', 'konshin']],
            ['S-009', 7, [['2026-07-02', 'board'], ['2026-07-03', 'undo_d1'], ['2026-07-04', 'undo_d2'], ['2026-07-05', 'undo_d3'], ['2026-07-07', 'shinkan']], [['2026-06-13', 'past_fes']], ['board', 'undo_d1', 'shinkan']],
            ['S-005', 6, [['2026-07-05', 'enni1'], ['2026-07-07', 'shinkan'], ['2026-07-10', 'konshin']], [['2026-06-17', 'enni_school']], ['enni1', 'shinkan', 'konshin', 'bousai']],
            ['S-021', 5, [['2026-07-10', 'hyosho']], [['2026-06-01', 'past_anniv']], ['hyosho', 'bousai']],
            ['S-018', 3, [['2026-07-23', 'bousai']], [['2026-06-01', 'past_anniv']], ['bousai', 'konshin']],
            ['S-014', 0, [], [['2026-06-01', 'past_anniv']], []],
            ['S-032', 0, [], [['2026-06-01', 'past_anniv']], ['bousai']],
            ['S-035', 4, [['2026-07-05', 'mizu'], ['2026-07-10', 'hyosho']], [], ['mizu', 'hyosho', 'bousai']],
            ['S-038', 3, [['2026-07-07', 'shinkan']], [], ['shinkan', 'bousai']],
            ['S-041', 0, [], [], []],
        ];

        foreach ($profiles as [$sid, $want, $assigned, $past, $applied]) {
            // アサイン（7月＋過去）。すべて status=確定 とする。
            foreach (array_merge($assigned, $past) as [$date, $pid]) {
                Assignment::updateOrCreate(
                    ['project_id' => $pid, 'staff_id' => $sid, 'date' => $date],
                    ['status' => '確定', 'assigned_at' => Carbon::parse($date)],
                );
            }

            // 希望日数ぶんの「希望」日を7月に作る。まずアサイン日を含め、足りない分を月初から補う。
            $dates = array_map(fn ($a) => $a[0], $assigned);
            for ($day = 1; count($dates) < $want && $day <= 28; $day++) {
                $d = sprintf('2026-07-%02d', $day);
                if (! in_array($d, $dates, true)) {
                    $dates[] = $d;
                }
            }
            foreach ($dates as $d) {
                ShiftPreference::updateOrCreate(
                    ['staff_id' => $sid, 'date' => $d],
                    ['period' => $period, 'availability' => '希望'],
                );
            }

            // 応募
            foreach ($applied as $pid) {
                Application::updateOrCreate(
                    ['staff_id' => $sid, 'project_id' => $pid],
                    ['intent' => '希望', 'applied_at' => Carbon::parse('2026-06-20')],
                );
            }
        }
    }
}
