<?php

namespace App\Support;

use App\Models\Person;

/**
 * 臨時スタッフの正本（2026-08-25 baba要望）。
 *
 * インターンで今月だけ来る方、誰かの知り合いの助っ人など、
 * 名簿に正式には載っていないけれど現場に入る人を、アサイン画面からその場で足す。
 *
 * 【決め方（baba選択）】
 *  ・名簿に足す形にする。アサインは「案件 × 名簿の人 × 日」で全画面がつながっているので、
 *    名前を文字で書く形にすると、その人だけ出勤数にも履歴にも入らなくなるため。
 *  ・**出勤数・集計にも数える**（ふつうのスタッフと同じ扱い）。
 *  ・ログインはしない＝メール・パスワードを持たない。名簿では「臨時」と分かるようにする。
 *
 * ⚠ 同姓同名を勝手に作らない。同じ氏名の人がすでに名簿にいたら作らずに知らせる
 *   （アサイン画面は自分の拠点しか出ないので、他拠点に同じ人がいても気づけないため）。
 */
final class SpotStaff
{
    /**
     * 臨時スタッフを1人足す。
     *
     * @return array{ok: bool, message: string, person: ?Person}
     */
    public static function create(string $name, ?string $office): array
    {
        $name = trim($name);

        if ($name === '') {
            return ['ok' => false, 'message' => '名前を入れてください。', 'person' => null];
        }
        if (mb_strlen($name) > 50) {
            return ['ok' => false, 'message' => '名前が長すぎます（50文字まで）。', 'person' => null];
        }

        // すでに名簿にいないか（氏名の空白の有無は無視して見る＝取込と同じ考え方）。
        $existing = self::findByName($name);
        if ($existing) {
            $where = $existing->office ? $existing->office.'の' : '';
            $kind = $existing->role === 'employee' ? '社員' : 'スタッフ';

            return [
                'ok' => false,
                'person' => null,
                'message' => "「{$existing->name}」さんは、すでに{$where}{$kind}として名簿にいます（{$existing->id}）。"
                    .'二重に作らないよう、そちらを使ってください。',
            ];
        }

        $person = Person::create([
            'id' => self::nextId(),
            'role' => 'staff',
            'permission' => 'staff',
            'name' => $name,
            'office' => $office ?: OfficeScope::DEFAULT_OFFICE,
            // 臨時の印。名簿で「臨時」と分かるようにする。
            'is_spot' => true,
            'active' => true,
            // ログインしない＝メール・パスワードを持たせない。初回設定にも通さない。
            'email' => null,
            'password' => null,
            'must_onboard' => false,
        ]);

        return ['ok' => true, 'person' => $person, 'message' => "「{$name}」さんを臨時スタッフとして名簿に足しました。"];
    }

    /**
     * 臨時の印を外して、ふつうのスタッフにする（2026-08-28 baba要望）。
     *
     * なぜ要るか＝「臨時で入ってもらった方のメールアドレスが分かって、正式に登録したい」は必ず起きる流れ。
     * これが無いと、同じ人をもう一度ふつうに登録することになり **名簿が二重になる**
     * （実際に2026-08-28 に起きた）。二重になると、アサインの記録が付いている方と
     * ログインできる方が別々になり、出勤数も分かれてしまう。
     *
     * ⚠ 印を外すだけで、その人の記録（アサイン・出勤数）はそのまま残る＝**新しく作り直さないのが肝**。
     * ⚠ 外したあとは、名簿の「📧 ログイン案内メールを送る」でメールを登録して送れる
     *   （臨時のあいだは LoginInvite が断る決まりになっている）。
     *
     * @return array{ok: bool, message: string}
     */
    public static function release(Person $person): array
    {
        if (! $person->is_spot) {
            return ['ok' => false, 'message' => $person->name.' さんは臨時スタッフではありません。'];
        }

        $person->is_spot = false;
        $person->save();

        return [
            'ok' => true,
            'message' => $person->name.' さんの「臨時」を外しました。'
                .'このあと「ログイン案内メールを送る」でメールアドレスを登録すれば、ご本人がログインできるようになります。',
        ];
    }

    /** 氏名で名簿を引く（空白の有無は無視する）。 */
    public static function findByName(string $name): ?Person
    {
        $key = self::normalizeName($name);
        if ($key === '') {
            return null;
        }

        return Person::all()
            ->first(fn (Person $p) => self::normalizeName((string) $p->name) === $key);
    }

    /** 空白（全角・半角）を取り除いた氏名。 */
    private static function normalizeName(string $name): string
    {
        return (string) preg_replace('/[\s　]+/u', '', trim($name));
    }

    /** 次のスタッフ番号（S-###）。名簿登録・CSV取込と同じ採番。 */
    private static function nextId(): string
    {
        $max = 0;
        foreach (Person::where('id', 'like', 'S-%')->pluck('id') as $id) {
            $n = (int) substr((string) $id, 2);
            if ($n > $max) {
                $max = $n;
            }
        }

        return 'S-'.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }
}
