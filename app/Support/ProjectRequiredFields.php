<?php

namespace App\Support;

/**
 * 案件登録で「入力必須」にしている項目の**正本**（2026-09-28 baba要望）。
 *
 * 案件登録の画面では、空の欄を
 *   ・赤（`data-need="req"`）＝いま必須
 *   ・黄（`data-need="later"`）＝後で必要（今は未定でOK）
 * の2段階で色分けしている。アサイン表でも「未入力」を同じ色で出したいので、
 * **どの項目がどちらなのか**をここ1か所にまとめる。
 *
 * ⚠ 画面ごとに項目名を書き並べないこと。
 *   案件登録に必須の欄を足したのにアサイン表が知らない、という食い違いが必ず起きる。
 *   見張り＝tests/Feature/ProjectRequiredFieldsTest.php
 *   （案件登録の `data-need` と、この表が一致していないと落ちる）。
 */
class ProjectRequiredFields
{
    /** 赤＝いま必須。空だと登録時に止める項目。 */
    public const RED = [
        'start_date'  => '開催日',
        'sales_owner' => '営業担当',
    ];

    /** 黄＝後で必要（登録の時点では未定でよいが、放っておくと困る項目）。 */
    public const YELLOW = [
        'lodging'           => '宿泊',
        'client'            => 'クライアント',
        'start_time'        => '集合時間',
        'end_time'          => '解散時間',
        'event_enter_time'  => '入場時間',
        'event_start_time'  => '開始時間',
        'event_end_time'    => '終了時間',
        'required_count'    => '運営人数（全体）',
        'ikusa_count'       => '運営人数（IKUSA）',
        'guest_count'       => '人数',
        'team_count'        => 'チーム数',
        'location'          => '会場住所',
        'assembly_type'     => '集合形式',
    ];

    /** その項目の段階。'req'＝赤／'later'＝黄／''＝どちらでもない（任意）。 */
    public static function levelOf(string $field): string
    {
        if (array_key_exists($field, self::RED)) {
            return 'req';
        }

        return array_key_exists($field, self::YELLOW) ? 'later' : '';
    }

    /**
     * 「未入力」の見せ方を決める（アサイン表の1行ぶん）。
     *
     * 1行に複数の項目が入ることがある（例＝入/開/終）ので、**いちばん強い段階**を採る。
     * ⚠ 赤 > 黄 > 任意。赤が1つでも混ざっていたら赤にする。
     *
     * @param  array<int, string>  $fields  その行で使っている項目名
     * @return string  'req'|'later'|''
     */
    public static function levelOfRow(array $fields): string
    {
        $level = '';
        foreach ($fields as $f) {
            $l = self::levelOf($f);
            if ($l === 'req') {
                return 'req';
            }
            if ($l === 'later') {
                $level = 'later';
            }
        }

        return $level;
    }
}
