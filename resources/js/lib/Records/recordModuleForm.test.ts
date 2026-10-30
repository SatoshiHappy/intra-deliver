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
    rowAccessScope: 'related',
};

const details: RecordAdminModuleForm = {
    ...module,
    code: 'agenda',
    icon: null,
    isVisible: null,
    isDetails: true,
    categoryIds: null,
    isAttachmentEnabled: null,
    rowAccessScope: null,
};

const categories = [
    { id: 'a', name: '営業' },
    { id: 'b', name: '総務' },
];

describe('initialModuleData', () => {
    it('見える範囲は今の値、明細のモジュール(null)は全員にしておく', () => {
        expect(initialModuleData(module).row_access_scope).toBe('related');
        expect(initialModuleData(details).row_access_scope).toBe('all');
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
    it('添付の使用を is_attachment_enabled、見える範囲を row_access_scope で送る', () => {
        expect(
            toModulePayload(initialModuleData(module), false, categories),
        ).toEqual({
            name: '会議',
            description: '',
            icon: 'calendar',
            is_visible: true,
            categories: ['b'],
            is_attachment_enabled: false,
            row_access_scope: 'related',
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

    it('明細のモジュールは名前と説明だけで、見える範囲を送らない', () => {
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
