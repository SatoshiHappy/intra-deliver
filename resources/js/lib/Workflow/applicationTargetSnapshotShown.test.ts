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
