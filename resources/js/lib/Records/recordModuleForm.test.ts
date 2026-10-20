import { describe, expect, it } from 'vite-plus/test';
import type { RecordAdminModuleForm } from '@/types';
import { initialModuleData, toModulePayload } from './recordModuleForm';

const module: RecordAdminModuleForm = {
    code: 'meetings',
    name: '会議',
    description: null,
    icon: 'calendar',
    isVisible: true,
    isDetails: false,
    categoryIds: ['b'],
    rowAccessScope: 'related',
};

const details: RecordAdminModuleForm = {
    ...module,
    code: 'agenda',
    icon: null,
    isVisible: null,
    isDetails: true,
    categoryIds: null,
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
});

describe('toModulePayload', () => {
    it('見える範囲を row_access_scope で送る', () => {
        expect(
            toModulePayload(initialModuleData(module), false, categories),
        ).toEqual({
            name: '会議',
            description: '',
            icon: 'calendar',
            is_visible: true,
            categories: ['b'],
            row_access_scope: 'related',
        });
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
