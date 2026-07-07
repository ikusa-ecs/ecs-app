<?php

namespace Database\Seeders;

use App\Models\Person;
use Illuminate\Database\Seeder;

/**
 * 人名簿（社員・スタッフ）の見本データ。モックの people.js を元にしている。
 * ★メールはすべて架空（@example.com）。実在の @ikusa.co.jp は使わないので、
 *   本物の自己登録と衝突しない。本番には入れない開発・動作確認用のダミー。
 */
class PersonSeeder extends Seeder
{
    public function run(): void
    {
        // ── 社員（employee）── dept: plan=イベプラ / sales=セールス / creative=クリエイティブ
        $employees = [
            ['id' => 'E-001', 'name' => '田中 健一', 'department' => 'イベプラ',     'hire_date' => '2021-12-01',
             'experienced_contents' => ['水合戦', '運動会', '縁日', '懇親会運営', '表彰式'], 'director_contents' => ['水合戦', '運動会', '表彰式'], 'shirt_size' => 'L',  'shoe_size' => '27.0'],
            ['id' => 'E-002', 'name' => '鈴木 彩花', 'department' => 'セールス',     'hire_date' => '2023-02-01',
             'experienced_contents' => ['縁日', '懇親会運営', 'ワークショップ系', '表彰式'], 'director_contents' => ['縁日', '懇親会運営'], 'shirt_size' => 'M',  'shoe_size' => '24.0'],
            ['id' => 'E-003', 'name' => '佐藤 大輔', 'department' => 'イベプラ',     'hire_date' => '2024-02-01',
             'experienced_contents' => ['運動会', '水合戦', 'クイズ大会'], 'director_contents' => ['運動会'], 'shirt_size' => 'LL', 'shoe_size' => '28.0'],
            ['id' => 'E-004', 'name' => '高橋 直樹', 'department' => 'クリエイティブ', 'hire_date' => '2025-04-01',
             'experienced_contents' => ['縁日', '懇親会運営', '表彰式'], 'director_contents' => [], 'shirt_size' => 'M',  'shoe_size' => '26.5'],
            ['id' => 'E-005', 'name' => '山本 萌',   'department' => 'セールス',     'hire_date' => '2026-01-01',
             'experienced_contents' => ['懇親会運営'], 'director_contents' => [], 'shirt_size' => 'S',  'shoe_size' => '23.5'],
            ['id' => 'E-006', 'name' => '中村 蓮',   'department' => 'イベプラ',     'hire_date' => '2026-04-01',
             'experienced_contents' => ['縁日'], 'director_contents' => [], 'shirt_size' => 'L',  'shoe_size' => '27.5'],
        ];

        foreach ($employees as $e) {
            Person::updateOrCreate(['id' => $e['id']], array_merge($e, [
                'role' => 'employee',
                'email' => strtolower($e['id']) . '@example.com',
                'active' => true,
                'is_admin' => false,
                'permission' => 'employee',   // 社員
                'password' => 'password',      // 開発用の仮パスワード（本番前に入れ替え・castで自動暗号化）
            ]));
        }

        // ── スタッフ（staff）── traits: [follow（新人フォロー可）, starter（自走）, atmos（空気を良くする）]
        $staff = [
            ['id' => 'S-001', 'name' => '高橋 由依',   'hire_date' => '2019-04-01', 'is_exclusive' => true,  'experience_count' => 82, 'traits' => [true, true, true],  'planner_impression' => '初回から落ち着いて全体を見られる。Dを任せられる。'],
            ['id' => 'S-007', 'name' => '伊藤 健',     'hire_date' => '2018-09-01', 'is_exclusive' => true,  'experience_count' => 90, 'traits' => [true, true, false], 'planner_impression' => '音響まわりに強い。機材トラブルにも冷静。'],
            ['id' => 'S-003', 'name' => '渡辺 さくら', 'hire_date' => '2020-05-01', 'is_exclusive' => true,  'experience_count' => 75, 'traits' => [true, true, true],  'planner_impression' => '盛り上げ系のMCが得意。声がよく通る。'],
            ['id' => 'S-027', 'name' => '清水 陽',     'hire_date' => '2021-03-01', 'is_exclusive' => true,  'experience_count' => 70, 'traits' => [true, true, true],  'planner_impression' => '新人のフォロー役として安定。全ポジションに目が届く。'],
            ['id' => 'S-009', 'name' => '松本 美優',   'hire_date' => '2024-04-01', 'is_exclusive' => false, 'experience_count' => 48, 'traits' => [false, true, true], 'planner_impression' => '現場の空気を明るくする。お客様対応が丁寧。'],
            ['id' => 'S-005', 'name' => '井上 大輝',   'hire_date' => '2024-06-01', 'is_exclusive' => false, 'experience_count' => 44, 'traits' => [true, true, false], 'planner_impression' => '現場経験が豊富で安定して動ける。幅広く対応できる。'],
            ['id' => 'S-014', 'name' => '鈴木 美咲',   'hire_date' => '2023-11-01', 'is_exclusive' => false, 'experience_count' => 40, 'traits' => [true, true, true],  'planner_impression' => '新人フォローが上手。軍師・サポーターを任せられる。'],
            ['id' => 'S-018', 'name' => '木村 拓海',   'hire_date' => '2024-08-01', 'is_exclusive' => false, 'experience_count' => 36, 'traits' => [false, true, false], 'planner_impression' => 'チェック業務が正確。PC操作はやや苦手。'],
            ['id' => 'S-021', 'name' => '山田 涼',     'hire_date' => '2024-10-01', 'is_exclusive' => false, 'experience_count' => 33, 'traits' => [false, true, true], 'planner_impression' => '体力現場の経験が豊富。動きがいい。'],
            ['id' => 'S-032', 'name' => '佐藤 健太',   'hire_date' => '2026-01-15', 'is_exclusive' => false, 'experience_count' => 6,  'traits' => [false, false, true], 'planner_impression' => '素直で吸収が早い。まずはFC・受付から経験を積ませたい。'],
            ['id' => 'S-035', 'name' => '池田 莉子',   'hire_date' => '2026-02-01', 'is_exclusive' => false, 'experience_count' => 4,  'traits' => [false, false, false], 'planner_impression' => '受付・チェッカー向き。緊張しやすいのでフォロー役とセットで。'],
            ['id' => 'S-038', 'name' => '橋本 颯',     'hire_date' => '2026-03-01', 'is_exclusive' => false, 'experience_count' => 3,  'traits' => [false, true, true], 'planner_impression' => '体を動かす現場が得意そう。育成現場で伸ばしたい。'],
            ['id' => 'S-041', 'name' => '石川 葵',     'hire_date' => '2026-04-01', 'is_exclusive' => false, 'experience_count' => 2,  'traits' => [false, false, true], 'planner_impression' => 'まだ受付からスタート。人当たりがよい。'],
        ];

        foreach ($staff as $s) {
            [$follow, $starter, $atmos] = $s['traits'];
            Person::updateOrCreate(['id' => $s['id']], [
                'role' => 'staff',
                'name' => $s['name'],
                'email' => strtolower($s['id']) . '@example.com',
                'hire_date' => $s['hire_date'],
                'active' => true,
                'permission' => 'staff',      // スタッフ
                'password' => 'password',      // 開発用の仮パスワード（本番前に入れ替え・castで自動暗号化）
                'is_exclusive' => $s['is_exclusive'],
                'monthly_cap' => 20,                       // 月上限は全員一律20（過重労働防止）
                'experience_count' => $s['experience_count'],
                'can_follow_newbie' => $follow,
                'self_starter' => $starter,
                'improves_atmosphere' => $atmos,
                'planner_impression' => $s['planner_impression'],
            ]);
        }
    }
}
