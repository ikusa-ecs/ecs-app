<?php

namespace App\Support;

/**
 * Salesforce（SF）の「担当拠点」→ ECSの拠点 の読み替えの正本（2026-11-16）。
 *
 * 【決まり（2026-09-12 中村さん判断・調査結果 B-4）】
 *   SF側は変えない。SFは「東京オフィス」、ECSは「東京」なので、ECS側で末尾の「オフィス」を落として突き合わせる。
 *
 * 【読み替えられないもの＝null（画面では「要確認」）】
 *   ・空欄（SFの商談の約4割は拠点が空）
 *   ・「東北・北海道オフィス」（旧い値・11件。東北か札幌か決まっていない）
 *   ・ほかの知らない値
 *   ⚠ 推測で東京などに寄せない＝間違った拠点の一覧に黙って入ると気づけないため。
 *
 * ⚠ 拠点の読み替えを画面やほかのクラスに書き写さない。必ずここを通す。
 */
final class SfOffice
{
    /** ECSの拠点（SFの選択肢と顔ぶれが一致している6つ）。 */
    public const ECS_OFFICES = ['東京', '大阪', '名古屋', '福岡', '東北', '札幌'];

    /** SFの値 → ECSの拠点。読み替えられなければ null（＝要確認）。 */
    public static function toEcs(?string $sfValue): ?string
    {
        // 前後の空白（全角も）を落とす。⚠ trim() に全角スペースを渡すと日本語を壊すので正規表現で。
        $s = preg_replace('/\A[\s\x{3000}]+|[\s\x{3000}]+\z/u', '', (string) $sfValue) ?? '';
        if ($s === '') {
            return null;
        }
        if (str_ends_with($s, 'オフィス')) {
            $s = mb_substr($s, 0, mb_strlen($s) - mb_strlen('オフィス'));
        }

        return in_array($s, self::ECS_OFFICES, true) ? $s : null;
    }
}
