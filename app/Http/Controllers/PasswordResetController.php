<?php

namespace App\Http\Controllers;

use App\Mail\PasswordResetMail;
use App\Models\Person;
use App\Support\PasswordResetToken;
use App\Support\TestAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * パスワード再設定（お忘れの方）＝ログイン前（ゲスト）の画面。
 *
 * 流れ：
 *   ① /forgot-password … メールアドレスを入れる
 *   ② 送信            … 登録があればメールで「再設定ページへのリンク」を送る
 *   ③ /reset-password … メールのリンクを開き、新しいパスワードを設定する
 *
 * 仕組み（2段階認証のメールコードと同じ考え方）：
 *   ・リンクに入れる合言葉（トークン）の扱いは App\Support\PasswordResetToken に集約。
 *     ハッシュ化して保存し、平文はメールのURLだけ。使い終わったら削除。
 *   ・有効期限 60 分。⚠ ログイン案内メール（初回設定）は同じ仕組みで期限だけ7日＝
 *     期限は「発行するときに書き込む」（一律60分にすると案内メールが1時間で切れる不具合になる）。
 *   ・「そのメールが登録済みか」は画面に出さない（同じ文面）＝誰のアドレスが登録されているか探られないため。
 */
class PasswordResetController extends Controller
{
    /** リンクの有効期限（分）。config/auth.php の passwords.users.expire と揃える。 */
    private const EXPIRE_MINUTES = 60;

    /** ① メールアドレス入力ページ。 */
    public function showRequestForm()
    {
        return view('password_forgot');
    }

    /** ② 入力されたアドレス宛に再設定リンクを送る（登録が無くても同じ応答）。 */
    public function sendResetLink(Request $request)
    {
        $request->validate(
            ['email' => ['required', 'email']],
            [],
            ['email' => 'メールアドレス']
        );

        $email = $request->input('email');

        // テスト用アカウント（見本）は対象外。実在かつ有効な社員／スタッフのみ送る。
        $person = TestAccounts::findByEmail($email)
            ? null
            : Person::where('email', $email)->where('active', true)->first();

        if ($person) {
            // 平文トークンはメールのURLにだけ載せ、DBにはハッシュを保存する。
            $token = PasswordResetToken::issue($email, Carbon::now()->addMinutes(self::EXPIRE_MINUTES));

            $url = route('password.reset', ['token' => $token, 'email' => $email]);

            Mail::to($email)->send(new PasswordResetMail($url, self::EXPIRE_MINUTES, $person->name));
        }

        // 登録の有無にかかわらず同じメッセージ（アドレスの存在を推測させない）。
        return back()->with('status', 'password-reset-link-sent');
    }

    /** ③ 新しいパスワードの入力ページ（メールのリンクから来る）。 */
    public function showResetForm(Request $request)
    {
        return view('password_reset', [
            'token' => (string) $request->query('token', ''),
            'email' => (string) $request->query('email', ''),
        ]);
    }

    /** ③ 新しいパスワードを保存する。 */
    public function reset(Request $request)
    {
        $request->validate([
            'token'    => ['required', 'string'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'min:8', 'confirmed'],
        ], [], [
            'password' => '新しいパスワード',
        ]);

        $email = $request->input('email');

        // トークンが無い／合わない／期限切れは、まとめて同じエラーにする。
        // 期限そのものは発行のときに書き込んである（案内メールなら7日・お忘れの方なら60分）。
        if (! PasswordResetToken::verify($email, (string) $request->input('token'))) {
            return back()
                ->withInput(['email' => $email])
                ->withErrors(['email' => 'この再設定リンクは無効か、期限が切れています。お手数ですが、もう一度「パスワードをお忘れの方」からやり直してください。']);
        }

        $person = Person::where('email', $email)->where('active', true)->first();
        if (! $person) {
            return back()->withErrors(['email' => 'このメールアドレスのアカウントが見つかりません。']);
        }

        // 保存（Person の password はキャストで自動ハッシュ化される）。
        $person->password = $request->input('password');
        // 本人が自分で決めた印。初回設定の画面で、もう一度パスワードを聞かないために使う
        // （ログイン案内メールのリンクから来た人は、ここで既に決めているため・2026-08-25）。
        $person->password_set_at = Carbon::now();
        $person->save();

        // 使い終わったトークンは削除（使い回し防止）。
        PasswordResetToken::consume($email);

        // ログイン画面へ戻し、成功を知らせる。
        return redirect('/')->with('status', 'password-reset-done');
    }
}
