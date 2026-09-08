<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 「public フォルダに、ログイン不要で開けるHTMLを置かない」ことの見張り（2026-09-08）。
 *
 * ⚠ public の中のファイルは Laravel を通らない（Webサーバーが直接返す）ので、
 *   ログインの門(auth)も権限の門(tier)も一切効かない。
 *   2026-09-07、モック23枚が public/ecs/*.html に置かれたままで、
 *   /ecs/staff.html のように「ログインしなくても誰でもURLで開ける」状態だった
 *   （デバッグ担当の指摘で発覚）。中身はモック＝架空の名前だが、
 *   画面構成・項目名・業務の流れは外から読めていた。
 *   → 2026-09-08、23枚を resources/mock/ へ移した（public の外＝配信されない）。
 *
 * ⚠ ここが落ちたら「モックのHTMLを public に戻した」ということ。戻さずに resources/mock へ置く。
 * ⚠ 逆に、public/ecs の style.css・csv-read.js・data/cases.js は今も本番の画面が読んでいる。
 *   「public/ecs を丸ごと消す」と画面が壊れるので、この3つが在ることも一緒に見張る。
 */
class PublicFolderNoMockHtmlTest extends TestCase
{
    /** public の中に .html が1つも無いこと（ログイン不要で読まれてしまうため）。 */
    public function test_public_folder_has_no_html_files(): void
    {
        $found = array_merge(
            glob(public_path('*.html')) ?: [],
            glob(public_path('*/*.html')) ?: [],
            glob(public_path('*/*/*.html')) ?: [],
        );

        $names = array_map(fn ($p) => str_replace(base_path().DIRECTORY_SEPARATOR, '', $p), $found);

        $this->assertSame([], $names,
            'public の中にHTMLがあります＝ログインしなくても誰でもURLで開けます。'
            ."resources/mock/ へ移してください。見つかったもの：\n".implode("\n", $names));
    }

    /** 逆に、今も画面が読んでいるファイルは残っていること（消しすぎの検知）。 */
    public function test_files_the_screens_still_read_are_kept(): void
    {
        foreach (['ecs/style.css', 'ecs/csv-read.js', 'ecs/data/cases.js'] as $rel) {
            $this->assertFileExists(public_path($rel),
                "public/{$rel} は今も本番の画面が読んでいます（cases.js は9画面）。消さないでください。");
        }
    }

    /** モックの見本が resources/mock に残っていること（移動先を消してしまわないように）。 */
    public function test_frozen_mocks_are_kept_outside_public(): void
    {
        $mocks = glob(base_path('resources/mock/*.html')) ?: [];

        $this->assertGreaterThan(20, count($mocks),
            'resources/mock のモックが減っています。見本は共有ドライブの ECS_モック\ にもありますが、'
            .'ここは凍結された見本の置き場です（2026-09-08に public/ecs から移した23枚）。');
    }
}
