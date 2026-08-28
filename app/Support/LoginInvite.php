<?php

namespace App\Support;

use App\Mail\LoginInviteMail;
use App\Models\Person;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * ログイン案内メール（招待）の正本（2026-08-24 baba要望）。
 *
 * 【なぜ「仮パスワードを送る」ではないのか】
 * メールは本人の受信箱に残り、転送も誤送信もありうる。パスワードそのものを送ると
 * 「そのパスワードで入れる紙」が残り続ける。そこで **パスワードはメールに載せず**、
 * 「自分でパスワードを決めるリンク」だけを送る（baba選択）。
 *
 * 【仕組み】
 * 「パスワードをお忘れの方」（PasswordResetController）と同じ置き場を使う。
 * 合言葉（トークン）の扱いは App\Support\PasswordResetToken が正本。
 *   ・リンクの合言葉はハッシュにして保存し、平文はメールのURLだけ。
 *   ・1回使うと消える（使い回しできない）。
 *   ・有効期限は7日（baba選択）。スタッフは業務委託ですぐ見ないことがあるため長め。
 *     切れても本人が「パスワードをお忘れの方」から再発行できる。
 *   ⚠ 2026-08-25まで、期限の判定が「お忘れの方」と同じ一律60分になっていて、
 *     メールに7日と書いてあるのに1時間で切れていた。期限は発行時にDBへ書き込む。
 *
 * ⚠ 送る条件＝メールアドレスがあり、在籍中で、見本（テスト）アカウントでないこと。
 *   条件を満たさない人は送らずに理由を返す＝黙って失敗しない。
 */
final class LoginInvite
{
    /** リンクの有効期限（日）。「パスワードをお忘れの方」の60分より長い＝招待は急がないため。 */
    public const EXPIRE_DAYS = 7;

    /**
     * 1人にログイン案内を送る。
     *
     * @return array{ok: bool, message: string}
     */
    public static function send(Person $person): array
    {
        $email = trim((string) $person->email);

        if ($email === '') {
            return ['ok' => false, 'message' => $person->name.' さんはメールアドレスが未登録のため送れません。'];
        }
        if (! $person->active) {
            return ['ok' => false, 'message' => $person->name.' さんは退職（在籍なし）のため送れません。'];
        }
        if (TestAccounts::isTest($person)) {
            return ['ok' => false, 'message' => '体験用のアカウントには送れません。'];
        }
        // 臨時スタッフはログインしない決まり（2026-08-25 baba）。
        // 送ると本人がパスワードを決められてしまうので、はっきり断る。
        if ($person->is_spot) {
            return ['ok' => false, 'message' => $person->name.' さんは臨時スタッフのため、ログインの案内は送りません。'];
        }

        // 平文トークンはメールのURLにだけ載せ、DBにはハッシュを保存する。
        $token = PasswordResetToken::issue($email, Carbon::now()->addDays(self::EXPIRE_DAYS));

        $url = route('password.reset', ['token' => $token, 'email' => $email]);

        try {
            Mail::to($email)->send(new LoginInviteMail($url, $person->name, self::EXPIRE_DAYS));
        } catch (Throwable $e) {
            // 送信に失敗したら、そのことをはっきり返す（送れたつもりが一番困る）。
            return ['ok' => false, 'message' => $person->name.' さんへの送信に失敗しました：'.$e->getMessage()];
        }

        // 「もう送ったか」が名簿で分かるように、送った日時を残す（送り漏れ・二重送信を防ぐ）。
        $person->invited_at = now();

        // まだ自分でパスワードを決めていない人＝これが初めてのログインになる人は、
        // 初回ログインの初期設定（/onboarding）へ通す（2026-08-28 baba要望）。
        // ⚠ これが無いと、案内メールから入った人だけ **ふりがな・身長・靴/衣装のサイズ・
        //   都道府県・最寄り駅・働き始めた年月が空のまま** になっていた
        //   （「メールはあとで」で名簿に入れた人・臨時から正式にした人が該当）。
        //   アサインの判断に使う情報なので、ここで必ず聞く。
        // ⚠ 送り直し（パスワードを忘れた人への再送）では戻さない＝ password_set_at で見分ける。
        //   すでに自分でパスワードを決めた人は、初期設定も済んでいる人なので触らない。
        if (! $person->password_set_at) {
            $person->must_onboard = true;
        }

        $person->save();

        return ['ok' => true, 'message' => $person->name.' さん（'.$email.'）にログイン案内を送りました。'];
    }

    /**
     * まとめて送る（CSV取込のあとなど）。
     *
     * @param  iterable<Person>  $people
     * @return array{sent: int, skipped: list<string>}
     */
    public static function sendMany(iterable $people): array
    {
        $sent = 0;
        $skipped = [];

        foreach ($people as $person) {
            $result = self::send($person);
            if ($result['ok']) {
                $sent++;
            } else {
                $skipped[] = $result['message'];
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }
}
