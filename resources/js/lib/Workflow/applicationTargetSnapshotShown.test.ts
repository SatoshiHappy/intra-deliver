import { describe, expect, it } from 'vite-plus/test';
import { applicationTargetChangesOf } from './applicationTargetSnapshot';
import {
    APPLICATION_TARGET_FIELD,
    snapshotOf,
    picked,
    planModule,
    recordOf,
    reference,
} from './applicationTarget.testing';

/** 確かめの行の「今の値」(写しの値は何でもよいので、全項目を違う値にする) */
function currentOf(code: string, record: ReturnType<typeof recordOf>) {
    const changes = applicationTargetChangesOf(
        planModule,
        APPLICATION_TARGET_FIELD,
        record,
        snapshotOf({
            status: { code: 'active', name: '進行中' },
            members: [reference(99)],
            flag: true,
            start_at: '2030-01-01T00:00:00+00:00',
        }),
    );
    const change = changes.find((item) => item.field.code === code);

    return change?.kind === 'value' ? change.current : undefined;
}

describe('確かめの行の今の値(入力済みかの判定と同じ空の扱い)', () => {
    it('空の欄は null(空の並び・空の選択・偽の真偽は、真偽だけが偽のまま)', () => {
        const record = recordOf({ members: [], status: '', flag: false });

        expect(currentOf('members', record)).toBeNull();
        expect(currentOf('status', record)).toBeNull();
        expect(currentOf('flag', record)).toBe(false);
    });

    it('選択は code と名前、参照は選んだ相手、日時は時差つきの値で表示に渡す', () => {
        const record = recordOf({
            status: 'planning',
            members: [picked(1, '山田')],
            start_at: '2026-10-11T09:30',
        });

        expect(currentOf('status', record)).toEqual({
            code: 'planning',
            name: '計画中',
        });
        expect(currentOf('members', record)).toEqual([
            { id: 1, code: null, label: '山田', affiliation: null },
        ]);
        expect(currentOf('start_at', record)).toBe('2026-10-11T09:30:00+09:00');
    });
});

/** 型「ユーザーと組織」の項目 parties を足した申請書の業務モジュール */
const partiesModule = {
    ...planModule,
    fields: [
        ...planModule.fields,
        {
            ...planModule.fields[0],
            code: 'parties',
            name: 'parties',
            type: 'user_or_department' as const,
            isMultiple: true,
            referencedModule: null,
        },
    ],
};
const user3 = { ...picked(3, '高橋 誠'), type: 'user' as const };
const party = (type: 'user' | 'department', id: number, label: string) => ({
    ...reference(id, label),
    type,
});
const changesOf = (snapshot: ReturnType<typeof snapshotOf>) =>
    applicationTargetChangesOf(
        partiesModule,
        APPLICATION_TARGET_FIELD,
        recordOf({ parties: [user3] }),
        snapshot,
    );

describe('型「ユーザーと組織」の項目の写し', () => {
    it('同じ種類と id の相手なら変わらない', () => {
        expect(
            changesOf(snapshotOf({ parties: [party('user', 3, '高橋 誠')] })),
        ).toEqual([]);
    });

    it('同じ id でも種類が違えば変わり、今の値と写す値を種類つきの相手で出す(値の表示が種類の印を付ける)', () => {
        const changes = changesOf(
            snapshotOf({ parties: [party('department', 3, '開発部')] }),
        );

        expect(changes).toHaveLength(1);
        expect(changes[0]).toMatchObject({
            kind: 'value',
            filled: true,
            current: [
                {
                    type: 'user',
                    id: 3,
                    code: null,
                    label: '高橋 誠',
                    affiliation: null,
                },
            ],
            next: [{ type: 'department', id: 3, label: '開発部' }],
        });
    });
});

describe('写しの表示の値の形は項目の型で決める', () => {
    it('ほかの参照の型の項目は、相手が種類を持っても参照の値で出す', () => {
        const current = recordOf({
            department: { ...picked(3, '開発部'), type: 'department' },
        });
        const changes = applicationTargetChangesOf(
            planModule,
            APPLICATION_TARGET_FIELD,
            current,
            snapshotOf({ department: reference(4, '総務部') }),
        );

        expect(changes[0]).toMatchObject({ kind: 'value' });
        expect(changes[0]?.kind === 'value' && changes[0].current).toEqual({
            id: 3,
            code: null,
            label: '開発部',
            affiliation: null,
        });
    });
});
