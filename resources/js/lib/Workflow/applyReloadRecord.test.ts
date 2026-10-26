import { describe, expect, it } from 'vite-plus/test';
import { applyReloadOf, staleApplicantsReloadOf } from './applyReload';

/** 既存のデータからの申請の画面を、申請者 10 で開いた(申請対象から開いた画面ではない) */
const recordOpened = { openedApplicant: 10, fromTarget: false };

/** レコードの指定を運ぶ props(開いたときの版・初期値・レコードの id と見出し)。読み直しはどれも触らない */
const RECORD_PROPS = ['recordVersion', 'initial', 'recordId', 'recordTitle'];

describe('既存のデータからの申請の読み直し(レコードの版は開いたときのまま)', () => {
    const current = { applicant: 10, baseDate: '2026-10-13' };
    const date = { field: 'base_date', value: '2026-10-20' } as const;
    const toSelf = { field: 'applicant', value: null } as const;
    const pending = ['module', 'applicant', 'applicants'];
    const stale = {
        applicants: [],
        applicant: 11,
        baseDate: '2026-10-13',
    };

    it('どの要求も only にレコードの props を含めず、クエリ record・target を外さない', () => {
        const requests = [
            applyReloadOf(date, current, null, recordOpened),
            applyReloadOf(toSelf, current, null, recordOpened),
            applyReloadOf(date, current, pending, recordOpened),
            staleApplicantsReloadOf(stale, recordOpened),
        ];

        for (const request of requests) {
            expect(request).not.toBeNull();
            const reloaded = request?.only ?? [];
            expect(
                reloaded.filter((prop) => RECORD_PROPS.includes(prop)),
            ).toEqual([]);
            // クエリは今の URL に重ねて送られる(undefined のキーだけが外れる)ので、record を指すキーを持たなければ URL の record が残る
            expect(Object.keys(request?.data ?? {})).not.toContain('record');
            expect(Object.keys(request?.data ?? {})).not.toContain('target');
        }
    });
});
