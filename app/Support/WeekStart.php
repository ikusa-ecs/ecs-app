<?php

namespace App\Support;

use App\Models\Person;
use Illuminate\Support\Facades\Auth;

/**
 * カレンダーの「週のはじまり」を人ごとに選ぶ（2026-09-15 baba要望）。
 *
 * 【babaの言葉】「マイページのカラーを選択できるみたいな感じで、
 *   カレンダー表示できるところは月曜始まりか日曜日始まりにするかを選択できるようにしよう」
 *   （もとは社員の出勤可能日フォームからの要望）。
 *
 * 【なぜ人ごとか】同じ会社の中でも「土日は並べて見たい（月曜はじまり）」「予定表は日曜から
 *   （日曜はじまり）」と好みが割れる。画面の色（[[Themes]]）と同じ考え方。
 *
 * ⚠ **これまで画面によってバラバラだった。** 社員の出勤可能日だけ月曜はじまり、
 *   スタッフ画面の稼働希望とマイページのカレンダーは日曜はじまりだった。
 *   1つの設定にそろえたので、既定（日曜はじまり）のままだと
 *   **社員の出勤可能日の並びが月曜→日曜はじまりに変わる。**「月曜はじまり」を選べば元に戻る。
 *
 * ⚠ 並べ替えの計算は必ず {@see lead()} を通す。画面ごとに (firstDow + 6) % 7 と書くと、
 *   片方だけ直して食い違う（実際に画面ごとにバラバラだったのが今回の発端）。
 */
final class WeekStart
{
    /** 何も選んでいない人の並び。 */
    public const DEFAULT = 'sun';

    /** 選べる並び。[値 => 画面に出す名前]。 */
    public const OPTIONS = [
        'sun' => '日曜はじまり',
        'mon' => '月曜はじまり',
    ];

    /** 選ぶ画面に小さく出す一言。 */
    public const NOTES = [
        'sun' => 'ふつうのカレンダーと同じ並び（日 月 火 水 木 金 土）。',
        'mon' => '土日が右端に並ぶので、週末の予定をまとめて見たいときに向いています（月 火 水 木 金 土 日）。',
    ];

    /** 知らない値・空は既定に寄せる（URLや古いデータで変な値が入っても崩れないように）。 */
    public static function normalize(?string $value): string
    {
        $v = trim((string) $value);

        return array_key_exists($v, self::OPTIONS) ? $v : self::DEFAULT;
    }

    /** その人の設定。 */
    public static function of(?Person $person): string
    {
        return self::normalize($person?->week_start);
    }

    /** ログイン中の人の設定（画面から呼ぶのはこれ）。 */
    public static function current(): string
    {
        $me = Auth::user();

        return self::of($me instanceof Person ? $me : null);
    }

    /**
     * 月初に入れる空きマスの数。
     *
     * @param  int  $firstDow  その月の1日の曜日（0=日 … 6=土）
     */
    public static function lead(int $firstDow, ?string $weekStart = null): int
    {
        $ws = self::normalize($weekStart ?? self::current());

        return $ws === 'mon' ? ($firstDow + 6) % 7 : $firstDow;
    }

    /**
     * 曜日見出しの並び（左から順）。値は 0=日 … 6=土。
     *
     * @return list<int>
     */
    public static function order(?string $weekStart = null): array
    {
        $ws = self::normalize($weekStart ?? self::current());

        return $ws === 'mon' ? [1, 2, 3, 4, 5, 6, 0] : [0, 1, 2, 3, 4, 5, 6];
    }
}
