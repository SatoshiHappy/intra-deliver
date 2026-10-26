import { describe, expect, it } from 'vite-plus/test';
import {
    snapshotRecordOf,
    applicationTargetOf,
    withApplicationTarget,
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

describe('写した後のフォームの値', () => {
    it('写しの値にある項目だけを置き換え、ほかの欄は今のまま。明細の行は id の無い新しい行', () => {
        const current = recordOf(
            { name: '旧名', budget: '5' },
            { milestones: [row(1, '旧行')] },
        );
        const copied = snapshotRecordOf(
            planModule,
            current,
            snapshotOf(
                { name: '新名', project: reference(9, '新') },
                { milestones: [{ values: { title: '新行', done: true } }] },
            ),
        );

        expect(copied.values.name).toBe('新名');
        expect(copied.values.budget).toBe('5');
        expect(copied.values.project).toEqual({
            id: 9,
            label: '新',
            affiliation: null,
        });
        expect(copied.details.milestones).toHaveLength(1);
        expect(copied.details.milestones[0]).toMatchObject({
            id: null,
            values: { title: '新行', done: true },
        });
    });

    it('元の値が空の項目は空を写す', () => {
        const copied = snapshotRecordOf(
            planModule,
            recordOf({ name: '旧名' }),
            snapshotOf({ name: null }),
        );

        expect(copied.values.name).toBe('');
    });
});

describe('申請対象の欄', () => {
    it('今の値を返し、選んでいなければ null。値だけを替えてもほかの欄は変わらない', () => {
        const record = recordOf({ project: picked(3), name: 'x' });

        expect(applicationTargetOf(record, APPLICATION_TARGET_FIELD)).toEqual(
            picked(3),
        );
        expect(
            applicationTargetOf(recordOf(), APPLICATION_TARGET_FIELD),
        ).toBeNull();
        expect(applicationTargetOf(record, null)).toBeNull();
        expect(
            withApplicationTarget(record, APPLICATION_TARGET_FIELD, null),
        ).toEqual({
            ...record,
            values: { ...record.values, project: null },
        });
    });
});
