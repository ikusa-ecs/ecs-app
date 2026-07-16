<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Person;
use App\Models\Project;
use App\Models\ShiftPreference;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * 追加のテスト希望者データ（動作確認用）。
 *
 * ねらい：これから開催の各案件に「エントリー（applications）」と「稼働希望（shift_preferences）」を入れ、
 * エントリー一覧・ピックアップ・日別ボードの「希望者／稼働可」を実際に試せるようにする。
 * スタッフは案件ごとに回転させて選ぶ（毎回同じ顔ぶれにならないよう・再実行しても updateOrCreate で重複しない）。
 * ※開発・動作確認用のダミー。本番には入れない。
 */
class TestEntrySeeder extends Seeder
{
    public function run(): void
    {
        $today = Carbon::today();
        $now = Carbon::now();

        // 応募者プール＝スタッフ（ID昇順で固定）。
        $staff = Person::staff()->orderBy('id')->pluck('id')->values()->all();
        $n = count($staff);
        if ($n === 0) {
            return;
        }

        // これから開催・完了/下書き以外の案件（開催日順）。
        $projects = Project::whereNotNull('start_date')
            ->whereNotIn('status', ['完了', '下書き'])
            ->orderBy('start_date')
            ->get()
            ->filter(fn (Project $p) => $p->start_date->gte($today))
            ->values();

        $appCount = 0;
        $prefCount = 0;

        foreach ($projects as $i => $p) {
            $need = (int) ($p->required_count ?: 8);
            // 応募人数＝必要人数より少し多め（ただしスタッフ総数が上限）。最低5名は出す。
            $k = min($n, max(5, $need + 2));
            $offset = ($i * 5) % $n;   // 案件ごとに開始位置をずらす＝顔ぶれを回転させる

            $date = $p->start_date->format('Y-m-d');
            $period = $p->start_date->format('Y-m');

            for ($j = 0; $j < $k; $j++) {
                $sid = $staff[($offset + $j) % $n];
                // 3人に1人を「可（条件付き）」、それ以外は「希望」。
                $isMaybe = ($j % 3 === 0);
                $intent = $isMaybe ? '可' : '希望';

                Application::updateOrCreate(
                    ['staff_id' => $sid, 'project_id' => $p->id],
                    ['intent' => $intent, 'applied_at' => $now],
                );
                $appCount++;

                // 同じ日は1行だけ（unique staff_id+date）。稼働希望も入れて「稼働可/希望」を点灯させる。
                // date は 'date' キャストで時刻付き保存になるため、照合は whereDate（日付部分だけ）で行う
                // ＝ updateOrCreate だと空振りして unique 制約エラーになる（Assignment と同じ罠）。
                $availability = $isMaybe ? '稼働可' : '希望';
                $pref = ShiftPreference::where('staff_id', $sid)->whereDate('date', $date)->first();
                if ($pref) {
                    $pref->update(['period' => $period, 'availability' => $availability]);
                } else {
                    ShiftPreference::create([
                        'staff_id' => $sid, 'date' => $date, 'period' => $period, 'availability' => $availability,
                    ]);
                }
                $prefCount++;
            }
        }

        $this->command?->info("エントリー {$appCount}件・稼働希望 {$prefCount}件を投入（案件 " . $projects->count() . '件）');
    }
}
