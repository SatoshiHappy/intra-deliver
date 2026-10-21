<?php

namespace App\Actions\Workflow;

use App\Models\Records\Record;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\RecordApplyFlows;
use App\Queries\Workflow\RecordApplyTargets;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * 既存のデータからの申請の、申請の行を書く前の確かめと申請の行の挿入(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請.md の 4 節の 0・1、
 * 失敗の形 = 同じ文書の 7 節)。挿入の競合は、名前を決めた 2 つの制約の違反だけを捕まえ、ほかの違反は上げる。
 */
class RecordApplicationSteps
{
    /** 持ち主がある・同じレコードへの別の申請と競合した(422 record) */
    public const HELD = '申請中のため申請できません';

    /** 同じレコードに未完了の案件を 1 つまでにする部分一意索引 */
    private const ACTIVE_RECORD_UNIQUE = 'workflow_cases_record_id_unique';

    /** 案件の record_id の外部キー(PostgreSQL の foreign_key_violation の文に名前が入る) */
    private const RECORD_FOREIGN_KEY = 'workflow_cases_record_id_foreign';

    private const FOREIGN_KEY_VIOLATION = '23503';

    public function __construct(
        private RecordApplyFlows $flows,
        private RecordApplyTargets $targets,
    ) {}

    /**
     * 0 の確かめ: 申請できる人 → レコード({@see RecordApplyTargets::requireRecord()}) → 持ち主の順(403 を先にして、権限の無い人に
     * レコードの有無を漏らさない)。
     *
     * @param  AccessContext  $applicant  申請者(代理のときは代理元)
     *
     * @throws AuthorizationException 申請者が既存のデータからの申請をできないとき
     * @throws ModelNotFoundException<Record> レコードが無い・その業務モジュールの親の無いレコードでないとき
     * @throws ValidationException 持ち主があるとき(record)
     */
    public function confirm(AccessContext $applicant, int $flowId, string $moduleCode, int $recordId): void
    {
        $requester = Requester::fromAccessContext($applicant);

        if (! $this->flows->allows($requester, $flowId, $applicant->date)) {
            throw CaseOperationErrors::notApplicable();
        }

        $this->targets->requireRecord($requester, $moduleCode, $recordId, $applicant->date);

        if ($this->targets->isHeld($recordId)) {
            throw self::held();
        }
    }

    /**
     * 1 の申請の行の挿入。0 の後に別の申請が入った競合は 422 record、レコードが消された競合は 404。
     *
     * @param  array<string, mixed>  $columns  案件の行のほかの列
     *
     * @throws ValidationException 同じレコードに未完了の案件があるとき(record)
     * @throws ModelNotFoundException<Record> レコードが無いとき
     * @throws QueryException ほかの制約の違反
     */
    public function insertCase(int $flowId, int $recordId, array $columns): WorkflowCase
    {
        $attributes = ['workflow_flow_id' => $flowId, 'revision' => 0, 'record_id' => $recordId, ...$columns];

        try {
            return WorkflowCase::query()->create($attributes);
        } catch (UniqueConstraintViolationException $exception) {
            throw $exception->index === self::ACTIVE_RECORD_UNIQUE ? self::held() : $exception;
        } catch (QueryException $exception) {
            throw self::violatesRecordForeignKey($exception)
                ? (new ModelNotFoundException)->setModel(Record::class, [$recordId])
                : $exception;
        }
    }

    private static function held(): ValidationException
    {
        return ValidationException::withMessages(['record' => [self::HELD]]);
    }

    private static function violatesRecordForeignKey(QueryException $exception): bool
    {
        return $exception->getCode() === self::FOREIGN_KEY_VIOLATION
            && str_contains($exception->getMessage(), 'foreign key constraint "'.self::RECORD_FOREIGN_KEY.'"');
    }
}
