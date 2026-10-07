<?php

namespace App\Support;

use App\Models\Person;
use App\Models\StaffRelation;

/**
 * NGペア（同じ現場に入れない組み合わせ）の判定の正本（2026-10-07 baba「月まとめ自動アサインでNGが効いていない」）。
 *
 * 【決め事（2026-10-07 baba）】
 *  ・**保存は一方通行**（書いた人の欄にだけ残す＝誰が言い出したかが分かる／消すときに片側が残らない）。
 *  ・**判定は両方向**。AがBをNGと書いていれば、BとAも同席させない。
 *
 * 【前の作りの穴（ここで全部ふさぐ）】
 *  ・名前の文字そのままで比べていた＝「池田 莉子」と「池田莉子」が別人になった。
 *    → 名簿にいる人は **people.id で比べる**。名前は空白（半角・全角）を無視して名簿の人に引き当てる。
 *  ・書いた人の側からしか見ていなかった＝入れる順番によってはすり抜けた。
 *  ・月まとめ自動アサインは「案件にすでにいる人」とだけ比べていた＝**同じ回でいっしょに入れる人どうし**は
 *    素通りだった（これがいちばん大きい）。→ 呼ぶ側が入れた人を足しながら conflict() を呼ぶ。
 *
 * ⚠ 名簿にいない名前（引き当てられないNG）は、名前（空白を除いたもの）どうしで比べる。
 */
class NgPairs
{
    /** @var array<string,array<string,true>> staff_id => [相手の staff_id => true]（両方向に入れてある） */
    private array $byId = [];

    /** @var array<string,array<string,true>> staff_id => [名簿にいない相手の名前（空白除去） => true] */
    private array $byLooseName = [];

    /** @var array<string,string> staff_id => 氏名（画面に出す用） */
    private array $names = [];

    /** 空白（半角・全角）を取って比べやすくする。 */
    public static function normalize(?string $name): string
    {
        return preg_replace('/[\s\x{3000}]+/u', '', (string) $name) ?? '';
    }

    /** NGペアを全部読み込む（1回の画面表示・1回の自動アサインで1度だけ呼ぶ）。 */
    public static function load(): self
    {
        $self = new self;

        $idByName = [];
        foreach (Person::query()->get(['id', 'name']) as $p) {
            $self->names[(string) $p->id] = (string) $p->name;
            $key = self::normalize($p->name);
            // 同姓同名が2人いるときは名前では決めない（取り違えるより、名前どうしの比較に回す）。
            $idByName[$key] = array_key_exists($key, $idByName) ? null : (string) $p->id;
        }

        foreach (StaffRelation::query()->where(fn ($q) => $q->where('relation_type', 'NG')->orWhereNull('relation_type'))->get() as $r) {
            $me = (string) $r->staff_id;
            $partner = $r->partner_id ? (string) $r->partner_id : ($idByName[self::normalize($r->partner_name)] ?? null);
            if ($partner !== null && $partner !== '' && $partner !== $me) {
                $self->byId[$me][$partner] = true;
                $self->byId[$partner][$me] = true;   // ⚠ 判定は両方向
            } elseif (($loose = self::normalize($r->partner_name)) !== '') {
                $self->byLooseName[$me][$loose] = true;
            }
        }

        return $self;
    }

    /** 2人がNGの組み合わせか（どちらが書いたかは問わない）。 */
    public function isNg(string $a, string $b): bool
    {
        if ($a === $b) {
            return false;
        }
        if (isset($this->byId[$a][$b])) {
            return true;
        }
        // 名簿に引き当てられなかったNG（名前どうしで比べる）。
        return isset($this->byLooseName[$a][self::normalize($this->names[$b] ?? '')])
            || isset($this->byLooseName[$b][self::normalize($this->names[$a] ?? '')]);
    }

    /**
     * この人と、いっしょにいる人たちの中にNGの相手がいれば、その人の名前を返す（いなければ null）。
     *
     * @param  iterable<string>  $memberIds  同じ案件にいる（入れる予定の）staff_id
     */
    public function conflict(string $staffId, iterable $memberIds): ?string
    {
        foreach ($memberIds as $mid) {
            if ($this->isNg($staffId, (string) $mid)) {
                return $this->names[(string) $mid] ?? (string) $mid;
            }
        }

        return null;
    }

    /**
     * この人とNGの相手（名簿にいる人）の氏名一覧。画面で「選んだ人どうし」を見るのに使う（両方向ぶん）。
     *
     * @return array<int,string>
     */
    public function partnerNames(string $staffId): array
    {
        $out = [];
        foreach (array_keys($this->byId[$staffId] ?? []) as $pid) {
            if (isset($this->names[$pid])) {
                $out[] = $this->names[$pid];
            }
        }
        // 名前だけのNG（同姓同名などで引き当てなかったもの）は、空白抜きで一致する名簿の人の氏名で出す。
        if (! empty($this->byLooseName[$staffId])) {
            foreach ($this->names as $pid => $name) {
                if ($pid !== $staffId && isset($this->byLooseName[$staffId][self::normalize($name)])) {
                    $out[] = $name;
                }
            }
        }

        return array_values(array_unique($out));
    }
}
