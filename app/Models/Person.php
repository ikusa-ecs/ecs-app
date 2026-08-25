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
            'departments' => 'array',   // 兼務も含めた所属すべて（主な所属＝department）
            'is_admin' => 'boolean',
            'must_onboard' => 'boolean',   // 初回ログインの初期設定が必要か
            // 臨時スタッフ（インターン・知り合いの助っ人など）。ログインしない（2026-08-25 baba）
            'is_spot' => 'boolean',
            'invited_at' => 'datetime',      // ログイン案内メールを最後に送った日時
            'password_set_at' => 'datetime', // 本人が自分でパスワードを決めた日時
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

    /**
     * 兼務を含めた所属の一覧（主な所属が先頭）。
     *
     * なぜ＝所属を兼ねている人がいる（2026-08-24 baba）。
     * departments が入っていればそれ、無ければ主な所属（department）1つだけ。
     * 主な所属は必ず先頭に来るようにそろえる（画面の1つめのバッジ＝主な所属）。
     */
    public function departmentList(): array
    {
        $main = trim((string) ($this->department ?? ''));
        $all = array_values(array_filter(array_map(
            fn ($d) => trim((string) $d),
            is_array($this->departments) ? $this->departments : []
        ), fn ($d) => $d !== ''));

        if ($main !== '') {
            // 主な所属を先頭へ（重複は落とす）
            $all = array_values(array_unique(array_merge([$main], $all)));
        }

        return $all;
    }

    /** その所属に属しているか（兼務も見る）。イベプラ判定などに使う。 */
    public function hasDepartment(string $name): bool
    {
        return in_array($name, $this->departmentList(), true);
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
     * D／SD／物品担当を選ぶプルダウン用の並び。
     * 「その拠点のイベプラ」を先頭に持ち上げ、あとは氏名順（2026-08-24 baba）。
     *
     * なぜ＝Dに立つのはほぼ自拠点のイベプラ。毎回リストを探しに行かなくて済むように。
     * ※ 拠点が未設定の人は既定拠点（東京）あつかい。名簿の office が空でも
     *   「東京のイベプラ」として先頭に来る（people.office 埋め直し前の保険）。
     * ※ グループの中の並びは五十音順（ふりがな）。→ scopeByKana
     * ※ 兼務でイベプラに入っている人も先頭グループに入れる（departments を見る）。
     *
     * ⚠ JSONの中を探すのに like を2つ並べているのは、日本語の入り方が環境で違うため。
     *   ・SQLite（ローカル）＝ Laravel が json_encode した文字がそのまま入る
     *     ＝日本語は "イベプラ" のような形で保存される。
     *   ・MySQL（本番）＝ JSON型が中身を解釈して持つので、文字に戻して比べられる。
     *   どちらでも当たるように「そのままの形」と「\uXXXXの形」の両方を見る。
     *   （JSON専用の関数は方言差が大きく、ローカルで動いても本番で落ちることがあるため使わない）
     *   前後を " で囲って探すので「イベプロ」を「イベプラ」と誤って拾うことはない。
     */
    public function scopePlannersOfOfficeFirst($query, ?string $office)
    {
        $office = $office ?: \App\Support\OfficeScope::DEFAULT_OFFICE;
        $planner = \App\Support\Departments::PLANNER;

        return $query
            ->orderByRaw(
                "case when coalesce(nullif(office, ''), ?) = ?"
                . " and (department = ? or departments like ? or departments like ?) then 0 else 1 end",
                [
                    \App\Support\OfficeScope::DEFAULT_OFFICE,
                    $office,
                    $planner,
                    '%"' . $planner . '"%',              // MySQL：文字のまま
                    '%' . json_encode($planner) . '%',   // SQLite：\uXXXX の形（前後の " も含む）
                ]
            )
            ->byKana();
    }

    /**
     * 五十音順（ふりがな順）に並べるクエリスコープ。
     *
     * なぜ＝漢字の氏名だけで並べると文字コード順になり、五十音にならない
     * （「青山」より「渡辺」が先に来ることがある）。読み（name_kana）で並べる。
     *
     * ふりがなが未入力の人は氏名順で末尾へ。
     * ※ coalesce(nullif(...)) は SQLite（ローカル）でも MySQL（本番）でも同じ動きをする。
     *   ローカルで確認した並びが本番で変わらないように、この書き方にしている。
     */
    public function scopeByKana($query)
    {
        return $query
            ->orderByRaw("case when coalesce(nullif(name_kana, ''), '') = '' then 1 else 0 end")
            ->orderBy('name_kana')
            ->orderBy('name')
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
