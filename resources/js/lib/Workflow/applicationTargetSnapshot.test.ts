import { describe, expect, it } from 'vite-plus/test';
import {
    needsConfirm,
    applicationTargetChangesOf,
} from './applicationTargetSnapshot';
import {
    APPLICATION_TARGET_FIELD,
    snapshotOf,
    picked,
    planModule,
    recordOf,
    reference,
    row,
} from './applicationTarget.testing';

/** 写すと変わる項目のコードの並び */
function codesOf(
    current: ReturnType<typeof recordOf>,
    snapshot: ReturnType<typeof snapshotOf>,
) {
    return applicationTargetChangesOf(
        planModule,
        APPLICATION_TARGET_FIELD,
        current,
        snapshot,
    ).map((change) => change.field.code);
}

describe('写す値と違う入力済みの値(通常の項目)', () => {
    it('今の値が空なら、写す値と違っても入力済みに数えず、確かめない(写すと変わる項目には出る)', () => {
        const changes = applicationTargetChangesOf(
            planModule,
            APPLICATION_TARGET_FIELD,
            recordOf(),
            snapshotOf({ name: '新製品', budget: 100 }),
        );

        expect(changes.map((change) => change.field.code)).toEqual([
            'name',
            'budget',
        ]);
        expect(changes.every((change) => !change.filled)).toBe(true);
        expect(needsConfirm(changes)).toBe(false);
    });

    it('入力済みで写す値と違えば確かめる。同じなら変わる項目に出ない', () => {
        const current = recordOf({ name: '旧名', budget: '100' });

        expect(
            needsConfirm(
                applicationTargetChangesOf(
                    planModule,
                    APPLICATION_TARGET_FIELD,
                    current,
                    snapshotOf({ name: '新名' }),
                ),
            ),
        ).toBe(true);
        expect(
            codesOf(current, snapshotOf({ name: '旧名', budget: 100 })),
        ).toEqual([]);
    });
});

describe('写す値と違う入力済みの値(通常の項目・続き)', () => {
    it('写す値が空で今の値が入力済みなら、空を写すので違う入力済みの値になる', () => {
        const changes = applicationTargetChangesOf(
            planModule,
            APPLICATION_TARGET_FIELD,
            recordOf({ name: '旧名' }),
            snapshotOf({ name: null }),
        );

        expect(needsConfirm(changes)).toBe(true);
    });

    it('写しの値に無い項目は比べない(今の値が違っていても)', () => {
        expect(
            codesOf(recordOf({ budget: '5' }), snapshotOf({ name: 'x' })),
        ).toEqual(['name']);
    });

    it('申請対象の項目そのものは比べない', () => {
        const current = recordOf({ project: picked(3, '旧プロジェクト') });
        const snapshot = snapshotOf({
            project: reference(4, '新プロジェクト'),
        });

        expect(codesOf(current, snapshot)).toEqual([]);
    });
});

describe('比べ方(参照は id・選択は code・日時は時差を直して・真偽は偽が空)', () => {
    it('参照は id で比べ、表示名が違うだけでは変わらない。id が違えば変わる', () => {
        const current = recordOf({ department: picked(3, '旧名の部署') });

        expect(
            codesOf(
                current,
                snapshotOf({ department: reference(3, '新名の部署') }),
            ),
        ).toEqual([]);
        expect(
            codesOf(
                current,
                snapshotOf({ department: reference(4, '別の部署') }),
            ),
        ).toEqual(['department']);
    });

    it('複数のユーザーは並びの順に id で比べる', () => {
        const current = recordOf({ members: [picked(1), picked(2)] });

        expect(
            codesOf(
                current,
                snapshotOf({ members: [reference(1), reference(2)] }),
            ),
        ).toEqual([]);
        expect(
            codesOf(
                current,
                snapshotOf({ members: [reference(2), reference(1)] }),
            ),
        ).toEqual(['members']);
    });
});

describe('比べ方(続き)', () => {
    it('選択は code で比べ、名前が違うだけでは変わらない', () => {
        const current = recordOf({ status: 'planning' });

        expect(
            codesOf(
                current,
                snapshotOf({ status: { code: 'planning', name: null } }),
            ),
        ).toEqual([]);
        expect(
            codesOf(
                current,
                snapshotOf({ status: { code: 'active', name: '進行中' } }),
            ),
        ).toEqual(['status']);
    });
});

describe('比べ方(続き・日時と真偽)', () => {
    it('日時は Asia/Tokyo に直して比べる', () => {
        const current = recordOf({ start_at: '2026-10-11T09:30' });

        expect(
            codesOf(
                current,
                snapshotOf({ start_at: '2026-10-11T00:30:00+00:00' }),
            ),
        ).toEqual([]);
        expect(
            codesOf(
                current,
                snapshotOf({ start_at: '2026-10-11T01:30:00+00:00' }),
            ),
        ).toEqual(['start_at']);
    });

    it('真偽は偽を空とみなす(偽 → 真は確かめず、真 → 偽は確かめる)', () => {
        const toTrue = applicationTargetChangesOf(
            planModule,
            APPLICATION_TARGET_FIELD,
            recordOf(),
            snapshotOf({ flag: true }),
        );
        const toFalse = applicationTargetChangesOf(
            planModule,
            APPLICATION_TARGET_FIELD,
            recordOf({ flag: true }),
            snapshotOf({ flag: false }),
        );

        expect([needsConfirm(toTrue), needsConfirm(toFalse)]).toEqual([
            false,
            true,
        ]);
    });
});

/** 今の明細の行が 2 つ */
const current = recordOf({}, { milestones: [row(1, '設計'), row(2, '開発')] });

describe('写す値と違う入力済みの値(明細)', () => {
    it('行の数が違えば違う。今の行が 1 つ以上あれば入力済み', () => {
        const changes = applicationTargetChangesOf(
            planModule,
            APPLICATION_TARGET_FIELD,
            current,
            snapshotOf(
                {},
                { milestones: [{ values: { title: '設計', done: false } }] },
            ),
        );

        expect(changes).toHaveLength(1);
        expect(changes[0]).toMatchObject({ kind: 'details', filled: true });
        expect(needsConfirm(changes)).toBe(true);
    });

    it('行の数が同じでも、行の値が違えば違う(行どうしは突き合わせず、並びの位置で比べる)', () => {
        const swapped = snapshotOf(
            {},
            {
                milestones: [
                    { values: { title: '開発', done: false } },
                    { values: { title: '設計', done: false } },
                ],
            },
        );

        expect(codesOf(current, swapped)).toEqual(['milestones']);
    });
});

describe('写す値と違う入力済みの値(明細・続き)', () => {
    it('行の数も値も同じなら変わらない(行の id は比べない)', () => {
        const same = snapshotOf(
            {},
            {
                milestones: [
                    { values: { title: '設計', done: false } },
                    { values: { title: '開発', done: false } },
                ],
            },
        );

        expect(codesOf(current, same)).toEqual([]);
    });

    it('今の行が無ければ入力済みでなく、写しの行は変わる項目に出る。写しの値に無い明細は比べない', () => {
        const rows = [{ values: { title: '設計', done: true } }];
        const changes = applicationTargetChangesOf(
            planModule,
            APPLICATION_TARGET_FIELD,
            recordOf(),
            snapshotOf({}, { milestones: rows }),
        );

        expect(changes[0]).toMatchObject({ kind: 'details', filled: false });
        expect(needsConfirm(changes)).toBe(false);
        expect(codesOf(current, snapshotOf({ name: 'x' }))).toEqual(['name']);
    });

    it('写す行が 0 行で今の行が入力済みなら違う', () => {
        expect(
            needsConfirm(
                applicationTargetChangesOf(
                    planModule,
                    APPLICATION_TARGET_FIELD,
                    current,
                    snapshotOf({}, { milestones: [] }),
                ),
            ),
        ).toBe(true);
    });
});
