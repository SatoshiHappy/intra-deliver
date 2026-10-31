import { describe, expect, it } from 'vite-plus/test';
import { folder, item } from '@/lib/Menu/menuNodes.testing';
import { folderContainsCurrent } from '@/lib/Menu/menuTree';

describe('folderContainsCurrent', () => {
    const current = item('current', '/a/b');
    const isCurrent = (node: { code: string }) => node.code === 'current';

    it('子孫(入れ子の中も)に現在地のアイテムがあれば真', () => {
        const tree = folder('outer', [
            item('x', '/x'),
            folder('inner', [current]),
        ]);

        expect(folderContainsCurrent(tree, isCurrent)).toBe(true);
    });

    it('現在地のアイテムが無ければ偽(空のフォルダを含む)', () => {
        expect(
            folderContainsCurrent(folder('f', [item('x', '/x')]), isCurrent),
        ).toBe(false);
        expect(folderContainsCurrent(folder('empty', []), isCurrent)).toBe(
            false,
        );
    });
});
