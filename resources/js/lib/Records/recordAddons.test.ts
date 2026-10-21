import { describe, expect, it } from 'vite-plus/test';
import type { RecordAddonAction } from '@/types';
import {
    addonActionState,
    addonBadgeColumn,
    ownValue,
    shownAddonActions,
} from './recordAddons';

/**
 * 後ろのモジュールが足す操作(押せる・値を変えない)。
 */
function action(
    code: string,
    overrides: Partial<RecordAddonAction> = {},
): RecordAddonAction {
    return {
        code,
        label: `${code}の操作`,
        dialog: `meeting.${code}`,
        props: { recordId: 1 },
        disabledReason: null,
        changesValues: false,
        ...overrides,
    };
}

describe('addonActionState', () => {
    it('どちらも無ければ押せて、Tooltip は出さない', () => {
        expect(addonActionState(action('create'), false)).toEqual({
            disabled: false,
            tooltip: null,
        });
    });

    it('押せない理由があれば押せない形で、理由を Tooltip にする(一覧も同じ)', () => {
        const busy = action('recreate', { disabledReason: '処理中です' });

        expect(addonActionState(busy, false)).toEqual({
            disabled: true,
            tooltip: '処理中です',
        });
    });

    it('持ち主があり値を変える操作は押せない形で、Tooltip は出さない(理由は持ち主の 1 行)', () => {
        expect(
            addonActionState(action('recreate', { changesValues: true }), true),
        ).toEqual({ disabled: true, tooltip: null });
        expect(
            addonActionState(
                action('recreate', {
                    changesValues: true,
                    disabledReason: '処理中です',
                }),
                true,
            ),
        ).toEqual({ disabled: true, tooltip: null });
    });

    it('持ち主があっても値を変えない操作は持ち主を見ない', () => {
        expect(addonActionState(action('share'), true)).toEqual({
            disabled: false,
            tooltip: null,
        });
        expect(
            addonActionState(
                action('share', { disabledReason: '処理中です' }),
                true,
            ),
        ).toEqual({ disabled: true, tooltip: '処理中です' });
    });
});

describe('shownAddonActions', () => {
    it('部品の登録が無い操作は出さず、その名前を返す(並びはそのまま)', () => {
        const registered = new Set(['meeting.create', 'meeting.share']);

        expect(
            shownAddonActions(
                [action('create'), action('unknown'), action('share')],
                (name) => registered.has(name),
            ),
        ).toEqual({
            shown: [action('create'), action('share')],
            missing: ['meeting.unknown'],
        });
    });
});

describe('addonBadgeColumn', () => {
    it('表示の項目が一覧にあれば、その項目の列', () => {
        expect(
            addonBadgeColumn([
                { code: 'held_on', isTitle: false },
                { code: 'title', isTitle: true },
            ]),
        ).toBe('title');
    });

    it('表示の項目が一覧に無ければ「#」の列(null)', () => {
        expect(addonBadgeColumn([{ code: 'held_on', isTitle: false }])).toBe(
            null,
        );
        expect(addonBadgeColumn([])).toBe(null);
    });
});

describe('ownValue', () => {
    it('持っているキーでは値を返す(行の id の数字でも引ける)', () => {
        expect(ownValue({ summary: '上書きされます' }, 'summary')).toBe(
            '上書きされます',
        );
        expect(ownValue({ '5': ['札'] }, 5)).toEqual(['札']);
    });

    it('空のオブジェクトでは、組み込みの名前のキーも undefined', () => {
        expect(ownValue({}, 'length')).toBeUndefined();
        expect(ownValue({}, 'constructor')).toBeUndefined();
        expect(ownValue({}, 'summary')).toBeUndefined();
    });
});
