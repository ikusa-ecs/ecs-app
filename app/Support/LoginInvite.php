<?php

namespace App\Support;

use App\Mail\LoginInviteMail;
use App\Models\Person;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
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
 * 「パスワードをお忘れの方」（PasswordResetController）と同じ password_resets を使う。
 *   ・リンクの合言葉（トークン）はハッシュにして保存し、平文はメールのURLだけ。
 *   ・1回使うと消える（使い回しできない）。
 *   ・有効期限は7日（baba選択）。スタッフは業務委託ですぐ見ないことがあるため長め。
 *     切れても本人が「パスワードをお忘れの方」から再発行できる。
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

        // 平文トークンはメールのURLにだけ載せ、DBにはハッシュを保存する。
        $token = Str::random(64);

        DB::table('password_resets')->updateOrInsert(
            ['email' => $email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        $url = route('password.reset', ['token' => $token, 'email' => $email]);

        try {
            Mail::to($email)->send(new LoginInviteMail($url, $person->name, self::EXPIRE_DAYS));
        } catch (Throwable $e) {
            // 送信に失敗したら、そのことをはっきり返す（送れたつもりが一番困る）。
            return ['ok' => false, 'message' => $person->name.' さんへの送信に失敗しました：'.$e->getMessage()];
        }

        // 「もう送ったか」が名簿で分かるように、送った日時を残す（送り漏れ・二重送信を防ぐ）。
        $person->invited_at = now();
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
