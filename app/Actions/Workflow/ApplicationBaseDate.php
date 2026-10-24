<?php

namespace App\Actions\Workflow;

use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\ApplicableFlows;
use App\Queries\Workflow\ApplicationBaseDates;
use App\Queries\Workflow\EffectiveFlowVersions;
use App\Queries\Workflow\RecordApplyFlows;
use App\Support\AccessContext;
use App\Support\InputText;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\ValidationException;

/**
 * 申請基準日の規則(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 10.1、同時実行と検査.md 13 節。422 キー base_date)。申請・一時保存からの申請で
 * 次の順に確かめ、最初に外れたものの文言を返す: (1) 日付として正しい(空・空白だけは無い = 業務処理は今日を既定にしない)(2) 今日の前後 1 年の中(両端を含む)
 * (3) その日に有効な版がある (4) その版の申請のノードに、判定を求める者がその日に本人の判定で合い create を許される(申請できる人の判定をその日で呼ぶ)。
 * 呼び手はフローの行に共有の鍵を取った後に呼ぶ(版の選択と写しの間に定義が変わらない = データモデル/直列化と検査.md 4.1)。
 */
class ApplicationBaseDate
{
    public function __construct(
        private EffectiveFlowVersions $versions,
        private ApplicableFlows $applicableFlows,
        private RecordApplyFlows $recordApplyFlows,
    ) {}

    /**
     * @param  string|null  $input  申請基準日の入力(前後の空白を除いて空なら無い)
     * @return array{CarbonImmutable, int} 申請基準日と、その日に有効な版の id
     *
     * @throws ValidationException 規則に外れたとき(base_date)
     */
    public function resolve(AccessContext $context, int $flowId, ?string $input): array
    {
        $requester = Requester::fromAccessContext($context);
        $applicable = fn (CarbonImmutable $date) => $this->applicableFlows->isApplicable($requester, $date, $flowId);

        return $this->resolveWith($context, $flowId, $input, $applicable);
    }

    /**
     * 既存のデータからの申請: 規則 (4) を、申請者が申請基準日に既存のデータからの申請をできるか({@see RecordApplyFlows::allows()})で見る
     * (docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 1 節)。ほかの規則と順は {@see self::resolve()} と同じ。
     *
     * @return array{CarbonImmutable, int} 申請基準日と、その日に有効な版の id
     *
     * @throws ValidationException 規則に外れたとき(base_date)
     */
    public function resolveFromRecord(AccessContext $context, int $flowId, ?string $input): array
    {
        $requester = Requester::fromAccessContext($context);
        $applicable = fn (CarbonImmutable $date) => $this->recordApplyFlows->allows($requester, $flowId, $date);

        return $this->resolveWith($context, $flowId, $input, $applicable);
    }

    /**
     * @param  Closure(CarbonImmutable): bool  $applicable  規則 (4)
     * @return array{CarbonImmutable, int}
     *
     * @throws ValidationException
     */
    private function resolveWith(AccessContext $context, int $flowId, ?string $input, Closure $applicable): array
    {
        $date = $this->parse($input);
        $day = $date->format(ApplicationBaseDates::FORMAT);

        if (! ApplicationBaseDates::isWithinYear($date, $context->date)) {
            throw self::error(CaseOperationErrors::BASE_DATE_OUT_OF_RANGE);
        }

        $versionId = $this->versions->idOn($flowId, $date) ?? throw self::error(sprintf(CaseOperationErrors::BASE_DATE_NO_VERSION, $day));

        if (! $applicable($date)) {
            throw self::error(sprintf(CaseOperationErrors::BASE_DATE_NOT_APPLICABLE, $day));
        }

        return [$date, $versionId];
    }

    /**
     * 規則 (1): 前後の空白を除いて空なら「無い」、暦の日付として読めなければ「日付でない」。
     *
     * @throws ValidationException
     */
    private function parse(?string $input): CarbonImmutable
    {
        if (! is_string(InputText::normalize($input))) {
            throw self::error(CaseOperationErrors::BASE_DATE_REQUIRED);
        }

        return ApplicationBaseDates::parse($input) ?? throw self::error(CaseOperationErrors::BASE_DATE_INVALID);
    }

    private static function error(string $message): ValidationException
    {
        return ValidationException::withMessages(['base_date' => [$message]]);
    }
}
