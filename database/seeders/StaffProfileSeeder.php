<?php

namespace Database\Seeders;

use App\Models\Person;
use App\Models\StaffRelation;
use App\Models\StaffRoleEligibility;
use Database\Seeders\Concerns\DemoOnly;
use Illuminate\Database\Seeder;

/**
 * スタッフの「できるポジション（可否）」と「NGペア」の見本データ。
 * モックの people.js の pos / ng を元にしている。※開発・動作確認用のダミー。
 */
class StaffProfileSeeder extends Seeder
{
    use DemoOnly;

    public function run(): void
    {
        // 本番など、見本データを入れてはいけない環境では何もしない（安全装置）。
        if ($this->demoBlocked()) {
            return;
        }

        // できるポジション（people.js の pos が true のものだけ）
        // 役割コード：D=ディレクター / OP=音響 / MC=司会 / FC=巡回ファシリ /
        //             CK=チェッカー / SP=軍師・サポーター / RP=受付
        $eligibility = [
            'S-001' => ['D', 'MC', 'FC', 'CK', 'SP', 'RP'],
            'S-007' => ['D', 'OP', 'FC', 'CK', 'SP'],
            'S-003' => ['MC', 'FC', 'CK', 'RP'],
            'S-027' => ['MC', 'FC', 'CK', 'SP', 'RP'],
            'S-009' => ['FC', 'CK', 'RP'],
            'S-005' => ['FC', 'CK', 'SP', 'RP'],
            'S-014' => ['FC', 'CK', 'SP', 'RP'],
            'S-018' => ['OP', 'FC', 'CK'],
            'S-021' => ['FC', 'CK', 'RP'],
            'S-032' => ['FC', 'RP'],
            'S-035' => ['CK', 'RP'],
            'S-038' => ['FC', 'CK'],
            'S-041' => ['RP'],
        ];

        foreach ($eligibility as $staffId => $positions) {
            foreach ($positions as $pos) {
                StaffRoleEligibility::updateOrCreate(
                    ['staff_id' => $staffId, 'position' => $pos],
                    []
                );
            }
        }

        // NGペア（people.js の ng）。相手が登録済みなら ID も付ける。
        $ngPairs = [
            'S-001' => ['佐々木 涼'],   // 未登録の人（IDなし）
            'S-018' => ['池田 莉子'],   // = S-035
            'S-035' => ['木村 拓海'],   // = S-018
        ];

        foreach ($ngPairs as $staffId => $partners) {
            foreach ($partners as $partnerName) {
                $partner = Person::where('name', $partnerName)->first();
                StaffRelation::updateOrCreate(
                    ['staff_id' => $staffId, 'partner_name' => $partnerName],
                    ['partner_id' => $partner?->id, 'relation_type' => 'NG']
                );
            }
        }
    }
}
