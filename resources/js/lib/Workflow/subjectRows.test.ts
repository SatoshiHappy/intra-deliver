import { describe, expect, it } from 'vite-plus/test';
import type { WorkflowSubjectCandidatesResponse } from '@/types';
import {
    SUBJECT_TYPES,
    candidateGroupsOf,
    draftsOf,
    rowsPayloadOf,
    rowsSignature,
    subjectKey,
    withPicked,
    withoutSubject,
} from './subjectRows';

const ROWS = [
    { type: 'department', id: 5, name: '経理部' },
    { type: 'role', id: 2, name: 'ワークフロー管理者' },
] as const;

const candidate = (id: number, name: string | null, code = `c${id}`) => ({
    id,
    code,
    name,
    mainDepartmentName: null,
});

describe('SUBJECT_TYPES', () => {
    it('ユーザー・組織・役職・ロールの順', () => {
        expect(SUBJECT_TYPES.map((item) => item.value)).toEqual([
            'user',
            'department',
            'company_post',
            'role',
        ]);
    });
});

describe('draftsOf・rowsPayloadOf・rowsSignature', () => {
    it('サーバーの行をそのまま入力にし、送る行は種類と id、識別は種類と id の並び', () => {
        const drafts = draftsOf([...ROWS]);

        expect(drafts).toEqual([...ROWS]);
        expect(rowsPayloadOf(drafts)).toEqual([
            { type: 'department', id: 5 },
            { type: 'role', id: 2 },
        ]);
        expect(rowsSignature([...ROWS])).toBe('department:5,role:2');
        expect(rowsPayloadOf(draftsOf([]))).toEqual([]);
    });
});

describe('withPicked・withoutSubject', () => {
    it('選んだ候補を札の末尾に足し(名前が無ければコード、ユーザーは主所属を添える)、× で外す', () => {
        const picked = withPicked(draftsOf([...ROWS]), 'user', {
            id: 9,
            code: 'u0009',
            name: '吉田 達也',
            mainDepartmentName: '総務部',
        });
        const noName = withPicked([], 'company_post', candidate(4, null, 'p4'));

        expect(picked.at(-1)).toEqual({
            type: 'user',
            id: 9,
            name: '吉田 達也',
            affiliation: '総務部',
        });
        expect(noName).toEqual([
            { type: 'company_post', id: 4, name: 'p4', affiliation: null },
        ]);
        expect(
            withoutSubject(picked, subjectKey({ type: 'department', id: 5 })),
        ).toEqual([ROWS[1], picked[2]]);
    });
});

describe('candidateGroupsOf', () => {
    const response: WorkflowSubjectCandidatesResponse = {
        groups: [
            { type: 'user', candidates: [candidate(5, '経理 太郎')] },
            {
                type: 'department',
                candidates: [candidate(5, '経理部'), candidate(6, '経理課')],
            },
            { type: 'company_post', candidates: [] },
            { type: 'role', candidates: [candidate(2, 'ワークフロー管理者')] },
        ],
    };

    it('選び済みの相手を除き(同じ id でも種類が違えば残す)、候補の無い群は出さず、群に種類の語を付ける', () => {
        expect(candidateGroupsOf(response.groups, draftsOf([...ROWS]))).toEqual(
            [
                {
                    type: 'user',
                    label: 'ユーザー',
                    candidates: [candidate(5, '経理 太郎')],
                },
                {
                    type: 'department',
                    label: '組織',
                    candidates: [candidate(6, '経理課')],
                },
            ],
        );
    });

    it('選び済みが無ければ、候補のある群をすべて応答の順に出す', () => {
        expect(
            candidateGroupsOf(response.groups, []).map((group) => group.label),
        ).toEqual(['ユーザー', '組織', 'ロール']);
    });
});
