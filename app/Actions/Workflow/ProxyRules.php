<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Master\Api\EffectiveUsers;
use App\Queries\Master\Api\MasterNames;
use App\Queries\Workflow\ApplicationBaseDates;
use App\Support\AccessContext;
use App\Support\InputText;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * 代理の登録の規則(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.9 節、文言 = 同時実行と検査.md の 13 節)。
 * ユーザーの期間を鍵なしで読む(設計/ワークフロー/案件の運用/データモデル/README.md の 4.2 節)。
 *
 * @phpstan-type ProxyRow array{
 *     principal_user_id: int, proxy_user_id: int, kind: WorkflowProxyKind, workflow_flow_id: int|null,
 *     start_date: string, end_date: string|null,
 * }
 */
class ProxyRules
{
    public const PRINCIPAL_REQUIRED = '代理元を選択してください';

    public const PROXY_REQUIRED = '代理先を選択してください';

    public const SAME_USER = '代理元と代理先に同じユーザーは指定できません';

    public const KIND_REQUIRED = '種類を選択してください';

    public const FLOW_REQUIRED = '対象のフローを選択してください';

    public const START_REQUIRED = '開始日を入力してください';

    public const START_INVALID = '開始日は日付で入力してください';

    public const START_OUT_OF_RANGE = '開始日は今日から 1 年以内の日付を入力してください';

    public const END_INVALID = '終了日は日付で入力してください';

    public const END_BEFORE_START = '終了日は開始日以降の日付を入力してください';

    public const END_OUT_OF_RANGE = '終了日は開始日から 1 年以内の日付を入力してください';

    public const PRINCIPAL_NOT_EFFECTIVE = '代理元が有効でない期間には、代理を設定できません';

    public function __construct(
        private MasterNames $names,
        private EffectiveUsers $effectiveUsers,
    ) {}

    /**
     * 規則を確かめ、代理の行に書く値を返す(end_date = 入力の終了日の翌日 = 半開区間。終了日なしは null)。
     *
     * @return ProxyRow
     *
     * @throws ValidationException 規則に外れたとき
     */
    public function validated(AccessContext $context, ?int $principalUserId, ProxyInput $input): array
    {
        $principal = $this->existing($principalUserId)
            ?? throw self::error('principal_user_id', self::PRINCIPAL_REQUIRED);
        $proxy = $this->existing($input->proxyUserId) ?? throw self::error('proxy_user_id', self::PROXY_REQUIRED);

        if ($principal === $proxy) {
            throw self::error('proxy_user_id', self::SAME_USER);
        }

        $kind = WorkflowProxyKind::tryFrom((string) InputText::normalize($input->kind))
            ?? throw self::error('kind', self::KIND_REQUIRED);
        $flowId = $this->flowIdOf($input->flowCode);
        [$start, $end] = $this->period($context->date, $input->startDate, $input->endDate);
        $rowEnd = $end?->addDay();

        if ($this->effectiveUsers->idsWithin([$principal], $start, $rowEnd) === []) {
            throw self::error('start_date', self::PRINCIPAL_NOT_EFFECTIVE);
        }

        return [
            'principal_user_id' => $principal,
            'proxy_user_id' => $proxy,
            'kind' => $kind,
            'workflow_flow_id' => $flowId,
            'start_date' => $start->toDateString(),
            'end_date' => $rowEnd?->toDateString(),
        ];
    }

    /**
     * 開始日と終了日(10.9 節の規則 (6)・(7))。
     *
     * @return array{CarbonImmutable, CarbonImmutable|null}
     *
     * @throws ValidationException
     */
    private function period(CarbonImmutable $today, ?string $startInput, ?string $endInput): array
    {
        if (InputText::normalize($startInput) === null) {
            throw self::error('start_date', self::START_REQUIRED);
        }

        $start = ApplicationBaseDates::parse($startInput) ?? throw self::error('start_date', self::START_INVALID);

        if (! self::isWithinYearFrom($start, $today)) {
            throw self::error('start_date', self::START_OUT_OF_RANGE);
        }

        if (InputText::normalize($endInput) === null) {
            return [$start, null];
        }

        $end = ApplicationBaseDates::parse($endInput) ?? throw self::error('end_date', self::END_INVALID);

        return match (true) {
            $end->lessThan($start) => throw self::error('end_date', self::END_BEFORE_START),
            ! self::isWithinYearFrom($end, $start) => throw self::error('end_date', self::END_OUT_OF_RANGE),
            default => [$start, $end],
        };
    }

    /**
     * 日が起点から起点 + 1 年の中か(両端を含む。1 年後の日の数え方は申請基準日と同じ = {@see ApplicationBaseDates::latest()})。
     */
    private static function isWithinYearFrom(CarbonImmutable $date, CarbonImmutable $origin): bool
    {
        $day = $date->toDateString();

        return $day >= $origin->toDateString() && $day <= ApplicationBaseDates::latest($origin)->toDateString();
    }

    /**
     * 対象のフロー(10.9 節の規則 (5)。空 = すべてのフロー)。
     *
     * @throws ValidationException 無いとき(workflow_flow_code)
     */
    private function flowIdOf(?string $flowCode): ?int
    {
        $code = InputText::normalize($flowCode);

        if ($code === null) {
            return null;
        }

        $flowId = WorkflowFlow::query()->where('code', $code)->value('id');

        return $flowId === null ? throw self::error('workflow_flow_code', self::FLOW_REQUIRED) : (int) $flowId;
    }

    /**
     * 存在するユーザーならその id(無い・存在しなければ null)。
     */
    private function existing(?int $userId): ?int
    {
        if ($userId === null) {
            return null;
        }

        // 名前の口は存在しない id を結果に入れないので、存在の確かめに使う(日付とロケールは名前だけに効く)
        $today = CarbonImmutable::today(AccessContext::TIMEZONE);
        $names = $this->names->users([$userId], $today, AccessContext::LOCALE_CODE);

        return array_key_exists($userId, $names) ? $userId : null;
    }

    private static function error(string $key, string $message): ValidationException
    {
        return ValidationException::withMessages([$key => [$message]]);
    }
}
