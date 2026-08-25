<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 「パスワードを決めるリンク」の合言葉（トークン）の正本（2026-08-25）。
 *
 * このリンクは2か所から発行される。
 *   ・ログイン案内メール（初回のパスワード設定）… App\Support\LoginInvite（期限7日）
 *   ・パスワードをお忘れの方                  … PasswordResetController（期限60分）
 *
 * ⚠ もともとは発行が2か所・期限の判定が1か所（一律60分）に分かれていたため、
 *   案内メールに「7日間有効」と書いてあるのに実際は1時間で切れる不具合になっていた。
 *   同じ間違いが起きないよう、**発行も判定も後始末もここだけ**にする。
 *
 * 仕組み：
 *   ・平文の合言葉はメールのURLにだけ載せ、DBにはハッシュを保存する（漏れても使えないように）。
 *   ・1メールアドレスにつき1件。もう一度発行すると古いリンクは無効になる。
 *   ・使い終わったら行ごと削除する（使い回し防止）。
 */
final class PasswordResetToken
{
    /**
     * expires_at が空の行（この仕組みより前に発行されたリンク）の期限。
     * 以前の判定と同じ「発行から60分」にして、切り替えの瞬間に挙動が変わらないようにする。
     */
    public const FALLBACK_MINUTES = 60;

    /**
     * リンク用の合言葉を発行する。戻り値はメールのURLに載せる平文。
     */
    public static function issue(string $email, CarbonInterface $expiresAt): string
    {
        $token = Str::random(64);

        DB::table('password_resets')->updateOrInsert(
            ['email' => $email],
            [
                'token'      => Hash::make($token),
                'created_at' => now(),
                'expires_at' => $expiresAt,
            ]
        );

        return $token;
    }

    /**
     * 合言葉が正しく、まだ期限内かどうか。
     * 「無い／合わない／期限切れ」はすべて false（画面では同じ文言にする＝どれが原因か教えない）。
     */
    public static function verify(string $email, string $token): bool
    {
        $row = DB::table('password_resets')->where('email', $email)->first();
        if (! $row) {
            return false;
        }

        $deadline = self::deadline($row);
        if (! $deadline || $deadline->isPast()) {
            return false;
        }

        return Hash::check($token, $row->token);
    }

    /** 使い終わった合言葉を消す。 */
    public static function consume(string $email): void
    {
        DB::table('password_resets')->where('email', $email)->delete();
    }

    /** その行の「いつまで有効か」。決められないときは null（＝無効扱い）。 */
    private static function deadline(object $row): ?Carbon
    {
        if (! empty($row->expires_at)) {
            return Carbon::parse($row->expires_at);
        }
        if (! empty($row->created_at)) {
            return Carbon::parse($row->created_at)->addMinutes(self::FALLBACK_MINUTES);
        }

        return null;
    }
}
