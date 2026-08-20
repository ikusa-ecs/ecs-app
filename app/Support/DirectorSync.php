<?php

namespace App\Support;

use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * D（ディレクター）・SD（サブディレクター）の保存を一本化する共通部品。
 *
 * ■ 経緯（2026-08-05 baba確定）
 *   Dの入口が3つ（D決め画面／案件一覧のプルダウン／CSV取込）あり、**2つの別の場所**に保存していた。
 *     ・D決め画面（/assign-director） → assignments（role='D'/'SD'）
 *     ・案件一覧のプルダウン         → projects.director_id / sd_id
 *   表示側の画面はほとんど古い `projects.director_id` を読むため、
 *   **D決め画面で決めても他の画面に出てこない**という食い違いが起きていた。
 *   → **保存先は assignments に一本化する。D決め画面でも案件一覧でも決められるようにする。**
 *
 * ■ 移行は3段階（急がない方針）
 *   ① 保存を一本化しつつ、古い列（director_id / sd_id）にも同じ値を「写し」で書く ← ★いまここ
 *      （表示がまだ古い列を読んでいる画面が壊れないようにするため）
 *   ② 表示を1画面ずつ assignments 側へ切り替える
 *   ③ 写しをやめて director_id / sd_id を削除（②完了＋baba目視確認後）
 *
 * ■ 使い方
 *   ・案件一覧のプルダウン（ProjectController@saveCells）＝ apply() を呼ぶ（assignments＋写しの両方）
 *   ・D決め画面（AssignDirectorController@save）＝ assignments は自前の処理で保存済みなので
 *     mirrorToProject() だけを呼ぶ（写しの部分だけ担当する）
 *
 * ※ 物品担当（goods_owner_id）はアサインの役割ではないので projects のまま（baba確定）。
 * ※ CSV取込のディレクター列は扱わない（取込の時点ではDが決まっていないため・baba確定）。
 */
class DirectorSync
{
    /**
     * D／SD を assignments へ保存し、古い列にも同じ値を写す。
     *
     * 「送られたキーだけ更新する」使い方に合わせて、触る役割をフラグで指定する
     * （案件一覧はセル1つずつ変える運用なので、Dだけ・SDだけの更新がある）。
     *
     * @param  string|null  $directorId  社員ID。null／空／存在しないIDは「担当なし」
     * @param  string|null  $sdId        同上
     * @param  bool  $touchDirector  D を更新するか（false＝今回は触らない）
     * @param  bool  $touchSd        SD を更新するか
     * @return array{saved:int, removed:int, skipped:bool}  skipped=true＝開催日が無くassignmentsに書けなかった
     */
    public static function apply(
        Project $project,
        ?string $directorId = null,
        ?string $sdId = null,
        bool $touchDirector = true,
        bool $touchSd = true,
    ): array {
        $dId = $touchDirector ? self::validEmployeeId($directorId) : null;
        $sId = $touchSd ? self::validEmployeeId($sdId) : null;

        $result = ['saved' => 0, 'removed' => 0, 'skipped' => false];

        // assignments は日付が必須。開催日が無い案件は写しだけ書いて終わる
        // （日付が入ったあとに改めて保存すれば assignments にも入る）。
        if (! $project->start_date) {
            $result['skipped'] = true;
            self::mirrorToProject($project, $dId, $sId, $touchDirector, $touchSd);

            return $result;
        }

        $date = Carbon::parse($project->start_date)->format('Y-m-d');

        foreach ([
            AssignmentRole::D => ['touch' => $touchDirector, 'id' => $dId],
            AssignmentRole::SD => ['touch' => $touchSd, 'id' => $sId],
        ] as $role => $info) {
            if (! $info['touch']) {
                continue;   // 今回触らない役割はそのまま
            }

            // その役割で入っている「今回選ばれていない人」の行を消す（担当を外す操作の反映）。
            // ※ date は時刻付きで保存され得るため whereDate で日付部分だけ照合する
            //   （文字列の完全一致だと取りこぼして重複エラーになる。D決め画面と同じ注意点）。
            $result['removed'] += Assignment::where('project_id', $project->id)
                ->whereDate('date', $date)
                ->where('role', $role)
                ->when($info['id'], fn ($q) => $q->where('staff_id', '!=', $info['id']))
                ->delete();

            if (! $info['id']) {
                continue;   // 担当なし＝行を作らない
            }

            $existing = Assignment::where('project_id', $project->id)
                ->where('staff_id', $info['id'])
                ->whereDate('date', $date)
                ->first();

            if ($existing) {
                // すでに行がある人は役割だけ変える。状態（仮/確定）は今のものを保つ
                // （確定していた担当が、別画面の操作で勝手に「仮」へ落ちないようにする）。
                $existing->update(['role' => $role, 'assigned_at' => Carbon::now()]);
            } else {
                Assignment::create([
                    'project_id' => $project->id,
                    'staff_id' => $info['id'],
                    'date' => $date,
                    'role' => $role,
                    'status' => '仮',       // 新しく決めた担当は「仮」から始める（D決め画面と同じ）
                ] + AssignmentStamp::forCreate('仮'));
            }
            $result['saved']++;
        }

        self::mirrorToProject($project, $dId, $sId, $touchDirector, $touchSd);

        return $result;
    }

    /**
     * 古い列（projects.director_id / sd_id）へ「写し」を書く＝移行①のための一時的な処理。
     * 表示がまだ古い列を読んでいる画面（アサイン表・案件一覧など）を壊さないために残している。
     * 移行③（表示の切替が終わったあと）でこのメソッドごと削除する。
     */
    public static function mirrorToProject(
        Project $project,
        ?string $directorId,
        ?string $sdId,
        bool $touchDirector = true,
        bool $touchSd = true,
    ): void {
        $dirty = false;

        if ($touchDirector) {
            $val = self::validEmployeeId($directorId);
            if ($project->director_id !== $val) {
                $project->director_id = $val;
                $dirty = true;
            }
        }
        if ($touchSd) {
            $val = self::validEmployeeId($sdId);
            if ($project->sd_id !== $val) {
                $project->sd_id = $val;
                $dirty = true;
            }
        }

        if ($dirty) {
            $project->save();
        }
    }

    /**
     * assignments に入っている今のD／SDを読む＝[D社員ID, SD社員ID]（未定は null）。
     * 移行②で表示側を切り替えるときに使う（古い列の代わりにここを読む）。
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function current(Project $project): array
    {
        $rows = Assignment::where('project_id', $project->id)
            ->whereIn('role', [AssignmentRole::D, AssignmentRole::SD])
            ->where('status', '!=', 'キャンセル')
            ->get(['staff_id', 'role']);

        $d = $rows->firstWhere('role', AssignmentRole::D)?->staff_id;
        $sd = $rows->firstWhere('role', AssignmentRole::SD)?->staff_id;

        return [$d, $sd];
    }

    /** 社員（people の employee）に実在するIDだけ通す。空・不正・スタッフのIDは null（担当なし）。 */
    private static function validEmployeeId(?string $id): ?string
    {
        $id = trim((string) $id);
        if ($id === '') {
            return null;
        }

        return Person::employees()->whereKey($id)->exists() ? $id : null;
    }
}
