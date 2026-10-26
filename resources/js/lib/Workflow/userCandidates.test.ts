import { describe, expect, it } from 'vite-plus/test';
import { userCandidatesUrl } from './userCandidates';

describe('人の候補の URL', () => {
    it('語だけ(案件も基準日も無い = 既存の呼び手と同じ)', () => {
        expect(userCandidatesUrl('高橋', null)).toBe(
            '/workflow/user-candidates?q=%E9%AB%98%E6%A9%8B',
        );
    });

    it('案件があれば case を付ける', () => {
        expect(userCandidatesUrl('a', 5)).toBe(
            '/workflow/user-candidates?q=a&case=5',
        );
    });

    it('基準日(入力中の開始日)があれば on を付け、無ければ付けない', () => {
        expect(userCandidatesUrl('a', null, '2026-11-01')).toBe(
            '/workflow/user-candidates?q=a&on=2026-11-01',
        );
        expect(userCandidatesUrl('a', null, undefined)).toBe(
            '/workflow/user-candidates?q=a',
        );
    });
});
