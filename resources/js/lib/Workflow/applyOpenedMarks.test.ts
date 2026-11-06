import { describe, expect, it } from 'vite-plus/test';
import { openedFormOf } from './applyOpened';
import type { RememberedCleared } from './applyOpened';
import { targetMarksOf } from './applicationTarget';
import { planModule, reference } from './applicationTarget.testing';
import { snapshotRecordOf } from './applicationTargetSnapshot';
import {
    clearedStateOf,
    fieldMarksOf,
    nextClearedStateOf,
    shownMarksOf,
} from '@/lib/clearedFields';
import type { ClearedField, WorkflowApplyProps } from '@/types';

// 案件からの複製で開いた申請の画面の、元の申請の値の印(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節の「空にした項目の知らせ」の
// 表 1。印は開いた時の申請対象の写しを当てる前の値から、フォームの値と同じ初期値で作る)。

const title = (row: number): ClearedField => ({
    kind: 'cell',
    field: 'title',
    detail: 'milestones',
    row,
    name: 'マイルストーンのtitle',
});

const budget: ClearedField = {
    kind: 'field',
    field: 'budget',
    detail: null,
    row: null,
    name: 'budget',
};

/** 案件からの複製で開いた props(明細の 1 行目の title と budget を空にした) */
function copyProps(
    targetValues: WorkflowApplyProps['targetValues'] = null,
): WorkflowApplyProps {
    return {
        mode: 'new',
        case: null,
        module: planModule,
        baseDate: '2026-10-13',
        applicant: null,
        targetField: 'project',
        targetEntry: null,
        targetValues,
        initial: {
            name: '元',
            comment: null,
            values: { project: reference(5, 'P5'), budget: null },
            details: {
                milestones: [
                    { values: { title: null, done: false } },
                    { values: { title: '残る', done: true } },
                ],
            },
        },
        copy: {
            caseId: 1,
            caseNumber: 'WF-000001',
            name: '元',
            clearedFields: [budget, title(0)],
            rowAccess: 'sealed',
        },
    } as unknown as WorkflowApplyProps;
}

/** 開いた時に出す印(初めの 1 回の確かめの後) */
function shownOf(opened: ReturnType<typeof openedFormOf>) {
    const state = clearedStateOf([opened.copyMarks, opened.opening.cleared], {
        record: opened.data.record,
    });

    return shownMarksOf(state.groups);
}

describe('元の申請の値の印とフォームの値の出どころ', () => {
    it('明細のセルの印の行の key がフォームの行の key と一致し、そのセルに印が付き、件数は 2(budget とセル)', () => {
        const opened = openedFormOf(copyProps(), 'k', () => undefined);
        const firstKey = opened.data.record.details.milestones[0].key;

        expect(fieldMarksOf(shownOf(opened)).cells).toEqual({
            milestones: { [firstKey]: { title: 'copy' } },
        });
        expect(shownOf(opened)).toHaveLength(2);
    });

    it('戻る・進むで覚えた値から戻ったときも、覚えた印の key がフォームの行と一致する', () => {
        const left = openedFormOf(copyProps(), 'k', () => undefined);
        const remembered: Record<string, unknown> = {
            'k:data': left.data,
            'k:cleared': {
                copy: left.copyMarks,
                target: [],
            } satisfies RememberedCleared,
        };
        const back = openedFormOf(copyProps(), 'k', (key) => remembered[key]);

        expect(back.restored).toBe(true);
        expect(shownOf(back)).toHaveLength(2);
        expect(
            Object.keys(fieldMarksOf(shownOf(back)).cells.milestones),
        ).toEqual([back.data.record.details.milestones[0].key]);
    });
});

describe('元の申請の値の印と開いた時・反映した申請対象の写し(表 1)', () => {
    it('開いた時の申請対象の写しが、元の申請の値で空にした欄に値を入れると、その欄の印は出ない', () => {
        const opened = openedFormOf(
            copyProps({
                values: { budget: 100 },
                details: {},
                clearedFields: [],
            }),
            'k',
            () => undefined,
        );

        expect(opened.data.record.values.budget).toBe('100');
        expect(shownOf(opened).map((mark) => mark.field)).toEqual(['title']);
    });

    it('写しが明細を置き換える(確かめて反映した)と元の申請の値のセルの印は外れ、写しが空にしたセルの印は新しい行の key に付く', () => {
        const opened = openedFormOf(copyProps(), 'k', () => undefined);
        const state = clearedStateOf(
            [opened.copyMarks, opened.opening.cleared],
            {
                record: opened.data.record,
            },
        );
        const snapshot = {
            values: {},
            details: { milestones: [{ values: { title: null, done: true } }] },
            clearedFields: [title(0)],
        };
        const after = snapshotRecordOf(
            planModule,
            opened.data.record,
            snapshot,
        );
        const next = nextClearedStateOf(
            state,
            [opened.copyMarks, targetMarksOf(snapshot, after)],
            { record: after },
        );
        const newKey = after.details.milestones[0].key;

        expect(fieldMarksOf(shownMarksOf(next.groups)).cells).toEqual({
            milestones: { [newKey]: { title: 'applicationTarget' } },
        });
        expect(shownMarksOf(next.groups)).toHaveLength(2);
    });
});
