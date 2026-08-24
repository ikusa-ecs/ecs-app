<?php

namespace Tests\Feature;

use App\Models\Person;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * 名簿CSV一括取込で、ログインアカウント（仮パスワード）もまとめて発行できるようにした
 * （2026-08-24 baba）。
 *
 * これまで：取込は名簿に人を登録するだけで、パスワードを作っていなかった。
 *   ＝名簿には載るがログインできない。さらに must_onboard も立たないので、
 *     初回ログインの初期設定（ふりがな・入社年月日を本人に入れてもらう画面）にも入らない。
 *   数十人を取り込んだあと、全員ぶんを1人ずつ「アカウント発行」し直すことになっていた。
 *
 * これから：チェックを入れると仮パスワードを自動生成し、初回設定へ誘導する。
 *   仮パスワードの一覧は、その画面で1回だけ表示する（サーバーには残さない）。
 */
class PersonImportAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** 取込用のCSVを作る（見出しは画面のテンプレートと同じ順）。 */
    private function csv(array $rows): UploadedFile
    {
        $header = '種別,氏名,ふりがな,メール,チャットワークID,事務所,所属,入社日,通算経験回数,できるポジション';
        $body = implode("\n", array_map(fn ($r) => implode(',', $r), $rows));

        return UploadedFile::fake()->createWithContent('meibo.csv', $header."\n".$body."\n");
    }

    /** チェックを入れると、仮パスワードが作られて初回設定へ誘導される。 */
    public function test_creates_login_accounts_with_temp_password(): void
    {
        $res = $this->actingAsPerson($this->admin())->post('/person-import', [
            'csv' => $this->csv([
                ['社員', '田中太郎', 'たなか たろう', 'tanaka@ikusa.co.jp', '', '東京', 'イベプラ', '2020-04-01', '', ''],
            ]),
            'make_accounts' => '1',
        ]);

        $res->assertRedirect('/person-import');

        $p = Person::where('email', 'tanaka@ikusa.co.jp')->firstOrFail();
        $this->assertNotNull($p->password, '仮パスワードが入っていること');
        $this->assertTrue((bool) $p->must_onboard, '初回設定へ誘導されること');

        // 画面へ渡した一覧に、その人の仮パスワードが入っていること。
        $issued = session('issued');
        $this->assertIsArray($issued);
        $this->assertCount(1, $issued);
        $this->assertSame('tanaka@ikusa.co.jp', $issued[0]['email']);
        $this->assertNotEmpty($issued[0]['password']);
        // 平文はDBに入れない（保存時にハッシュ化される）。
        $this->assertNotSame($issued[0]['password'], $p->password);
        $this->assertTrue(Hash::check($issued[0]['password'], $p->password), 'その仮パスワードでログインできること');
    }

    /** チェックを入れないと、これまでどおり名簿に載るだけ（パスワードは作らない）。 */
    public function test_without_checkbox_no_account_is_created(): void
    {
        $this->actingAsPerson($this->admin())->post('/person-import', [
            'csv' => $this->csv([
                ['社員', '佐藤花子', 'さとう はなこ', 'sato@ikusa.co.jp', '', '東京', 'セールス', '2021-04-01', '', ''],
            ]),
        ])->assertRedirect('/person-import');

        $p = Person::where('email', 'sato@ikusa.co.jp')->firstOrFail();
        $this->assertNull($p->password);
        $this->assertFalse((bool) $p->must_onboard);
        $this->assertNull(session('issued') ? (session('issued')[0] ?? null) : null);
    }

    /** メールが空の行はアカウントを作らない（ログインに使うため）。名簿には登録する。 */
    public function test_row_without_email_is_registered_but_gets_no_account(): void
    {
        $this->actingAsPerson($this->admin())->post('/person-import', [
            'csv' => $this->csv([
                ['スタッフ', 'メール無し', 'めーるなし', '', '', '東京', '', '', '5', 'OP'],
                ['スタッフ', 'メール有り', 'めーるあり', 'ari@ikusa.co.jp', '', '東京', '', '', '3', 'MC'],
            ]),
            'make_accounts' => '1',
        ])->assertRedirect('/person-import');

        $noMail = Person::where('name', 'メール無し')->firstOrFail();
        $this->assertNull($noMail->password, 'メールが無い人はアカウントを作らない');
        $this->assertFalse((bool) $noMail->must_onboard);

        $withMail = Person::where('name', 'メール有り')->firstOrFail();
        $this->assertNotNull($withMail->password);

        // 一覧に出るのはメールがある人だけ。
        $issued = session('issued');
        $this->assertCount(1, $issued);
        $this->assertSame('ari@ikusa.co.jp', $issued[0]['email']);

        // 発行できなかった人は、メッセージで知らせる。
        $this->assertStringContainsString('メール未記入', session('status'));
    }

    /** ふりがな・チャットワークID・兼務の所属も一緒に入る。 */
    public function test_imports_kana_chatwork_id_and_concurrent_departments(): void
    {
        $this->actingAsPerson($this->admin())->post('/person-import', [
            'csv' => $this->csv([
                ['社員', '兼務太郎', 'けんむ たろう', 'kenmu@ikusa.co.jp', '7654321', '東京', 'ARENA／イベプラ', '2019-04-01', '', ''],
            ]),
            'make_accounts' => '1',
        ])->assertRedirect('/person-import');

        $p = Person::where('email', 'kenmu@ikusa.co.jp')->firstOrFail();
        $this->assertSame('けんむ たろう', $p->name_kana);
        $this->assertSame('7654321', $p->chatwork_id);
        $this->assertSame('ARENA', $p->department, '先頭が主な所属');
        $this->assertSame(['ARENA', 'イベプラ'], $p->departments);
    }

    /** テンプレートの見本行（メールが @example.com）は取り込まない。 */
    public function test_sample_rows_are_rejected(): void
    {
        $res = $this->actingAsPerson($this->admin())->post('/person-import', [
            'csv' => $this->csv([
                // テンプレートの見本そのまま（消し忘れ）
                ['スタッフ', '山田花子', 'やまだ はなこ', 'hanako@example.com', '', '東京', '', '2025-04-01', '12', 'OP'],
                // 本物の行
                ['社員', '本物の人', 'ほんもののひと', 'honmono@ikusa.co.jp', '', '東京', 'イベプラ', '2020-04-01', '', ''],
            ]),
            'make_accounts' => '1',
        ]);

        $res->assertRedirect('/person-import');

        // 見本行は登録されない。
        $this->assertDatabaseMissing('people', ['name' => '山田花子']);
        // 本物の行は登録される。
        $this->assertDatabaseHas('people', ['name' => '本物の人']);
        // 何が悪かったか分かるメッセージが出る。
        $this->assertStringContainsString('見本', session('status'));
    }
}
