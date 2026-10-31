import { userChartUrl } from '@/lib/organizationChart';
import { overview } from '@/routes/authz';
import type { WorkflowLinkedPerson, WorkflowPersonLinks } from '@/types';

/** 名前の札の「…」の 1 項目(新しいタブで開く) */
type PersonLinkItem = { label: string; href: string };

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
