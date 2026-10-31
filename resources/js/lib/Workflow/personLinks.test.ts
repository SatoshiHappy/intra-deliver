import { describe, expect, it } from 'vite-plus/test';
import { personLinkItemsOf } from './personLinks';

const person = {
    id: 9,
    code: 'u0009',
    name: '吉田 達也',
    mainDepartmentId: 21,
};

const chart = {
    label: '組織図で見る',
    href: '/master/organization-chart?department=21&user=9',
};

const authz = { label: '認可設定で見る', href: '/authz/overview?user=9' };

describe('名前の札の「…」の項目(links の真偽の 4 通り。空なら「…」を出さない)', () => {
    it.each([
        { organizationChart: true, authz: true, items: [chart, authz] },
        { organizationChart: true, authz: false, items: [chart] },
        { organizationChart: false, authz: true, items: [authz] },
        { organizationChart: false, authz: false, items: [] },
    ])('組織図 $organizationChart・認可設定 $authz', ({ items, ...links }) => {
        expect(personLinkItemsOf(person, links)).toEqual(items);
    });

    it('主所属の無い人の組織図はユーザーだけ', () => {
        expect(
            personLinkItemsOf(
                { ...person, mainDepartmentId: null },
                { organizationChart: true, authz: false },
            ),
        ).toEqual([
            {
                label: '組織図で見る',
                href: '/master/organization-chart?user=9',
            },
        ]);
    });
});
