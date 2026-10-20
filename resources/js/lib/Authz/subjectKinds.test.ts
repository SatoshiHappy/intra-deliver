import { describe, expect, it } from 'vite-plus/test';
import { SUBJECT_KINDS, subjectKindLabel, subjectKindOf } from './subjectKinds';

describe('SUBJECT_KINDS', () => {
    it('ユーザー・ロール・組織・役職の順', () => {
        expect(SUBJECT_KINDS.map((kind) => kind.label)).toEqual([
            'ユーザー',
            'ロール',
            '組織',
            '役職',
        ]);
    });
});

describe('subjectKindOf', () => {
    it('並びにある値は種類になり、無い値は undefined', () => {
        expect(subjectKindOf('department')).toBe('department');
        expect(subjectKindOf('company')).toBeUndefined();
    });
});

describe('subjectKindLabel', () => {
    it('種類の画面の語を返す', () => {
        expect(subjectKindLabel('post')).toBe('役職');
    });
});
