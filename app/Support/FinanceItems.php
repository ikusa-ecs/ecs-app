<?php

namespace App\Support;

/**
 * 収支の「経費の費目」の正本（single source of truth）。
 *
 * これまで費目と単価は収支入力画面（mypage_finance.blade.php）のJSにだけ書かれていた。
 * 収支の一覧（/finance-list）でも同じ計算をする必要があるため、ここへ一本化する。
 *   ・画面（JS）はここから渡された定義を使う（window.ECS_COST_ITEMS）
 *   ・一覧・CSV・リマインドはこのクラスの costTotal() で合計を出す
 * ＝どちらか片方だけ直して金額が食い違う事故を防ぐ。
 *
 * 費目の考え方：
 *   ・price が数値＝単価が決まっているもの。金額＝単価 × 数量（qty）。
 *   ・price が null＝実費。金額を直接入力し、**1,000円単位に切り上げ**て合計する
 *     （例：499円→1,000円。入力画面の注記と同じルール）。
 *
 * ⚠ 単価はイベプラの運用ルール（アサイン表の「収支」シート／Notion）に合わせる。
 *   単価を変えるときはここだけ直せば、入力画面と一覧の両方に効く。
 */
class FinanceItems
{
    /** 実費（金額を直接入力する費目）の丸め単位。 */
    public const ROUND_UNIT = 1000;

    /**
     * 費目の定義。key＝DB（project_finances.items）に入る識別子なので変えない。
     *
     * @var list<array{key:string,name:string,price:int|null,unit:string,note:string}>
     */
    public const ITEMS = [
        // ── 当日スタッフ費（形態別。単価は1人1日ぶん。数量＝スタッフ人数） ──
        ['key' => 'staff_online', 'name' => '当日スタッフ費（オンライン）', 'price' => 7000, 'unit' => '名', 'note' => '交通費込み・1人1日ぶん'],
        ['key' => 'staff_real', 'name' => '当日スタッフ費（リアル）', 'price' => 10000, 'unit' => '名', 'note' => '交通費込み・1人1日ぶん'],
        ['key' => 'staff_long', 'name' => '当日スタッフ費（リアルロング）', 'price' => 12000, 'unit' => '名', 'note' => '交通費込み・1人1日ぶん'],
        ['key' => 'stay_pre', 'name' => '前泊手当', 'price' => 2000, 'unit' => '件', 'note' => '前泊ありの場合 ＋2,000/件'],
        ['key' => 'stay_post', 'name' => '後泊手当', 'price' => 2000, 'unit' => '件', 'note' => '後泊ありの場合 ＋2,000/件'],
        // ── 単価が決まっている費用 ──
        ['key' => 'food', 'name' => '飲食費（水分含む）', 'price' => 1000, 'unit' => '人', 'note' => ''],
        ['key' => 'print_conveni', 'name' => 'コンビニ印刷費', 'price' => 1000, 'unit' => '件', 'note' => 'コンビニで印刷した分'],
        ['key' => 'goods_move', 'name' => '輸送費', 'price' => 2000, 'unit' => 'コンテナ(片道)', 'note' => '1コンテナ1箱・片道。大型/チャーターは実費へ'],
        ['key' => 'move_air', 'name' => 'スタッフ移動費（飛行機）', 'price' => 20000, 'unit' => '片道', 'note' => ''],
        ['key' => 'move_taxi', 'name' => 'スタッフ移動費（タクシー）', 'price' => 2000, 'unit' => '片道', 'note' => ''],
        ['key' => 'move_bus', 'name' => 'スタッフ移動費（高速バス）', 'price' => 2000, 'unit' => '片道', 'note' => ''],
        ['key' => 'parking', 'name' => '駐車場費', 'price' => 3000, 'unit' => '日', 'note' => ''],
        ['key' => 'lodging', 'name' => '宿泊費', 'price' => 11000, 'unit' => '泊', 'note' => '5人で泊まったら5泊。前泊・後泊手当も含む'],
        ['key' => 'trainer', 'name' => '研修講師費', 'price' => 77000, 'unit' => '日', 'note' => 'OODAチャンバラ・サバ研はスタッフ費で計上'],
        // ── 実費（金額を直接入力。1,000円単位に切り上げ） ──
        ['key' => 'highway', 'name' => '高速費（ガソリン・ETC含む）', 'price' => null, 'unit' => '実費', 'note' => '片道：30km1,800/50km3,000/90km5,400/120km7,200/150km9,000/180km10,800/210km12,600'],
        ['key' => 'rentacar', 'name' => 'レンタカー費', 'price' => null, 'unit' => '実費', 'note' => 'ハイエース基準：1泊2日16,000/2泊3日29,000/3泊4日42,000/4泊5日55,000'],
        ['key' => 'food_cater', 'name' => 'フード手配費', 'price' => null, 'unit' => '実費', 'note' => 'ケータリング/オードブル/BBQ/格付け/マグロ等。業者別ルールは収支定義を参照'],
        ['key' => 'outsource', 'name' => '外注費', 'price' => null, 'unit' => '実費', 'note' => 'MC・音響・配信・警備・設備など'],
        ['key' => 'print_input', 'name' => '入稿印刷費', 'price' => null, 'unit' => '実費', 'note' => 'パッケージ以外'],
        ['key' => 'goods_buy', 'name' => '物品購入費', 'price' => null, 'unit' => '実費', 'note' => '該当イベントのみで使う物品（今後使う物は計上しない）'],
        ['key' => 'move_irregular', 'name' => 'イレギュラー輸送費', 'price' => null, 'unit' => '実費', 'note' => '大型物品輸送・チャーター便など'],
        ['key' => 'onsite', 'name' => '緊急購入物品費', 'price' => null, 'unit' => '実費', 'note' => '現場で緊急的に購入した物品'],
    ];

    /**
     * 画面（JS）へ渡す費目定義。
     *
     * @return list<array{key:string,name:string,price:int|null,unit:string,note:string}>
     */
    public static function all(): array
    {
        return self::ITEMS;
    }

    /** 費目キー → 表示名（一覧・CSVの見出しに使う）。未知のキーはそのまま返す。 */
    public static function label(string $key): string
    {
        foreach (self::ITEMS as $item) {
            if ($item['key'] === $key) {
                return $item['name'];
            }
        }

        return $key;
    }

    /**
     * 経費の合計（円）。入力画面の recalc() と同じルールで計算する。
     *   ・単価つきの費目＝単価 × 数量（qty）
     *   ・実費の費目　　＝入力金額を 1,000円単位に切り上げ
     *   ・定義に無いキー＝保存されている金額をそのまま足す（古いデータを落とさないため）
     *
     * @param  array<string, array<string, mixed>>|null  $items  project_finances.items
     */
    public static function costTotal(?array $items): int
    {
        if (empty($items)) {
            return 0;
        }

        $total = 0;
        $known = [];

        foreach (self::ITEMS as $item) {
            $key = $item['key'];
            $known[$key] = true;
            $row = $items[$key] ?? null;
            if (! is_array($row)) {
                continue;
            }

            if ($item['price'] !== null) {
                $qty = (int) ($row['qty'] ?? 0);
                if ($qty > 0) {
                    $total += $item['price'] * $qty;
                }
                continue;
            }

            // 実費＝1,000円単位に切り上げ（499円→1,000円）
            $raw = (int) ($row['amount'] ?? 0);
            if ($raw > 0) {
                $total += (int) ceil($raw / self::ROUND_UNIT) * self::ROUND_UNIT;
            }
        }

        // 定義から消えた費目が残っている場合の保険（金額をそのまま足す）
        foreach ($items as $key => $row) {
            if (! isset($known[$key]) && is_array($row)) {
                $total += max(0, (int) ($row['amount'] ?? 0));
            }
        }

        return $total;
    }

    /** 利益（売上 − 経費合計）。売上が未入力なら経費ぶんのマイナスになる。 */
    public static function profit(?int $revenue, ?array $items): int
    {
        return (int) ($revenue ?? 0) - self::costTotal($items);
    }

    /** 入力済みか（売上か経費のどちらかが入っていれば「入力済み」とみなす）。 */
    public static function isFilled(?int $revenue, ?array $items): bool
    {
        return ($revenue !== null && $revenue > 0) || self::costTotal($items) > 0;
    }
}
