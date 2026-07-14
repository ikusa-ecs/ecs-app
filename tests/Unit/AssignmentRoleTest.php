<?php

namespace Tests\Unit;

use App\Support\AssignmentRole;
use Tests\TestCase;

/**
 * 役割コード（AssignmentRole）の単体テスト。テスト仕様書 UT-ROLE-01。
 *
 * 役割コードは assignments.role / staff_role_eligibility.position の「唯一の正」。
 * 表記ゆれ（同じ役割が別物に見える事故）や、旧コード（GUN/UKE）の混入がないことを守るためのテスト。
 * DBを一切触らない純粋な静的メソッドだけを検証する。
 */
class AssignmentRoleTest extends TestCase
{
    /** all() は正規の8コードちょうど（D/SD/OP/MC/FC/CK/SP/RP）を返す。 */
    public function test_all_returns_exactly_the_eight_canonical_codes(): void
    {
        $expected = ['D', 'SD', 'OP', 'MC', 'FC', 'CK', 'SP', 'RP'];

        // 並びは問わず「集合として一致」を確認する。
        $this->assertEqualsCanonicalizing($expected, AssignmentRole::all());
        $this->assertCount(8, AssignmentRole::all());
    }

    /** 旧コード（GUN→SP・UKE→RP）へのリネームが完了し、旧コードは含まれない。 */
    public function test_old_codes_gun_and_uke_are_renamed_and_absent(): void
    {
        $all = AssignmentRole::all();

        $this->assertContains('SP', $all, '軍師・サポーターの新コード SP が無い');
        $this->assertContains('RP', $all, '受付の新コード RP が無い');
        $this->assertNotContains('GUN', $all, '旧コード GUN が残っている');
        $this->assertNotContains('UKE', $all, '旧コード UKE が残っている');
    }

    /** isValid() は正規コードだけ true を返す。 */
    public function test_is_valid_accepts_every_canonical_code(): void
    {
        foreach (AssignmentRole::all() as $code) {
            $this->assertTrue(
                AssignmentRole::isValid($code),
                "正規コード {$code} が無効判定された"
            );
        }
    }

    /** isValid() は空文字・null・旧コード・日本語別表記・大小文字違いを false にする。 */
    public function test_is_valid_rejects_invalid_values(): void
    {
        $invalid = [null, '', ' ', 'GUN', 'UKE', 'ディレクター', 'd', 'op', 'XX', 'DIRECTOR'];

        foreach ($invalid as $bad) {
            $this->assertFalse(
                AssignmentRole::isValid($bad),
                '無効値が有効判定された: ' . var_export($bad, true)
            );
        }
    }

    /** POSITIONS（できる役割）は SD を除く7種。 */
    public function test_positions_exclude_sub_director(): void
    {
        $this->assertCount(7, AssignmentRole::POSITIONS);
        $this->assertNotContains('SD', AssignmentRole::POSITIONS, 'POSITIONS に SD が混ざっている');
        // POSITIONS は all() の部分集合（正規コードのみ）。
        foreach (AssignmentRole::POSITIONS as $code) {
            $this->assertContains($code, AssignmentRole::all());
        }
    }

    /** positionLabels() は SD を含まず、POSITIONS と同じキー集合を返す。 */
    public function test_position_labels_match_positions(): void
    {
        $labels = AssignmentRole::positionLabels();

        $this->assertArrayNotHasKey('SD', $labels, 'プルダウン用ラベルに SD が混ざっている');
        $this->assertEqualsCanonicalizing(AssignmentRole::POSITIONS, array_keys($labels));
    }

    /** label() は既知コードは表示名を、未知コードは入力値をそのまま返す。 */
    public function test_label_returns_display_name_or_passthrough(): void
    {
        $this->assertSame('D（ディレクター）', AssignmentRole::label('D'));
        $this->assertSame('受付', AssignmentRole::label('RP'));
        $this->assertSame('軍師・サポーター', AssignmentRole::label('SP'));
        // 未知コードはそのまま返す（落とさない）。
        $this->assertSame('ZZZ', AssignmentRole::label('ZZZ'));
        $this->assertSame('', AssignmentRole::label(null));
    }

    /** 英語名の対応（参考メモ）。SP=Support・RP=Reception のリネーム対応を固定化する。 */
    public function test_english_names_reflect_renamed_codes(): void
    {
        $this->assertSame('Support', AssignmentRole::ENGLISH['SP']);
        $this->assertSame('Reception', AssignmentRole::ENGLISH['RP']);
        $this->assertSame('Director', AssignmentRole::ENGLISH['D']);
        // LABELS と ENGLISH はキー集合が一致（取りこぼしなし）。
        $this->assertEqualsCanonicalizing(
            array_keys(AssignmentRole::LABELS),
            array_keys(AssignmentRole::ENGLISH)
        );
    }
}
