<?php

namespace App\Support;

/**
 * 運営人数の「6〜8人」のような おおよその数 の正本（2026-08-25 baba要望）。
 *
 * 【持ち方】
 *   projects.required_count      … 計算に使う数字（＝範囲の**多いほう**）。今までと同じ意味。
 *   projects.required_count_min  … 範囲の少ないほう。範囲でないときは null。
 *
 * ⚠ なぜ required_count を「多いほう」にしたか
 *   「残り○名」「必要○名」の計算は、すでにアプリ中の20か所ほどが required_count を見ている。
 *   多いほうをここに入れておけば、**計算の作りをひとつも変えずに**「8人埋まって初めて満員」
 *   （2026-08-25 baba決定）が成り立つ。直すのは入力欄と表示だけで済む。
 *
 * ⚠ 「6〜8」を数字だけ抜き出すと「68」になる（実際にCSV取込がそうなっていた）。
 *   数の読み取りは必ずこのクラスを通すこと。
 */
final class Headcount
{
    /** 範囲の区切りとして認める文字（全角チルダ・波ダッシュ・ハイフン各種）。 */
    private const SEPARATORS = ['〜', '～', '~', '-', '－', 'ー', '–', '—', 'から'];

    /**
     * 「6」「6〜8」「6〜8名」「6名～8名」などを読む。
     *
     * @return array{min: ?int, max: ?int}  max＝計算に使う数字。読めなければ両方 null。
     */
    public static function parse(?string $value): array
    {
        $v = trim((string) $value);
        if ($v === '') {
            return ['min' => null, 'max' => null];
        }

        // 全角数字を半角に（スプレッドシートから来ると全角のことがある）。
        $v = strtr($v, ['０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4',
            '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9']);

        // 区切りをすべて「〜」にそろえてから分ける。
        $v = str_replace(self::SEPARATORS, '〜', $v);

        $parts = array_values(array_filter(
            array_map(fn ($p) => self::digits($p), explode('〜', $v)),
            fn ($n) => $n !== null
        ));

        if ($parts === []) {
            return ['min' => null, 'max' => null];
        }
        if (count($parts) === 1) {
            // 1つだけ＝範囲ではない。今までどおり「その人数ちょうど」。
            return ['min' => null, 'max' => $parts[0]];
        }

        // 3つ以上書かれていても、いちばん小さいのと大きいのを取る（書き方の揺れに耐える）。
        $min = min($parts);
        $max = max($parts);

        // 「8〜6」のように逆に書かれても直して受ける。同じ数字なら範囲ではない。
        return ['min' => $min === $max ? null : $min, 'max' => $max];
    }

    /**
     * 画面に出す文字。「6〜8」または「8」。未入力は空文字。
     * ⚠ 「名」「人」は付けない＝付ける側の画面に合わせる。
     */
    public static function label(?int $min, ?int $max): string
    {
        if ($max === null) {
            return '';
        }
        if ($min !== null && $min !== $max) {
            return $min.'〜'.$max;
        }

        return (string) $max;
    }

    /** 範囲かどうか（画面で「おおよそ」と添えるときに使う）。 */
    public static function isRange(?int $min, ?int $max): bool
    {
        return $min !== null && $max !== null && $min !== $max;
    }

    /** 文字列から数字だけ取り出す。数字が無ければ null。 */
    private static function digits(string $value): ?int
    {
        $d = preg_replace('/[^0-9]/u', '', trim($value));

        return ($d === null || $d === '') ? null : (int) $d;
    }
}
