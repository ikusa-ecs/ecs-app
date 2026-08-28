<?php

namespace App\Support;

use App\Models\Content;
use Illuminate\Support\Facades\Schema;

/**
 * コンテンツの「略称」を引くところの正本（2026-08-28）。
 *
 * 略称の置き場所はコンテンツ台帳（contents.short_name）1か所だけ。
 * ⚠ 画面ごとに「この名前はこう縮める」という変換表を持たないこと。
 *   持つと、コンテンツが増えたときに片方だけ直して食い違う。
 *
 * いま使っている場所：
 *  ・カレンダーの予定名（CalendarTitle）
 *  ・社員の出勤可能日に出す「もう決まっている案件」（ConfirmedSchedule）
 */
final class ContentShortName
{
    /**
     * コンテンツ名 => 略称（略称が入っているものだけ）。
     * ⚠ 1回のクエリでまとめて引く（コンテンツごとに引かない）。
     *
     * @param  list<string>  $names
     * @return array<string, string>
     */
    public static function map(array $names): array
    {
        $names = array_values(array_unique(array_filter(array_map(
            fn ($n) => trim((string) $n),
            $names
        ), fn ($n) => $n !== '')));

        if ($names === []) {
            return [];
        }

        // まだ migrate していないサーバーでも動くようにする（略称なし＝正式名になるだけ）。
        if (! Schema::hasColumn('contents', 'short_name')) {
            return [];
        }

        return Content::whereIn('content_name', $names)
            ->whereNotNull('short_name')
            ->where('short_name', '!=', '')
            ->pluck('short_name', 'content_name')
            ->all();
    }

    /**
     * コンテンツ名の並び → 画面に出す1つの文字列。複数あれば「・」でつなぐ。
     *
     * @param  list<string>  $names
     * @param  string  $fallback  コンテンツ名が空のときに使う名前（案件名）
     * @param  array<string,string>|null  $map  すでに引いてある略称表（一覧でまとめて引いた場合）
     */
    public static function label(array $names, string $fallback = '', bool $short = true, ?array $map = null): string
    {
        $names = array_values(array_filter(array_map(
            fn ($n) => trim((string) $n),
            $names
        ), fn ($n) => $n !== ''));

        if ($names === []) {
            return trim($fallback);
        }

        if ($short) {
            // 台帳に無い名前（単発コンテンツ）はそのまま使う。
            $map ??= self::map($names);
            $names = array_map(fn ($n) => $map[$n] ?? $n, $names);
        }

        return implode('・', $names);
    }
}
