<?php

namespace App\Support;

/**
 * 派遣依頼の「状態」の正本（2026-09-03）。
 *
 * ⚠ 言葉と色を画面に直書きすると、片方だけ直して食い違う
 *   （日別ボード・派遣一覧の2画面で使う）。
 */
final class DispatchStatus
{
    public const ASKED = '依頼中';
    public const FIXED = '確定';
    public const CANCELLED = 'キャンセル';

    /** 選べる状態（プルダウンの並び）。 */
    public const ALL = [self::ASKED, self::FIXED, self::CANCELLED];

    /**
     * 画面で使う色の種類（CSSのクラス名の一部）。
     * ⚠ 実際の色はそれぞれの画面のCSSで持つ。ここは「どの種類か」だけ。
     */
    public const CLASSES = [
        self::ASKED => 'asked',
        self::FIXED => 'fixed',
        self::CANCELLED => 'cancelled',
    ];

    /** 入力チェック用（'依頼中,確定,キャンセル'）。 */
    public static function inRule(): string
    {
        return 'in:'.implode(',', self::ALL);
    }

    public static function cls(?string $status): string
    {
        return self::CLASSES[(string) $status] ?? 'asked';
    }
}
