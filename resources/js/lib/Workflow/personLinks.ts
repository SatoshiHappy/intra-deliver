import { departmentChartUrl, userChartUrl } from '@/lib/organizationChart';
import { show as overview } from '@/routes/authz/settings';
import type { WorkflowLinkedPerson, WorkflowPersonLinks } from '@/types';

/** 名前の札の「…」の 1 項目(新しいタブで開く) */
export type PersonLinkItem = { label: string; href: string };

/** リンクを渡さない画面の既定(「…」を出さない) */
export const NO_PERSON_LINKS: WorkflowPersonLinks = {
    organizationChart: false,
    authz: false,
};

/**
 * 名前の札の「…」に出す項目(docs の 設計/ワークフロー/申請の周辺/メニューと導線.md 2.1。並び = 組織図・認可設定)。
 * 出す条件はページが渡す links(移り先のルートの execute)。空なら「…」を出さない。
 */
export function personLinkItemsOf(
    person: WorkflowLinkedPerson,
    links: WorkflowPersonLinks,
): PersonLinkItem[] {
    const items: PersonLinkItem[] = [];

    if (links.organizationChart) {
        items.push({
            label: '組織図で見る',
            href: userChartUrl(person.id, person.mainDepartmentId),
        });
    }

    if (links.authz) {
        items.push({
            label: '認可設定で見る',
            href: overview.url({ query: { user: person.id } }),
        });
    }

    return items;
}

/**
 * フロー定義の詳細の要約の、具体の組織の名前の「…」に出す項目(組織図で見る。メニューと導線.md 2.2。出す条件は組織図の execute)。
 */
export function departmentLinkItemsOf(
    departmentId: number,
    links: WorkflowPersonLinks,
): PersonLinkItem[] {
    return links.organizationChart
        ? [
              {
                  label: '組織図で見る',
                  href: departmentChartUrl(departmentId),
              },
          ]
        : [];
}

/**
 * フロー定義の詳細の見出しの右の「認可設定で見る」(フローの種類の格子。出す条件は認可設定の execute)。出さないときは null。
 */
export function flowAuthzLinkOf(
    links: WorkflowPersonLinks,
): PersonLinkItem | null {
    return links.authz
        ? {
              label: '認可設定で見る',
              href: overview.url({ query: { type: 'workflow-flow' } }),
          }
        : null;
}
