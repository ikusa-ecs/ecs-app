<?php

namespace App\Support;

/**
 * CSS・JS などの「置いてあるファイル」に付ける更新印。
 *
 * ブラウザはCSSを覚えて（キャッシュして）くれるので表示が速いが、
 * こちらがCSSを直しても古いものを使い続けてしまうことがある。
 * とくにスマホは強く、「直したのに変わらない」の原因になっていた。
 *
 * URLの末尾に ?v=（ファイルの更新日時）を足すと、中身を直したときだけ
 * URLが変わる＝ブラウザが新しいものを取りに行く。直していないときは
 * URLが同じままなので、これまでどおりキャッシュが効いて速い。
 */
class Asset
{
    /** 同じリクエスト内で同じファイルを何度も調べないための控え。 */
    private static array $cache = [];

    /**
     * public/ の中のファイルの更新印を返す（例: '1755400000'）。
     * ファイルが見つからないときは '0'（印なしと同じ扱い）。
     */
    public static function ver(string $relativePath): string
    {
        if (isset(self::$cache[$relativePath])) {
            return self::$cache[$relativePath];
        }

        $time = @filemtime(public_path($relativePath));

        return self::$cache[$relativePath] = (string) ($time ?: 0);
    }
}
