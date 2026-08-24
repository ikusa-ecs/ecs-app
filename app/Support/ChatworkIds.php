<?php

namespace App\Support;

use App\Models\Person;

/**
 * 「この人のチャットワークID」を引く共通部品（2026-08-24 baba要望）。
 *
 * これまで：宛先は「ECSの氏名」と「チャットワークのルームメンバーの表示名」を
 * 突き合わせて決めていた。表記ゆれ（スペース・旧姓・ニックネーム・絵文字つきの表示名）で
 * 照合が外れると、その人にタスクが飛ばない。実際「照合できなかった名前」を
 * 集めて画面に出す作り＝外れる前提の設計になっていた。
 *
 * これから：名簿（people.chatwork_id）に登録があればそれを使う。
 * 登録が無い人だけ、今までどおりルームメンバーの名前で照合する（併用）。
 *
 * ⚠ 名前の正規化（空白を落とす）はここが正本。各サービスで別々に書かないこと。
 */
final class ChatworkIds
{
    /** 突き合わせ用に名前をそろえる（全角・半角の空白を落とす）。 */
    public static function normName(string $s): string
    {
        return preg_replace('/[\s\x{3000}]+/u', '', trim($s));
    }

    /**
     * 名簿に登録済みのIDから「名前 → CWID」を作る。
     * 名簿が正なので、これをベースにしてルームメンバーの照合結果を「穴埋め」として足す。
     */
    public static function fromPeople(): array
    {
        $map = [];
        Person::whereNotNull('chatwork_id')
            ->get(['name', 'chatwork_id'])
            ->each(function (Person $p) use (&$map) {
                $key = self::normName((string) $p->name);
                $id = trim((string) $p->chatwork_id);
                if ($key !== '' && $id !== '') {
                    $map[$key] = $id;
                }
            });

        return $map;
    }

    /**
     * 名簿の登録を優先し、足りないぶんをルームメンバーの照合で埋める。
     *
     * @param  array  $registered  fromPeople() の結果（名簿の登録）
     * @param  array  $fromRoom    ルームメンバーから作った「名前 → CWID」
     */
    public static function merge(array $registered, array $fromRoom): array
    {
        // 先に $fromRoom を置き、あとから $registered で上書き＝名簿の登録が勝つ。
        return array_merge($fromRoom, $registered);
    }
}
