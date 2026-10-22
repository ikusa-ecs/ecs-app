<?php

namespace App\Support;

use App\Mail\BroadcastNoticeMail;
use App\Models\Person;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * お知らせメールの一斉送信の正本（2026-10-22 baba要望）。
 *
 * きっかけ＝PHPの穴の対策で、配布までECSの入口に共通パスワード（Basic認証）をかけた。
 * その共通パスワードを、グループではなく「ログインに使っているメール」へ1人ずつ届けたい。
 *
 * 【決まり】
 *   ・送り先＝ログインできる人（在籍中・メールあり・パスワード設定済み）。臨時スタッフと体験用は外す
 *     （ログイン案内 LoginInvite と同じ考え方）。
 *   ・1人に1通ずつ送る＝ほかの人のアドレスは見えない（BCCの付け忘れが起きない）。
 *   ・件名と本文は送るときに画面で入力する。⚠ 本文（共通パスワードなど）はDBにもコードにも残さない。
 *   ・一度に送るのは BATCH 人まで。画面のJSが小分けに呼ぶ＝送信中に画面の時間切れで止まらない。
 */
final class BroadcastMail
{
    public const BATCH = 20;

    /** 送り先の種類 → 名簿の role。 */
    public const TARGETS = ['all' => null, 'employee' => 'employee', 'staff' => 'staff'];

    /** @return Collection<int, Person> */
    public static function recipients(string $target): Collection
    {
        $q = Person::query()
            ->inService()
            ->whereNotNull('password')
            ->whereNotNull('email')->where('email', '<>', '')
            ->where(fn ($w) => $w->where('is_spot', false)->orWhereNull('is_spot'));

        $role = self::TARGETS[$target] ?? null;
        if ($role) {
            $q->where('role', $role);
        }

        return $q->orderBy('role')->orderBy('id')->get()
            ->reject(fn (Person $p) => TestAccounts::isTest($p))
            ->values();
    }

    /**
     * 渡された人たちに送る。送り先の条件から外れる人は送らずに理由を返す（黙って失敗しない）。
     *
     * @param  list<string>  $ids
     * @return array{sent: int, skipped: list<string>}
     */
    public static function sendTo(array $ids, string $target, string $subject, string $body): array
    {
        $allowed = self::recipients($target)->keyBy(fn (Person $p) => (string) $p->getKey());
        $sent = 0;
        $skipped = [];

        foreach (array_slice($ids, 0, self::BATCH) as $id) {
            $person = $allowed->get((string) $id);
            if (! $person) {
                $skipped[] = $id.'：送り先の条件に合わないため送りませんでした。';
                continue;
            }
            try {
                Mail::to(trim($person->email))->send(new BroadcastNoticeMail($subject, $body, $person->name));
                $sent++;
            } catch (Throwable $e) {
                $skipped[] = $person->name.' さんへの送信に失敗しました：'.$e->getMessage();
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }
}
