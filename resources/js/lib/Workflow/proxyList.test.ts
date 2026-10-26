import { describe, expect, it } from 'vite-plus/test';
import {
    adminEmptyTextOf,
    adminProxiesUrl,
    adminTableRow,
    ownTableRow,
} from './proxyList';
import type { WorkflowAdminProxyQuery } from '@/types';

const takahashi = {
    id: 3,
    code: 'u0003',
    name: '高橋 誠',
    department: '第一営業部',
};
const yoshida = {
    id: 9,
    code: 'u0009',
    name: '吉田 達也',
    department: '総務部',
};
const base = {
    id: 1,
    kind: 'approve' as const,
    flowName: null,
    startDate: '2026-10-13',
    endDate: null,
    status: 'active' as const,
};

describe('表の行', () => {
    it('代理の設定の行は人の列が 1 つで登録した人を持たない', () => {
        expect(ownTableRow({ ...base, person: yoshida })).toMatchObject({
            id: 1,
            people: [yoshida],
            registeredBy: null,
        });
    });

    it('代理の管理の行は代理元・代理先の順に人を持ち、登録した人を持つ', () => {
        const registeredBy = { id: 3, code: 'u0003', name: '高橋 誠' };

        expect(
            adminTableRow({
                ...base,
                principal: takahashi,
                proxy: yoshida,
                registeredBy,
            }),
        ).toMatchObject({ people: [takahashi, yoshida], registeredBy });
    });
});

describe('代理の管理の URL', () => {
    const none: WorkflowAdminProxyQuery = {
        principal: null,
        proxy: null,
        kind: null,
        active: false,
    };

    it('既定の値は付けない', () => {
        expect(adminProxiesUrl(none)).toBe('/workflow/admin/proxies');
    });

    it('人は id、種類、有効は 1、ページは 2 以降だけ付く', () => {
        expect(
            adminProxiesUrl(
                {
                    principal: { id: 3, code: 'u0003', name: '高橋 誠' },
                    proxy: { id: 9, code: 'u0009', name: '吉田 達也' },
                    kind: 'apply',
                    active: true,
                },
                2,
            ),
        ).toBe(
            '/workflow/admin/proxies?principal=3&proxy=9&kind=apply&active=1&page=2',
        );
        expect(adminProxiesUrl(none, 1)).toBe('/workflow/admin/proxies');
    });
});

describe('adminEmptyTextOf', () => {
    const none = { principal: null, proxy: null, kind: null, active: false };
    const person = { id: 3, code: 'u0003', name: '高橋 誠' };

    it('絞り込みが無ければ「代理はありません」', () => {
        expect(adminEmptyTextOf(none)).toBe('代理はありません');
    });

    it('絞り込みのどれか 1 つがあれば「該当する代理はありません」', () => {
        for (const query of [
            { ...none, principal: person },
            { ...none, proxy: person },
            { ...none, kind: 'approve' as const },
            { ...none, active: true },
        ]) {
            expect(adminEmptyTextOf(query)).toBe('該当する代理はありません');
        }
    });
});
