<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Support\ProfileOptions;
use App\Support\SkillFilter;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 名簿を「スキルで絞る」（2026-09-08 baba要望）。
 *   「スタッフと社員側でスキルを選択してピックアップできるようにしたい。例）英語ができる人を見たい」
 *
 * 見張っていること＝
 *  ① 英語・運転の「レベルの文字」が ProfileOptions から消えていないか
 *     （消えると絞り込みが**黙って0名**になる。エラーは出ないので気づけない）
 *  ② 判定を画面（Blade）に書き写していないか（2画面あるので必ず食い違う）
 *  ③ 社員名簿に「社員が入力できない項目」を出していないか（必ず0名になる項目）
 */
class SkillFilterTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): Person
    {
        return PersonFactory::new()->create(['office' => '東京']);
    }

    /**
     * ① レベルの文字が正本（ProfileOptions）にまだあるか。
     * ⚠ 言い方を変えるときは SkillFilter の境目も一緒に直す。
     */
    public function test_english_and_driving_levels_still_exist(): void
    {
        $this->assertContains('日常会話レベル', ProfileOptions::ENGLISH);
        $this->assertContains('ビジネス会話可能レベル', ProfileOptions::ENGLISH);
        $this->assertContains('ハイエースも普通サイズも運転可能', ProfileOptions::DRIVING);
    }

    /** ビジネス会話可の人は「日常会話以上」でも見つかる（下限で絞るため）。 */
    public function test_business_english_matches_daily_too(): void
    {
        $p = PersonFactory::new()->staff()->make(['english_level' => 'ビジネス会話可能レベル']);

        $this->assertSame(['english_daily', 'english_biz'], SkillFilter::keysFor($p));
    }

    /** 日常会話レベルの人は「ビジネス会話可能」では出てこない。 */
    public function test_daily_english_is_not_business(): void
    {
        $p = PersonFactory::new()->staff()->make(['english_level' => '日常会話レベル']);

        $this->assertSame(['english_daily'], SkillFilter::keysFor($p));
    }

    /**
     * 片言レベルは絞り込みに出てこない。
     * 理由＝探す目的は「英語の案件を任せられる人を見つけること」。片言の人が混ざると探した意味がなくなる。
     */
    public function test_broken_english_is_not_a_skill(): void
    {
        $p = PersonFactory::new()->staff()->make(['english_level' => '片言レベル']);

        $this->assertSame([], SkillFilter::keysFor($p));
    }

    /** ハイエースも運転できる人は「運転できる」でも見つかる。 */
    public function test_hiace_driver_matches_plain_driving(): void
    {
        $p = PersonFactory::new()->staff()->make(['driving_level' => 'ハイエースも普通サイズも運転可能']);

        $this->assertSame(['drive', 'drive_hiace'], SkillFilter::keysFor($p));
    }

    /** 何も入れていない人には印が付かない（絞り込むと出てこない）。 */
    public function test_person_without_input_has_no_skills(): void
    {
        $p = PersonFactory::new()->staff()->make();

        $this->assertSame([], SkillFilter::keysFor($p));
        $this->assertSame([], SkillFilter::badgesFor($p));
    }

    /**
     * バッジは「いちばん上の段だけ」出す。
     * ⚠ ビジネス会話可の人に「日常会話」と「ビジネス」を2つ並べると読みにくい。
     */
    public function test_badge_shows_only_the_top_level(): void
    {
        $p = PersonFactory::new()->staff()->make([
            'english_level' => 'ビジネス会話可能レベル',
            'driving_level' => 'ハイエースも普通サイズも運転可能',
            'can_kigurumi' => true,
        ]);

        $this->assertSame(['英語：ビジネス', '運転：ハイエース可', '着ぐるみ'], SkillFilter::badgesFor($p));
    }

    /**
     * ③ 社員名簿には「着ぐるみ・前泊・MC審査」を出さない。
     * 理由＝社員が入力する画面が無い＝選ぶと必ず0名になり「壊れている」と誤解される。
     */
    public function test_employee_roster_hides_staff_only_skills(): void
    {
        $keys = array_column(SkillFilter::options(false), 'key');

        $this->assertContains('english_biz', $keys);
        $this->assertContains('drive', $keys);
        $this->assertNotContains('kigurumi', $keys);
        $this->assertNotContains('stay_over', $keys);
        $this->assertNotContains('mc_passed', $keys);

        // スタッフ名簿では、その3つも並ぶ（本人が自分で入れているため）。
        $staffKeys = array_column(SkillFilter::options(true), 'key');
        $this->assertContains('kigurumi', $staffKeys);
        $this->assertContains('stay_over', $staffKeys);
        $this->assertContains('mc_passed', $staffKeys);
    }

    /**
     * 画面に渡すときは JSON になり、日本語は「バックスラッシュ＋u＋4桁」の形に化ける。
     * ⚠ だから画面のHTMLを日本語そのままで探すと、入っていても見つからない。
     */
    private function asJson(string $text): string
    {
        return trim(json_encode($text), '"');
    }

    /** スタッフ名簿の画面に、選択肢とその人の印が渡っている。 */
    public function test_staff_roster_page_has_skill_filter(): void
    {
        PersonFactory::new()->staff()->create([
            'name' => '英語できる人',
            'english_level' => 'ビジネス会話可能レベル',
            'office' => '東京',
        ]);

        $html = $this->actingAsPerson($this->employee())->get('/staff')
            ->assertOk()
            ->assertSee('window.ECS_SKILL_OPTIONS', false)
            ->assertSee('id="fSkill"', false)
            ->getContent();

        // プルダウンの文字（選択肢はサーバーから渡している）。
        $this->assertStringContainsString($this->asJson('英語：ビジネス会話可能'), $html);
        // その人に付いた印（絞り込みは画面でこれを見るだけ）。
        $this->assertStringContainsString('english_biz', $html);
        // 一覧のバッジ。
        $this->assertStringContainsString($this->asJson('英語：ビジネス'), $html);
    }

    /** 社員名簿の画面にも、同じ絞り込みが渡っている。 */
    public function test_employee_roster_page_has_skill_filter(): void
    {
        $me = $this->employee();
        $me->english_level = '日常会話レベル';
        $me->save();

        $html = $this->actingAsPerson($me)->get('/employees')
            ->assertOk()
            ->assertSee('window.ECS_SKILL_OPTIONS', false)
            ->assertSee('id="fSkill"', false)
            ->getContent();

        $this->assertStringContainsString('english_daily', $html);
        $this->assertStringContainsString($this->asJson('英語：日常会話'), $html);
        // 社員が入力できない項目はプルダウンに出さない（選ぶと必ず0名になるため）。
        $this->assertStringNotContainsString('kigurumi', $html);
        $this->assertStringNotContainsString('mc_passed', $html);
    }

    /**
     * ② 判定を画面に書き写していないか。
     * ⚠ この repo は「同じ判定を2つの画面に書いて片方だけ直す」事故を何度も起こしている。
     *   レベルの文字が Blade の中にあったら、そこで判定している疑いがある。
     */
    public function test_views_do_not_copy_the_judgement(): void
    {
        foreach (['staff', 'employees'] as $view) {
            $blade = file_get_contents(resource_path('views/'.$view.'.blade.php'));

            $this->assertStringNotContainsString('ビジネス会話可能レベル', $blade,
                $view.'.blade.php にレベルの文字がある＝画面で判定している疑い。正本は SkillFilter。');
            $this->assertStringNotContainsString('ハイエースも普通サイズも', $blade,
                $view.'.blade.php にレベルの文字がある＝画面で判定している疑い。正本は SkillFilter。');
            // 選択肢はサーバーから受け取って組み立てる（画面に項目名を書かない）。
            $this->assertStringContainsString('buildSkillFilter(', $blade);
        }
    }
}
