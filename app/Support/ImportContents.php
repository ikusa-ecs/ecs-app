<?php

namespace App\Support;

use App\Models\Content;

/**
 * 取込（アサイン表の取込・毎朝の受信箱・案件CSV）で、シートに書かれたコンテンツ名を
 * コンテンツ台帳につなぐ正本（2026-09-29 baba要望「コンテンツは量産しないでほしい」）。
 *
 * 【何が起きていたか】
 *   ① 1つの欄に「謎パ・格付けバトル」のように**複数書いてあっても、まるごと1つの名前**として扱い、
 *      「謎パ・格付けバトル」という新しいコンテンツを台帳に作っていた。
 *   ② 台帳と**1文字でも**違う（全角/半角・空白など）と、別物として台帳に足していた。
 *
 * 【いまの決まり】
 *   ・まず欄の名前まるごとで台帳を探す（「ヒラメキ・クエスト」のように「・」を含む名前もあるため）。
 *   ・見つからなければ、区切り記号（・＋／、改行 など）で分けて、1つずつ台帳を探す。
 *   ・比べるときは書き方の違いを無視する（正本＝RoleRequirementCsv::normalizeName）。
 *   ・**取込では台帳に足さない。** 見つからなかった名前は、その案件だけの「単発」として
 *     案件に文字で残し（content_names）、取込のあとに一覧で知らせる＝足すかどうかは人が決める。
 *
 * ⚠ 案件登録の画面（人が1つずつ選ぶ）はこれを通さない。あちらは選んだものをそのまま使う。
 */
final class ImportContents
{
    /** 1つの欄に複数のコンテンツを書くときの区切り（「・」は名前の中にもあるので、まるごと探したあとで使う）。 */
    private const SEPARATORS = '/[・･＋+／\/、,，＆&×\r\n]+/u';

    /** 台帳の引き当て表（1回の取込で何百回も呼ばれるので、読むのは1回だけ）。 */
    private ?array $exact = null;

    private ?array $byNorm = null;

    /**
     * 欄の文字 → 台帳のコンテンツ。
     *
     * @return array{ids: list<string>, names: list<string>, unknown: list<string>}
     *   ids＝つながった台帳のID／names＝案件に出す名前（台帳の名前・見つからないものは書いてあったまま）
     *   unknown＝台帳に見つからなかった名前（単発として残る＝取込のあとに知らせる）
     */
    public function resolve(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['ids' => [], 'names' => [], 'unknown' => []];
        }

        // ① まるごと
        $whole = $this->find($raw);
        if ($whole !== null) {
            return ['ids' => [$whole['id']], 'names' => [$whole['name']], 'unknown' => []];
        }

        // ② 区切って1つずつ
        $parts = array_values(array_unique(array_filter(
            array_map('trim', preg_split(self::SEPARATORS, $raw) ?: []),
            fn ($s) => $s !== ''
        )));
        if ($parts === []) {
            $parts = [$raw];
        }

        $ids = [];
        $names = [];
        $unknown = [];
        foreach ($parts as $p) {
            $hit = $this->find($p);
            if ($hit !== null) {
                if (! in_array($hit['id'], $ids, true)) {
                    $ids[] = $hit['id'];
                    $names[] = $hit['name'];
                }
            } else {
                $names[] = $p;
                $unknown[] = $p;
            }
        }

        // ⚠ 分けても1つも台帳に当たらないときは、分けずに書いてあったまま1つの単発にする
        //   （知らない名前を勝手に切り刻むと、かえって分からなくなる）。
        if ($ids === []) {
            return ['ids' => [], 'names' => [$raw], 'unknown' => [$raw]];
        }

        return ['ids' => $ids, 'names' => $names, 'unknown' => $unknown];
    }

    /** 名前1つ → 台帳の1件（完全一致 → 書き方だけ違う一致。2件以上なら古いほう）。 */
    private function find(string $name): ?array
    {
        $this->load();
        if (isset($this->exact[$name])) {
            return $this->exact[$name];
        }
        $norm = RoleRequirementCsv::normalizeName($name);

        return $norm !== '' ? ($this->byNorm[$norm] ?? null) : null;
    }

    private function load(): void
    {
        if ($this->exact !== null) {
            return;
        }
        $this->exact = [];
        $this->byNorm = [];
        foreach (Content::orderBy('id')->get(['id', 'content_name']) as $c) {
            $name = (string) $c->content_name;
            $hit = ['id' => (string) $c->id, 'name' => $name];
            $this->exact[$name] ??= $hit;
            $norm = RoleRequirementCsv::normalizeName($name);
            if ($norm !== '') {
                $this->byNorm[$norm] ??= $hit;   // ⚠ 2件以上あれば古いほう（IDが小さいほう）
            }
        }
    }

    /**
     * 顧客名を「同じお客様か」を比べるための形にする（2026-09-29）。
     * 「株式会社」「（株）」「様」・空白・全角半角の違いは無いものとして比べる。
     * ⚠ 比べるときだけ使う。保存する顧客名は変えない。
     */
    public static function clientKey(?string $client): string
    {
        $s = mb_convert_kana(trim((string) ClientName::normalize($client)), 'asKV');
        $s = str_replace(['株式会社', '有限会社', '合同会社', '一般社団法人', '（株）', '(株)', '㈱', '（有）', '(有)', '様'], '', $s);
        $s = (string) preg_replace('/[\s・\x{3000}]+/u', '', $s);

        return mb_strtolower($s, 'UTF-8');
    }

    /** コンテンツ名の並びを「同じ中身か」を比べるための形にする（順番・書き方の違いは無視）。 */
    public static function namesKey(array $names): string
    {
        $keys = array_values(array_unique(array_filter(array_map(
            fn ($n) => RoleRequirementCsv::normalizeName((string) $n),
            $names
        ))));
        sort($keys);

        return implode('|', $keys);
    }
}
