<?php

namespace App\Support;

use App\Models\Office;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 拠点の「表示範囲」を決める共通部品（全拠点運用・設計書19.2(1)）。
 *
 * ルール：
 *  ・管理者・Administrator（manager/admin）＝全拠点を見られる。画面上のスイッチで拠点を選べる。
 *      選択なし＝全拠点（filter が null）。
 *  ・一般社員・スタッフ（employee/staff）＝自分の拠点だけ。スイッチは出さず、常に自拠点で固定。
 *
 * 現状は全員が東京なので、絞っても絞らなくても見える案件は同じ（＝実害なく仕組みだけ入る）。
 */
class OfficeScope
{
    /**
     * 拠点が未設定の人・案件を、どの拠点あつかいにするか（既定）。
     * これまで '東京' が3か所に直書きされていたので、正本をここ1つにする。
     * ※ 実データ投入時に people.office を埋め直すまでの保険。
     */
    public const DEFAULT_OFFICE = '東京';

    /**
     * 「全拠点」をURLに書くときの合言葉（2026-09-10 baba要望）。
     *
     * ⚠ 以前は「?office= が空＝全拠点」だったが、**画面をはじめて開いたときは自拠点で始める**
     *   ことにしたので、「何も指定していない（＝自拠点）」と「全拠点をわざわざ選んだ」を
     *   区別する必要が出た。空文字だと `array_filter` を通ったリンクで消えてしまい、
     *   月を動かしただけで全拠点→自拠点に戻ってしまうため、消えない文字にしてある。
     */
    public const ALL = 'all';

    /** 管理者以上か（＝全拠点を見られる・スイッチを出す対象か）。 */
    public static function canSeeAll(): bool
    {
        $perm = Auth::user()->permission ?? 'staff';

        return in_array($perm, ['manager', 'admin'], true);
    }

    /** ログインしている人の拠点（未設定なら東京）。 */
    public static function mine(): string
    {
        return trim((string) (Auth::user()->office ?? '')) ?: self::DEFAULT_OFFICE;
    }

    /**
     * 絞り込んだ拠点（null＝全拠点）を、URLに載せる文字に直す。
     * リンクを作るときは必ずこれを通す（空文字にすると `array_filter` で消えるため）。
     */
    public static function param(?string $office): string
    {
        return $office === null ? self::ALL : $office;
    }

    /**
     * 実際に絞り込む拠点名を返す。null＝全拠点（絞らない）。
     *  ・管理者以上：スイッチで選んだ拠点（?office=）。
     *      **何も指定していないときは自分の拠点で始める**（2026-09-10 baba要望）。
     *      「全拠点」を見たいときは ?office=all を選ぶ。
     *  ・それ以外  ：自分の拠点で固定（未設定なら東京）。
     */
    public static function filter(Request $request): ?string
    {
        return self::fromValue($request->query('office'));
    }

    /**
     * 拠点の指定（'all'／拠点名／未指定）を、絞り込みに使う値に直す。null＝全拠点。
     *
     * ⚠ `filter()` はURLの `?office=` しか見ない。**フォームで送る（POST）ときは
     *   こちらに `$request->input('office')` を渡す**（下見と実行で拠点がズレるのを防ぐ）。
     */
    public static function fromValue(?string $value): ?string
    {
        if (self::canSeeAll()) {
            $sel = trim((string) $value);

            if ($sel === self::ALL) {
                return null;   // 全拠点をわざわざ選んだとき
            }

            if ($sel !== '') {
                return $sel;   // スイッチで選んだ拠点
            }

            return self::mine();   // 何も選んでいない＝自分の拠点で始める
        }

        return Auth::user()->office ?: self::DEFAULT_OFFICE;
    }

    /**
     * 「必ずどこか1拠点」を返す（＝全拠点は無し）。
     *
     * 公開ボードのように、全拠点をまとめて操作すると事故になる画面で使う
     * （2026-08-21 baba：全拠点表示のまま一括公開すると、他拠点の未公開案件まで
     *  スタッフに出てしまう）。管理者以上はスイッチで拠点を選べるが「全拠点」は選べない。
     */
    public static function filterSingle(Request $request): string
    {
        if (self::canSeeAll()) {
            $sel = trim((string) $request->query('office', ''));
            if ($sel !== '' && in_array($sel, self::options(), true)) {
                return $sel;
            }
        }

        return trim((string) (Auth::user()->office ?? '')) ?: self::DEFAULT_OFFICE;
    }

    /**
     * スイッチのハイライト用に「今どこを見ているか」を返す（'all'＝全拠点）。管理者以上のみ意味を持つ。
     * ⚠ URLの値をそのまま返さない。何も指定していないときは自拠点で始めるので、
     *   filter() と同じ判断を通して「実際に見ている拠点」を返す（光る場所と中身をそろえる）。
     */
    public static function selected(Request $request): string
    {
        return self::param(self::filter($request));
    }

    /** スイッチに並べる拠点の選択肢（有効な拠点・並び順）。 */
    public static function options(): array
    {
        return Office::where('active', true)->orderBy('sort_order')->pluck('name')->all();
    }

    /**
     * 案件（projects）を拠点で絞る。$office が null なら何もしない（＝全拠点）。
     *
     * 「その拠点で登録された案件」＋「その拠点に共有（ヘルパ/巻き取り）された案件」を見せる。
     * ※ 同じ式を各画面にコピペすると片方だけ直して食い違うので、ここ1か所に置いて呼び出す。
     *
     * ⚠ **拠点が空の案件は「東京」あつかい**（人を絞る applyToPeople と同じ考え方）。
     *   2026-09-10 追加。はじめの表示が自拠点になったので、ここを手当てしないと
     *   **拠点を入れ忘れた案件がどの画面からも消えてしまう**（前は全拠点表示だったので見えていた）。
     */
    public static function applyToProjects($query, ?string $office)
    {
        return $query->when($office, fn ($q) => $q->where(function ($qq) use ($office) {
            $qq->where('office', $office);
            if ($office === self::DEFAULT_OFFICE) {
                $qq->orWhereNull('office')->orWhere('office', '');
            }
            $qq->orWhereHas('shares', fn ($s) => $s->where('office', $office));
        }));
    }

    /**
     * 人（people＝社員・スタッフ）を拠点で絞る。$office が null なら何もしない（＝全拠点）。
     *
     * $keepIds ＝拠点が違っても必ず残す人（例：すでにその案件へアサインされている他拠点のヘルプ）。
     *   理由：D決め・アサイン画面の保存は「いま画面に出ている人で上書き」なので、
     *        候補から消えた人は保存した瞬間に担当を外されてしまう。それを防ぐための逃げ道。
     *
     * ⚠ people.office が空の人は「東京」扱いにする（OfficeScope::filter の既定と同じ考え方）。
     *   実データ投入時に拠点を埋め直すまでの保険。
     *
     * @param  array<int|string, string>  $keepIds
     */
    public static function applyToPeople($query, ?string $office, array $keepIds = [])
    {
        return $query->when($office, fn ($q) => $q->where(function ($qq) use ($office, $keepIds) {
            $qq->where('office', $office);
            if ($office === self::DEFAULT_OFFICE) {
                $qq->orWhereNull('office')->orWhere('office', '');
            }
            if ($keepIds) {
                $qq->orWhereIn('id', $keepIds);
            }
        }));
    }
}
