<?php

namespace Database\Seeders;

use App\Models\Person;
use App\Models\StaffRoleEligibility;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * 動作確認・デモ用のテストスタッフを増やす（希望者の顔ぶれを多様にする）。
 *
 * 既存スタッフ(S-001〜S-041)と重ならない S-101〜S-120 で追加。
 * できる役割・事務所・入社日(=区分)・経験回数にバラつきを持たせ、
 * エントリー一覧／ピックアップ／日別ボードの候補が豊かに見えるようにする。
 * ※開発・デモ用のダミー。本番には入れない。追加後に TestEntrySeeder を回すと希望者にも載る。
 */
class TestStaffSeeder extends Seeder
{
    public function run(): void
    {
        $today = Carbon::today();

        $names = [
            '春日 悠真', '小林 結衣', '中村 陽向', '加藤 莉子', '吉田 湊',
            '山本 美月', '佐々木 大和', '山口 芽依', '松田 颯太', '井口 咲良',
            '藤井 蓮', '岡本 楓', '長谷川 樹', '森 心春', '池田 悠斗',
            '橋本 杏', '石川 律', '前田 紬', '後藤 朝陽', '村上 陽菜',
        ];
        $offices = ['東京', '東京', '大阪', '名古屋', '福岡', '北海道', '東北'];

        // できる役割の型（誰でもFC/CKはできる前提なので、変化を付けるのは D/MC/OP/SP）。
        // 各要素＝そのスタッフに付ける役割コードの配列。必ず FC/CK を含める。
        $rolePatterns = [
            ['FC', 'CK', 'OP', 'MC', 'SP'],
            ['FC', 'CK', 'MC', 'SP'],
            ['FC', 'CK', 'OP', 'SP'],
            ['FC', 'CK', 'MC', 'RP'],
            ['FC', 'CK', 'SP', 'RP'],
            ['FC', 'CK', 'OP'],
            ['FC', 'CK', 'MC'],
            ['FC', 'CK', 'SP'],
            ['FC', 'CK', 'D', 'MC', 'OP', 'SP'], // D もできる（少数）
        ];

        // 入社日パターン（区分＝在籍年数で新人/中堅/ベテランに散らす）。
        $hireOffsets = [3, 8, 14, 26, 40, 60]; // 何か月前に入社したか

        $created = 0;
        for ($i = 0; $i < count($names); $i++) {
            $n = $i + 101;                         // S-101〜
            $id = 'S-' . $n;
            if (Person::whereKey($id)->exists()) {
                continue;                          // 再実行しても重複作成しない
            }

            $roles = $rolePatterns[$i % count($rolePatterns)];
            $hireOff = $hireOffsets[$i % count($hireOffsets)];
            // OPができる人だけ「オンライン/リアル」の別を散らす（B案）。
            $opOnline = in_array('OP', $roles, true) ? ($i % 2 === 0) : null;
            $opReal = in_array('OP', $roles, true) ? ($i % 3 !== 0) : null;

            Person::create([
                'id' => $id,
                'name' => $names[$i],
                'email' => 'teststaff' . $n . '@ecs.local',
                'role' => 'staff',
                'permission' => 'staff',
                'office' => $offices[$i % count($offices)],
                'hire_date' => $today->copy()->subMonths($hireOff)->format('Y-m-d'),
                'experience_count' => ($i * 7) % 60,        // 0〜59 でばらつき
                'is_exclusive' => ($i % 5 === 0),           // 5人に1人は専属
                'op_online' => $opOnline,
                'op_real' => $opReal,
                'active' => true,
                'must_onboard' => false,
            ]);

            // できる役割（重複除去して入れる）。
            foreach (array_unique($roles) as $pos) {
                StaffRoleEligibility::create(['staff_id' => $id, 'position' => $pos]);
            }
            $created++;
        }

        $this->command?->info("テストスタッフを {$created}名 追加（S-101〜）。希望者に載せるには db:seed --class=TestEntrySeeder を実行。");
    }
}
