<?php

namespace App\Actions\Workflow;

use Illuminate\Validation\ValidationException;

/**
 * 一括承認・一括確認の選択の規則(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.7 節、文言 = 同時実行と検査.md の 13 節)。
 * 値は入口から受けたまま確かめ、誤りがあればどの案件も処理しない。
 */
class BulkInputValidator
{
    /** 一度に選べる案件の数(一覧の 1 ページ) */
    public const MAX_CASES = 20;

    /**
     * 一括承認の cases(各行 = id・revision・node_id・principal_user_id)。
     *
     * @return list<BulkApprovalInput>
     *
     * @throws ValidationException 並び・件数・形・重複の誤り(cases)
     */
    public function approvals(mixed $cases): array
    {
        self::requireListSize($cases, 'cases');
        $rows = [];

        foreach ($cases as $case) {
            $rows[] = self::approvalOf($case) ?? throw self::invalid('cases', CaseOperationErrors::BULK_REQUIRED);
        }

        self::requireUnique(array_map(fn (BulkApprovalInput $row) => $row->caseId, $rows), 'cases');

        return $rows;
    }

    /**
     * 一括確認の case_ids(案件の id の並び)。
     *
     * @return list<int>
     *
     * @throws ValidationException 並び・件数・形・重複の誤り(case_ids)
     */
    public function caseIds(mixed $caseIds): array
    {
        self::requireListSize($caseIds, 'case_ids');
        $ids = [];

        foreach ($caseIds as $id) {
            $ids[] = is_int($id) ? $id : throw self::invalid('case_ids', CaseOperationErrors::BULK_REQUIRED);
        }

        self::requireUnique($ids, 'case_ids');

        return $ids;
    }

    /**
     * 並び(list)で、1〜{@see self::MAX_CASES} 件。
     *
     * @phpstan-assert non-empty-list<mixed> $value
     *
     * @throws ValidationException
     */
    private static function requireListSize(mixed $value, string $key): void
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw self::invalid($key, CaseOperationErrors::BULK_REQUIRED);
        }

        if (count($value) > self::MAX_CASES) {
            throw self::invalid($key, CaseOperationErrors::BULK_TOO_MANY);
        }
    }

    /**
     * @param  list<int>  $caseIds
     *
     * @throws ValidationException 同じ案件が 2 度あるとき
     */
    private static function requireUnique(array $caseIds, string $key): void
    {
        if (count(array_unique($caseIds)) !== count($caseIds)) {
            throw self::invalid($key, CaseOperationErrors::BULK_REQUIRED);
        }
    }

    /**
     * 1 行の形(id・revision・node_id は整数、principal_user_id は整数か null か無し)。合わなければ null。
     */
    private static function approvalOf(mixed $row): ?BulkApprovalInput
    {
        if (! is_array($row)) {
            return null;
        }

        [$id, $revision, $nodeId] = [$row['id'] ?? null, $row['revision'] ?? null, $row['node_id'] ?? null];
        $principal = $row['principal_user_id'] ?? null;

        $isPrincipal = $principal === null || is_int($principal);

        if (! is_int($id) || ! is_int($revision) || ! is_int($nodeId) || ! $isPrincipal) {
            return null;
        }

        return new BulkApprovalInput($id, $revision, $nodeId, $principal);
    }

    private static function invalid(string $key, string $message): ValidationException
    {
        return ValidationException::withMessages([$key => [$message]]);
    }
}
