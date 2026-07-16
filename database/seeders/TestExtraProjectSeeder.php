<?php

namespace Database\Seeders;

use App\Models\Content;
use App\Models\Project;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * 追加のテスト案件（動作確認用）。
 *
 * ねらい：必要アサイン人数リスト（content_role_requirements）が登録済みのコンテンツを使い、
 * アサイン画面・日別ボード・ピックアップで「ポジション枠／担当（軍師・サポ）／巡回数／案件の備考」を
 * 実際に試せる案件を増やす。ID は TP-xx 固定で updateOrCreate＝何度流しても重複しない。
 * ※開発・動作確認用のダミー。本番には入れない。
 */
class TestExtraProjectSeeder extends Seeder
{
    public function run(): void
    {
        $today = Carbon::today();

        // 規模 → 目安の運営人数（必要人数）。
        $needByScale = ['小型' => 6, '中型' => 10, '大型' => 16];

        // ディレクター候補（社員）をローテーションで割り当てる。
        $dirs = ['E-001', 'E-002', 'E-003', 'E-004', 'E-005', 'E-006'];

        // [連番, コンテンツID, 規模, クライアント, 開催日=今日から+off, 実施形態, 区分, 状況, 公開, 備考]
        $rows = [
            ['01', 'CT-118', '中型', '株式会社アルファ',      2,  'イベント東(リアル)',      '通常案件', '調整中',  false, '集合はビル1F受付。館内マップを事前共有すること。'],
            ['02', 'CT-119', '大型', 'ベータ工業株式会社',    4,  'イベント東(リアル)',      '通常案件', '未着手',  false, ''],
            ['03', 'CT-121', '中型', 'ガンマ商事株式会社',    5,  'イベント東(オンライン)',  '通常案件', '調整中',  false, 'オンライン。配信URLは前日にDへ共有。'],
            ['04', 'CT-124', '小型', 'デルタ物流株式会社',    7,  'イベント東(リアル)',      '追加案件', '未着手',  false, ''],
            ['05', 'CT-126', '大型', 'イプシロン電機株式会社', 9,  'イベント東(リアル)',      '通常案件', '確定',    true,  '前泊者あり。宿の手配を要確認。'],
            ['06', 'CT-127', '中型', 'ゼータ食品株式会社',    11, 'イベント東(リアル)',      '通常案件', '未着手',  false, ''],
            ['07', 'CT-131', '大型', 'イータ株式会社',        13, 'イベント東(リアルロング)', '通常案件', '調整中',  false, '長時間案件。巡回の割り振りに注意。'],
            ['08', 'CT-137', '中型', 'シータ製薬株式会社',    15, 'イベント東(リアル)',      '通常案件', '未着手',  false, ''],
            ['09', 'CT-139', '小型', 'イオタ観光株式会社',    18, 'イベント東(リアル)',      '追加案件', '調整中',  false, '会場が狭いため機材は最小構成で。'],
            ['10', 'CT-146', '中型', 'カッパ教育株式会社',    20, 'イベント東(リアル)',      '通常案件', '未着手',  false, ''],
            ['11', 'CT-112', '大型', 'ラムダ金融株式会社',    23, 'イベント東(リアル)',      '通常案件', '未着手',  false, '受付の軍師・サポーターの人数調整あり。'],
            ['12', 'CT-128', '中型', 'ミュー建設株式会社',    26, 'イベント東(リアル)',      '通常案件', '未着手',  false, ''],
        ];

        // コンテンツ名（案件名づくり用）。
        $contentNames = Content::pluck('content_name', 'id');

        foreach ($rows as $i => $r) {
            [$seq, $cid, $scale, $client, $off, $format, $category, $status, $published, $note] = $r;

            $cname = $contentNames[$cid] ?? $cid;

            Project::updateOrCreate(['id' => 'TP-' . $seq], [
                'project_name' => $client . ' ' . $cname,
                'content_ids' => [$cid],
                'client' => $client,
                'scale' => $scale,
                'category' => $category,
                'site_category' => '通常',
                'start_date' => $today->copy()->addDays($off),
                'required_count' => $needByScale[$scale] ?? 10,
                'director_id' => $dirs[$i % count($dirs)],
                'location' => '東京都（テスト住所）',
                'start_time' => '09:00',
                'end_time' => '17:00',
                'event_enter_time' => '09:30',
                'event_start_time' => '10:00',
                'event_end_time' => '16:30',
                'format' => $format,
                'lodging' => str_contains($note, '前泊') ? '前泊有' : '無',
                'is_recruiting' => true,
                'date_type' => '本番',
                'status' => $status,
                'staff_published' => $published,
                'guest_count' => $needByScale[$scale] * 12,
                'team_count' => max(2, intdiv($needByScale[$scale], 2)),
                'note' => $note,
            ]);
        }
    }
}
