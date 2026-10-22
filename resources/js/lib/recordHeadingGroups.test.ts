import { describe, expect, it } from 'vite-plus/test';
import type { RecordHeadingGroup } from '@/types';
import {
    headingIdsContaining,
    isHeadingGroup,
    toggleClosed,
    withOpened,
} from './recordHeadingGroups';

const groups: RecordHeadingGroup[] = [
    { id: null, name: null, fieldCodes: ['name', 'status'] },
    { id: 7, name: '期間と予算', fieldCodes: ['start_date', 'budget'] },
    { id: 9, name: '説明', fieldCodes: ['summary'] },
];

describe('isHeadingGroup', () => {
    it('頭のまとまり(id・name が null)は見出しの行を持たない', () => {
        expect(groups.map(isHeadingGroup)).toEqual([false, true, true]);
    });
});

describe('toggleClosed', () => {
    it('押した見出しを畳み、もう一度押すと開く', () => {
        expect(toggleClosed([], 7)).toEqual([7]);
        expect(toggleClosed([7, 9], 7)).toEqual([9]);
    });
});

describe('headingIdsContaining', () => {
    it('誤りの項目を含む見出しの id だけ(頭のまとまりの項目は含めない)', () => {
        expect(headingIdsContaining(groups, ['budget', 'name'])).toEqual([7]);
        expect(headingIdsContaining(groups, ['summary', 'start_date'])).toEqual(
            [7, 9],
        );
        expect(headingIdsContaining(groups, ['name'])).toEqual([]);
        expect(headingIdsContaining(groups, [])).toEqual([]);
    });
});

describe('withOpened', () => {
    it('指定した見出しだけ開き、誤りのない見出しは今の開閉のまま', () => {
        expect(withOpened([7, 9], [7])).toEqual([9]);
        expect(withOpened([9], [])).toEqual([9]);
    });
});
