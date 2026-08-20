<?php

namespace App\Support;

use App\Mail\StaffNotifyMail;
use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;
use App\Models\StaffNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * スタッフへのお知らせ（メール）を組み立てて送る係。
 *
 * ■ 方針（2026-08-20 baba 決定）
 *   **自動送信はしない。** 社員が /assign-notify の画面で「誰に・どんな文面で送るか」を
 *   確かめてから「送信」を押したときだけ送る。理由＝いまDBのメールは全部ダミー
 *   （@example.com）で、自動化すると誤送信が起きる。実データが入ってから自動化を足す。
 *
 * ■ 2種類のお知らせ
 *   assign_confirmed  … あなたのアサインが確定しました（公開ON＋自分のアサインが確定）
 *   project_published … 案件の募集が始まりました（公開ONの募集案件を、その拠点のスタッフへ）
 *
 * ■ 送るモード
 *   dry  … 送らない（件数と文面の確認だけ）
 *   test … 操作している本人のメールアドレスにだけ送る（文面の見た目を確かめる）
 *   live … 対象者へ実際に送る。記録を残し、同じ知らせは二度送らない
 *
 * ■ 安全弁
 *   ・宛先がダミー（@example.com）・空・通知オフの人は live でも送らず「skipped」で記録
 *   ・すでに送った組み合わせ（dedup_key）は候補に出さない
 */
class StaffNotifyService
{
    public const KIND_CONFIRMED = 'assign_confirmed';
    public const KIND_PUBLISHED = 'project_published';

    /** 送らない宛先（見本データのメール）。本番の実データが入るまでの安全弁。 */
    private const DUMMY_MAIL_DOMAIN = '@example.com';

    /**
     * 送る候補を集める。
     *
     * @return array<int, array<string, mixed>>
     */
    public function collect(string $kind): array
    {
        return $kind === self::KIND_PUBLISHED
            ? $this->collectPublished()
            : $this->collectConfirmed();
    }

    /**
     * 「あなたのアサインが確定しました」の候補。
     * 条件＝公開ON・下書き/完了でない・これからの日・自分のアサインが「確定」。
     * スタッフ画面の確定アサインタブと同じ条件にそろえる（画面に出ていないものを知らせない）。
     */
    private function collectConfirmed(): array
    {
        $today = Carbon::today();

        $projects = Project::where('staff_published', true)
            ->whereNotIn('status', ['下書き', '完了'])
            ->get()
            ->keyBy('id');
        if ($projects->isEmpty()) {
            return [];
        }

        $rows = Assignment::whereIn('project_id', $projects->keys()->all())
            ->where('status', '確定')
            ->orderBy('date')
            ->get();

        $people = Person::whereIn('id', $rows->pluck('staff_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $sent = $this->sentKeys(self::KIND_CONFIRMED);
        $out = [];

        foreach ($rows as $a) {
            $p = $projects->get($a->project_id);
            $person = $people->get($a->staff_id);
            if (! $p || ! $person || $p->is_archived === true) {
                continue;
            }
            $date = $a->date ?: $p->start_date;
            if (! $date || $date->copy()->startOfDay()->lt($today)) {
                continue;   // 過ぎた日は知らせない
            }

            $key = $this->key(self::KIND_CONFIRMED, $p->id, $person->id, $date->format('Y-m-d'));
            if (isset($sent[$key])) {
                continue;   // すでに送った
            }

            $out[] = [
                'kind'       => self::KIND_CONFIRMED,
                'dedup_key'  => $key,
                'staff_id'   => $person->id,
                'staff_name' => $person->name,
                'to'         => (string) ($person->email ?? ''),
                'project_id' => $p->id,
                'project'    => $p->project_name,
                'date'       => $date->format('Y-m-d'),
                'subject'    => '【ECS】アサインが確定しました（' . $date->format('n/j') . ' ' . $p->project_name . '）',
                'headline'   => '下記の案件について、あなたのアサインが確定しました。',
                'lines'      => [
                    '日付'       => $date->format('Y年n月j日') . '（' . $this->dow($date) . '）',
                    '案件'       => (string) $p->project_name,
                    'クライアント' => (string) ($p->client ?? ''),
                    'あなたの担当' => AssignmentRole::label($a->role)
                        . ($a->role2 ? '（兼任：' . AssignmentRole::label($a->role2) . '）' : ''),
                    '集合〜解散'  => ($p->staff_meet_time ?? $p->start_time ?? '—')
                        . ' 〜 ' . ($p->staff_leave_time ?? $p->end_time ?? '—'),
                    '集合場所'    => trim((string) ($p->assembly_type ?? '') . ' ' . (string) ($p->assembly_detail ?? '')),
                    '会場'       => (string) ($p->location ?? ''),
                    '服装'       => (string) ($p->staff_dresscode ?? ''),
                    '持ち物'      => (string) ($p->staff_belongings ?? ''),
                    '注意事項'    => (string) ($p->staff_notes ?? ''),
                    '担当より'    => trim((string) ($a->remark ?? '')),
                ],
                'footer'     => '内容が変わったときは、スタッフ画面の表示が自動で新しくなります。',
                'skipReason' => $this->skipReason($person),
            ];
        }

        return $out;
    }

    /**
     * 「案件の募集が始まりました」の候補。
     * 条件＝公開ON・募集する・下書き/完了でない・これからの日。
     * 宛先＝その案件の拠点のスタッフ（拠点が空の人は東京あつかい＝OfficeScope と同じ考え方）。
     */
    private function collectPublished(): array
    {
        $today = Carbon::today();

        $projects = Project::where('staff_published', true)
            ->where('is_recruiting', true)
            ->whereNotIn('status', ['下書き', '完了'])
            ->orderBy('start_date')
            ->get()
            ->filter(fn (Project $p) => $p->start_date && $p->start_date->copy()->startOfDay()->gte($today))
            ->filter(fn (Project $p) => $p->is_archived !== true);
        if ($projects->isEmpty()) {
            return [];
        }

        $staff = Person::staff()->where('active', true)->get();
        $sent = $this->sentKeys(self::KIND_PUBLISHED);
        $out = [];

        foreach ($projects as $p) {
            $office = trim((string) ($p->office ?? ''));
            foreach ($staff as $person) {
                // 拠点が違う人には送らない（募集タブに出ないものを知らせない）。
                $personOffice = trim((string) ($person->office ?? '')) ?: OfficeScope::DEFAULT_OFFICE;
                if ($office !== '' && $personOffice !== $office) {
                    continue;
                }

                $key = $this->key(self::KIND_PUBLISHED, $p->id, $person->id);
                if (isset($sent[$key])) {
                    continue;
                }

                $out[] = [
                    'kind'       => self::KIND_PUBLISHED,
                    'dedup_key'  => $key,
                    'staff_id'   => $person->id,
                    'staff_name' => $person->name,
                    'to'         => (string) ($person->email ?? ''),
                    'project_id' => $p->id,
                    'project'    => $p->project_name,
                    'date'       => $p->start_date->format('Y-m-d'),
                    'subject'    => '【ECS】募集が出ました（' . $p->start_date->format('n/j') . ' ' . $p->project_name . '）',
                    'headline'   => '新しい案件の募集が始まりました。入れる場合はスタッフ画面から「エントリーする」を押してください。',
                    'lines'      => [
                        '日付'      => $p->start_date->format('Y年n月j日') . '（' . $this->dow($p->start_date) . '）',
                        '案件'      => (string) $p->project_name,
                        '募集人数'   => (((int) ($p->required_count ?? 0)) > 0 ? (int) $p->required_count : 5) . '名',
                        '集合〜解散' => ($p->staff_meet_time ?? $p->start_time ?? '—')
                            . ' 〜 ' . ($p->staff_leave_time ?? $p->end_time ?? '—'),
                        '集合場所'   => trim((string) ($p->assembly_type ?? '') . ' ' . (string) ($p->assembly_detail ?? '')),
                        '会場'      => (string) ($p->location ?? ''),
                    ],
                    'footer'     => 'エントリーは先着ではありません。締切までに出していただければ大丈夫です。',
                    'skipReason' => $this->skipReason($person),
                ];
            }
        }

        return $out;
    }

    /**
     * 送る。
     *
     * @param  string  $mode  dry / test / live
     * @return array{mode:string,kind:string,total:int,sent:int,skipped:int,failed:int,messages:array<int,string>}
     */
    public function run(string $kind, string $mode): array
    {
        $cases = $this->collect($kind);
        $result = [
            'mode' => $mode, 'kind' => $kind,
            'total' => count($cases), 'sent' => 0, 'skipped' => 0, 'failed' => 0,
            'messages' => [],
        ];

        if ($mode === 'dry') {
            $result['messages'][] = '送信はしていません（件数と文面の確認だけ）。対象 ' . count($cases) . ' 件。';

            return $result;
        }

        // test＝操作している本人のアドレスにだけ、先頭1件を送って見た目を確かめる。
        if ($mode === 'test') {
            $me = Auth::user();
            $to = trim((string) ($me->email ?? ''));
            if ($to === '') {
                $result['messages'][] = 'あなたのメールアドレスが登録されていないため、テスト送信できません。';

                return $result;
            }
            $first = $cases[0] ?? null;
            if (! $first) {
                $result['messages'][] = '送る対象がありません。';

                return $result;
            }
            try {
                Mail::to($to)->send($this->mailFor($first));
                $result['sent'] = 1;
                $result['messages'][] = 'テスト送信しました（宛先＝あなた: ' . $to . '／記録は残していません）。';
            } catch (Throwable $e) {
                $result['failed'] = 1;
                $result['messages'][] = 'テスト送信に失敗しました：' . $e->getMessage();
            }

            return $result;
        }

        // live＝対象者へ実際に送る。送った・送らなかったを1件ずつ記録する。
        $by = Auth::id();
        foreach ($cases as $c) {
            $skip = $c['skipReason'];
            if ($skip !== null) {
                $this->log($c, 'skipped', $skip, $by);
                $result['skipped']++;
                continue;
            }
            try {
                Mail::to($c['to'])->send($this->mailFor($c));
                $this->log($c, 'sent', null, $by);
                $result['sent']++;
            } catch (Throwable $e) {
                $this->log($c, 'failed', mb_substr($e->getMessage(), 0, 200), $by);
                $result['failed']++;
            }
        }

        $result['messages'][] = "送信 {$result['sent']} 件／送らなかった {$result['skipped']} 件／失敗 {$result['failed']} 件。";
        if ($result['skipped'] > 0) {
            $result['messages'][] = '「送らなかった」は、宛先が見本データ（@example.com）・未登録・本人が通知オフのいずれかです。';
        }

        return $result;
    }

    /** 1件ぶんのメールを組み立てる。 */
    private function mailFor(array $c): StaffNotifyMail
    {
        return new StaffNotifyMail(
            mailSubject: $c['subject'],
            staffName: (string) $c['staff_name'],
            headline: $c['headline'],
            lines: array_filter($c['lines'], fn ($v) => trim((string) $v) !== ''),
            footer: $c['footer'],
        );
    }

    /**
     * この人に送らない理由（送ってよければ null）。
     * ・メール未登録 ・見本データのアドレス ・本人が「アサイン確定の通知」をオフにしている
     */
    private function skipReason(Person $person): ?string
    {
        $to = trim((string) ($person->email ?? ''));
        if ($to === '') {
            return 'メールアドレスが未登録';
        }
        if (str_ends_with(mb_strtolower($to), self::DUMMY_MAIL_DOMAIN)) {
            return '見本データのアドレス（' . self::DUMMY_MAIL_DOMAIN . '）';
        }
        $settings = is_array($person->notify_settings) ? $person->notify_settings : [];
        if (array_key_exists('assign', $settings) && ! $settings['assign']) {
            return '本人が通知をオフにしている';
        }

        return null;
    }

    /** 送信の記録を1行残す。 */
    private function log(array $c, string $status, ?string $note, ?string $by): void
    {
        StaffNotification::updateOrCreate(
            ['dedup_key' => $c['dedup_key']],
            [
                'kind' => $c['kind'],
                'staff_id' => $c['staff_id'],
                'project_id' => $c['project_id'],
                'date' => $c['date'] ?? null,
                'channel' => 'mail',
                'to' => $c['to'] !== '' ? $c['to'] : null,
                'status' => $status,
                'note' => $note,
                'sent_by' => $by,
                'sent_at' => Carbon::now(),
            ]
        );
    }

    /** すでに送った（記録がある）鍵の一覧。 */
    private function sentKeys(string $kind): array
    {
        return StaffNotification::where('kind', $kind)
            ->pluck('dedup_key')
            ->flip()
            ->all();
    }

    /** 重複防止の鍵。 */
    private function key(string $kind, string $projectId, string $staffId, ?string $date = null): string
    {
        return implode('|', array_filter([$kind, $projectId, $staffId, $date]));
    }

    /** 日本語の曜日1文字。 */
    private function dow(Carbon $d): string
    {
        return ['日', '月', '火', '水', '木', '金', '土'][$d->dayOfWeek];
    }
}
