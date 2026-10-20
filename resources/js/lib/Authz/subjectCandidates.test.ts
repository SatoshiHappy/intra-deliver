import { describe, expect, it } from 'vite-plus/test';
import type { AuthzSubjectCandidates } from '@/types';
import { INITIAL_SUBJECT_CANDIDATES, waitingFor } from './subjectCandidates';

describe('waitingFor', () => {
    it('新しい要求の開始で、前の失敗を戻して待ち状態にする', () => {
        const failed = { ...INITIAL_SUBJECT_CANDIDATES, hasFailed: true };

        expect(waitingFor(failed)).toEqual({
            result: null,
            isWaiting: true,
            hasFailed: false,
        });
    });

    it('採った結果は応答が届くまで残す', () => {
        const result = { candidates: [] } as unknown as AuthzSubjectCandidates;

        expect(
            waitingFor({ result, isWaiting: false, hasFailed: true }).result,
        ).toBe(result);
    });
});
