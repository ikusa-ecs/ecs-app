<?php

namespace Tests\Feature;

use App\Models\ShiftPreference;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 出勤可能日は「同じ月を何度保存しても通る」こと（2026-08-31 修正）。
 *
 * 【実際にあった不具合】
 * `updateOrCreate(['staff_id' => …, 'date' => '2026-09-06'])` と書いていたが、
 * date は**日時**として保存される（'2026-09-06 00:00:00'）。
 * 'Y-m-d' の文字とは一致しないので既にある行を見つけられず、新しく作ろうとして
 * unique(staff_id, date) に引っかかり **500になって保存できなかった**。
 * ＝ 一度入れた月をもう一度直そうとすると、必ず失敗する状態だった。
 *
 * ⚠ 1回目だけを試すテストでは通ってしまう。**2回保存する**のがこのテストの肝。
 */
class AvailabilitySaveTwiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_the_same_month_twice_works(): void
    {
        $me = PersonFactory::new()->create();

        $body = fn (string $value) => [
            'employee_id' => $me->id,
            'period' => '2026-09',
            'state' => ['2026-9-6' => $value],
            'memo' => '',
        ];

        $this->actingAsPerson($me)
            ->postJson('/employee-availability/save', $body('ok'))
            ->assertOk()->assertJson(['ok' => true]);

        // 2回目＝ここで落ちていた
        $this->actingAsPerson($me)
            ->postJson('/employee-availability/save', $body('ng'))
            ->assertOk()->assertJson(['ok' => true]);

        $rows = ShiftPreference::where('staff_id', $me->id)->get();
        $this->assertCount(1, $rows, '同じ日の行が2つできてしまいました');
        $this->assertSame('NG', $rows->first()->availability, '2回目の内容で上書きされていません');
    }

    /** 取込も同じ＝2回取り込んでも落ちない（やり直しができる）。 */
    public function test_importing_the_same_month_twice_works(): void
    {
        $me = PersonFactory::new()->manager()->create();
        $target = PersonFactory::new()->create(['name' => '中村 淳司']);

        $sheet = implode("\n", [
            "\t項目\t9/6\t9/7\t9/13\t平日希望休日\t備考",
            "\t中村 淳司\t〇\t×\t〇\t\t",
        ]);
        $body = ['period' => '2026-09', 'pasted' => $sheet, 'overwrite' => '1'];

        // ⚠ この表は1行目が「空のセル」から始まる（＝タブ始まり）。
        //   前後の空白を削ると1行目だけ列が1つずれて、1人も読めなくなる（2026-08-31 に踏んだ）。
        $plan = $this->actingAsPerson($me)
            ->post('/availability-import/preview', $body)
            ->viewData('preview');
        $this->assertNotEmpty($plan['rows'],
            '1行目が空のセルから始まる表が読めていません（貼り付けた文字を trim すると起きます）。');

        $this->actingAsPerson($me)->post('/availability-import', $body)
            ->assertSessionHasNoErrors()->assertSessionHas('status')->assertRedirect();
        $this->actingAsPerson($me)->post('/availability-import', $body)
            ->assertSessionHasNoErrors()->assertSessionHas('status')->assertRedirect();

        $this->assertCount(3, ShiftPreference::where('staff_id', $target->id)->get(),
            '取り込み直しで行が二重になりました');
    }
}
