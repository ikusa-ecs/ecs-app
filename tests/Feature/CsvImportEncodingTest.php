<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Person;
use App\Models\Project;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * CSV一括取込の「文字コード」まわりのテスト（2026-08-18）。
 *
 * 実際に起きた事故：ECSが配るテンプレートはUTF-8だが、**Excelで開いて普通に上書き保存すると
 * Shift_JIS（CP932）に変わる**。取込側がUTF-8しか読めなかったため、見出しの「コンテンツ名」等が
 * 一致せず、中身は入っているのに全行「〇〇が空です」というエラーになっていた。
 *
 * App\Support\CsvText で読む前にUTF-8へそろえるようにしたので、
 * ここでは「Shift_JISのCSVでもちゃんと登録される」ことを3つの取込すべてで確かめる。
 */
class CsvImportEncodingTest extends TestCase
{
    use RefreshDatabase;

    /** UTF-8の文字列を、ExcelがCSV保存するときと同じShift_JIS（CP932）に変える。 */
    private function toShiftJis(string $utf8): string
    {
        return mb_convert_encoding($utf8, 'SJIS-win', 'UTF-8');
    }

    /** コンテンツ台帳：Shift_JISで保存されたCSVでも取り込める。 */
    public function test_content_import_reads_shift_jis_csv(): void
    {
        $manager = PersonFactory::new()->manager()->create();

        $csv = "コンテンツ名,分類,体力系,紙が必要,1チーム枚数,利用中\n"
             . "水合戦,盛り上げ系,○,,,○\n"
             . "謎解き脱出ゲーム,真面目系,,○,3,○\n";

        $file = UploadedFile::fake()->createWithContent('contents.csv', $this->toShiftJis($csv));

        $this->actingAsPerson($manager)
            ->post('/content-import', ['csv' => $file])
            ->assertRedirect('/content-import');

        $this->assertSame(2, Content::count());
        $this->assertNotNull(Content::where('content_name', '水合戦')->first());
        // 分類などの値も文字化けせずに入っていること。
        $this->assertSame('盛り上げ系', Content::where('content_name', '水合戦')->first()->category);
    }

    /** 名簿：Shift_JISで保存されたCSVでも取り込める。 */
    public function test_person_import_reads_shift_jis_csv(): void
    {
        $manager = PersonFactory::new()->manager()->create();
        $before = Person::count();

        $csv = "種別,氏名,メール,事務所,所属,入社日,通算経験回数,できるポジション\n"
             . "社員,山田 太郎,taro-yamada@example.com,大阪,イベプラ,2023-04-01,,\n";

        $file = UploadedFile::fake()->createWithContent('people.csv', $this->toShiftJis($csv));

        $this->actingAsPerson($manager)
            ->post('/person-import', ['csv' => $file])
            ->assertRedirect('/person-import');

        $added = Person::where('email', 'taro-yamada@example.com')->first();
        $this->assertNotNull($added, 'Shift_JISの名簿CSVが取り込めていない');
        $this->assertSame('山田 太郎', $added->name);
        // 事務所（拠点）が化けずに入っていること＝ここが空だと自動で「東京」扱いになる。
        $this->assertSame('大阪', $added->office);
        $this->assertSame($before + 1, Person::count());
    }

    /** 案件：Shift_JISで保存されたCSVでも取り込める。 */
    public function test_project_import_reads_shift_jis_csv(): void
    {
        $user = PersonFactory::new()->create();

        $csv = "案件名,開催日,運営人数,クライアント\n"
             . "水合戦,2026-10-05,16,〇〇株式会社\n";

        $file = UploadedFile::fake()->createWithContent('cases.csv', $this->toShiftJis($csv));

        $this->actingAsPerson($user)
            // 取込に成功すると案件一覧へ、失敗すると取込画面へ戻る仕様。
            ->post('/project-import', ['csv' => $file])
            ->assertRedirect('/projects');

        $project = Project::where('project_name', '水合戦')->first();
        $this->assertNotNull($project, 'Shift_JISの案件CSVが取り込めていない');
        $this->assertSame('〇〇株式会社', $project->client);
    }

    /** これまでどおり、UTF-8（BOM付き＝テンプレートそのまま）のCSVも読める。 */
    public function test_utf8_with_bom_still_works(): void
    {
        $manager = PersonFactory::new()->manager()->create();

        $csv = "\xEF\xBB\xBF" . "コンテンツ名,分類,体力系,紙が必要,1チーム枚数,利用中\n"
             . "サバイバルゲーム,盛り上げ系,○,,,○\n";

        $file = UploadedFile::fake()->createWithContent('contents.csv', $csv);

        $this->actingAsPerson($manager)
            ->post('/content-import', ['csv' => $file])
            ->assertRedirect('/content-import');

        $this->assertNotNull(Content::where('content_name', 'サバイバルゲーム')->first());
    }
}
