<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Support\LineGroupText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * LINEグループを作るときにコピーする文章（App\Support\LineGroupText）。
 *
 * 2026-09-16 baba要望。10月ぶんのLINEグループ作りが重い、という相談から。
 * ここで見張るのは「毎回手で書いていたものが、案件の内容どおりに出るか」。
 */
class LineGroupTextTest extends TestCase
{
    use RefreshDatabase;

    /** baba が実際に送っている案件（2026-09-16 のやり取りで出してもらった見本）。 */
    private function sampleProject(array $over = []): Project
    {
        $p = new Project(array_merge([
            'project_name' => '謎パ',
            'content_names' => ['謎パ'],
            'client' => '東京水道株式会社様',
            'scale' => '小型',
            'sales_owners' => ['八重'],
            'operation_place' => '現地',
            'is_multi' => false,
            'lodging' => '無',
            'start_time' => '7:30',
            'end_time' => '17:00',
            'event_enter_time' => '9:00',
            'event_start_time' => '10:00',
            'event_end_time' => '11:30',
            'location' => '東京都千代田区富士見2-10-2　飯田橋グラン・ブルーム',
            'assembly_type' => '会場現地',
            'alcohol' => false,
        ], $over));
        $p->id = 'P-2026-9999';
        $p->start_date = Carbon::parse('2026-10-01');

        return $p;
    }

    /** ① アイコン用は3行。日付(yymmdd)・コンテンツ名・会社名（様なし）。 */
    public function test_icon_is_three_lines(): void
    {
        $this->assertSame(
            "261001\n謎パ\n東京水道株式会社",
            LineGroupText::icon($this->sampleProject())
        );
    }

    /**
     * ② グループ名は「日付コンテンツ＠企業名様」。
     * ⚠ グループ名には「様」を付ける（2026-09-16 baba）。省くのはアイコンだけ。
     */
    public function test_group_name(): void
    {
        $this->assertSame(
            '261001謎パ＠東京水道株式会社様',
            LineGroupText::groupName($this->sampleProject())
        );
    }

    /**
     * ③ クライアント名に「様」が入っていなくても、グループ名には必ず付く。
     * ⚠ 案件によって様あり・なしが混ざっているので、ここでそろえる。「様様」にもしない。
     */
    public function test_group_name_always_has_sama_once(): void
    {
        $this->assertSame(
            '261001謎パ＠東京水道株式会社様',
            LineGroupText::groupName($this->sampleProject(['client' => '東京水道株式会社']))
        );
        $this->assertSame(
            '261001謎パ＠あいう様',
            LineGroupText::groupName($this->sampleProject(['client' => 'あいう御中']))
        );
    }

    /**
     * ④ 会社名（アイコン用）は「様」「御中」だけ外す。短縮はしない。
     * ⚠ 取引先の名前を勝手に変えないため（株式会社→(株)のような置き換えはしない）。
     */
    public function test_company_only_drops_sama(): void
    {
        $this->assertSame('東京水道株式会社', LineGroupText::company($this->sampleProject()));
        $this->assertSame('あいう', LineGroupText::company($this->sampleProject(['client' => 'あいう御中'])));
        $this->assertSame('あいう', LineGroupText::company($this->sampleProject(['client' => 'あいう様 '])));
        // 短縮していないこと＝「株式会社」がそのまま残る。
        $this->assertStringContainsString('株式会社', LineGroupText::company($this->sampleProject()));
    }

    /** ④ 概要文に、毎回書いている項目がそろっている。 */
    public function test_summary_has_every_item(): void
    {
        $text = LineGroupText::summary($this->sampleProject());

        foreach ([
            '日程　10月1日(木)',
            '宿泊　無',
            'コンテンツ　謎パ',
            '案件規模　小型',
            '営業担当　八重',
            '顧客名（代理店名）　東京水道株式会社様',   // 概要文では「様」を付けたまま
            '運営場所　現地',
            '複数開催　なし',
            '7:30',
            '17:00',
            '9:30',                                     // 拘束時間（集合〜解散）
            '9:00',
            '10:00',
            '11:30',
            '東京都千代田区富士見2-10-2',
            '集合形式　会場現地',
            'お酒　なし',
        ] as $needle) {
            $this->assertStringContainsString($needle, $text, "概要文に「{$needle}」が出ていません");
        }
    }

    /** ⑤ 拘束時間＝集合〜解散。日をまたぐ案件でもマイナスにならない。 */
    public function test_duration(): void
    {
        $this->assertSame('9:30', LineGroupText::duration('7:30', '17:00'));
        $this->assertSame('8:00', LineGroupText::duration('9:00', '17:00'));
        // 夜から翌朝（22:00→翌6:00）＝8時間。24時間を足して数える。
        $this->assertSame('8:00', LineGroupText::duration('22:00', '6:00'));
        // 時間が入っていなければ「—」。空欄を0時間と言い切らない。
        $this->assertSame('—', LineGroupText::duration('', '17:00'));
    }

    /**
     * ⑥ スタッフ向けの集合・解散が入っていれば、そちらを使う。
     * ⚠ LINEグループはスタッフに向けたもの。社員の時間（前泊・積み込み込み）を出すと違う時間が伝わる。
     */
    public function test_staff_times_win(): void
    {
        $text = LineGroupText::summary($this->sampleProject([
            'staff_meet_time' => '8:00',
            'staff_leave_time' => '16:00',
        ]));

        $this->assertStringContainsString('8:00', $text);
        $this->assertStringContainsString('16:00', $text);
        $this->assertStringContainsString('8:00', $text);  // 拘束＝8時間
    }

    /**
     * ⑦ 前泊ありのときだけ、前泊の行が出る。出発時間は空欄（手で足す・2026-09-16 baba）。
     */
    public function test_pre_stay_line(): void
    {
        $normal = LineGroupText::summary($this->sampleProject());
        $this->assertStringNotContainsString('前泊集合', $normal, '前泊でないのに前泊の行が出ています');

        $stay = LineGroupText::summary($this->sampleProject([
            'lodging' => '前泊有',
            'stay_pre_meet_time' => '6:00',
        ]));
        $this->assertStringContainsString('前泊集合　6:00', $stay);
        $this->assertStringContainsString('前泊出発', $stay, '手で足すための空欄が出ていません');
    }

    /**
     * ⑧ ポジションは「@」までしか出さない（名前は手打ち・2026-09-16 baba）。
     *    2人以上入っているポジションは、その人数ぶん行が出る。
     */
    public function test_position_lines(): void
    {
        $text = LineGroupText::summary($this->sampleProject(), [
            ['roleCode' => 'D'],
            ['roleCode' => 'OP'],
            ['roleCode' => 'OP'],
            ['roleCode' => 'MC'],
        ]);

        // 名前は出さない＝「@」で終わる行になっている。
        $this->assertStringContainsString("ディレクター @\n", $text.PHP_EOL);
        // OPが2人なので2行。
        $this->assertSame(2, substr_count($text, 'OP予定 @'), 'OPの行数が人数と合っていません');
        // 誰も入っていなくても、D/OP/MC/FC の枠は出る（何人ぶん打つかの目印）。
        $this->assertStringContainsString('MC予定 @', $text);
        $this->assertStringContainsString('FC予定 @', $text);
        // 入っていない役割（SD・受付など）は出さない＝行が無駄に増えない。
        $this->assertStringNotContainsString('SD予定', $text);
        $this->assertStringNotContainsString('受付予定', $text);
    }

    /** ⑨ 定型文は共通設定から変えられる。未設定のときだけ初期値が出る。 */
    public function test_notice_is_editable(): void
    {
        // 一度も設定していない＝初期値（いま送っている文面）。
        $this->assertStringContainsString('※前泊や同日案件がないか確認お願いします！', LineGroupText::notice());
        $this->assertStringContainsString('※前泊や同日案件がないか確認お願いします！', LineGroupText::summary($this->sampleProject()));

        LineGroupText::saveNotice("新しい決まり文句\n2行目");
        $this->assertSame("新しい決まり文句\n2行目", LineGroupText::notice());
        $this->assertStringContainsString('新しい決まり文句', LineGroupText::summary($this->sampleProject()));
        $this->assertStringNotContainsString('※前泊や同日案件がないか', LineGroupText::summary($this->sampleProject()));

        // わざと空にしたら、初期値に戻さずそのまま空にする。
        LineGroupText::saveNotice('');
        $this->assertSame('', LineGroupText::notice());
        $this->assertStringNotContainsString('※前泊や同日案件がないか', LineGroupText::summary($this->sampleProject()));
    }

    /** ⑩ 未入力の欄は「—」で出す（空欄を「なし」と言い切らない）。 */
    public function test_blank_fields_show_dash(): void
    {
        $text = LineGroupText::summary($this->sampleProject([
            'alcohol' => null,
            'assembly_type' => null,
            'location' => null,
        ]));

        $this->assertStringContainsString('お酒　—', $text);
        $this->assertStringContainsString('集合形式　—', $text);
        $this->assertStringContainsString('会場住所（〒なし）　—', $text);
    }
}
