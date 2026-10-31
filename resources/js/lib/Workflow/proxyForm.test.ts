import { describe, expect, it } from 'vite-plus/test';
import {
    candidateBaseDate,
    initialProxyForm,
    proxyPayloadOf,
    withScope,
} from './proxyForm';

const flows = [
    { code: 'project_plan', name: 'プロジェクト計画の承認' },
    { code: 'issue_fix', name: '課題の対応の承認' },
];

const person = {
    id: 9,
    code: 'u0009',
    name: '吉田 達也',
    mainDepartmentId: null,
};

describe('入力の初期値', () => {
    it('種類 = 承認、対象 = すべてのフロー、開始日 = 渡された今日、終了日 = 空', () => {
        expect(initialProxyForm('2026-10-15')).toEqual({
            principal: [],
            proxy: [],
            kind: 'approve',
            scope: 'all',
            flow_code: '',
            start_date: '2026-10-15',
            end_date: '',
        });
    });

    it('開始日の既定値は渡された今日(ブラウザの時計に依らない)', () => {
        expect(initialProxyForm('2027-01-02').start_date).toBe('2027-01-02');
    });
});

describe('対象の切替', () => {
    it('フローを指定すると先頭のフローを選んでおき、すべてのフローに戻すと選びを空にする', () => {
        const specified = withScope(
            initialProxyForm('2026-10-15'),
            'flow',
            flows,
        );
        expect(specified.scope).toBe('flow');
        expect(specified.flow_code).toBe('project_plan');
        expect(withScope(specified, 'all', flows).flow_code).toBe('');
    });
});

describe('送る内容', () => {
    const base = {
        ...initialProxyForm('2026-10-15'),
        proxy: [person],
    };

    it('代理の設定は代理元を送らず、すべてのフローは null、空の終了日は null', () => {
        expect(proxyPayloadOf(base, false)).toEqual({
            proxy_user_id: 9,
            kind: 'approve',
            workflow_flow_code: null,
            start_date: '2026-10-15',
            end_date: null,
        });
    });

    it('代理の管理は代理元を送る(未選択は null)。フローの指定と終了日はそのまま', () => {
        const data = {
            ...base,
            principal: [
                {
                    id: 3,
                    code: 'u0003',
                    name: '高橋 誠',
                    mainDepartmentId: null,
                },
            ],
            scope: 'flow' as const,
            flow_code: 'issue_fix',
            end_date: '2026-10-31',
        };
        expect(proxyPayloadOf(data, true)).toMatchObject({
            principal_user_id: 3,
            workflow_flow_code: 'issue_fix',
            end_date: '2026-10-31',
        });
        expect(proxyPayloadOf(base, true).principal_user_id).toBeNull();
        expect(
            proxyPayloadOf({ ...base, proxy: [] }, false).proxy_user_id,
        ).toBeNull();
    });
});

describe('人の候補の基準日', () => {
    it('入力中の開始日が日付の形なら送り、空・途中の入力は送らない(口が今日にする)', () => {
        expect(candidateBaseDate('2026-11-01')).toBe('2026-11-01');
        expect(candidateBaseDate('')).toBeUndefined();
        expect(candidateBaseDate('2026-11')).toBeUndefined();
    });
});
