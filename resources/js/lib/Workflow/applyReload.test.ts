import { describe, expect, it } from 'vite-plus/test';
import {
    applyReloadOf,
    loadedApplicantOf,
    staleApplicantsReloadOf,
} from './applyReload';

const self = { id: 9, name: '吉田 達也', department: '総務部', isSelf: true };
const yamada = { id: 10, name: '山田 恵', department: '経理部', isSelf: false };
const sato = { id: 11, name: '佐藤 花子', department: null, isSelf: false };

/** 申請者に依る props(申請者の選択肢は入らない) */
const APPLICANT_PROPS = [
    'module',
    'next',
    'baseDate',
    'baseDateRange',
    'applicant',
];

/** 新規の画面を自分で開いた */
const opened = { openedApplicant: null, fromTarget: false };

/** 申請対象から自分で開いた */
const fromTarget = { openedApplicant: null, fromTarget: true };

describe('待っている要求が無いときの読み直し', () => {
    it('申請基準日を変えたら next だけを読み直し、今の申請者もクエリに載せる', () => {
        const request = applyReloadOf(
            { field: 'base_date', value: '2026-10-20' },
            { applicant: 10, baseDate: '2026-10-13' },
            null,
            opened,
        );

        expect(request).toEqual({
            data: { applicant: 10, base_date: '2026-10-20' },
            only: ['next'],
        });
    });

    it('申請者を変えたら申請者に依る props を読み直し、選択肢は読み直さない', () => {
        const request = applyReloadOf(
            { field: 'applicant', value: 10 },
            { applicant: null, baseDate: '2026-10-13' },
            null,
            opened,
        );

        expect(request?.data).toEqual({
            applicant: 10,
            base_date: '2026-10-13',
        });
        expect(request?.only).toEqual(APPLICANT_PROPS);
        expect(request?.only).not.toContain('applicants');
    });
});

describe('待っている要求が無いときの読み直し(続き)', () => {
    it('自分に戻すときはクエリ applicant を外し、空の申請基準日も送らない', () => {
        const request = applyReloadOf(
            { field: 'applicant', value: null },
            { applicant: 10, baseDate: '' },
            null,
            opened,
        );

        expect(request?.data.applicant).toBeUndefined();
        expect(request?.data.base_date).toBeUndefined();
    });

    it('申請基準日を空にしたら送らない(待っている要求を取り消すだけ)', () => {
        const request = applyReloadOf(
            { field: 'base_date', value: '' },
            { applicant: 10, baseDate: '2026-10-13' },
            null,
            opened,
        );

        expect(request).toBeNull();
    });
});

describe('待っている要求を取り消して送る読み直し', () => {
    it('申請者の待ち → 申請基準日を変える: 申請者の props を引き継ぎ、新しい申請者と日付を載せる', () => {
        const request = applyReloadOf(
            { field: 'base_date', value: '2026-10-20' },
            { applicant: 10, baseDate: '2026-10-13' },
            APPLICANT_PROPS,
            opened,
        );

        expect(request?.data).toEqual({
            applicant: 10,
            base_date: '2026-10-20',
        });
        expect([...(request?.only ?? [])].sort()).toEqual(
            [...APPLICANT_PROPS].sort(),
        );
    });

    it('申請者の待ち → 申請基準日を空にする: 申請基準日を外して申請者の props を送り直す', () => {
        const request = applyReloadOf(
            { field: 'base_date', value: '' },
            { applicant: 10, baseDate: '2026-10-13' },
            APPLICANT_PROPS,
            opened,
        );

        expect(request?.data).toEqual({ applicant: 10 });
        expect('base_date' in (request?.data ?? {})).toBe(true);
        expect(request?.only).toEqual(APPLICANT_PROPS);
    });
});

describe('待っている要求を取り消して送る読み直し(申請基準日の待ち)', () => {
    it('申請基準日の待ち → 申請基準日を空にする: 送らない', () => {
        const request = applyReloadOf(
            { field: 'base_date', value: '' },
            { applicant: 10, baseDate: '2026-10-20' },
            ['next'],
            opened,
        );

        expect(request).toBeNull();
    });

    it('申請基準日の待ち → 申請者を変える: 申請者の props と next を、入力中の日付で送る', () => {
        const request = applyReloadOf(
            { field: 'applicant', value: 11 },
            { applicant: 10, baseDate: '2026-10-20' },
            ['next'],
            opened,
        );

        expect(request?.data).toEqual({
            applicant: 11,
            base_date: '2026-10-20',
        });
        expect(request?.only).toEqual(APPLICANT_PROPS);
    });
});

describe('申請対象から開いた画面のクエリ target', () => {
    const current = { applicant: null, baseDate: '2026-10-13' };

    it('申請者を変えていない申請基準日の読み直しは target を触らない(URL のまま)', () => {
        const request = applyReloadOf(
            { field: 'base_date', value: '2026-10-20' },
            current,
            null,
            fromTarget,
        );

        expect('target' in (request?.data ?? {})).toBe(false);
    });

    it('申請者を変えた後の申請基準日の読み直しは target を外す', () => {
        const request = applyReloadOf(
            { field: 'base_date', value: '2026-10-20' },
            { ...current, applicant: 10 },
            null,
            fromTarget,
        );

        expect(request?.data).toHaveProperty('target', undefined);
        expect('target' in (request?.data ?? {})).toBe(true);
    });
});

describe('申請対象から開いた画面のクエリ target(申請者の読み直し)', () => {
    const current = { applicant: null, baseDate: '2026-10-13' };

    it('申請者を変える読み直しは target を外し、targetEntry と選択肢を読み直さない', () => {
        const request = applyReloadOf(
            { field: 'applicant', value: 10 },
            current,
            null,
            fromTarget,
        );

        expect('target' in (request?.data ?? {})).toBe(true);
        expect(request?.data.target).toBeUndefined();
        expect(request?.only).not.toContain('targetEntry');
        expect(request?.only).not.toContain('applicants');
    });

    it('開いたときの申請者に戻す読み直しは target を触らない', () => {
        const request = applyReloadOf(
            { field: 'applicant', value: 10 },
            { ...current, applicant: 11 },
            null,
            { openedApplicant: 10, fromTarget: true },
        );

        expect('target' in (request?.data ?? {})).toBe(false);
    });

    it('申請対象から開いていない画面は target を載せない', () => {
        const request = applyReloadOf(
            { field: 'applicant', value: 10 },
            current,
            null,
            opened,
        );

        expect('target' in (request?.data ?? {})).toBe(false);
    });
});

describe('読み直しの応答をフォームへ写す', () => {
    const loaded = {
        applicants: [self, yamada, sato],
        applicant: 11,
        baseDate: '2026-10-01',
    };

    it('申請者に依る props を読み直した応答は、申請者と申請基準日を写す', () => {
        expect(loadedApplicantOf(APPLICANT_PROPS, loaded)).toEqual({
            applicant: 11,
            base_date: '2026-10-01',
        });
        expect(
            loadedApplicantOf(['next', ...APPLICANT_PROPS], loaded),
        ).not.toBeNull();
    });

    it('申請基準日だけの読み直しは写さない', () => {
        expect(loadedApplicantOf(['next'], loaded)).toBeNull();
    });
});

describe('読み直しの応答が返した申請者と選択肢', () => {
    const loaded = {
        applicants: [self, yamada, sato],
        applicant: 11,
        baseDate: '2026-10-01',
    };

    it('返った申請者が選択肢にあれば選択肢を読み直さない', () => {
        expect(staleApplicantsReloadOf(loaded, opened)).toBeNull();
        expect(
            staleApplicantsReloadOf({ ...loaded, applicant: null }, opened),
        ).toBeNull();
    });

    it('返った申請者が選択肢に無ければ、選択肢を足して読み直す(targetEntry は読み直さない)', () => {
        const request = staleApplicantsReloadOf(
            { ...loaded, applicants: [self, yamada] },
            fromTarget,
        );

        expect(request?.data).toEqual({
            applicant: 11,
            base_date: '2026-10-01',
            target: undefined,
        });
        expect(request?.only).toEqual([...APPLICANT_PROPS, 'applicants']);
        expect(request?.only).not.toContain('targetEntry');
    });

    it('自分が返り、自分が選択肢に無ければ読み直す', () => {
        const request = staleApplicantsReloadOf(
            { ...loaded, applicants: [yamada, sato], applicant: null },
            opened,
        );

        expect(request?.data.applicant).toBeUndefined();
        expect(request?.only).toContain('applicants');
    });
});
