import { describe, expect, it } from 'vite-plus/test';
import {
    applyActionOf,
    backHrefOf,
    breadcrumbsOf,
    draftActionOf,
    recordCandidatesUrlOf,
    applicationTargetSnapshotUrlOf,
} from './applyRoutes';
import { RECORDS_DATA_SOURCE } from '@/hooks/use-record-candidates';
import type { WorkflowApplyProps } from '@/types';

const scope = {
    mode: 'new' as const,
    flowCode: 'project_plan',
    caseId: null,
    recordId: null,
};

describe('参照項目の候補の引き先', () => {
    it('申請の画面は workflow.record-candidates(語・除く id に flow を足す)', () => {
        expect(
            recordCandidatesUrlOf(
                'project',
                { q: '新', exclude: 3 },
                scope,
                null,
            ),
        ).toBe(
            '/workflow/record-candidates/project?q=%E6%96%B0&exclude=3&flow=project_plan',
        );
    });

    it('再申請は case、既存のデータからは record も、代理元なら applicant が付く', () => {
        expect(
            recordCandidatesUrlOf(
                'project',
                {},
                { ...scope, mode: 'reapply', caseId: 5 },
                10,
            ),
        ).toBe('/workflow/record-candidates/project?case=5&applicant=10');
        expect(
            recordCandidatesUrlOf(
                'project',
                {},
                { ...scope, mode: 'record', recordId: 7 },
                null,
            ),
        ).toBe(
            '/workflow/record-candidates/project?flow=project_plan&record=7',
        );
    });

    it('業務データの入れ物の画面の既定の引き先は records.data.candidates のまま', () => {
        expect(RECORDS_DATA_SOURCE('project', { q: 'a', exclude: 3 })).toBe(
            '/records/data/project/candidates?q=a&exclude=3',
        );
        expect(RECORDS_DATA_SOURCE('project', {})).toBe(
            '/records/data/project/candidates',
        );
    });
});

describe('申請対象の値の写しの引き先', () => {
    it('workflow.target-values のパスに選んだレコードの id、クエリは候補の口と同じ読み(新規は flow、再申請は case)', () => {
        expect(applicationTargetSnapshotUrlOf(12, scope, null)).toBe(
            '/workflow/target-values/12?flow=project_plan',
        );
        expect(
            applicationTargetSnapshotUrlOf(
                12,
                { ...scope, mode: 'reapply', caseId: 5 },
                null,
            ),
        ).toBe('/workflow/target-values/12?case=5');
    });

    it('既存のデータからは record も、代理元なら applicant が付く', () => {
        expect(
            applicationTargetSnapshotUrlOf(
                12,
                { ...scope, mode: 'record', recordId: 7 },
                10,
            ),
        ).toBe(
            '/workflow/target-values/12?flow=project_plan&record=7&applicant=10',
        );
    });
});

const flow = { code: 'project_plan', name: 'プロジェクト計画の承認' };
const module = { code: 'project_plan_application' };
const newProps = { mode: 'new', flow, module, case: null, recordId: null };
const recordProps = { ...newProps, mode: 'record', recordId: 7 };
const reapplyProps = {
    ...newProps,
    mode: 'reapply',
    case: { id: 5, number: 'WF-000005', revision: 1 },
    initial: { name: '計画' },
};
const draftProps = { ...reapplyProps, mode: 'draft' };
const asProps = (props: object) => props as unknown as WorkflowApplyProps;

describe('既存のデータからの申請の送り先と戻り先', () => {
    it('申請は新規と同じ workflow.applications.store(record_id・record_version は送る内容が指す)', () => {
        expect(applyActionOf(asProps(recordProps)).url).toBe(
            '/workflow/flows/project_plan/apply',
        );
        expect(applyActionOf(asProps(recordProps))).toEqual(
            applyActionOf(asProps(newProps)),
        );
    });

    it('キャンセルの戻り先はそのレコードの詳細(records.data.show)', () => {
        expect(backHrefOf(asProps(recordProps)).url).toBe(
            '/records/data/project_plan_application/7',
        );
    });

    it('ほかの画面の送り先・戻り先は変わらない', () => {
        expect(backHrefOf(asProps(newProps)).url).toBe('/workflow/flows');
        expect(backHrefOf(asProps(draftProps)).url).toBe(
            '/workflow/cases?tab=draft',
        );
        expect(backHrefOf(asProps(reapplyProps)).url).toBe('/workflow/cases/5');
        expect(applyActionOf(asProps(draftProps)).url).toBe(
            '/workflow/drafts/5/apply',
        );
        expect(applyActionOf(asProps(reapplyProps)).url).toBe(
            '/workflow/cases/5/reapply',
        );
        expect(draftActionOf(asProps(newProps)).url).toBe(
            '/workflow/flows/project_plan/drafts',
        );
    });

    it('パンくずは描画で落ちず、フローの先はレコードを残した申請の画面', () => {
        const crumbs = breadcrumbsOf(asProps(recordProps));

        expect(crumbs.map((crumb) => crumb.title)).toEqual([
            '申請の種類を選ぶ',
            'プロジェクト計画の承認',
        ]);
        expect(crumbs[1].href.url).toBe(
            '/workflow/flows/project_plan/apply?record=7',
        );
        expect(breadcrumbsOf(asProps(newProps))[1].href.url).toBe(
            '/workflow/flows/project_plan/apply',
        );
    });
});
