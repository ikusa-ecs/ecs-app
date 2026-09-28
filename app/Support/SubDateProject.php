<?php

namespace App\Support;

/**
 * 本番案件から「予備日・リハ日・前日設営」を作るときの決まり（2026-09-28 baba要望）。
 *
 * 【なぜ】
 *   実際の作り方は「まず本番案件を登録して、あとから予備日やリハ日を足す」。
 *   これまでは予備日も一から入力し直していたので、顧客も会場もコンテンツも手で打ち直していた。
 *
 * 【baba が決めたこと（2026-09-28）】
 *   ・**案件の情報だけ引き継ぐ**（顧客・会場・コンテンツなど）。
 *   ・**時間と人数は空にする**。本番とは別物なので、コピーされていると
 *     「直したつもりで直っていない」が起きる。
 *   ・**アサインした人は引き継がない**（本番の人がそのまま出るとは限らない）。
 *
 * ⚠ 引き継がない項目を増やすときは、必ずこの BLANK／BLANK_FLAGS に足すこと。
 *   画面（project_form.blade.php）に条件を書かない＝また片方だけ直して食い違う。
 *   見張り＝tests/Feature/SubDateProjectTest.php
 */
class SubDateProject
{
    /** 作れる日程種別。⚠ 案件登録のプルダウン（date_type_sub）と同じ並び・同じ言葉にする。 */
    public const TYPES = ['予備日', 'リハ日', '前日設営'];

    /**
     * 本番から引き継がない項目（空にする）＝**時間と人数**。
     *
     * ⚠ 開催日と運営シートURLは、もともと複製（?copy=）のしくみが空にしている。
     *   ここには入れない（二重に書くと、どちらが正か分からなくなる）。
     */
    public const BLANK = [
        // 時間
        'start_time',         // 集合
        'end_time',           // 解散
        'staff_meet_time',    // スタッフだけ別の集合時間
        'staff_leave_time',   // スタッフだけ別の解散時間
        'event_enter_time',   // 入場
        'event_start_time',   // 開始
        'event_end_time',     // 終了
        // 人数
        'required_count',     // 運営人数（全体）
        'ikusa_count',        // 運営人数（IKUSA）
        'guest_count',        // お客様の人数
        'team_count',         // チーム数
    ];

    /** 上の項目にぶら下がっている「未定」のチェック。値を空にするなら、これも外す。 */
    public const BLANK_FLAGS = [
        'event_time_tbd',     // イベント時間は未定
        'count_tentative',    // 人数は仮
        'team_tentative',     // チーム数は仮
    ];

    /** 受け取った種別が作れるものか。 */
    public static function isType(?string $type): bool
    {
        return in_array((string) $type, self::TYPES, true);
    }

    /**
     * 複製で作った入力内容に、「予備日として作る」ぶんの手当てをする。
     *
     * @param  array<string, mixed>  $editProject  複製で作った入力内容（?copy= のときの中身）
     * @param  string  $type       予備日／リハ日／前日設営
     * @param  string  $parentId   紐づく本番案件のID
     * @return array<string, mixed>
     */
    public static function apply(array $editProject, string $type, string $parentId): array
    {
        foreach (self::BLANK as $f) {
            $editProject[$f] = '';
        }
        foreach (self::BLANK_FLAGS as $f) {
            $editProject[$f] = false;
        }

        // 画面はこの2つを見て「予備日・リハ日・前日設営として登録する」を自動でチェックする
        // （project_form.blade.php の applyEdit）。
        $editProject['date_type'] = $type;
        $editProject['parent_project_id'] = $parentId;

        // ⚠ 募集は引き継がない。予備日を作った瞬間にスタッフへ募集が出てしまうのを防ぐ。
        $editProject['is_recruiting'] = false;

        return $editProject;
    }
}
