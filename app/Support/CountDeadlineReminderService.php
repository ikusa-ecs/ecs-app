<?php

namespace App\Support;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\CountDeadlineReminderLog;
use App\Models\Person;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * 人数確定リマインド（アサイン人数締切リマインドのECS版）。
 *
 * ねらい：イベント開催の「14日前（2週間前）を迎えた案件」を拾い、
 *   参加人数の確定締め切りを、営業＋D（ディレクター）へチャットワークで知らせる。
 *   Dだけには期限つきタスクも付ける。一度送った案件は二度送らない（重複防止）。
 *
 * GAS版（アサイン表→チャットワーク）との違い：
 *   ・データ源＝スプレッドシートの「引継ぎ先」タブ ではなく ECSの projects テーブル。
 *   ・営業＝projects.sales_owners[0]（氏名）／D＝assignments(role='D') の社員（people）。
 *   ・CWID（チャットワークID）は送信先ルームのメンバー一覧APIから氏名で自動照合する。
 */
class CountDeadlineReminderService
{
    /** イベント何日前から送るか（2週間前＝14）。 */
    public const DAYS_BEFORE = 14;

    /** D（ディレクター）のタスク期限＝実行日から何営業日後か。 */
    public const TASK_BIZ_DAYS = 3;

    public function __construct(private ?ChatworkClient $chatwork = null)
    {
        $this->chatwork = $chatwork ?? new ChatworkClient();
    }

    /**
     * 対象案件を集める（14日前以内・未開催・本番日）。
     * 各案件に「送信済みか（alreadySent）」も付けて返す。表示にも送信にも使う。
     *
     * @return array<int, array<string, mixed>>
     */
    public function collectCases(): array
    {
        $today = Carbon::today();
        $limitDay = $today->copy()->addDays(self::DAYS_BEFORE);

        // コンテンツID → 名前
        $contentNames = Content::pluck('content_name', 'id');

        // 案件ごとの D（ディレクター）の社員名。assignments(role='D'・キャンセル除く)→people。
        $employeeNames = Person::employees()->pluck('name', 'id');
        $directorByProject = Assignment::where('role', 'D')
            ->where('status', '!=', 'キャンセル')
            ->get(['project_id', 'staff_id'])
            ->groupBy('project_id')
            ->map(fn ($rows) => $employeeNames[$rows->first()->staff_id] ?? null);

        // 送信済みキー（重複防止）
        $sentKeys = CountDeadlineReminderLog::pluck('dedup_key')->flip();

        $cases = [];
        $projects = Project::orderBy('start_date')->get();
        foreach ($projects as $p) {
            // アーカイブ・下書きは対象外
            if (in_array($p->status, ['完了', '下書き'], true)) {
                continue;
            }
            // 予備日・リハは対象外（人数確定は本番日の話）。空欄＝本番扱い。
            $dateType = (string) ($p->date_type ?? '');
            if ($dateType !== '' && $dateType !== '本番') {
                continue;
            }

            $start = $p->start_date;
            if (! $start) {
                continue;   // 開催日が無い行は無視
            }
            $ev = Carbon::parse($start)->startOfDay();
            if ($ev->lt($today) || $ev->gt($limitDay)) {
                continue;   // 開催済み or まだ14日より先＝対象外
            }

            $firstContentId = is_array($p->content_ids) ? ($p->content_ids[0] ?? null) : null;
            $content = $firstContentId ? ($contentNames[$firstContentId] ?? '') : '';
            $client = (string) ($p->client ?? '');
            if ($content === '' && $client === '') {
                continue;   // 空行スキップ
            }

            $sales = is_array($p->sales_owners) ? (string) ($p->sales_owners[0] ?? '') : '';
            $director = (string) ($directorByProject->get($p->id) ?? '');

            $wd = ['日', '月', '火', '水', '木', '金', '土'][$ev->dayOfWeek];
            $key = $ev->format('Y/m/d') . '|' . $content . '|' . $client;

            $cases[] = [
                'key' => $key,
                'id' => $p->id,
                'dateStr' => $ev->format('n/j') . '(' . $wd . ')',
                'eventDate' => $ev->format('Y-m-d'),
                'daysLeft' => (int) $today->diffInDays($ev),   // 開催日までの残り日数（0=本日）
                'content' => $content,
                'client' => $client,
                'sales' => trim($sales),
                'director' => trim($director),
                'alreadySent' => $sentKeys->has($key),
            ];
        }

        return $cases;
    }

    /**
     * リマインドを実行する。
     * $mode = 'dry'（件数だけ・送信しない）/ 'test'（テスト部屋へ）/ 'live'（本番部屋へ）。
     *
     * @return array<string, mixed> 実行サマリ（画面表示用）
     */
    public function run(string $mode): array
    {
        $dryRun = ($mode === 'dry');
        $isTest = ($mode === 'test');
        $modeLabel = $dryRun ? '件数確認' : ($isTest ? 'テスト送信' : '本番送信');

        $token = config('services.chatwork.token');
        $room = $isTest ? config('services.chatwork.test_room') : config('services.chatwork.room');

        if (! $dryRun && (empty($token) || empty($room))) {
            return [
                'ok' => false,
                'mode' => $mode,
                'modeLabel' => $modeLabel,
                'title' => '⚠️ 未設定',
                'text' => 'APIトークンまたはルームIDが未設定です。.env の CHATWORK_TOKEN / CHATWORK_ROOM_ID を設定してください。',
                'cases' => [],
                'unknownNames' => [],
            ];
        }

        // 全対象のうち「まだ送っていない」ものだけを送る（表示は全件だが送信は未送信のみ）
        $allCases = $this->collectCases();
        $cases = array_values(array_filter($allCases, fn ($c) => ! $c['alreadySent']));
        $skipSent = count($allCases) - count($cases);

        // 名前→CWID 辞書（ルームメンバーAPIで自動照合）
        // 宛先のCWIDは「名簿の登録（people.chatwork_id）」が正。
        // 登録が無い人だけ、今までどおりルームメンバーの表示名で照合して穴埋めする。
        // 名前の突き合わせは表記ゆれで外れることがあるため、名簿の登録を優先する。
        $registered = ChatworkIds::fromPeople();
        $fromRoom = [];
        $memberError = null;
        if (! empty($token) && ! empty($room)) {
            try {
                foreach ($this->chatwork->roomMembers($room) as $m) {
                    if (isset($m['name'], $m['account_id'])) {
                        $fromRoom[$this->normName($m['name'])] = (string) $m['account_id'];
                    }
                }
            } catch (Throwable $e) {
                $memberError = 'ルームメンバー一覧の取得に失敗：' . $e->getMessage();
            }
        }
        $nameToCwid = ChatworkIds::merge($registered, $fromRoom);

        // 本文組み立て＋Dごとのタスク集約
        $unknownNames = [];
        $taskTargets = [];   // cwid => ['name'=>..., 'lines'=>[...]]
        $body = $this->buildBody($cases, $nameToCwid, $isTest, $unknownNames, $taskTargets);

        // タスク期限＝3営業日後 18:00
        $limitDate = $this->addBusinessDays(Carbon::now(), self::TASK_BIZ_DAYS);
        $limitLabel = $limitDate->format('n/j');
        $limitSec = $limitDate->copy()->setTime(18, 0, 0)->timestamp;

        $sent = 0;
        $sendErr = null;
        $taskCount = 0;
        $taskErr = 0;

        if (! $dryRun && count($cases) > 0) {
            try {
                $this->chatwork->postMessage($room, $body);
                $sent = 1;
            } catch (Throwable $e) {
                $sendErr = $e->getMessage();
            }

            foreach ($taskTargets as $cwid => $g) {
                $taskBody = '【参加人数の確定締め切り（開催2週間前／期限 ' . $limitLabel . '）】' . "\n"
                    . 'クライアントからの参加人数の回答状況をご確認ください。未回答ならご督促をお願いします。' . "\n"
                    . implode("\n", $g['lines']);
                try {
                    $this->chatwork->postTask($room, $taskBody, (string) $cwid, $limitSec);
                    $taskCount++;
                } catch (Throwable $e) {
                    $taskErr++;
                }
            }

            // メッセージが送れたときだけ「送信済み」を記録（重複防止）。
            // ※ テスト送信は別部屋への練習なので、本番の重複防止には数えない（本番だけ記録）。
            if ($sent === 1 && ! $isTest) {
                $now = Carbon::now();
                foreach ($cases as $c) {
                    CountDeadlineReminderLog::firstOrCreate(
                        ['dedup_key' => $c['key']],
                        [
                            'project_id' => $c['id'],
                            'event_date' => $c['eventDate'],
                            'room_id' => $room,
                            'sent_at' => $now,
                        ]
                    );
                }
            }
        }

        return [
            'ok' => $sendErr === null,
            'mode' => $mode,
            'modeLabel' => $modeLabel,
            'title' => '✅ ' . $modeLabel . '完了',
            'room' => $room,
            'isTest' => $isTest,
            'hit' => count($cases),
            'skipSent' => $skipSent,
            'sent' => $sent,
            'sendErr' => $sendErr,
            'taskCount' => $taskCount,
            'taskErr' => $taskErr,
            'limitLabel' => $limitLabel,
            'memberError' => $memberError,
            'unknownNames' => array_keys($unknownNames),
            'cases' => $cases,
        ];
    }

    /**
     * 送信本文を組み立てる（案件を1通にまとめる）。
     * 副作用：$unknownNames（CWID未取得の氏名）と $taskTargets（Dごとのタスク行）を埋める。
     */
    private function buildBody(array $cases, array $nameToCwid, bool $isTest, array &$unknownNames, array &$taskTargets): string
    {
        $body = '';
        if ($isTest) {
            $body .= "🧪 これはテスト送信です\n";
        }
        if (count($cases) === 0) {
            return $body;
        }

        $body .= "おつかれさまです！🙌\n";
        $body .= "イベント日まで【約2週間】になった案件のお知らせです📣\n";
        $body .= "原則、開催2週間前までに【参加人数】をクライアントから確定してもらいます。\n";
        $body .= "各案件、参加人数の回答状況をご確認ください（未回答ならご督促をお願いします）🙏\n";

        // 冒頭：対象の営業・Dをまとめて一括メンション
        $headIds = [];
        foreach ($cases as $x) {
            foreach ([$x['sales'], $x['director']] as $nm) {
                $id = $nm !== '' ? ($nameToCwid[$this->normName($nm)] ?? '') : '';
                if ($id !== '') {
                    $headIds[$id] = true;
                }
            }
        }
        $head = implode('', array_map(fn ($id) => '[To:' . $id . ']', array_keys($headIds)));
        if ($head !== '') {
            $body .= $head . "\n";
        }

        $body .= "\n────────────────\n";
        foreach ($cases as $x) {
            $sId = $x['sales'] !== '' ? ($nameToCwid[$this->normName($x['sales'])] ?? '') : '';
            $dId = $x['director'] !== '' ? ($nameToCwid[$this->normName($x['director'])] ?? '') : '';

            $body .= '■ ' . $x['dateStr'] . '　' . ($x['content'] !== '' ? $x['content'] : '(コンテンツ未記入)')
                . ($x['client'] !== '' ? '／' . $x['client'] : '') . "\n";
            $body .= '　営業：' . ($x['sales'] !== '' ? $x['sales'] : '(未記入)') . ($sId ? '' : '（CWID未取得）')
                . '　／　D：' . ($x['director'] !== '' ? $x['director'] : '(未記入)') . ($dId ? '' : '（CWID未取得）') . "\n";

            if (! $sId && $x['sales'] !== '') {
                $unknownNames[$x['sales']] = true;
            }
            if (! $dId && $x['director'] !== '') {
                $unknownNames[$x['director']] = true;
            }

            // D のタスク用に集約（CWIDがある人のみ）
            if ($dId) {
                if (! isset($taskTargets[$dId])) {
                    $taskTargets[$dId] = ['name' => $x['director'], 'lines' => []];
                }
                $taskTargets[$dId]['lines'][] = '・' . $x['dateStr'] . ' ' . $x['content']
                    . ($x['client'] !== '' ? '／' . $x['client'] : '');
            }
        }
        $body .= "────────────────\n";
        $body .= "📌 ディレクターのみなさんには、別途「参加人数の確定（期限つき）」のタスクをお付けします。\n";
        $body .= 'お手数ですが、対応をよろしくお願いします😊';

        return $body;
    }

    /** 氏名を照合用に正規化（全角/半角スペース・空白を除去）。 */
    private function normName(string $s): string
    {
        // 正規化のしかたの正本は App\Support\ChatworkIds（名簿側と同じ揃え方にするため）。
        return ChatworkIds::normName($s);
    }

    /** 営業日をn日加算（土日を飛ばす。祝日は未対応）。 */
    private function addBusinessDays(Carbon $from, int $n): Carbon
    {
        $d = $from->copy()->startOfDay();
        $added = 0;
        while ($added < $n) {
            $d->addDay();
            if (! $d->isWeekend()) {
                $added++;
            }
        }

        return $d;
    }
}
