<?php

namespace Database\Seeders;

use App\Models\Person;
use App\Models\StaffRelation;
use App\Models\StaffRoleEligibility;
use Illuminate\Database\Seeder;

/**
 * スタッフの「できるポジション（可否）」と「NGペア」の見本データ。
 * モックの people.js の pos / ng を元にしている。※開発・動作確認用のダミー。
 */
class StaffProfileSeeder extends Seeder
{
    public function run(): void
    {
        // できるポジション（people.js の pos が true のものだけ）
        // 役割コード：D=ディレクター / OP=音響 / MC=司会 / FC=巡回ファシリ /
        //             CK=チェッカー / GUN=軍師・サポーター / UKE=受付
        $eligibility = [
            'S-001' => ['D', 'MC', 'FC', 'CK', 'GUN', 'UKE'],
            'S-007' => ['D', 'OP', 'FC', 'CK', 'GUN'],
            'S-003' => ['MC', 'FC', 'CK', 'UKE'],
            'S-027' => ['MC', 'FC', 'CK', 'GUN', 'UKE'],
            'S-009' => ['FC', 'CK', 'UKE'],
            'S-005' => ['FC', 'CK', 'GUN', 'UKE'],
            'S-014' => ['FC', 'CK', 'GUN', 'UKE'],
            'S-018' => ['OP', 'FC', 'CK'],
            'S-021' => ['FC', 'CK', 'UKE'],
            'S-032' => ['FC', 'UKE'],
            'S-035' => ['CK', 'UKE'],
            'S-038' => ['FC', 'CK'],
            'S-041' => ['UKE'],
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
