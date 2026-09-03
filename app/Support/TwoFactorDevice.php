<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * 2段階認証「この端末を30日間おぼえる」（2026-09-03）。
 *
 * ねらい：スタッフから「開くたびにメールの6桁コードを入れさせられて面倒」という声。
 * 原因は2つ重なっていた：
 *   ・ログイン状態が2時間で切れる（.env の SESSION_LIFETIME=120）
 *   ・「コード確認済み」の印が session('twofa_ok') ＝ **そのログイン中しか残らない**
 * そのためログイン画面の「ログイン状態を保持する」にチェックを入れても、
 * ログインは自動で戻るのに**確認コードだけ毎回聞かれる**状態だった。
 *
 * 直し方：コードが合ったときに、本人と結びついた印をクッキーへ30日ぶん置く。
 * 次からはそのクッキーがあればコード入力をとばす（銀行やGoogleと同じやり方）。
 *
 * ⚠ 覚える／照合する／忘れる の3つは、コード入力ページ・ゲート・解除ボタンの
 *   3か所から使う。**必ずこのクラス1か所にまとめる**（書き写すと食い違って事故る）。
 *
 * クッキーの中身：`利用者ID . '.' . 署名`
 *   ・署名 ＝ 利用者IDとDBのパスワードのハッシュを材料に、APP_KEY で作ったHMAC。
 *   ・生のパスワードも個人情報も入れない。
 *   ・パスワードのハッシュを材料にしているので、**パスワードを変えると署名が合わなくなり、
 *     おぼえた端末はすべて自動で無効**になる（乗っ取られたときに切り離せる）。
 *   ・クッキー自体も Laravel が暗号化して渡す（EncryptCookies）。
 */
class TwoFactorDevice
{
    /** クッキーの名前。 */
    public const COOKIE = 'ecs_twofa_device';

    /** おぼえておく日数。 */
    public const DAYS = 30;

    /**
     * この端末をおぼえるクッキーを作る。
     * ⚠ 返すだけ。実際に渡すのは呼び出し側（`->withCookie(...)`）。
     */
    public static function issue($user): ?SymfonyCookie
    {
        $token = self::token($user);

        if ($token === null) {
            return null;
        }

        return cookie(
            self::COOKIE,
            $token,
            self::DAYS * 24 * 60,   // 分で指定する
            null,                   // path     … 未指定＝セッションと同じ設定を使う
            null,                   // domain   … 同上
            null,                   // secure   … 同上（本番httpsなら自動でsecureになる）
            true,                   // httpOnly … 画面のJavaScriptからは読めない
            false,                  // raw
            null                    // SameSite … 同上（既定は lax）
        );
    }

    /** おぼえたことを取り消すクッキー（＝消す指示）を作る。 */
    public static function forget(): SymfonyCookie
    {
        return Cookie::forget(self::COOKIE);
    }

    /**
     * 届いたクッキーが「いまログインしている本人のもの」かを照べる。
     *
     * ⚠ 必ずログイン中の本人と突き合わせる。IDだけを見て通すと、
     *   別の人のクッキーが残っている端末でコードをとばせてしまう。
     */
    public static function matches(Request $request, $user): bool
    {
        $token = self::token($user);

        if ($token === null) {
            return false;
        }

        $value = $request->cookies->get(self::COOKIE);

        if (! is_string($value) || $value === '') {
            return false;
        }

        // hash_equals ＝ 1文字ずつ比べる時間の差から中身を当てられないようにする比べ方。
        return hash_equals($token, $value);
    }

    /**
     * クッキーに入れる印を作る。
     * パスワードが未設定の人（発行直後など）は、おぼえる対象にしない。
     */
    private static function token($user): ?string
    {
        if (! $user) {
            return null;
        }

        $id = (string) $user->getAuthIdentifier();
        $hash = (string) $user->getAuthPassword();

        if ($id === '' || $hash === '') {
            return null;
        }

        return $id.'.'.hash_hmac('sha256', $id.'|'.$hash, (string) config('app.key'));
    }
}
