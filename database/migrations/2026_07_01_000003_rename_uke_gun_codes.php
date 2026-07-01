<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 役割コードの一部変更（2026-07-01 baba）。表示は従来どおり（受付／軍師・サポーター）。
 *   UKE → RP（受付）
 *   GUN → SP（軍師・サポーター）
 * 対象＝見本データ（staff_role_eligibility.position・assignments.role）。
 */
return new class extends Migration
{
    private array $map = [
        'UKE' => 'RP',
        'GUN' => 'SP',
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
