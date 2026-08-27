<?php

namespace App\Support;

use App\Models\Person;
use Illuminate\Support\Collection;

/**
 * 「氏名の文字列」から名簿（people）の人を探す共通部品（2026-08-24）。
 *
 * なぜ要るか＝過去案件のCSVには、アサインされた人が「氏名」で書かれている
 * （D・MC・OP・スタッフの列）。氏名は書き方がぶれるので、素直な完全一致だと
 * 見つからない人が大量に出る。逆に雑に照合すると別人のアサインを作ってしまう。
 *
 * 【方針】
 *  ・照合は「空白を落とした氏名」で行う（「田中健一」でも「田中 健一」でも当たる）。
 *  ・同姓同名が2人以上いたら「決められない」として返す＝勝手に選ばない。
 *    人の取り違えは、あとから気づけない事故になるため。
 *  ・見つからない人は「見つからない」として返す＝呼ぶ側が一覧で知らせる。
 *
 * ⚠ 名前の空白の落とし方は App\Support\ChatworkIds::normName と同じにしている
 *   （同じ「氏名の突き合わせ」で規則が違うと、画面によって当たる／当たらないが変わる）。
 */
final class PersonLookup
{
    /** 突き合わせ用に氏名をそろえる（全角・半角の空白を落とす）。 */
    /**
     * 氏名の飾り記号（★☆など）。
     *
     * アサイン表では、スタッフ（アルバイト）の名前の頭に「★」「☆」を付けて区別している
     * （2026-08-27 baba）。名簿には付いていないので、そのまま照合すると
     * **その人だけ「名簿に見つかりません」になり、アサインが丸ごと落ちる**。
     * 照合のときだけ落とす（名簿に保存する名前は触らない）。
     */
    private const NAME_MARKS = ['★', '☆', '◎', '●', '○', '◯', '▲', '△', '▼', '▽', '■', '□', '◆', '◇', '※', '*'];

    public static function normName(string $name): string
    {
        return ChatworkIds::normName(str_replace(self::NAME_MARKS, '', $name));
    }

    /**
     * 人に見せる／名簿に登録するときの氏名。飾り記号（★☆）だけを外し、空白はそのまま残す。
     *
     * ⚠ 突き合わせ用の normName とは別物。normName は空白まで落とすので、
     *   そのまま名簿に登録すると「永松一子」のように空白の無い名前で登録されてしまう。
     */
    public static function displayName(string $name): string
    {
        return trim(str_replace(self::NAME_MARKS, '', $name));
    }

    /**
     * 「そろえた氏名 → その名前の人たち」の辞書を作る。
     * 何度も引くので、呼ぶ側で1回作って使い回す。
     *
     * @return array<string, list<array{id: string, name: string}>>
     */
    public static function index(): array
    {
        $map = [];
        Person::query()
            ->get(['id', 'name'])
            ->each(function (Person $p) use (&$map) {
                $key = self::normName((string) $p->name);
                if ($key === '') {
                    return;
                }
                $map[$key][] = ['id' => $p->id, 'name' => $p->name];
            });

        return $map;
    }

    /**
     * 氏名の文字列（カンマ区切りで複数可）を、名簿のIDに直す。
     *
     * @param  string  $cell  例「田中 健一, 鈴木彩」
     * @param  array  $index  index() の結果
     * @return array{ids: list<string>, missing: list<string>, ambiguous: list<string>}
     *         ids＝見つかったIDの一覧／missing＝名簿に無かった名前／ambiguous＝同姓同名で決められなかった名前
     */
    public static function resolveNames(string $cell, array $index): array
    {
        $ids = [];
        $missing = [];
        $ambiguous = [];

        foreach (self::splitNames($cell) as $name) {
            $key = self::normName($name);
            $hits = $index[$key] ?? [];

            if (count($hits) === 1) {
                $ids[] = $hits[0]['id'];
            } elseif (count($hits) > 1) {
                // ⚠ 知らせる名前は飾り記号を外したもの。そのまま名簿に登録できるようにするため
                //   （名古屋のアサイン表は「★永松 一子」のように印が付いている・2026-08-27 baba）。
                $ambiguous[] = self::displayName($name);
            } else {
                $missing[] = self::displayName($name);
            }
        }

        return [
            'ids' => array_values(array_unique($ids)),
            'missing' => array_values(array_unique($missing)),
            'ambiguous' => array_values(array_unique($ambiguous)),
        ];
    }

    /**
     * 1つのセルに書かれた氏名を分ける。
     * 区切りはカンマ（半角・全角）・読点・スラッシュ・改行を許す
     * （人が書くものなので、区切り方をひとつに強制しない）。
     * ⚠ 中黒（・）は区切りにしない＝氏名に使われることがあるため。
     *
     * @return list<string>
     */
    public static function splitNames(string $cell): array
    {
        $parts = preg_split('/[,、，\/／\r\n]+/u', $cell) ?: [];

        return array_values(array_filter(
            array_map(fn ($s) => trim((string) $s), $parts),
            fn ($s) => $s !== ''
        ));
    }
}
