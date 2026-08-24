<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 利用者（社員・スタッフ共通）。テーブルは people。
 * role='employee'（社員）/ 'staff'（スタッフ）で区別する。
 *
 * ログインもこの people 名簿で行う（アカウントは users 表ではなくここに持たせる）。
 * Authenticatable を実装＝この行のまま「ログインできる人」として扱える。
 */
class Person extends Model implements AuthenticatableContract
{
    use Authenticatable;

    protected $table = 'people';

    // ID は E-001 / S-001 のような文字列なので、自動採番しない。
    public $incrementing = false;
    protected $keyType = 'string';

    protected $guarded = [];

    // 画面やJSONに出さない（パスワード等の秘密情報）
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',   // 代入時に自動で暗号化（平文を保存しない）
            'hire_date' => 'date',
            'active' => 'boolean',
            'experienced_contents' => 'array',
            'director_contents' => 'array',
            'is_admin' => 'boolean',
            'must_onboard' => 'boolean',   // 初回ログインの初期設定が必要か
            'is_exclusive' => 'boolean',
            'op_online' => 'boolean',   // OPオンライン可（B案）
            'op_real' => 'boolean',     // OPリアル(現地)可（B案）
            'notify_settings' => 'array',   // マイページの通知オン/オフ
            'mc_audition_passed' => 'boolean',
            'can_kigurumi' => 'boolean',
            'can_stay_over' => 'boolean',
            'can_follow_newbie' => 'boolean',
            'self_starter' => 'boolean',
            'improves_atmosphere' => 'boolean',
            'oversleeper' => 'boolean',
            'sensitive_care' => 'boolean',
        ];
    }

    /** できるポジション（可否）。staff_role_eligibility を参照。 */
    public function roleEligibilities(): HasMany
    {
        return $this->hasMany(StaffRoleEligibility::class, 'staff_id');
    }

    /** NGペア（相性）。staff_relations を参照。 */
    public function ngRelations(): HasMany
    {
        return $this->hasMany(StaffRelation::class, 'staff_id');
    }

    /** 社員だけを取り出すクエリスコープ（Person::employees()->get()） */
    public function scopeEmployees($query)
    {
        return $query->where('role', 'employee');
    }

    /** スタッフだけを取り出すクエリスコープ（Person::staff()->get()） */
    public function scopeStaff($query)
    {
        return $query->where('role', 'staff');
    }

    /**
     * 社歴順（入社日の古い人が先）に並べるクエリスコープ。
     *
     * なぜ＝名簿の並びを「社歴の長い人が上」にしたい（2026-08-24 baba）。
     * 社員番号（E-001…）は登録した順に振られるだけで社歴とは関係がないため、
     * 番号を付け替えるのではなく並び順で解決する（番号は他のデータが人を指す名札なので変えない）。
     *
     * 入社日が未入力の人は末尾へ。入社日が同じ人は社員番号順。
     * ※ 「hire_date is null」は SQLite でも MySQL でも 0/1 を返すので、
     *    昇順に並べると「入っている人（0）→ 空の人（1）」の順になる。
     *    ローカル(SQLite)と本番(MySQL)で並びが変わらないよう、この書き方にしている。
     */
    public function scopeBySeniority($query)
    {
        return $query
            ->orderByRaw('hire_date is null')
            ->orderBy('hire_date')
            ->orderBy('id');
    }

    /**
     * 区分（新人/中堅/ベテラン）。保存せず hire_date からの在籍年数で都度計算する。
     * 新人＝在籍1年未満／中堅＝1年以上3年未満／ベテラン＝3年以上（設計書19.1の確定方針）。
     * 入社日が無い場合は null を返す。
     */
    public function getSkillLevelAttribute(): ?string
    {
        if (! $this->hire_date) {
            return null;
        }

        $years = $this->hire_date->diffInDays(now()) / 365.25;

        return $years < 1 ? '新人' : ($years < 3 ? '中堅' : 'ベテラン');
    }
}
