<?php

namespace App\Support;

use App\Models\Person;
use App\Models\PersonNote;

/**
 * 人ごとのメモの正本（2026-09-02 baba要望）。
 *
 * D決め画面で社員名を押したときのふきだしに書く、その人あてのメモ。
 * 例：「10/3 大型入ってるからアサインしない」
 *
 * ⚠ 読み書きは必ずここを通す。画面ごとに書き方を持つと、片方だけ直して食い違う。
 * ⚠ 空にしたら**行を消す**（空文字の行を残さない）＝「メモあり」の印を出すときに、
 *   空なのに印が付く、が起きないようにする。
 */
final class PersonNotes
{
    /** メモの長さの上限。⚠ ふきだしに出すものなので、長文は別の場所（名簿の備考）へ。 */
    public const MAX = 500;

    /**
     * 何人かぶんまとめて読む（画面で1人ずつ引かないため）。
     *
     * @param  list<string>  $personIds
     * @return array<string, array{note:string, by:string, at:string}>
     */
    public static function forMany(array $personIds): array
    {
        $personIds = array_values(array_unique(array_filter($personIds)));
        if ($personIds === []) {
            return [];
        }

        $rows = PersonNote::whereIn('person_id', $personIds)->get();
        if ($rows->isEmpty()) {
            return [];
        }

        // 「誰が書いたか」は名前で出す（IDのままだと誰か分からない）。
        $names = Person::whereIn('id', $rows->pluck('updated_by')->filter()->unique()->all())
            ->pluck('name', 'id');

        $out = [];
        foreach ($rows as $r) {
            $note = trim((string) $r->note);
            if ($note === '') {
                continue;
            }
            $out[$r->person_id] = [
                'note' => $note,
                'by' => (string) ($names[$r->updated_by] ?? ''),
                'at' => optional($r->updated_at)->format('n/j') ?? '',
            ];
        }

        return $out;
    }

    /**
     * 1人ぶん保存する。空にしたら消す。
     *
     * @return string 保存後のメモ（空なら空文字）
     */
    public static function put(string $personId, ?string $note, ?string $byId = null): string
    {
        $note = trim((string) $note);
        if (mb_strlen($note) > self::MAX) {
            $note = mb_substr($note, 0, self::MAX);
        }

        if ($note === '') {
            PersonNote::where('person_id', $personId)->delete();

            return '';
        }

        PersonNote::updateOrCreate(
            ['person_id' => $personId],
            ['note' => $note, 'updated_by' => $byId]
        );

        return $note;
    }
}
