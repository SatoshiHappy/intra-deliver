import { nameOrCode } from '@/lib/nameOrCode';
import type { MenuGroupNode, MenuNode } from '@/types';

/** サイドバーの区画 1 つ。heading = 見出し(見出しなしの区画は null)、nodes = 区画の中、key = 描画のキー */
export type SidebarSection = {
    key: string;
    heading: string | null;
    nodes: MenuNode[];
};

/**
 * 今のアプリの木を区画に分ける。直下のフォルダは見出し付きの区画、続いて並ぶ直下のアイテムは見出しなしの区画で、
 * 並びの順のまま作る(見出しの区画の後のアイテムは見出しの中身に入らない)。規則は docs の 設計/メニュー/表示.md 3.3.3。
 */
export function sidebarSections(app: MenuGroupNode): SidebarSection[] {
    const sections: SidebarSection[] = [];

    for (const node of app.children) {
        if (node.type === 'folder') {
            sections.push({
                key: node.code,
                heading: nameOrCode(node),
                nodes: node.children,
            });

            continue;
        }

        const last = sections.at(-1);

        if (last !== undefined && last.heading === null) {
            last.nodes.push(node);
        } else {
            sections.push({
                key: `items:${node.code}`,
                heading: null,
                nodes: [node],
            });
        }
    }

    return sections;
}
