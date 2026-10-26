import { describe, expect, it } from 'vite-plus/test';
import { recordCandidatesUrlOf } from './applyRoutes';
import { RECORDS_DATA_SOURCE } from '@/hooks/use-record-candidates';

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
