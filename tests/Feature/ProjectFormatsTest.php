<?php

namespace Tests\Feature;

use App\Support\ProjectFormats;
use Tests\TestCase;

/**
 * 実施形態の正本（App\Support\ProjectFormats）。2026-08-27。
 *
 * ⚠ それまで一覧と判定が6か所に散っていて、1つ増やすと6か所直す必要があった。
 *   実際に「集計だけ古い形のまま」で全部その他になる不具合が起きている（2026-08-26 に修正）。
 */
class ProjectFormatsTest extends TestCase
{
    /** 案件登録で選べる5つ。 */
    public function test_all_formats(): void
    {
        $this->assertSame(
            ['リアル', 'リアルロング', 'オンライン', 'ARENA場所貸し', '体験会'],
            ProjectFormats::ALL
        );
    }

    /**
     * 件数の集計に使う種別コード。
     * ⚠ ARENA・体験会・巻き取りは「リアル系」として数える（従来どおり）。
     *   数える／数えないは実施形態ではなく EventCount が決める。
     */
    public function test_count_code(): void
    {
        $this->assertSame('real', ProjectFormats::countCode('リアル'));
        $this->assertSame('long', ProjectFormats::countCode('リアルロング'));
        $this->assertSame('online', ProjectFormats::countCode('オンライン'));
        // ⚠⚠ 「ヘルプのみ」はオンラインではない（2026-09-01 baba訂正）。
        //   ヘルプのみ＝どの拠点が手伝うかという運用の話で、実施形態ではない。
        //   リアルのイベントは、ヘルプで入っていてもリアル。
        $this->assertSame('real', ProjectFormats::countCode('イベント東(ヘルプのみ)'));
        $this->assertSame('online', ProjectFormats::countCode('イベント東(オンライン・ヘルプのみ)'));
        $this->assertSame('real', ProjectFormats::countCode('ARENA場所貸し'));
        $this->assertSame('real', ProjectFormats::countCode('体験会'));
        // 昔の書き方（カッコ付き）でも読める。
        $this->assertSame('long', ProjectFormats::countCode('イベント東(リアルロング)'));
        // 未設定はリアル扱い（従来どおり）。
        $this->assertSame('real', ProjectFormats::countCode(null));
    }

    /**
     * アサイン表の色分けキー。
     * ⚠ 見る順番に意味がある＝「リアルロング」を「リアル」より先に見ないとロングが消える。
     */
    public function test_type_code(): void
    {
        $this->assertSame('basho', ProjectFormats::typeCode('ARENA場所貸し'));
        $this->assertSame('tokyo', ProjectFormats::typeCode('イベント他拠点→東(巻き取り)'));
        $this->assertSame('tohoku', ProjectFormats::typeCode('イベント東北(リアル)'));
        $this->assertSame('help', ProjectFormats::typeCode('イベント東(ヘルプのみ)'));
        $this->assertSame('taiken', ProjectFormats::typeCode('体験会'));
        $this->assertSame('long', ProjectFormats::typeCode('リアルロング'));
        $this->assertSame('online', ProjectFormats::typeCode('オンライン'));
        $this->assertSame('real', ProjectFormats::typeCode('リアル'));
        $this->assertSame('other', ProjectFormats::typeCode(''));
    }

    /** 案件一覧のバッジ色。 */
    public function test_badge_code(): void
    {
        $this->assertSame('fmt-arena', ProjectFormats::badgeCode('ARENA場所貸し'));
        $this->assertSame('fmt-online', ProjectFormats::badgeCode('オンライン'));
        $this->assertSame('fmt-other', ProjectFormats::badgeCode('イベント他拠点→東'));
        $this->assertSame('fmt-long', ProjectFormats::badgeCode('リアルロング'));
        $this->assertSame('fmt-real', ProjectFormats::badgeCode('リアル'));
        $this->assertSame('fmt-etc', ProjectFormats::badgeCode('体験会'));
    }

    /**
     * ⚠ 見張り：「リアルロングかどうか」を判定してよいのは正本（ProjectFormats）だけ。
     *
     * 【なぜ】リアルとリアルロングの違いは**スタッフの手当（当日スタッフ費）が変わる**ところ。
     *   判定の写しを別の場所に持つと、片方だけ直したときに手当を間違える。
     *   実際 2026-08-31 に、収支の当日スタッフ費（PersonalCases）・メンバー決めの絞り込み
     *   （AssignBoardController）・D決め（AssignDirectorController）の3か所に写しが残っていた。
     *
     * 【引っかかったら】その場で判定を書かず、`ProjectFormats::countCode()` を呼ぶこと。
     */
    public function test_only_the_one_source_decides_long(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            if (str_ends_with(str_replace('\\', '/', $file), 'app/Support/ProjectFormats.php')) {
                continue;   // ここが正本。
            }

            $code = (string) file_get_contents($file);
            // 「ロング」という文字と比べている行＝判定の写し。ラベルの表（'long' => 'リアルロング'）は
            // 比較ではないので引っかからない。
            if (preg_match('/(str_contains|str_starts_with|mb_strpos|strpos)\s*\([^;]*ロング/u', $code)) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
            }
        }

        $this->assertSame([], $offenders,
            '実施形態「リアルロング」の判定が正本の外に書かれています： '.implode(' / ', $offenders)
            .'。リアルとリアルロングはスタッフの手当が変わるところなので、'
            .'App\Support\ProjectFormats::countCode() を呼んでください。');
    }

    /** app/ 以下の .php を全部あげる。 */
    private function phpFilesUnder(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    /**
     * ⚠ 見張り：`public/ecs/data/cases.js` の window.ECS_fmtCode は countCode と同じ規則を
     *   JavaScript 側にも持っている（危険日の警告で使う）。cases.js は凍結ファイルなので
     *   触っていないが、**片方だけ変えると危険日の判定だけ古いままになる**。
     *   規則の目印（キーワード）が消えていないかだけ見る。
     */
    public function test_cases_js_still_uses_the_same_keywords(): void
    {
        $js = (string) file_get_contents(public_path('ecs/data/cases.js'));

        $this->assertStringContainsString('ECS_fmtCode', $js);
        foreach (['オンライン', 'リアルロング'] as $keyword) {
            $this->assertStringContainsString(
                $keyword,
                $js,
                "cases.js の ECS_fmtCode から「{$keyword}」が消えています。"
                    .'ProjectFormats::countCode と食い違うと、危険日の警告だけ判定が変わります。'
            );
        }

        // ⚠ 「ヘルプのみ」で online を返してはいけない（2026-09-01 baba訂正・危険日の警告もそろえた）。
        //   ヘルプのみ＝どの拠点が手伝うかという運用の話で、実施形態ではない。
        //   リアルのイベントはヘルプで入ってもリアル＝危険日でもリアルとして数える。
        $this->assertDoesNotMatchRegularExpression(
            "~indexOf\('ヘルプのみ'\)[^\n]*return 'online'~u",
            $js,
            'cases.js の ECS_fmtCode が「ヘルプのみ→オンライン」に戻っています。'
                .'ヘルプのみは実施形態ではないので、リアル系として数えてください。'
        );
    }
}
