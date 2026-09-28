<?php

namespace Tests\Feature;

use App\Support\ProjectRequiredFields;
use Tests\TestCase;

/**
 * 「入力必須」の表（App\Support\ProjectRequiredFields）が、案件登録の画面と食い違っていないこと。
 *
 * ⚠ 案件登録は欄に `data-need="req"`（赤＝いま必須）／`data-need="later"`（黄＝後で必要）を書いている。
 *   アサイン表の「未入力」の色も同じ決まりで出すので、**2か所がずれると嘘の色が出る**。
 *   案件登録に必須の欄を足したら、ProjectRequiredFields にも足す＝忘れたらここで落ちる。
 *
 * ⚠ 案件登録の画面にしか無い欄（コンテンツの選び方など、input に name が無いもの）は見ない。
 *   見るのは「name と data-need の両方がある欄」だけ。
 */
class ProjectRequiredFieldsTest extends TestCase
{
    public function test_案件登録のdata_needと必須項目の表が一致している(): void
    {
        $src = (string) file_get_contents(resource_path('views/project_form.blade.php'));

        $fromForm = [];
        // 1つのタグの中に name と data-need が両方ある場合だけ拾う（書く順はどちらでもよい）。
        foreach ($this->tags($src) as $tag) {
            if (! preg_match('/\bname="([a-z_]+)"/', $tag, $n)) {
                continue;
            }
            if (! preg_match('/\bdata-need="(req|later)"/', $tag, $d)) {
                continue;
            }
            $fromForm[$n[1]] = $d[1];
        }

        $this->assertNotEmpty($fromForm, '案件登録から data-need の欄を1つも読めていない（この見張りが効いていない）');

        $fromTable = [];
        foreach (array_keys(ProjectRequiredFields::RED) as $f) {
            $fromTable[$f] = 'req';
        }
        foreach (array_keys(ProjectRequiredFields::YELLOW) as $f) {
            $fromTable[$f] = 'later';
        }

        ksort($fromForm);
        ksort($fromTable);

        $this->assertSame(
            $fromForm,
            $fromTable,
            "案件登録の必須の欄と、App\\Support\\ProjectRequiredFields の表が食い違っています。\n"
            ."案件登録に欄を足した／消したときは、この表も直してください（アサイン表の「未入力」の色がずれます）。"
        );
    }

    /** そのファイルの中の HTML タグ（開始タグ）を1つずつ返す。 */
    private function tags(string $src): array
    {
        preg_match_all('/<(input|select|textarea)\b[^>]*>/i', $src, $m);

        return $m[0];
    }
}
