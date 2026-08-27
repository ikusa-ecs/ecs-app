<?php

namespace App\Support;

use App\Models\Assignment;
use App\Models\CalendarSync;
use App\Models\Project;
use Illuminate\Support\Facades\Schema;

/**
 * 「カレンダーを直す必要がある案件」に印を付ける係（2026-08-27 baba要望）。**印を付ける入口はここだけ。**
 *
 * 【なぜ1か所にまとめるか】
 * ⚠ 案件やDが変わる入口はとても多い。Dが変わるだけで5か所（D決め画面／案件一覧のセル／
 *   保守コマンド／取込×2）、日程が変わるのが3か所、キャンセル・削除・アーカイブはそれぞれ別処理。
 *   画面ごとに「カレンダーを直す」処理を書くと**必ず書き忘れて古い予定が残る**。
 *   Project／Assignment の保存イベントから呼べば、どの画面から変えても・画面が増えても漏れない。
 *   （編集履歴＝ProjectHistoryRecorder が同じ形で動いていて、実績のある型）
 *
 * 【ここでは Google に送らない】
 * 印を付けるだけ。実際に送るのは別のタイミング（画面のボタン／定期実行）。
 * ⚠ そうしないと、Googleの通信が遅い・失敗したときに**案件の保存そのものが止まる**。
 */
final class CalendarSyncQueue
{
    /** 一括取込・シーダーのあいだは印を付けない（付けても意味は同じだが、無駄に大量に立てない）。 */
    private static bool $enabled = true;

    /** 印を付けずに処理する（シーダー・一括取込用）。 */
    public static function withoutMarking(callable $callback)
    {
        $previous = self::$enabled;
        self::$enabled = false;
        try {
            return $callback();
        } finally {
            self::$enabled = $previous;
        }
    }

    /**
     * いま印を付けられる状態か。
     * ⚠ テーブルの有無を見るのが大事：**これが無いと、まだ migrate していないサーバーでは
     *   「案件を保存するだけでエラー」になる。** カレンダーは補助の機能なので、
     *   保存先が無ければ黙って何もしないだけにして、案件そのものの保存は必ず通す。
     */
    public static function available(): bool
    {
        return self::$enabled && Schema::hasTable('calendar_syncs');
    }

    /**
     * 案件が保存された（作成・更新）＝カレンダーを直す必要がある。
     *
     * ⚠ 「カレンダーに出さない案件」になったときは**消す印**を立てる。
     *   キャンセル・アーカイブ・下書きは現場の予定ではないので、予定を残してはいけない。
     */
    public static function markSaved(Project $project): void
    {
        if (! self::available()) {
            return;
        }

        self::shouldHaveEvent($project)
            ? self::mark($project->id, needsSync: true, needsDelete: false)
            : self::mark($project->id, needsSync: false, needsDelete: true);
    }

    /**
     * 案件が消された＝カレンダーの予定も消す。
     * ⚠ 案件の行はもう無いので、予定IDを頼りに消す（待ち行列の行は残しておく）。
     */
    public static function markDeleted(Project $project): void
    {
        if (! self::available()) {
            return;
        }

        self::mark($project->id, needsSync: false, needsDelete: true);
    }

    /**
     * アサインが変わった＝その案件のカレンダーを直す必要がある。
     *
     * ⚠ これが要る理由＝**Dが決まったらカレンダーに招待を足す**決まりにしたため（baba選択）。
     *   Dが変わる入口は5か所あるが、どの入口も最後は assignments に書くので、ここで拾える。
     */
    public static function markAssignmentChanged(Assignment $assignment): void
    {
        if (! self::available()) {
            return;
        }

        $project = Project::find($assignment->project_id);
        if ($project === null) {
            return;
        }

        self::markSaved($project);
    }

    /**
     * その案件はカレンダーに予定があるべきか。
     *
     * 出さないもの：
     *  ・キャンセル … 実施しないため
     *  ・下書き … まだ案件として決まっていないため
     *  ・手動アーカイブ（隠す） … 一覧から隠した案件なので予定も残さない
     *  ・開催日が無い（日付未定） … いつの予定か決められないため
     *
     * ⚠ 「開催日が過ぎたら消す」はここでは判定しない。過去の予定はカレンダーに残っていて
     *   困らない（履歴として役に立つ）ので、消すのは上の4つに当てはまったときだけにする。
     */
    public static function shouldHaveEvent(Project $project): bool
    {
        if ($project->start_date === null) {
            return false;
        }
        if ($project->is_cancelled) {
            return false;
        }
        if ($project->status === '下書き') {
            return false;
        }
        // is_archived は null＝自動（開催日で決まる）なので、手で隠したときだけ消す。
        if ($project->is_archived === true) {
            return false;
        }

        return true;
    }

    /** 待ち行列に印を付ける（無ければ作る）。 */
    private static function mark(string $projectId, bool $needsSync, bool $needsDelete): void
    {
        $row = CalendarSync::firstOrNew(['project_id' => $projectId]);

        // まだ予定を作っていないのに「消す」印を立てても意味が無い＝行を作らない。
        if ($needsDelete && ! $row->exists) {
            return;
        }
        if ($needsDelete && ($row->google_event_id ?? '') === '') {
            // 予定がまだ無いので、作る予定も取り消しておく。
            $row->needs_sync = false;
            $row->needs_delete = false;
            $row->save();

            return;
        }

        $row->needs_sync = $needsSync;
        $row->needs_delete = $needsDelete;
        $row->save();
    }

    /**
     * いま直す必要がある案件の数（画面の見出しに出す）。
     *
     * @return array{sync:int, delete:int, error:int}
     */
    public static function counts(): array
    {
        if (! Schema::hasTable('calendar_syncs')) {
            return ['sync' => 0, 'delete' => 0, 'error' => 0];
        }

        return [
            'sync' => CalendarSync::where('needs_sync', true)->count(),
            'delete' => CalendarSync::where('needs_delete', true)->count(),
            'error' => CalendarSync::whereNotNull('last_error')->count(),
        ];
    }
}
