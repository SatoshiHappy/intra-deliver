import { describe, expect, it } from 'vite-plus/test';
import { isCurrentItem, pathOfHref } from '@/lib/Menu/currentItem';
import { item } from '@/lib/Menu/menuNodes.testing';

describe('pathOfHref', () => {
    it('クエリ・フラグメントを除き、区分を / でつないで先頭に / を付ける', () => {
        expect(pathOfHref('/workflow/cases')).toBe('/workflow/cases');
        expect(pathOfHref('/workflow/cases?tab=pending')).toBe(
            '/workflow/cases',
        );
        expect(pathOfHref('/workflow/cases#top')).toBe('/workflow/cases');
        expect(pathOfHref('/workflow/cases?a=1#x')).toBe('/workflow/cases');
    });

    it('空の区分を除く(末尾の / ・連続した /)', () => {
        expect(pathOfHref('/workflow/cases/')).toBe('/workflow/cases');
        expect(pathOfHref('//workflow///cases')).toBe('/workflow/cases');
        expect(pathOfHref('workflow/cases')).toBe('/workflow/cases');
    });

    it('スキームとホストが付いていても、パスだけを比べる形にする', () => {
        expect(pathOfHref('https://example.com/workflow/cases?x=1')).toBe(
            '/workflow/cases',
        );
    });

    it('パスが / だけなら / (区分が無い)', () => {
        expect(pathOfHref('/')).toBe('/');
        expect(pathOfHref('/?a=1')).toBe('/');
    });
});

describe('isCurrentItem', () => {
    const cases = item('cases', '/workflow/cases');

    it('currentItemPath と href のパスが同じアイテムに印が付く', () => {
        expect(isCurrentItem(cases, '/workflow/cases')).toBe(true);
    });

    it('href のクエリ・フラグメントは見ない(引数つきのアイテム)', () => {
        const withQuery = item('pending', '/workflow/cases?tab=pending#list');

        expect(isCurrentItem(withQuery, '/workflow/cases')).toBe(true);
    });

    it('同じパスのアイテムはどれにも付く(2 つの分類に属す業務モジュールなど)', () => {
        const a = item('a', '/records/data/project');
        const b = item('b', '/records/data/project');

        expect(
            [a, b].map((node) => isCurrentItem(node, '/records/data/project')),
        ).toEqual([true, true]);
    });

    it('パスが違うアイテム(上の階層・下の階層・別の画面)には付かない', () => {
        expect(
            isCurrentItem(item('home', '/workflow'), '/workflow/cases'),
        ).toBe(false);
        expect(
            isCurrentItem(
                item('deep', '/workflow/cases/42'),
                '/workflow/cases',
            ),
        ).toBe(false);
        expect(
            isCurrentItem(item('other', '/workflow/casesx'), '/workflow/cases'),
        ).toBe(false);
    });

    it('外部サイトのアイテムには付かない(パスが同じでも)', () => {
        const external = item('ext', 'https://example.com/workflow/cases', {
            isExternal: true,
        });

        expect(isCurrentItem(external, '/workflow/cases')).toBe(false);
    });

    it('currentItemPath が null なら、どのアイテムにも印が無い', () => {
        expect(isCurrentItem(cases, null)).toBe(false);
        expect(isCurrentItem(item('root', '/'), null)).toBe(false);
    });
});
