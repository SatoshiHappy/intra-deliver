import { describe, expect, it } from 'vite-plus/test';
import {
    CASE_OPERATION_EMPTY_TEXT,
    CASE_OPERATION_SCOPES,
    caseOperationsUrl,
} from './caseOperationList';
import type { WorkflowCaseOperationQuery } from '@/types';

const base: WorkflowCaseOperationQuery = {
    flow: null,
    scope: 'active',
    q: '',
    overdue: false,
};

describe('caseOperationsUrl', () => {
    it('既定の値は付けない', () => {
        expect(caseOperationsUrl(base)).toBe('/workflow/admin/cases');
    });

    it('フロー・検索の語・期限超過・ページを付ける(語の前後の空白は落とす)', () => {
        expect(
            caseOperationsUrl(
                {
                    ...base,
                    flow: 'project_plan',
                    q: ' WF-000013 ',
                    overdue: true,
                },
                2,
            ),
        ).toBe(
            '/workflow/admin/cases?flow=project_plan&q=WF-000013&overdue=1&page=2',
        );
    });

    it('完了のときは状態を付け、期限超過は読まれないので付けない', () => {
        expect(
            caseOperationsUrl({ ...base, scope: 'completed', overdue: true }),
        ).toBe('/workflow/admin/cases?scope=completed');
    });
});

describe('一覧の文', () => {
    it('状態の切替は未完了・完了の順', () => {
        expect(CASE_OPERATION_SCOPES.map(({ label }) => label)).toEqual([
            '未完了',
            '完了',
        ]);
    });

    it('0 件の文は設計の文のとおり', () => {
        expect(CASE_OPERATION_EMPTY_TEXT).toBe('該当する申請はありません');
    });
});
