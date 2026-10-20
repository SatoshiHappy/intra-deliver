import { describe, expect, it } from 'vite-plus/test';
import type { AuthzGridSetting } from '@/types';
import {
    cellKey,
    cellPayload,
    directSettingOf,
    isStaleCellMessage,
    nextSetting,
} from './cellEdit';

function setting(
    effect: 'permit' | 'deny',
    inheritedFrom: AuthzGridSetting['inheritedFrom'] = null,
): AuthzGridSetting {
    return { subjectGroupId: 3, action: 'execute', effect, inheritedFrom };
}

const parent = { code: 'http-services', name: '画面・処理' };

describe('directSettingOf', () => {
    it('行に直接置いた設定はそのエフェクト', () => {
        expect(directSettingOf(setting('permit'))).toBe('permit');
        expect(directSettingOf(setting('deny'))).toBe('deny');
    });

    it('継承のセルは none として扱う', () => {
        expect(directSettingOf(setting('permit', parent))).toBe('none');
        expect(directSettingOf(setting('deny', parent))).toBe('none');
    });

    it('設定の無いセルは none', () => {
        expect(directSettingOf(undefined)).toBe('none');
    });
});

describe('nextSetting', () => {
    it('許可 → 禁止 → 未設定 → 許可の順に進む', () => {
        expect(nextSetting('permit')).toBe('deny');
        expect(nextSetting('deny')).toBe('none');
        expect(nextSetting('none')).toBe('permit');
    });
});

describe('cellPayload', () => {
    const target = {
        resourceGroupId: 11,
        subjectGroupId: 3,
        action: 'execute',
    };

    it('見ていた直接の設定を from に、循環の次を to に送る', () => {
        expect(
            cellPayload('service', { ...target, setting: setting('permit') }),
        ).toEqual({
            resource_group_id: 11,
            subject_group_id: 3,
            type: 'service',
            action: 'execute',
            from: 'permit',
            to: 'deny',
        });
    });

    it('継承のセルは from を none にして、押すと直接の許可', () => {
        const payload = cellPayload('service', {
            ...target,
            setting: setting('deny', parent),
        });

        expect(payload.from).toBe('none');
        expect(payload.to).toBe('permit');
    });

    it('設定の無いセルは none から許可', () => {
        const payload = cellPayload('im-menu-group', {
            ...target,
            setting: undefined,
        });

        expect(payload.type).toBe('im-menu-group');
        expect([payload.from, payload.to]).toEqual(['none', 'permit']);
    });
});

describe('cellKey', () => {
    it('行・対象者・アクションの組ごとに別の鍵になる', () => {
        expect(cellKey(1, 2, 'read')).toBe('1:2:read');
        expect(cellKey(1, 2, 'read')).not.toBe(cellKey(2, 1, 'read'));
    });
});

describe('isStaleCellMessage', () => {
    it('同時の編集の文だけが真', () => {
        expect(
            isStaleCellMessage(
                'ほかの利用者が変更しました。画面を再読み込みしてください。',
            ),
        ).toBe(true);
        expect(
            isStaleCellMessage(
                'この変更を行うと認可設定を使用できなくなるため、変更できません。',
            ),
        ).toBe(false);
    });
});
