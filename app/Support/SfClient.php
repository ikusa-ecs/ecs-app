<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Salesforce（SF）につなぐ窓口（2026-11-20）。SFと話すのはこのクラスだけ。
 *
 * 【つなぎ方】SFの「外部クライアントアプリ（接続アプリ）」が出す鍵（番号と秘密の2つ）で入る
 *   ＝ client_credentials 方式。人のパスワードは使わない。
 *   SF側でこのアプリの「実行ユーザー」を連携専用ユーザー（見るだけ）にしてもらうので、
 *   ECSからは読むことしかできない（書き込みはしない・できない）。
 *
 * ⚠ 鍵はコードに書かない。.env の SF_URL / SF_CLIENT_ID / SF_CLIENT_SECRET（config/services.php の salesforce）。
 * ⚠ 鍵やトークンをログ・例外の文に出さない（下の例外はSFの返事の「エラーの種類」だけを出す）。
 */
final class SfClient
{
    /** 鍵が3つとも入っているか。入っていなければ「まだつないでいない」＝何もしない。 */
    public static function configured(): bool
    {
        return self::conf('url') !== '' && self::conf('client_id') !== '' && self::conf('client_secret') !== '';
    }

    private static function conf(string $key): string
    {
        return trim((string) config('services.salesforce.'.$key));
    }

    /**
     * SOQL（SFの検索文）を投げて、結果を全部受け取る。
     * ⚠ SFは1回に2000件までしか返さない＝「続きがある」と言われたら続きも取りに行く。
     *
     * @return list<array<string, mixed>>
     */
    public static function query(string $soql): array
    {
        [$token, $base] = self::login();
        $version = self::conf('api_version') ?: 'v62.0';

        $records = [];
        $url = $base.'/services/data/'.$version.'/query';
        $params = ['q' => $soql];
        // 続きのページは多くても数回のはず。万一SFが同じURLを返し続けても止まるように上限を付ける。
        for ($page = 0; $page < 50; $page++) {
            $res = Http::withToken($token)->acceptJson()->timeout(30)->get($url, $params);
            if (! $res->successful()) {
                throw new RuntimeException('SFの読み取りに失敗しました（'.$res->status().'・'.self::errorCode($res->json()).'）');
            }
            $body = (array) $res->json();
            foreach ((array) ($body['records'] ?? []) as $rec) {
                if (is_array($rec)) {
                    $records[] = $rec;
                }
            }
            $next = $body['nextRecordsUrl'] ?? null;
            if (($body['done'] ?? true) || ! is_string($next) || $next === '') {
                break;
            }
            $url = $base.$next;
            $params = [];
        }

        return $records;
    }

    /**
     * 鍵でSFに入り、通行証（トークン）と、実際に話しかける先のURLをもらう。
     *
     * @return array{0: string, 1: string}
     */
    private static function login(): array
    {
        if (! self::configured()) {
            throw new RuntimeException('SFの鍵がまだ設定されていません（SF_URL / SF_CLIENT_ID / SF_CLIENT_SECRET）');
        }
        $base = rtrim(self::conf('url'), '/');
        $res = Http::asForm()->acceptJson()->timeout(30)->post($base.'/services/oauth2/token', [
            'grant_type' => 'client_credentials',
            'client_id' => self::conf('client_id'),
            'client_secret' => self::conf('client_secret'),
        ]);
        $body = (array) $res->json();
        $token = $body['access_token'] ?? null;
        if (! $res->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException('SFに入れませんでした（'.$res->status().'・'.self::errorCode($body).'）');
        }
        $instance = $body['instance_url'] ?? null;

        return [$token, is_string($instance) && $instance !== '' ? rtrim($instance, '/') : $base];
    }

    /** SFの返事から「エラーの種類」だけ取り出す（中身の文章や鍵は出さない）。 */
    private static function errorCode(mixed $body): string
    {
        if (is_array($body)) {
            $code = $body['error'] ?? ($body[0]['errorCode'] ?? null);
            if (is_string($code) && $code !== '') {
                return $code;
            }
        }

        return '理由不明';
    }
}
