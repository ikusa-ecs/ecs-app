<?php

namespace App\Support;

use App\Models\Person;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * チャットワークの知らせで「誰にメンション（[To:]）を付けるか」の正本（2026-09-17 baba要望）。
 *
 * 【babaの言葉】
 * 「チャットワークの送り先のところにメンションする人を選べるようにしてほしい。
 *   人数確定リマインドや収支未入力リマインドは対象の人にタスク付けされると思うけど、
 *   アサイン表自動取り込みもアサイン担当にメンションがほしくて、
 *   できれば任意で選んだ人にできるようにしたい」
 *
 * 【考え方】
 * 送り先（部屋）は {@see ChatworkRooms} が持つ。ここはその「宛名」だけを持つ。
 * 知らせの種類は ChatworkRooms::KINDS と同じものを使う＝画面の並びも同じになる。
 *
 * ⚠ 覚えるのは **people.id**（名簿の人）であって、チャットワークIDではない。
 *   チャットワークIDは人ごとに `people.chatwork_id` に入っている（名簿・アカウント管理から登録）。
 *   IDを設定画面に直接書かせると、人が入れ替わったときに誰のことか分からなくなる。
 *
 * ⚠ チャットワークIDが登録されていない人は**飛ばす**（送信そのものは止めない）。
 *   1人ぶんのメンションが付かないために知らせが丸ごと届かない、が一番困るため。
 *   代わりに設定画面で「ID未登録」と出して、気づけるようにする。
 *
 * ⚠ 人数確定・収支リマインドは、もともと**その案件の担当者**に [To:] とタスクが付く。
 *   ここで選ぶ人は、それに**足す**もの（毎回必ず見てほしい人）。置き換えではない。
 */
final class ChatworkMentions
{
    /** settings テーブルのキーの頭。 */
    private const KEY_PREFIX = 'chatwork_mention:';

    /** 1つの知らせに選べる人数の上限（宛名だらけで本文が読めなくならないように）。 */
    public const MAX_PEOPLE = 10;

    /**
     * 「毎朝の『届きました』報告にもメンションを付けるか」の保存先。
     *
     * ⚠ 既定は**付けない**。アサイン表の知らせは2通あり、
     *   ①毎朝の「届きました」報告（毎日鳴る）／②届かなかった日の警告（本命）。
     *   ①に毎日メンションが飛ぶと、すぐ見なくなって②に気づけなくなる
     *   （＝オオカミ少年になる）。必要なら設定画面で入れられる。
     */
    private const DAILY_REPORT_KEY = 'chatwork_mention_sheet_report';

    /** 毎朝の「届きました」報告にもメンションを付けるか（既定＝付けない）。 */
    public static function mentionDailyReport(): bool
    {
        return (string) Setting::get(self::DAILY_REPORT_KEY, '0') === '1';
    }

    /** 上の設定を保存する。 */
    public static function saveMentionDailyReport(bool $on): void
    {
        Setting::put(self::DAILY_REPORT_KEY, $on ? '1' : '0');
    }

    /**
     * その知らせでメンションする人（名簿のID）。
     *
     * @return list<string>
     */
    public static function ids(string $kind): array
    {
        $raw = Setting::get(self::KEY_PREFIX.$kind);
        $arr = is_array($raw) ? $raw : json_decode((string) $raw, true);

        if (! is_array($arr)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($v) => trim((string) $v),
            $arr
        ), fn ($v) => $v !== ''));
    }

    /**
     * 保存する。名簿にいない人は捨てる（消えた人のIDが残り続けないように）。
     *
     * @param  array<int, string>  $personIds
     * @return list<string> 保存した名簿のID
     */
    public static function save(string $kind, array $personIds): array
    {
        $wanted = array_values(array_unique(array_filter(array_map(
            fn ($v) => trim((string) $v),
            $personIds
        ), fn ($v) => $v !== '')));

        $exists = Person::whereIn('id', $wanted)->pluck('id')->all();

        // 画面に並んだ順（＝名簿の順）を保つため、$wanted の並びで絞り込む。
        $list = array_values(array_filter($wanted, fn ($id) => in_array($id, $exists, true)));
        $list = array_slice($list, 0, self::MAX_PEOPLE);

        Setting::put(self::KEY_PREFIX.$kind, json_encode($list, JSON_UNESCAPED_UNICODE));

        return $list;
    }

    /**
     * 本文の先頭に付ける宛名。例： `[To:1234567]山田 太郎さん\n`
     *
     * 誰も選んでいない／選んだ人にチャットワークIDが無いときは**空文字**を返す。
     * ⚠ 呼ぶ側は「空なら何も足さない」だけでよい（空行を作らないこと）。
     */
    public static function head(string $kind): string
    {
        $people = self::chosenWithId($kind);
        if ($people->isEmpty()) {
            return '';
        }

        return $people
            ->map(fn (Person $p) => '[To:'.trim((string) $p->chatwork_id).']'.$p->name.'さん')
            ->implode('')."\n";
    }

    /**
     * 本文の先頭に宛名を足したものを返す（何も選ばれていなければ本文のまま）。
     * ⚠ 宛名の付け方を各サービスに書き写さないための入口。
     */
    public static function prefix(string $kind, string $body): string
    {
        $head = self::head($kind);

        return $head === '' ? $body : $head.$body;
    }

    /**
     * 選ばれていて、かつチャットワークIDが登録されている人。
     *
     * @return Collection<int, Person>
     */
    public static function chosenWithId(string $kind): Collection
    {
        $ids = self::ids($kind);
        if (! $ids) {
            return collect();
        }

        $people = Person::whereIn('id', $ids)
            ->whereNotNull('chatwork_id')
            ->where('chatwork_id', '!=', '')
            ->get(['id', 'name', 'chatwork_id'])
            ->keyBy('id');

        // 選んだ順（＝保存した順）で返す。
        return collect($ids)->map(fn ($id) => $people->get($id))->filter()->values();
    }

    /**
     * 選ばれているのに**チャットワークIDが登録されていない**人の名前。
     * ⚠ 設定画面に出して気づけるようにする（黙って飛ばすと「なぜ鳴らない？」になる）。
     *
     * @return list<string>
     */
    public static function missingIdNames(string $kind): array
    {
        $ids = self::ids($kind);
        if (! $ids) {
            return [];
        }

        return Person::whereIn('id', $ids)
            ->where(fn ($q) => $q->whereNull('chatwork_id')->orWhere('chatwork_id', ''))
            ->pluck('name')
            ->all();
    }

    /**
     * 設定画面のチェックに並べる人（社員だけ・名簿の並び）。
     * チャットワークIDが入っているかも一緒に渡す＝画面で「ID未登録」と出すため。
     *
     * @return list<array{id:string, name:string, office:string, hasId:bool}>
     */
    public static function options(): array
    {
        return Person::employees()
            ->orderBy('name')
            ->get(['id', 'name', 'office', 'chatwork_id'])
            ->map(fn (Person $p) => [
                'id' => (string) $p->id,
                'name' => (string) $p->name,
                'office' => (string) ($p->office ?? ''),
                'hasId' => trim((string) ($p->chatwork_id ?? '')) !== '',
            ])
            ->values()
            ->all();
    }
}
