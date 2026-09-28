<?php

namespace App\Support;

use App\Models\Project;

/**
 * 日別ボードの「📣 再募集の文章」＝まだ人が足りない案件を、スタッフ全体のLINEグループへ
 * 1通にまとめて流すための文章（2026-09-28 baba要望）。
 *
 * 【対象にする案件】スタッフに公開ずみ・募集中（🔒で締めていない）・スタッフ画面で「あと◯名」が出ているもの。
 *   未公開の案件はスタッフがエントリーできない（入口が無い）ので入れない（baba決定）。
 *   ⚠ 対象の判定と「あと◯名」の数は、画面（assign.blade.php の remainForStaff）が持っている。
 *     アサインをその場で動かすと数が変わるため＝サーバーで数を決めると、押した直後の画面と食い違う。
 *
 * ⚠ 1件ぶんの文章（日付・コンテンツ・会社名・時間・場所）を作るのはここ1か所だけ。
 *   画面のJSでは「番号をふる」「あと◯名を足す」「見出しと締めでくるむ」しかしない
 *   （BladeのJSで文章を組み立てると、置換で壊れる事故を繰り返しているため）。
 *
 * ⚠ 会社名は載せてよい（2026-09-28 baba＝スタッフ画面にも出ている）。「様」を付ける。
 */
class RecruitAgainText
{
    /** 1通のいちばん上。 */
    public const HEADER = "【再募集】まだ人が足りていない案件です🙏\nご都合の合う方、エントリーお待ちしています！";

    /** 1件ぶんの文章（番号と「あと◯名」は画面が付ける）。 */
    public static function block(Project $project, array $contentMaster = []): string
    {
        $lines = [];

        $office = trim((string) ($project->office ?? ''));
        $lines[] = LineGroupText::dateLabel($project).($office !== '' ? '　'.$office : '');

        $content = LineGroupText::contentName($project, $contentMaster);
        if ($content !== '') {
            $lines[] = $content;
        }

        $company = LineGroupText::companyWithSama($project);
        if ($company !== '') {
            $lines[] = $company;
        }

        // ⚠ スタッフに向けた文章なので、スタッフ向けの時間があればそちら（LINEの概要文と同じ考え方）。
        $meet = trim((string) ($project->staff_meet_time ?: $project->start_time ?: ''));
        $leave = trim((string) ($project->staff_leave_time ?: $project->end_time ?: ''));
        if ($meet !== '' || $leave !== '') {
            $lines[] = '集合 '.($meet !== '' ? $meet : '—').' 〜 解散 '.($leave !== '' ? $leave : '—');
        }

        $lines[] = '場所：'.self::place($project);

        // 宿泊があるときだけ（「無」は書かない＝行を増やさない）。
        $lodging = Lodging::label($project->lodging);
        if ($lodging !== '無') {
            $lines[] = '宿泊：'.$lodging;
        }

        return implode("\n", $lines);
    }

    /** 1通のいちばん下（スタッフ画面の場所を添える）。 */
    public static function footer(): string
    {
        return "スタッフ画面からエントリーお願いします！\n".url('/staff-portal');
    }

    /**
     * 場所。オンラインなら「オンライン」、それ以外は会場住所（無ければ運営場所）。
     * ⚠ オンラインの判定は ProjectFormats が正本（ここで文字を探さない）。
     */
    private static function place(Project $project): string
    {
        if (ProjectFormats::countCode($project->format) === 'online') {
            return 'オンライン';
        }

        $loc = trim((string) ($project->location ?? ''));
        if ($loc !== '') {
            return $loc;
        }
        $op = trim((string) ($project->operation_place ?? ''));

        return $op !== '' ? $op : '未定';
    }
}
