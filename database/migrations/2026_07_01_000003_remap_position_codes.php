<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 役割コードの作り替え（2026-07-01 baba確定の正式定義に合わせる）。
 *   UKE → RP（受付→レセプション）
 *   GUN → SP（軍師・サポーター→サポート）
 *   CK  → ET（チェッカーは廃止。得点係などと同じ「その他」へ寄せる）
 * 対象＝見本データ（staff_role_eligibility.position・assignments.role）。
 */
return new class extends Migration
{
    private array $map = [
        'UKE' => 'RP',
        'GUN' => 'SP',
        'CK' => 'ET',
    ];

    public function up(): void
    {
        foreach ($this->map as $old => $new) {
            DB::table('staff_role_eligibility')->where('position', $old)->update(['position' => $new]);
            DB::table('assignments')->where('role', $old)->update(['role' => $new]);
        }
    }

    public function down(): void
    {
        foreach ($this->map as $old => $new) {
            DB::table('staff_role_eligibility')->where('position', $new)->update(['position' => $old]);
            DB::table('assignments')->where('role', $new)->update(['role' => $old]);
        }
    }
};
