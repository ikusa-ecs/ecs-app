<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * チャットワークAPIの薄いラッパー。
 * GAS版の postChatwork_ / postChatworkTask_ / fetchRoomMembers_ に相当。
 *
 * トークンは config('services.chatwork.token')（＝.env の CHATWORK_TOKEN）から読む。
 * コードには鍵を書かない。
 */
class ChatworkClient
{
    private const ENDPOINT = 'https://api.chatwork.com/v2';

    public function __construct(private ?string $token = null)
    {
        $this->token = $token ?? config('services.chatwork.token');
    }

    /** トークンが設定されているか（未設定なら送信系は使えない）。 */
    public function hasToken(): bool
    {
        return ! empty($this->token);
    }

    /**
     * ルームのメンバー一覧を取得（[{account_id, name, ...}]）。
     * 「名前→CWID」辞書づくりに使う。
     */
    public function roomMembers(string $room): array
    {
        $res = Http::withHeaders(['X-ChatWorkToken' => $this->token])
            ->get(self::ENDPOINT . '/rooms/' . rawurlencode($room) . '/members');

        if (! $res->ok()) {
            throw new RuntimeException('メンバー取得 HTTP ' . $res->status() . ': ' . $res->body());
        }

        return $res->json() ?? [];
    }

    /** ルームにメッセージを1通投稿する。 */
    public function postMessage(string $room, string $body): void
    {
        $res = Http::withHeaders(['X-ChatWorkToken' => $this->token])
            ->asForm()
            ->post(self::ENDPOINT . '/rooms/' . rawurlencode($room) . '/messages', [
                'body' => $body,
            ]);

        if (! $res->ok()) {
            throw new RuntimeException('メッセージ送信 HTTP ' . $res->status() . ': ' . $res->body());
        }
    }

    /**
     * ルームにタスクを登録する。
     * $toIds＝担当者のCWID（カンマ区切り可）／$limitUnixSec＝期限（Unix秒）。
     */
    public function postTask(string $room, string $body, string $toIds, int $limitUnixSec): array
    {
        $res = Http::withHeaders(['X-ChatWorkToken' => $this->token])
            ->asForm()
            ->post(self::ENDPOINT . '/rooms/' . rawurlencode($room) . '/tasks', [
                'body' => $body,
                'to_ids' => $toIds,
                'limit' => (string) $limitUnixSec,
                'limit_type' => 'date',
            ]);

        if (! $res->ok()) {
            throw new RuntimeException('タスク登録 HTTP ' . $res->status() . ': ' . $res->body());
        }

        return $res->json() ?? [];
    }
}
