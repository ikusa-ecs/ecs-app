<?php

namespace App\Support;

/**
 * 「その役割を、どの規模の案件までできるか」の正本（2026-09-09 baba要望）。
 *
 * 【babaの言葉】「MCさんも大型だったらこのメンバーの中で選ぶとか、
 *   このMCさんは最近MCオーディション合格したから少人数の案件でMCにしたいとかも加味できますか？」
 *
 * 【いまの対象＝MCだけ】（baba選択「MCだけ先に作る」）。
 * うまく回れば OP・軍師/サポーターにも広げる。
 * ⚠ 広げるときは people に列を足すのではなく、staff_role_eligibility に
 *   「役割ごとの上限規模」を持たせる形に移すこと（列が役割の数だけ増えていくのを避ける）。
 *   いまは OP の op_online/op_real と同じ作法（people の列）にそろえている。
 *
 * 【持ち方】people.mc_max_scale ＝「MCとして入れる**いちばん大きい規模**」。
 *   空（null）＝制限なし＝どの規模のMCにも入れる（今までと同じ）。
 *   '小型' ＝ 小型の案件のMCだけ。'中型' ＝ 小型と中型。'大型' ＝ 全部。
 *
 * ⚠ これは「できる／できない」の代わりではなく**上乗せ**。
 *   まず「できるポジションにMCがあるか」を見て、そのうえでこの規模を見る。
 * ⚠ 規模の言葉と並び順は RoleRequirementCsv::SCALES が正本（小さい順）。ここに書き写さない。
 */
final class RoleScale
{
    /** 名簿のプルダウンに出す選択肢（値 => 表示）。空＝制限なし。 */
    public static function options(): array
    {
        $out = ['' => 'すべての規模'];
        foreach (RoleRequirementCsv::SCALES as $s) {
            $out[$s] = $s.'まで';
        }

        return $out;
    }

    /** 知らない値・空は「制限なし」に寄せる。 */
    public static function normalize(?string $value): ?string
    {
        $v = trim((string) $value);

        return in_array($v, RoleRequirementCsv::SCALES, true) ? $v : null;
    }

    /**
     * その人が、その規模の案件でその役割に入れるか。
     *
     * @param  ?string  $maxScale       本人の上限（null＝制限なし）
     * @param  ?string  $projectScale   案件の規模（空・知らない値のときは ProjectScale の決まりに合わせる）
     */
    public static function allows(?string $maxScale, ?string $projectScale): bool
    {
        $max = self::normalize($maxScale);
        if ($max === null) {
            return true;   // 制限なし＝今までどおり
        }

        // ⚠ 案件規模が空のときの扱いは ProjectScale が正本（いまは「小型」に数える）。
        //   ここで別の決め方をすると、集計と自動アサインで言うことが変わる。
        $scale = ProjectScale::of($projectScale);

        $order = RoleRequirementCsv::SCALES;
        $need = array_search($scale, $order, true);
        $can = array_search($max, $order, true);

        if ($need === false || $can === false) {
            return true;   // 分からないときは止めない（人が決める）
        }

        return $need <= $can;
    }
}
