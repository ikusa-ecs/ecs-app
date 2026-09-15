<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Support\WeekStart;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * カレンダーの「週のはじまり」を人ごとに選ぶ（2026-09-15 baba要望・社員フォームの要望から）。
 *
 * ⚠ もとは画面ごとにバラバラだった（社員の出勤可能日だけ月曜はじまり、
 *   スタッフ画面の稼働希望とマイページのカレンダーは日曜はじまり）。
 *   1つの設定にそろえたので、**画面ごとに並べ替えの計算を書かない**こと。
 */
class WeekStartTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $id = 'E-001'): Person
    {
        return PersonFactory::new()->create([
            'id' => $id, 'name' => 'テスト社員', 'permission' => 'manager',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    public function test_既定は日曜はじまり(): void
    {
        $this->assertSame('sun', WeekStart::of($this->person()));
        $this->assertSame([0, 1, 2, 3, 4, 5, 6], WeekStart::order('sun'));
        // 2026年9月1日は火曜（dow=2）＝日曜はじまりなら空きマス2つ。
        $this->assertSame(2, WeekStart::lead(2, 'sun'));
    }

    public function test_月曜はじまりは土日が右端になる(): void
    {
        $this->assertSame([1, 2, 3, 4, 5, 6, 0], WeekStart::order('mon'));
        // 1日が火曜（dow=2）＝月曜はじまりなら空きマスは1つ。
        $this->assertSame(1, WeekStart::lead(2, 'mon'));
        // 1日が日曜（dow=0）＝月曜はじまりなら空きマスは6つ（ここを間違えると1週ズレる）。
        $this->assertSame(6, WeekStart::lead(0, 'mon'));
        $this->assertSame(0, WeekStart::lead(0, 'sun'));
    }

    /** 知らない値は既定に寄せる（URLや古いデータで変な値が入っても崩れないように）。 */
    public function test_知らない値は既定にする(): void
    {
        $this->assertSame('sun', WeekStart::normalize('でたらめ'));
        $this->assertSame('sun', WeekStart::normalize(null));
        $this->assertSame('mon', WeekStart::normalize('mon'));
    }

    public function test_本人が選んで保存できる(): void
    {
        $me = $this->person();

        $this->actingAsPerson($me)->post('/week-start', ['week_start' => 'mon'])
            ->assertRedirect();

        $this->assertSame('mon', $me->fresh()->week_start);
    }

    /** ⚠ 変えられるのは自分のぶんだけ（人のIDを受け取らない作りになっていること）。 */
    public function test_他人の設定は変えられない(): void
    {
        $me = $this->person('E-001');
        $other = $this->person('E-002');

        $this->actingAsPerson($me)->post('/week-start', [
            'week_start' => 'mon',
            'id' => $other->id,       // 送っても無視されること
            'person_id' => $other->id,
        ])->assertRedirect();

        $this->assertSame('mon', $me->fresh()->week_start);
        $this->assertNull($other->fresh()->week_start);
    }

    /**
     * 画面が「自分で並べ替えを計算していない」こと。
     * ⚠ ここが崩れると、片方の画面だけ直して並びが食い違う（今回の発端がそれ）。
     */
    public function test_画面は共通の部品だけを使う(): void
    {
        foreach (['employee_availability', 'mypage', 'staff_portal'] as $view) {
            $blade = file_get_contents(resource_path("views/{$view}.blade.php"));
            $this->assertStringContainsString('ECS_WEEK_LEAD(', $blade,
                "{$view} が週のはじまりの共通部品を使っていません");
            $this->assertStringNotContainsString('(firstDow + 6) % 7', $blade,
                "{$view} に並べ替えの計算が直に書き戻されています");
        }
    }

    public function test_設定した並びが画面に渡る(): void
    {
        $me = $this->person();
        $me->week_start = 'mon';
        $me->save();

        $this->actingAsPerson($me)->get('/employee-availability')
            ->assertOk()
            ->assertSee('window.ECS_WEEK_START = "mon"', false);
    }
}
