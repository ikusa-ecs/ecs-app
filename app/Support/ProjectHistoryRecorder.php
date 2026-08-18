<?php

namespace App\Support;

use App\Models\Project;
use App\Models\ProjectHistory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * 案件の編集履歴を残す係（先-1／2026-08-18）。**記録の入口はここ1か所だけ。**
 *
 * なぜ1か所にまとめるか：
 *   案件を書き換える画面は今5か所ある（案件登録／案件一覧のセル編集／アサイン表／
 *   公開ボード／D決め）。画面ごとに「履歴を残す」処理を書くと、必ず書き忘れが出る。
 *   Project モデルの保存イベント（App\Models\Project の booted）から呼べば、
 *   どの画面から変えても、これから画面が増えても、自動で残る。
 *
 * 残さないもの：
 *   ・App\Support\ProjectFieldLabels に日本語名の無い列（id・作成日時など）
 *   ・見た目の文字が変わらない書き換え（null → 空文字 など。読む人には意味が無いため）
 */
class ProjectHistoryRecorder
{
    /**
     * 記録するかどうか。見本データの投入（シーダー）やCSVの一括取込のように
     * 「人が1件ずつ直したのではない」ときは、いったん止めて履歴を汚さないようにする。
     */
    private static bool $enabled = true;

    /** 履歴を残さずに処理する（シーダー・一括取込用）。 */
    public static function withoutRecording(callable $callback)
    {
        $previous = self::$enabled;
        self::$enabled = false;
        try {
            return $callback();
        } finally {
            self::$enabled = $previous;
        }
    }

    public static function enabled(): bool
    {
        return self::$enabled;
    }

    /**
     * いま履歴を残せる状態か。
     * ・止められていない（withoutRecording の外）
     * ・保存先テーブルが出来ている（`php artisan migrate` 済み）
     *
     * ⚠ テーブルの有無を見るのが大事な理由：**これが無いと、まだ migrate していないサーバーでは
     *   「案件を保存するだけでエラー」になる。** 履歴は補助の機能なので、保存先が無ければ
     *   黙って残さないだけにして、案件そのものの保存は必ず通す。
     *
     * ※ 結果は覚えない（案件の保存1回につき1回だけ確認する程度の負担なので）。
     *   覚えないことで、あとから migrate したその時点から記録が始まる。
     */
    public static function available(): bool
    {
        if (! self::$enabled) {
            return false;
        }
        try {
            return Schema::hasTable('project_histories');
        } catch (\Throwable $e) {
            // DBに繋がらない等。履歴は補助なので、ここで例外を投げて本体の保存を止めない。
            return false;
        }
    }

    /** 新規登録を1行残す。 */
    public static function recordCreated(Project $project): void
    {
        if (! self::available()) {
            return;
        }

        self::write($project, 'created', null, null, null);
    }

    /** 削除を1行残す（案件が消えても「誰が消したか」は残る）。 */
    public static function recordDeleted(Project $project): void
    {
        if (! self::available()) {
            return;
        }

        self::write($project, 'deleted', null, null, null);
    }

    /**
     * 変更を項目ごとに1行ずつ残す。
     * Project の updated イベントから呼ぶ（この時点では getOriginal が「保存前の値」を持っている）。
     */
    public static function recordUpdated(Project $project): void
    {
        if (! self::available()) {
            return;
        }

        foreach (array_keys($project->getChanges()) as $field) {
            if (! ProjectFieldLabels::isTracked($field)) {
                continue;
            }

            // 新旧どちらも「型を通した値」で比べる（配列・日付・0/1 の食い違いを避けるため）。
            $oldText = ProjectFieldLabels::display($field, $project->getOriginal($field));
            $newText = ProjectFieldLabels::display($field, $project->getAttribute($field));

            // 見た目が変わらないなら残さない（例：null → 空文字）。
            if ($oldText === $newText) {
                continue;
            }

            self::write($project, 'updated', $field, $oldText, $newText);
        }
    }

    /** 1行書き込む。誰が変えたかはログイン中の利用者から取る（不明なら空＝バッチ処理など）。 */
    private static function write(Project $project, string $action, ?string $field, ?string $old, ?string $new): void
    {
        $me = Auth::user();

        ProjectHistory::create([
            'project_id'   => (string) $project->id,
            'project_name' => $project->project_name,
            'action'       => $action,
            'field'        => $field,
            'field_label'  => $field ? ProjectFieldLabels::label($field) : null,
            'old_value'    => $old,
            'new_value'    => $new,
            'person_id'    => $me->id ?? null,
            'person_name'  => $me->name ?? null,
        ]);
    }
}
