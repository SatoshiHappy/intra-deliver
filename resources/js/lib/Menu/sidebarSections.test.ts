import { describe, expect, it } from 'vite-plus/test';
import { folder, group, item } from '@/lib/Menu/menuNodes.testing';
import { sidebarSections } from '@/lib/Menu/sidebarSections';

describe('sidebarSections(区画の分け方)', () => {
    it('直下のフォルダが、見出し(フォルダの表示名)の区画になり、中身はフォルダの子になる', () => {
        const inner = [item('apply', '/workflow/apply')];
        const app = group('workflow', [
            folder('approvals', inner, { name: '申請と承認' }),
        ]);

        expect(sidebarSections(app)).toEqual([
            { key: 'approvals', heading: '申請と承認', nodes: inner },
        ]);
    });

    it('表示名が無いフォルダの見出しはメニュー ID を出す', () => {
        const app = group('app', [folder('forms', [], { name: null })]);

        expect(sidebarSections(app)[0].heading).toBe('forms');
    });

    it('見出しの区画の後に続く直下のアイテムは、新しい見出しなしの区画になる(見出しの中身に入らない)', () => {
        const app = group('app', [
            item('home', '/app'),
            folder('heading-a', [item('a1', '/app/a1')]),
            item('tail-1', '/app/t1'),
            item('tail-2', '/app/t2'),
            folder('heading-b', [item('b1', '/app/b1')]),
        ]);

        const sections = sidebarSections(app);

        expect(
            sections.map((section) => [
                section.heading,
                section.nodes.map((node) => node.code),
            ]),
        ).toEqual([
            [null, ['home']],
            ['heading-a', ['a1']],
            [null, ['tail-1', 'tail-2']],
            ['heading-b', ['b1']],
        ]);
    });
});

describe('sidebarSections(アイテムだけ・空・キー)', () => {
    it('直下のアイテムだけのアプリは、見出しなしの区画 1 つになる', () => {
        const app = group('app', [
            item('one', '/app/1'),
            item('two', '/app/2'),
        ]);

        const sections = sidebarSections(app);

        expect(sections).toHaveLength(1);
        expect(sections[0].heading).toBeNull();
        expect(sections[0].nodes.map((node) => node.code)).toEqual([
            'one',
            'two',
        ]);
    });

    it('空のアプリは区画が無い', () => {
        expect(sidebarSections(group('empty', []))).toEqual([]);
    });

    it('区画のキーは重ならない(見出しのフォルダと見出しなしの区画)', () => {
        const app = group('app', [
            item('x', '/app/x'),
            folder('f', [item('y', '/app/y')]),
            item('z', '/app/z'),
        ]);

        const keys = sidebarSections(app).map((section) => section.key);

        expect(new Set(keys).size).toBe(keys.length);
    });

    it('見出しの区画の後の見出しなしの区画に足しても、元のフォルダの子の配列を変えない', () => {
        const children = [item('a1', '/app/a1')];
        const app = group('app', [
            folder('heading', children),
            item('tail', '/app/tail'),
        ]);

        sidebarSections(app);

        expect(children.map((node) => node.code)).toEqual(['a1']);
    });
});
