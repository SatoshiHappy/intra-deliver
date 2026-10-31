import { describe, expect, it } from 'vite-plus/test';
import {
    appBreadcrumbs,
    appTiles,
    homeAppTiles,
    launcherPanel,
    showAdminMenu,
    sidebarView,
} from '@/lib/Menu/appNavigationView';
import { folder, group, item, navigation } from '@/lib/Menu/menuNodes.testing';

const workflow = group(
    'workflow',
    [
        item('wf-home', '/workflow'),
        folder('wf-approvals', [item('wf-cases', '/workflow/cases')]),
    ],
    { name: 'ワークフロー', icon: 'send' },
);
const records = group('records', [item('rec', '/records/data/x')], {
    name: '業務',
});
const admin = group('admin', [item('adm', '/workflow/admin/flows')], {
    name: '管理',
});
const page = [{ title: '申請一覧', href: '/workflow/cases' }];

describe('sidebarView(今のアプリがある)', () => {
    it('今のアプリがあるとき、アプリの見出しと区画を出す(ランチャーのアプリ)', () => {
        const view = sidebarView(
            navigation({
                launcher: [workflow, records],
                adminMenu: [admin],
                currentApp: 'workflow',
            }),
        );

        expect(view.app).toBe(workflow);
        expect(
            view.sections.map((section) => [section.heading, section.key]),
        ).toEqual([
            [null, 'items:wf-home'],
            ['wf-approvals', 'wf-approvals'],
        ]);
    });

    it('今のアプリが歯車のアプリでも、そのアプリの見出しと区画を出す', () => {
        const view = sidebarView(
            navigation({
                launcher: [workflow],
                adminMenu: [admin],
                currentApp: 'admin',
            }),
        );

        expect(view.app).toBe(admin);
        expect(view.sections).toHaveLength(1);
    });
});

describe('sidebarView(今のアプリが無い)', () => {
    it('今のアプリが無いとき、見出し「アプリ」と一覧の区画 1 つ(歯車のアプリは出ない)', () => {
        const view = sidebarView(
            navigation({ launcher: [workflow, records], adminMenu: [admin] }),
        );

        expect(view.app).toBeNull();
        expect(view.sections).toHaveLength(1);
        expect(view.sections[0].heading).toBe('アプリ');
        expect(view.sections[0].nodes).toEqual([
            expect.objectContaining({
                code: 'workflow',
                type: 'item',
                name: 'ワークフロー',
                icon: 'send',
                href: '/workflow',
                isExternal: false,
            }),
            expect.objectContaining({ code: 'records', href: '/records' }),
        ]);
    });

    it('アプリの一覧の行は、アプリの先頭の画面(entry)へ移り、外部サイトならその印を持つ', () => {
        const external = group(
            'ext',
            [item('e', 'https://example.com/x', { isExternal: true })],
            {
                entry: { href: 'https://example.com/x', isExternal: true },
            },
        );

        const node = sidebarView(navigation({ launcher: [external] }))
            .sections[0].nodes[0];

        expect(node).toMatchObject({
            href: 'https://example.com/x',
            isExternal: true,
        });
    });
});

describe('sidebarView(一覧と見出しが出ない場合)', () => {
    it('ランチャーのアプリが 1 つも無く今のアプリも無いとき、見出し「アプリ」も区画も出さない', () => {
        const view = sidebarView(navigation({ adminMenu: [admin] }));

        expect(view).toEqual({ app: null, sections: [] });
    });

    it('今のアプリのコードが見つからないときは、今のアプリが無い扱い(一覧)', () => {
        const view = sidebarView(
            navigation({ launcher: [records], currentApp: 'missing' }),
        );

        expect(view.app).toBeNull();
        expect(view.sections[0].heading).toBe('アプリ');
    });

    it('空のアプリが今のアプリなら、見出しだけで区画は無い', () => {
        const empty = group('empty', []);

        expect(
            sidebarView(navigation({ launcher: [empty], currentApp: 'empty' })),
        ).toEqual({ app: empty, sections: [] });
    });
});

describe('appTiles(ランチャーのタイル)', () => {
    it('ランチャーのアプリが並びの順にタイルになり、今のアプリのタイルだけに「現在のアプリ」の印(isCurrent)', () => {
        const tiles = appTiles(
            navigation({
                launcher: [workflow, records],
                adminMenu: [admin],
                currentApp: 'records',
            }),
        );

        expect(
            tiles.map(({ app, isCurrent }) => [app.code, isCurrent]),
        ).toEqual([
            ['workflow', false],
            ['records', true],
        ]);
    });

    it('今のアプリが歯車のアプリのとき、どのタイルにも印は付かない', () => {
        const tiles = appTiles(
            navigation({
                launcher: [workflow],
                adminMenu: [admin],
                currentApp: 'admin',
            }),
        );

        expect(tiles.map((tile) => tile.isCurrent)).toEqual([false]);
    });
});

describe('launcherPanel・homeAppTiles', () => {
    it('ランチャーのアプリが 0 のとき、パネルにタイルが無い(検索欄とサイトマップだけ)', () => {
        expect(
            launcherPanel(
                navigation({ adminMenu: [admin], showSitemap: true }),
            ),
        ).toEqual({ tiles: [], showSitemap: true });
    });

    it('showSitemap が偽のとき、パネルの下端のサイトマップを出さない', () => {
        const panel = launcherPanel(
            navigation({ launcher: [workflow], showSitemap: false }),
        );

        expect(panel.showSitemap).toBe(false);
        expect(panel.tiles).toHaveLength(1);
    });

    it('全体のホームのタイルの面は、ランチャーのアプリが 0 のとき null(見出し「アプリ」も出さない)', () => {
        expect(homeAppTiles(navigation({ adminMenu: [admin] }))).toBeNull();
    });

    it('全体のホームのタイルは、ランチャーのアプリだけ(歯車のアプリは出ない)', () => {
        const tiles = homeAppTiles(
            navigation({ launcher: [workflow, records], adminMenu: [admin] }),
        );

        expect(tiles?.map((tile) => tile.app.code)).toEqual([
            'workflow',
            'records',
        ]);
    });
});

describe('showAdminMenu', () => {
    it('歯車のアプリが 0 のとき歯車を出さない(場所を空けない)', () => {
        expect(showAdminMenu(navigation({ launcher: [workflow] }))).toBe(false);
    });

    it('歯車のアプリが 1 つ以上のとき歯車を出す', () => {
        expect(showAdminMenu(navigation({ adminMenu: [admin] }))).toBe(true);
    });
});

describe('appBreadcrumbs', () => {
    it('今のアプリがあるとき、アプリの段(先頭の画面へのリンク)→ ページの段', () => {
        expect(
            appBreadcrumbs(
                navigation({ launcher: [workflow], currentApp: 'workflow' }),
                page,
            ),
        ).toEqual([
            { title: 'ワークフロー', href: '/workflow', isExternal: false },
            ...page,
        ]);
    });

    it('歯車のアプリが今のアプリでも、アプリの段を置く', () => {
        const crumbs = appBreadcrumbs(
            navigation({ adminMenu: [admin], currentApp: 'admin' }),
            page,
        );

        expect(crumbs.map((crumb) => crumb.title)).toEqual([
            '管理',
            '申請一覧',
        ]);
    });
});

describe('appBreadcrumbs(アプリの段の形)', () => {
    it('ページの段が空なら、アプリの段が最後の段になりリンクにしない', () => {
        expect(
            appBreadcrumbs(
                navigation({ launcher: [workflow], currentApp: 'workflow' }),
                [],
            ),
        ).toEqual([{ title: 'ワークフロー', href: null }]);
    });

    it('今のアプリが無いとき、ページの段だけ', () => {
        expect(appBreadcrumbs(navigation({ launcher: [workflow] }), page)).toBe(
            page,
        );
    });

    it('先頭の画面が外部サイトなら、アプリの段はその印を持つ', () => {
        const external = group('ext', [], {
            entry: { href: 'https://example.com/', isExternal: true },
        });

        expect(
            appBreadcrumbs(
                navigation({ launcher: [external], currentApp: 'ext' }),
                page,
            )[0],
        ).toEqual({
            title: 'ext',
            href: 'https://example.com/',
            isExternal: true,
        });
    });
});
