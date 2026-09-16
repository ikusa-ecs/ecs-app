<?php

namespace App\Support;

use App\Models\Setting;

/**
 * 危険日を「イベプラのGoogleカレンダーの予定」にするときの中身（2026-09-16 baba要望）。
 *
 * ねらい＝危険日にお客様との商談を入れられてしまう前に、**先に枠を押さえる**。
 * 実際にカレンダーへ入れるのは GAS（手順書＝`稼働管理\ECS\危険日をカレンダーに入れるGAS.txt`）。
 * ECSは「どの日が危険日か」と「何と書くか」を返すだけで、カレンダーには触らない。
 *
 * ⚠ 鍵（Googleの認証）はECSに持たせない。GASは onuma@ikusa.co.jp 自身として動くので鍵が要らない。
 *
 * 【baba が決めた条件（2026-09-16）】
 *  ・時間＝10:00〜19:00 ／ ・土日も入れる ／ ・説明文を入れる
 *  ・宛先＝東イベプラのメーリングリスト（そこへ予定を出せば全員の予定が押さえられる）
 *  ・対象＝自動判定の危険日 ＋ 共通設定で手で足した危険日
 *  ・危険日でなくなったら、**ECSが入れた予定だけ**自動で消す
 *  ・入れる範囲＝6か月先まで
 */
final class DangerCalendar
{
    /** 予定の開始・終了（baba指定）。⚠ 手順書にも同じ数字が書いてある。 */
    public const START_TIME = '10:00';

    public const END_TIME = '19:00';

    /** 何か月先まで入れるか（baba指定・2026-09-16）。 */
    public const MONTHS_AHEAD = 6;

    /**
     * ECSが入れた予定の**目印**。説明文のいちばん下に必ず付ける。
     *
     * ⚠ これが無いと、消してよい予定かどうかが分からない。
     *   目印の無い予定（人が自分で入れた予定）には**絶対に触らない**。
     *   ⚠ この文字を変えると、前に入れた予定が「ECSのものではない」と見なされて消せなくなる。
     */
    public const MARK = '[ECS危険日]';

    /** 共通設定の保存先。 */
    public const TITLE_KEY = 'danger_calendar_title';

    public const BODY_KEY = 'danger_calendar_body';

    /** 入力欄の上限（画面と合わせる）。 */
    public const TITLE_MAX = 100;

    public const BODY_MAX = 2000;

    /** タイトルの初期値。 */
    public const TITLE_DEFAULT = '【ECS】危険日（イベント集中）';

    /** 説明文の初期値（2026-09-16 baba の文面）。 */
    public const BODY_DEFAULT = <<<'TXT'
危険日のため、他の日程で調整できる商談はほか日程でお願いします。
TXT;

    /**
     * 予定のタイトル。未設定・空なら初期値。
     * ⚠ 説明文と違い、**空は認めない**（名無しの予定がカレンダーに並ぶと何の予定か分からない）。
     */
    public static function title(): string
    {
        $raw = trim((string) Setting::get(self::TITLE_KEY, ''));

        return $raw !== '' ? $raw : self::TITLE_DEFAULT;
    }

    /** 予定の説明文（目印は付けない・付けるのは body() ）。未設定なら初期値。 */
    public static function bodyText(): string
    {
        $raw = Setting::get(self::BODY_KEY);

        return $raw === null ? self::BODY_DEFAULT : trim((string) $raw);
    }

    /**
     * 実際に予定へ入れる説明文＝説明文 ＋ 危険日の理由 ＋ 目印。
     *
     * ⚠ 目印は**いちばん下**に置く。上に置くと、人が説明文を読む前に記号が目に入る。
     *
     * @param  list<string>  $reasons  危険日と判定した理由
     */
    public static function body(array $reasons = []): string
    {
        $parts = [self::bodyText()];

        if ($reasons) {
            $parts[] = '― 危険日と判定した理由 ―'."\n".'・'.implode("\n".'・', $reasons);
        }

        $parts[] = self::MARK.' この予定はECSが自動で入れています。手で直しても、次の取り込みで戻ります。';

        return implode("\n\n", array_filter($parts, fn ($p) => trim((string) $p) !== ''));
    }

    /** タイトルを保存する。保存した中身を返す。 */
    public static function saveTitle(string $value): string
    {
        $clean = mb_substr(trim($value), 0, self::TITLE_MAX);
        Setting::put(self::TITLE_KEY, $clean);

        return $clean;
    }

    /** 説明文を保存する。保存した中身を返す。 */
    public static function saveBody(string $value): string
    {
        $clean = mb_substr(trim($value), 0, self::BODY_MAX);
        Setting::put(self::BODY_KEY, $clean);

        return $clean;
    }
}
