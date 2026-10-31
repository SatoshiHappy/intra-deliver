import { describe, expect, it } from 'vite-plus/test';
import type { RecordAdminModuleForm } from '@/types';
import {
    initialModuleData,
    toModulePayload,
    withVisibilityStop,
} from './recordModuleForm';

const module: RecordAdminModuleForm = {
    code: 'meetings',
    name: '会議',
    description: null,
    icon: 'calendar',
    isVisible: true,
    isDetails: false,
    categoryIds: ['b'],
    isAttachmentEnabled: false,
    rowAccessRestrictedByDefault: true,
};

const details: RecordAdminModuleForm = {
    ...module,
    code: 'agenda',
    icon: null,
    isVisible: null,
    isDetails: true,
    categoryIds: null,
    isAttachmentEnabled: null,
    rowAccessRestrictedByDefault: null,
};

const categories = [
    { id: 'a', name: '営業' },
    { id: 'b', name: '総務' },
];

describe('initialModuleData', () => {
    it('参照制限の既定は今の値、明細のモジュール(null)は制限しないにしておく', () => {
        expect(initialModuleData(module).row_access_restricted_by_default).toBe(
            true,
        );
        expect(
            initialModuleData(details).row_access_restricted_by_default,
        ).toBe(false);
    });

    it('添付の使用は今の値、明細のモジュール(null)は使用するにしておく', () => {
        expect(initialModuleData(module).is_attachment_enabled).toBe(false);
        expect(initialModuleData(details).is_attachment_enabled).toBe(true);
    });

    it('表示の止めがあれば、表示中でもサイドバーに表示を偽にする', () => {
        expect(initialModuleData(module).is_visible).toBe(true);
        expect(initialModuleData(module, '止めの理由').is_visible).toBe(false);
    });
});

describe('withVisibilityStop', () => {
    it('止めが立ったら「サイドバーに表示」を偽にし、止めが無ければ変えない', () => {
        const data = initialModuleData(module);

        expect(withVisibilityStop(data, '止めの理由').is_visible).toBe(false);
        expect(withVisibilityStop(data, null)).toBe(data);
    });
});

describe('toModulePayload', () => {
    it('添付の使用を is_attachment_enabled、参照制限の既定を row_access_restricted_by_default で送る', () => {
        expect(
            toModulePayload(initialModuleData(module), false, categories),
        ).toEqual({
            name: '会議',
            description: '',
            icon: 'calendar',
            is_visible: true,
            categories: ['b'],
            is_attachment_enabled: false,
            row_access_restricted_by_default: true,
        });
    });

    it('表示の止めがあれば is_visible を偽、分類を空で送る', () => {
        const payload = toModulePayload(
            initialModuleData(module, '止めの理由'),
            false,
            categories,
        );

        expect([payload.is_visible, payload.categories]).toEqual([false, []]);
    });

    it('明細のモジュールは名前と説明だけで、参照制限の既定を送らない', () => {
        expect(
            toModulePayload(initialModuleData(details), true, categories),
        ).toEqual({ name: '会議', description: '' });
    });

    it('サイドバーに出さないなら分類は空、出すなら一覧の並びの順', () => {
        const data = {
            ...initialModuleData(module),
            categories: ['b', 'a'],
        };

        expect(toModulePayload(data, false, categories).categories).toEqual([
            'a',
            'b',
        ]);
        expect(
            toModulePayload({ ...data, is_visible: false }, false, categories)
                .categories,
        ).toEqual([]);
    });
});
