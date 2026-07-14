<?php

namespace Tests;

use App\Models\Person;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * 「この人でログイン中＋メール2段階認証（OTP）も確認済み」の状態を作る。
     *
     * 業務画面は auth → 2段階認証(EnsureTwoFactor) → 権限(tier) の順にガードされる。
     * 認証以外の機能を単体で試したいテストでは、ログインの流れそのものはテスト対象外なので、
     * ここで一気に「通過済み」の状態にしてから本題（案件・アサイン等）を検証する。
     *
     * ※ 認証の作り替え（Fortify/OTP）で通過条件が変わったら、直すのはこの1か所だけで済む。
     */
    protected function actingAsPerson(Person $user): static
    {
        return $this->actingAs($user)->withSession(['twofa_ok' => true]);
    }
}
