import { describe, expect, it } from 'vite-plus/test';
import {
    versionAddBlockedText,
    versionChangeText,
    versionStartBounds,
} from './flowVersionAdd';

describe('versionStartBounds(今日 2026-10-31、max = 2027-10-31)', () => {
    it('最後の版の開始日の翌日が今日より前なら min は今日', () => {
        expect(versionStartBounds('2026-10-31', '2026-04-01')).toEqual({
            min: '2026-10-31',
            max: '2027-10-31',
            canAdd: true,
        });
    });

    it('最後の版の開始日の翌日が今日と同じ(開始日が昨日)なら min は今日', () => {
        expect(versionStartBounds('2026-10-31', '2026-10-30').min).toBe(
            '2026-10-31',
        );
    });

    it('最後の版の開始日の翌日が今日より後なら min はその翌日', () => {
        expect(versionStartBounds('2026-10-31', '2026-10-31').min).toBe(
            '2026-11-01',
        );
        expect(versionStartBounds('2026-10-31', '2027-01-15').min).toBe(
            '2027-01-16',
        );
    });

    it('最後の版の開始日が今日 + 1 年の前日なら min = max で追加できる', () => {
        expect(versionStartBounds('2026-10-31', '2027-10-30')).toEqual({
            min: '2027-10-31',
            max: '2027-10-31',
            canAdd: true,
        });
    });

    it('最後の版の開始日が今日 + 1 年なら追加できない', () => {
        const bounds = versionStartBounds('2026-10-31', '2027-10-31');

        expect(bounds.min).toBe('2027-11-01');
        expect(bounds.canAdd).toBe(false);
    });

    it('最後の版の開始日が今日 + 1 年より先でも追加できない', () => {
        expect(versionStartBounds('2026-10-31', '2028-01-01').canAdd).toBe(
            false,
        );
    });
});

describe('versionStartBounds の暦', () => {
    it('2 月 29 日の 1 年後は 2 月 28 日(サーバーの addYearNoOverflow と同じ)', () => {
        expect(versionStartBounds('2028-02-29', '2028-01-01').max).toBe(
            '2029-02-28',
        );
    });

    it('最後の版の開始日の翌日は月と年をまたぐ', () => {
        expect(versionStartBounds('2026-10-31', '2026-12-31').min).toBe(
            '2027-01-01',
        );
    });
});

describe('versionChangeText', () => {
    it('開始日の前日を、最後の版の終了日として出す', () => {
        expect(versionChangeText(3, '2027-04-01')).toBe(
            '版 3 の終了日が 2027-03-31 に変更されます。',
        );
    });

    it('開始日が日付でなければ出さない', () => {
        expect(versionChangeText(3, '')).toBeNull();
        expect(versionChangeText(3, '2027-04')).toBeNull();
        expect(versionChangeText(3, '2027-02-30')).toBeNull();
    });
});

describe('versionAddBlockedText', () => {
    it('最後の版の番号を入れた文', () => {
        expect(versionAddBlockedText(4)).toBe(
            '版 4 の開始日が今日から 1 年より先のため、版を追加できません。',
        );
    });
});
