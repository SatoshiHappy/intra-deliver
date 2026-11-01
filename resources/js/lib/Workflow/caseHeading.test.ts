import { describe, expect, it } from 'vite-plus/test';
import { headingStepOf } from './caseHeading';

describe('headingStepOf', () => {
    it('未完了は「・」の後に現在のステップ(並列は「、」でつないだ名前のまま)', () => {
        expect(
            headingStepOf({
                state: 'active',
                currentSummary: { state: 'waiting', name: '部長' },
            }),
        ).toBe('・部長');
        expect(
            headingStepOf({
                state: 'active',
                currentSummary: { state: 'waiting', name: '課長、部長' },
            }),
        ).toBe('・課長、部長');
    });

    it('完了は出さない(札だけ)', () => {
        expect(
            headingStepOf({ state: 'completed', currentSummary: null }),
        ).toBe(null);
    });

    it('未完了でも現在のステップが無ければ出さない', () => {
        expect(headingStepOf({ state: 'active', currentSummary: null })).toBe(
            null,
        );
    });
});
