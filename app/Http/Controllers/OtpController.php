<?php

namespace App\Http\Controllers;

use App\Mail\LoginCodeMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * 2段階認証（メールでコード）の入力ページ。
 * ログイン後、業務画面へ進む前にここで「メールに届いた6桁コード」を入れてもらう。
 *
 * 仕組み：
 * ・コードはサーバー側で6桁をランダム生成し、ハッシュ化してセッションに保持（平文は保存しない）。
 * ・平文はメール（ローカル=ログ / 本番=SES）で本人へ送る。
 * ・有効期限10分・試行回数5回まで。合致すれば session('twofa_ok')=true にして通過。
 */
class OtpController extends Controller
{
    private const TTL_MINUTES = 10;
    private const MAX_ATTEMPTS = 5;

    /** コード入力ページ。まだコードが無い／期限切れなら新規に発行してメール送信する。 */
    public function show(Request $request)
    {
        // すでに確認済みなら行き先へ。
        if ($request->session()->get('twofa_ok')) {
            return redirect($this->homeFor(Auth::user()));
        }

        if (! $this->hasValidPendingCode($request)) {
            $this->issueCode($request);
        }

        return view('otp_challenge', [
            'email' => Auth::user()->email,
        ]);
    }

    /** 入力されたコードを照合する。 */
    public function verify(Request $request)
    {
        $request->validate(['code' => ['required', 'string']], [], ['code' => '確認コード']);

        $pending = $request->session()->get('twofa');

        if (! $this->hasValidPendingCode($request)) {
            throw ValidationException::withMessages([
                'code' => 'コードの有効期限が切れました。「コードを再送する」で新しいコードを取り寄せてください。',
            ]);
        }

        if (($pending['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            throw ValidationException::withMessages([
                'code' => '入力回数が上限に達しました。「コードを再送する」でやり直してください。',
            ]);
        }

        if (! Hash::check(trim($request->input('code')), $pending['code_hash'])) {
            // 試行回数を1つ増やして戻す。
            $pending['attempts'] = ($pending['attempts'] ?? 0) + 1;
            $request->session()->put('twofa', $pending);

            throw ValidationException::withMessages([
                'code' => 'コードが違います。メールに届いた6桁を確認してください。',
            ]);
        }

        // 合致：このセッションを「2段階認証OK」にする。
        $request->session()->forget('twofa');
        $request->session()->put('twofa_ok', true);

        return redirect()->intended($this->homeFor(Auth::user()));
    }

    /** コードを再送する。 */
    public function resend(Request $request)
    {
        $this->issueCode($request);

        return redirect()->route('otp.challenge')->with('status', 'confirmation-code-sent');
    }

    /** 6桁コードを新規発行し、ハッシュをセッションに保存＋平文をメール送信する。 */
    private function issueCode(Request $request): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $request->session()->put('twofa', [
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES)->getTimestamp(),
            'attempts' => 0,
        ]);

        $user = Auth::user();
        Mail::to($user->email)->send(new LoginCodeMail($code, $user->name));
    }

    /** セッションに有効な（期限内の）コードがあるか。 */
    private function hasValidPendingCode(Request $request): bool
    {
        $pending = $request->session()->get('twofa');

        return is_array($pending)
            && isset($pending['code_hash'], $pending['expires_at'])
            && $pending['expires_at'] >= now()->getTimestamp();
    }

    /** 確認後の行き先：スタッフはスタッフ画面、それ以外は業務ダッシュボード。 */
    private function homeFor($user): string
    {
        return ($user && $user->role === 'staff') ? '/staff-portal' : '/dashboard';
    }
}
