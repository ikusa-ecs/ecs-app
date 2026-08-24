<?php

namespace App\Support;

/**
 * 仮パスワードの作り方の正本。
 *
 * 「アカウント発行（1人ずつ）」と「名簿CSV一括取込（まとめて発行）」の2か所で使う。
 * 別々に書くと、片方だけ紛らわしい文字が混ざる等の食い違いが出るのでここに寄せる。
 *
 * ・読み間違えにくい文字だけを使う（0/O、1/l/I を入れない）＝口頭やチャットで伝えるため。
 * ・random_int は暗号的に安全（推測されにくい）。
 * ・本人は初回ログインの初期設定で必ず自分のパスワードに変える（must_onboard=true）。
 */
final class TempPassword
{
    /** 紛らわしい 0 O 1 l I を除いた文字だけ。 */
    private const CHARS = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function make(int $length = 8): string
    {
        $max = strlen(self::CHARS) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::CHARS[random_int(0, $max)];
        }

        return $out;
    }
}
