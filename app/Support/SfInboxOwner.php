<?php

namespace App\Support;

use App\Models\Person;
use App\Models\SfInboxItem;
use Illuminate\Support\Collection;

/**
 * SFの商談が「だれあてか」を決める正本（2026-11-20）。
 *
 * 【決まり（小沼さん 11/20）】
 *   ・商談の担当営業（SFの Owner.Email）と、ECSの名簿（people.email）のメールが同じ人＝その商談の「本人」。
 *   ・本人のマイページに、自分あての「取り込み待ち」の商談を出す。本人が見て「案件として登録」か「見送り」を押す。
 *   ・受信箱（/sf-inbox）は管理者以上の画面だが、本人あての商談に限り、社員でも登録・見送りできる。
 *     ⚠ 他人あての商談は社員には押させない。画面でボタンを隠すだけでなく、サーバー側で必ずここを通して判定する
 *       （URLを手で打てば他人の商談も送れてしまうため）。
 *
 * ⚠ メールは大文字・小文字と前後の空白を無視して比べる（SFとECSで書き方がずれていても本人に届くように）。
 * ⚠ メールが空の人・空の商談は、だれの本人にもしない（空どうしが「一致」して他人の商談が出る事故を防ぐ）。
 */
final class SfInboxOwner
{
    /** 商談の担当営業のメール（小文字・空白なし）。列が空なら受け取った中身（Owner.Email）を見る。 */
    public static function emailOf(SfInboxItem $item): string
    {
        $email = (string) ($item->owner_email ?? '');
        if (trim($email) === '') {
            $payload = is_array($item->payload) ? $item->payload : [];
            $email = (string) ($payload['Owner']['Email'] ?? '');
        }

        return self::normalize($email);
    }

    /** この人が、この商談の本人（担当営業）か。 */
    public static function isOwner(SfInboxItem $item, ?Person $person): bool
    {
        if (! $person) {
            return false;
        }
        $mine = self::normalize((string) ($person->email ?? ''));
        if ($mine === '') {
            return false;
        }

        return self::emailOf($item) === $mine;
    }

    /**
     * この人あての「取り込み待ち」の商談（新しく届いた順）。
     *
     * @return Collection<int, SfInboxItem>
     */
    public static function waitingFor(?Person $person): Collection
    {
        if (! $person || self::normalize((string) ($person->email ?? '')) === '') {
            return collect();
        }

        // ⚠ メールの列が空で、中身（payload）にだけメールがある商談もあるので、件数の少ない「取り込み待ち」を全部読んでから見分ける。
        return SfInboxItem::where('status', SfInboxItem::WAITING)
            ->orderByDesc('received_at')->orderByDesc('id')
            ->get()
            ->filter(fn (SfInboxItem $item) => self::isOwner($item, $person))
            ->values();
    }

    /**
     * この人が担当営業で「見送り」にしてある商談（新しく見送った順）。マイページから本人が戻せるように出す（2026-11-20 小沼さん）。
     *
     * @return Collection<int, SfInboxItem>
     */
    public static function skippedFor(?Person $person): Collection
    {
        if (! $person || self::normalize((string) ($person->email ?? '')) === '') {
            return collect();
        }

        return SfInboxItem::where('status', SfInboxItem::SKIPPED)
            ->orderByDesc('decided_at')->orderByDesc('id')
            ->get()
            ->filter(fn (SfInboxItem $item) => self::isOwner($item, $person))
            ->values();
    }

    private static function normalize(string $email): string
    {
        return mb_strtolower(preg_replace('/\A[\s\x{3000}]+|[\s\x{3000}]+\z/u', '', $email) ?? '');
    }
}
