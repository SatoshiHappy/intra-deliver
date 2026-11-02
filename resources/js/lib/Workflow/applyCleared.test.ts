import { describe, expect, it } from 'vite-plus/test';
import { applyClearedOf } from './applyCleared';
import { clearedMarksOf } from '@/lib/clearedFields';
import type { RecordFormData } from '@/lib/recordForm';
import type { ClearedMark } from '@/lib/clearedFields';
import type { ClearedField, WorkflowApplyProps } from '@/types';

// 申請の画面の空にした欄の知らせ(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節の「空にした項目の知らせ」)。

const record: RecordFormData = {
    values: { manager: null, budget: null },
    details: {},
};

const field = (code: string): ClearedField => ({
    kind: 'field',
    field: code,
    detail: null,
    row: null,
    name: code,
});

const viewers: ClearedField = {
    kind: 'viewers',
    field: null,
    detail: null,
    row: null,
    name: '参照制限の参照者',
};

const copyOf = (cleared: ClearedField[]) =>
    ({
        caseId: 1,
        caseNumber: 'WF-000001',
        name: '元',
        clearedFields: cleared,
        rowAccess: 'sealed',
    }) as WorkflowApplyProps['copy'];

const sourceOf = (
    copy: WorkflowApplyProps['copy'],
    copyMarks: ClearedMark[],
    targetMarks: ClearedMark[],
) => ({
    props: { copy },
    clearedMarks: [copyMarks, targetMarks],
});

describe('申請の画面の知らせ', () => {
    it('元の申請の値と申請対象の値の印を合わせ、注意の 1 行は 1 つで件数を合わせる', () => {
        const copy = copyOf([field('manager')]);
        const cleared = applyClearedOf(
            sourceOf(
                copy,
                clearedMarksOf(copy?.clearedFields ?? [], 'copy', { record }),
                clearedMarksOf([field('budget')], 'applicationTarget', {
                    record,
                }),
            ),
        );

        expect(cleared.fields.fields).toEqual({
            manager: 'copy',
            budget: 'applicationTarget',
        });
        expect(cleared.notice).toBe(
            '今は使えない値を空にした項目が 2 件あります。⚠ の付いた欄を入力してください。',
        );
        expect(cleared.viewersNotice).toBeNull();
    });

    it('引き継ぐ参照者を外したときは別の 1 行を出し、件数に数えない', () => {
        const cleared = applyClearedOf(sourceOf(copyOf([viewers]), [], []));

        expect([cleared.notice, cleared.viewersNotice]).toEqual([
            null,
            '引き継ぐ参照制限の参照者のうち、今は使えない人・組織を外しました。',
        ]);
    });
});
