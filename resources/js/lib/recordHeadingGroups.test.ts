import { describe, expect, it } from 'vite-plus/test';
import type { RecordHeadingGroup } from '@/types';
import {
    HEAD_GROUP_NAME,
    headingIdsContaining,
    headingNameOf,
    toggleClosed,
    withOpened,
} from './recordHeadingGroups';

const groups: RecordHeadingGroup[] = [
    { id: null, name: null, fieldCodes: ['name', 'status'] },
    { id: 7, name: '期間と予算', fieldCodes: ['start_date', 'budget'] },
    { id: 9, name: '説明', fieldCodes: ['summary'] },
];

describe('headingNameOf', () => {
    it('頭のまとまり(id・name が null)の見出しの行は固定の「基本情報」、ほかは見出しの名前', () => {
        expect(HEAD_GROUP_NAME).toBe('基本情報');
        expect(groups.map(headingNameOf)).toEqual([
            '基本情報',
            '期間と予算',
            '説明',
        ]);
    });
});

describe('toggleClosed', () => {
    it('押した見出しを畳み、もう一度押すと開く', () => {
        expect(toggleClosed([], 7)).toEqual([7]);
        expect(toggleClosed([7, 9], 7)).toEqual([9]);
    });

    it('頭のまとまり(id = null)も畳める・開ける', () => {
        expect(toggleClosed([], null)).toEqual([null]);
        expect(toggleClosed([null, 7], null)).toEqual([7]);
    });
});

describe('headingIdsContaining', () => {
    it('誤りの項目を含む見出しの id だけ(頭のまとまりの項目なら null)', () => {
        expect(headingIdsContaining(groups, ['budget', 'name'])).toEqual([
            null,
            7,
        ]);
        expect(headingIdsContaining(groups, ['summary', 'start_date'])).toEqual(
            [7, 9],
        );
        expect(headingIdsContaining(groups, ['name'])).toEqual([null]);
        expect(headingIdsContaining(groups, ['other'])).toEqual([]);
        expect(headingIdsContaining(groups, [])).toEqual([]);
    });
});

describe('withOpened', () => {
    it('指定した見出しだけ開き、誤りのない見出しは今の開閉のまま', () => {
        expect(withOpened([7, 9], [7])).toEqual([9]);
        expect(withOpened([9], [])).toEqual([9]);
        expect(withOpened([null, 9], [null])).toEqual([9]);
    });
});
