import { describe, expect, it } from 'vite-plus/test';
import { caseLayoutOf, caseRecordModeOf, reloadStepOf } from './caseRecordMode';
import type { CaseRecordContent, CaseSubmittedRecord } from './caseRecordMode';

const content = { id: 'content' } as unknown as CaseRecordContent;
const submitted = { id: 'snapshot' } as unknown as CaseSubmittedRecord;

describe('caseRecordModeOf', () => {
    it('recordAccess が真で今の値があれば live(今の値を持つ)', () => {
        expect(
            caseRecordModeOf({
                recordAccess: true,
                content,
                submittedRecord: null,
            }),
        ).toEqual({ kind: 'live', content });
    });

    it('recordAccess が偽で写しがあれば snapshot(今の値が残っていても使わない)', () => {
        expect(
            caseRecordModeOf({
                recordAccess: false,
                content: null,
                submittedRecord: submitted,
            }),
        ).toEqual({ kind: 'snapshot', submittedRecord: submitted });
        expect(
            caseRecordModeOf({
                recordAccess: false,
                content,
                submittedRecord: submitted,
            }),
        ).toEqual({ kind: 'snapshot', submittedRecord: submitted });
    });

    it('部分の再読み込みで recordAccess が偽に変わり写しが無ければ、全体を読み直す', () => {
        expect(
            caseRecordModeOf({
                recordAccess: false,
                content,
                submittedRecord: null,
            }),
        ).toEqual({ kind: 'reload' });
    });

    it('recordAccess が真でも今の値が無ければ、全体を読み直す', () => {
        expect(
            caseRecordModeOf({
                recordAccess: true,
                content: null,
                submittedRecord: submitted,
            }),
        ).toEqual({ kind: 'reload' });
    });
});

describe('reloadStepOf', () => {
    it('1 回目のずれで読み直す', () => {
        expect(reloadStepOf(true, false)).toEqual({
            reload: true,
            reloaded: true,
        });
    });

    it('同じずれの間は繰り返さない', () => {
        expect(reloadStepOf(true, true)).toEqual({
            reload: false,
            reloaded: true,
        });
    });

    it('そろったら印を戻す', () => {
        expect(reloadStepOf(false, true)).toEqual({
            reload: false,
            reloaded: false,
        });
    });

    it('そろった後の 2 回目のずれでまた読み直す', () => {
        const first = reloadStepOf(true, false);
        const aligned = reloadStepOf(false, first.reloaded);

        expect(reloadStepOf(true, aligned.reloaded).reload).toBe(true);
    });
});

describe('caseLayoutOf(処理のボタンの帯はどの形でも出す)', () => {
    it('中身がそろった形は左右の枠を描き、帯は詳細のカードの下', () => {
        const live = { kind: 'live', content } as const;
        const snapshot = {
            kind: 'snapshot',
            submittedRecord: submitted,
        } as const;

        expect(caseLayoutOf(live)).toEqual({
            frame: live,
            actionBar: 'frameFooter',
        });
        expect(caseLayoutOf(snapshot).actionBar).toBe('frameFooter');
    });

    it('読み直しの形(reload)は枠を描かず、帯は見出しの下に出す', () => {
        expect(caseLayoutOf({ kind: 'reload' })).toEqual({
            frame: null,
            actionBar: 'belowHeading',
        });
    });
});
