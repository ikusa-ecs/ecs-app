<?php

namespace App\Support;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\FinanceReminderLog;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectFinance;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * 収支の未入力リマインド（2026-08-06 baba確定）。
 *
 * ねらい：収支は「イベント終了後3営業日以内」に入れるルールなので、
 *   締切を迎えたのに未入力の案件を拾い、D（ディレクター）へチャットワークで
 *   期限つきタスクを付ける。営業担当にはメッセージで一緒に知らせる。
 *   一度送った案件には二度送らない（finance_reminder_logs で重複防止）。
 *
 * 作りは「人数確定リマインド」（CountDeadlineReminderService）と同じ形にそろえている。
 *   ・氏名→チャットワークID は送信先ルームのメンバー一覧APIで自動照合
 *   ・dry（件数確認）／test（テスト部屋）／live（本番部屋）の3モード
 */
class FinanceReminderService
{
    /** 締切から何日ぶんまで遡って拾うか（古すぎる案件を今さら催促しないための上限）。 */
    public const LOOKBACK_DAYS = 60;

    public function __construct(private ?ChatworkClient $chatwork = null)
    {
        $this->chatwork = $chatwork ?? new ChatworkClient();
    }

    /**
     * 対象案件を集める＝「開催済み」かつ「収支が未入力」かつ「締切を過ぎている」案件。
     * 締切当日はまだ催促しない（その日いっぱいは猶予）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function collectCases(): array
    {
        $today = Carbon::today();
        $oldest = $today->copy()->subDays(self::LOOKBACK_DAYS);

        $contentNames = Content::pluck('content_name', 'id');
        $employeeNames = Person::employees()->pluck('name', 'id');

        // 案件ごとのD（キャンセル除く）。
        $directorByProject = Assignment::where('role', 'D')
            ->where('status', '!=', 'キャンセル')
            ->get(['project_id', 'staff_id'])
            ->groupBy('project_id')
            ->map(fn ($rows) => $employeeNames[$rows->first()->staff_id] ?? null);

        // 収支の入力状況（案件ID→行）。
        $finances = ProjectFinance::all()->keyBy('project_id');

        $sentKeys = FinanceReminderLog::pluck('dedup_key')->flip();

        $cases = [];
        foreach (Project::orderBy('start_date')->get() as $p) {
            // 下書きは対象外。キャンセルも催促しない（収支が発生しないため）。
            if (in_array($p->status, ['下書き', 'キャンセル'], true)) {
                continue;
            }
            if (! $p->start_date) {
                continue;
            }

            $ev = Carbon::parse($p->start_date)->startOfDay();
            if ($ev->gte($today)) {
                continue;   // まだ開催前＝収支はまだ入れられない
            }
            if ($ev->lt($oldest)) {
                continue;   // 古すぎる案件は今さら催促しない
            }

            $deadline = FinanceAccess::deadline($p);
            if (! $deadline || $today->lte($deadline)) {
                continue;   // 締切当日まではまだ猶予
            }

            $fin = $finances->get($p->id);
            if (FinanceItems::isFilled($fin->revenue ?? null, $fin->items ?? [])) {
                continue;   // すでに入力済み
            }

            // 知らせに出すコンテンツ名。複数選んであれば「A・B」と全部つなぐ（正本＝ProjectContentName）。
            // 分からないときは今までどおり案件名で代用する。
            $content = ProjectContentName::of($p, $contentNames);

            $sales = is_array($p->sales_owners) ? (string) ($p->sales_owners[0] ?? '') : '';
            $wd = ['日', '月', '火', '水', '木', '金', '土'][$ev->dayOfWeek];

            $cases[] = [
                'key' => $p->id,     // 案件1件につき1回（重複防止キー）
                'id' => $p->id,
                'dateStr' => $ev->format('n/j') . '(' . $wd . ')',
                'eventDate' => $ev->format('Y-m-d'),
                'deadline' => $deadline->format('Y-m-d'),
                'deadlineStr' => $deadline->format('n/j'),
                'daysLate' => (int) $deadline->diffInDays($today),   // 締切から何日過ぎたか
                'content' => $content,
                'client' => (string) ($p->client ?? ''),
                'office' => (string) ($p->office ?? ''),
                'sales' => trim($sales),
                'director' => trim((string) ($directorByProject->get($p->id) ?? '')),
                'alreadySent' => $sentKeys->has($p->id),
            ];
        }

        return $cases;
    }

    /**
     * リマインドを実行する。
     * $mode = 'dry'（件数だけ）/ 'test'（テスト部屋へ）/ 'live'（本番部屋へ）。
     *
     * @return array<string, mixed>
     */
    public function run(string $mode): array
    {
        $dryRun = ($mode === 'dry');
        $isTest = ($mode === 'test');
        $modeLabel = $dryRun ? '件数確認' : ($isTest ? 'テスト送信' : '本番送信');

        $token = config('services.chatwork.token');
        // 送り先の部屋は知らせごとに変えられる（2026-09-15 baba要望）。正本＝App\Support\ChatworkRooms。
        $room = ChatworkRooms::for($isTest ? ChatworkRooms::TEST : ChatworkRooms::FINANCE);

        if (! $dryRun && (empty($token) || empty($room))) {
            return [
                'ok' => false,
                'mode' => $mode,
                'modeLabel' => $modeLabel,
                'title' => '⚠️ 未設定',
                'text' => 'APIトークンまたは送り先の部屋が未設定です。'
                    .'トークンは .env の CHATWORK_TOKEN（エンジニア依頼）、'
                    .'部屋は 共通設定 →「チャットワークの送り先」で設定できます。',
                'cases' => [],
                'unknownNames' => [],
            ];
        }

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

        $unknownNames = [];
        $taskTargets = [];
        $body = $this->buildBody($cases, $nameToCwid, $isTest, $unknownNames, $taskTargets);

        // タスク期限＝実行日の翌営業日 18:00（もう締切を過ぎているので短く区切る）。
        $limitDate = FinanceAccess::addBusinessDays(Carbon::now(), 1);
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
                $taskBody = '【収支の入力をお願いします（期限 ' . $limitLabel . '）】' . "\n"
                    . 'イベント終了後3営業日以内が収支入力の締切です。下記の案件が未入力です。' . "\n"
                    . 'ECSの「収支入力」から、売上と経費を入れてください。' . "\n"
                    . implode("\n", $g['lines']);
                try {
                    $this->chatwork->postTask($room, $taskBody, (string) $cwid, $limitSec);
                    $taskCount++;
                } catch (Throwable $e) {
                    $taskErr++;
                }
            }

            // メッセージが送れたときだけ「送信済み」を記録（本番だけ＝テストは練習なので数えない）。
            if ($sent === 1 && ! $isTest) {
                $now = Carbon::now();
                foreach ($cases as $c) {
                    FinanceReminderLog::firstOrCreate(
                        ['dedup_key' => $c['key']],
                        [
                            'project_id' => $c['id'],
                            'event_date' => $c['eventDate'],
                            'deadline' => $c['deadline'],
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
     * 送信本文を組み立てる（未入力の案件を1通にまとめる）。
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
        $body .= "【収支が未入力の案件】のお知らせです💰\n";
        $body .= "収支の入力は、イベント終了後【3営業日以内】が締切です。\n";
        $body .= "下記の案件が未入力のままなので、ECSの「収支入力」から売上と経費をお願いします🙏\n";

        // 冒頭：対象のD・営業をまとめて一括メンション
        $headIds = [];
        foreach ($cases as $x) {
            foreach ([$x['director'], $x['sales']] as $nm) {
                $id = $nm !== '' ? ($nameToCwid[$this->normName($nm)] ?? '') : '';
                if ($id !== '') {
                    $headIds[$id] = true;
                }
            }
        }
        // 共通設定で選んだ「この知らせで毎回メンションする人」も足す（2026-09-17 baba要望）。
        // ⚠ 案件のD・営業への [To:] は今までどおり。ここは**足すだけ**（置き換えない）。
        //   正本＝App\Support\ChatworkMentions。
        foreach (ChatworkMentions::chosenWithId(ChatworkRooms::FINANCE) as $person) {
            $headIds[trim((string) $person->chatwork_id)] = true;
        }

        $head = implode('', array_map(fn ($id) => '[To:' . $id . ']', array_keys($headIds)));
        if ($head !== '') {
            $body .= $head . "\n";
        }

        $body .= "\n────────────────\n";
        foreach ($cases as $x) {
            $dId = $x['director'] !== '' ? ($nameToCwid[$this->normName($x['director'])] ?? '') : '';
            $sId = $x['sales'] !== '' ? ($nameToCwid[$this->normName($x['sales'])] ?? '') : '';

            $body .= '■ ' . $x['dateStr'] . '　' . ($x['content'] !== '' ? $x['content'] : '(コンテンツ未記入)')
                . ($x['client'] !== '' ? '／' . $x['client'] : '') . "\n";
            $body .= '　締切：' . $x['deadlineStr'] . '（' . $x['daysLate'] . '日超過）'
                . '　／　D：' . ($x['director'] !== '' ? $x['director'] : '(未定)') . ($dId ? '' : '（CWID未取得）')
                . '　／　営業：' . ($x['sales'] !== '' ? $x['sales'] : '(未記入)') . "\n";

            if (! $dId && $x['director'] !== '') {
                $unknownNames[$x['director']] = true;
            }
            if (! $sId && $x['sales'] !== '') {
                $unknownNames[$x['sales']] = true;
            }

            if ($dId) {
                if (! isset($taskTargets[$dId])) {
                    $taskTargets[$dId] = ['name' => $x['director'], 'lines' => []];
                }
                $taskTargets[$dId]['lines'][] = '・' . $x['dateStr'] . ' ' . $x['content']
                    . ($x['client'] !== '' ? '／' . $x['client'] : '');
            }
        }
        $body .= "────────────────\n";
        $body .= "📌 ディレクターのみなさんには、別途「収支の入力（期限つき）」のタスクをお付けします。\n";
        $body .= 'よろしくお願いします😊';

        return $body;
    }

    /** 氏名を照合用に正規化（全角/半角スペース・空白を除去）。 */
    private function normName(string $s): string
    {
        // 正規化のしかたの正本は App\Support\ChatworkIds（名簿側と同じ揃え方にするため）。
        return ChatworkIds::normName($s);
    }
}
