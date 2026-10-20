import { describe, expect, it } from 'vite-plus/test';
import type { AuthzCan } from '@/types';
import { canStartEditing, operationsFor, startedAfter } from './editMode';

const none: AuthzCan = {
    editCells: false,
    addSubject: false,
    deleteSubject: false,
};

describe('canStartEditing', () => {
    it('can のどれかが真なら開始できる', () => {
        expect(canStartEditing({ ...none, editCells: true })).toBe(true);
        expect(canStartEditing({ ...none, addSubject: true })).toBe(true);
        expect(canStartEditing({ ...none, deleteSubject: true })).toBe(true);
    });

    it('すべて偽なら開始できない', () => {
        expect(canStartEditing(none)).toBe(false);
    });
});

describe('startedAfter', () => {
    it('can が全部偽になれば開始済みを戻す', () => {
        expect(startedAfter(true, none)).toBe(false);
    });

    it('can のどれかが真なら開始済みを保つ', () => {
        expect(startedAfter(true, { ...none, addSubject: true })).toBe(true);
    });

    it('開始していなければ can が真でも開始済みにしない', () => {
        expect(startedAfter(false, { ...none, editCells: true })).toBe(false);
    });
});

describe('operationsFor', () => {
    const can: AuthzCan = {
        editCells: true,
        addSubject: false,
        deleteSubject: true,
    };

    it('編集中は can が真の操作だけを出す', () => {
        expect(operationsFor(can, true)).toEqual(can);
    });

    it('編集中でなければ何も出さない', () => {
        expect(operationsFor(can, false)).toEqual(none);
    });
});
