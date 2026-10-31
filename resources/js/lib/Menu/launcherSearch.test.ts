import { describe, expect, it } from 'vite-plus/test';
import { LAUNCHER_EMPTY_TEXT, searchLauncher } from '@/lib/Menu/launcherSearch';
import { folder, group, item } from '@/lib/Menu/menuNodes.testing';

const expense = item('wf-expense', '/workflow/expense', { name: '経費精算書' });
const workflow = group(
    'workflow',
    [
        item('wf-home', '/workflow', { name: 'ホーム' }),
        folder('wf-forms', [expense], { name: '申請書' }),
        item('wf-cases', '/workflow/cases', { name: '申請一覧' }),
    ],
    { name: 'ワークフロー' },
);
const business = group(
    'records',
    [
        item('rec-projects', '/records/data/project', { name: 'Project 案件' }),
        item('rec-noname', '/records/data/memo', { name: null }),
    ],
    { name: '業務' },
);
const admin = group(
    'admin',
    [item('adm-authz', '/authz/overview', { name: '認可設定' })],
    { name: '管理' },
);
const launcher = [workflow, business];

const itemCodes = (term: string) =>
    searchLauncher(launcher, term)?.screens.map(
        ({ item: found }) => found.code,
    );

describe('searchLauncher(照合と並び)', () => {
    it('アプリの名前の一致 = 名前に語を含むアプリが群「アプリ」に出る', () => {
        const result = searchLauncher(launcher, '業務');

        expect(result?.apps.map((app) => app.code)).toEqual(['records']);
    });

    it('画面の名前の一致 = 名前に語を含むアイテムが、属するアプリを添えて群「画面」に出る(フォルダの中も)', () => {
        const result = searchLauncher(launcher, '経費');

        expect(result?.apps).toEqual([]);
        expect(result?.screens).toEqual([{ item: expense, app: workflow }]);
    });

    it('アプリの名前の一致で、そのアプリの画面がすべて出る(木の順)', () => {
        expect(itemCodes('ワークフロー')).toEqual([
            'wf-home',
            'wf-expense',
            'wf-cases',
        ]);
    });

    it('表示名が無いアイテムは、メニュー ID で照合する(大文字小文字は問わない)', () => {
        expect(itemCodes('NONAME')).toEqual(['rec-noname']);
    });

    it('大文字と小文字を区別しない', () => {
        expect(itemCodes('PROJECT')).toEqual(['rec-projects']);
        expect(itemCodes('project')).toEqual(['rec-projects']);
    });

    it('語の前後の空白は除いて照合する', () => {
        expect(itemCodes('  申請一覧　')).toEqual(['wf-cases']);
    });
});

describe('searchLauncher(語の扱いと結果の形)', () => {
    it('空・空白だけの語は入れていない扱い(null。タイルを出す)', () => {
        expect(searchLauncher(launcher, '')).toBeNull();
        expect(searchLauncher(launcher, '   ')).toBeNull();
        expect(searchLauncher(launcher, '　 \t')).toBeNull();
    });

    it('当たるものが無ければ、2 つの群とも空(画面は 0 件の文を出す)', () => {
        expect(searchLauncher(launcher, '該当なし')).toEqual({
            apps: [],
            screens: [],
        });
        expect(LAUNCHER_EMPTY_TEXT).toBe('該当するアプリ・画面はありません');
    });

    it('歯車のアプリは対象に入れない(ランチャーのアプリだけを渡す)', () => {
        const navigation = { launcher, adminMenu: [admin] };

        expect(
            searchLauncher(navigation.launcher, '認可設定')?.screens,
        ).toEqual([]);
        expect(searchLauncher(navigation.launcher, '管理')?.apps).toEqual([]);
    });

    it('群の中の並び = アプリは launcher の並び、画面はアプリの並びの順にアプリの中は木の順', () => {
        const second = group('b', [item('b1', '/b/1'), item('b2', '/b/2')], {
            name: 'App B',
        });
        const first = group('a', [item('a1', '/a/1')], { name: 'App A' });

        const result = searchLauncher([second, first], 'app');

        expect(result?.apps.map((app) => app.code)).toEqual(['b', 'a']);
        expect(result?.screens.map(({ item: found }) => found.code)).toEqual([
            'b1',
            'b2',
            'a1',
        ]);
    });
});

describe('searchLauncher(同じ画面の重複)', () => {
    it('アプリの中で同じパスのアイテム(2 つの分類に属す業務モジュール)は、木の順で先の 1 行だけ出る', () => {
        const first = item('cat-a-project', '/records/data/project', {
            name: '案件',
        });
        const second = item('cat-b-project', '/records/data/project?x=1', {
            name: '案件',
        });
        const app = group(
            'records',
            [folder('a', [first]), folder('b', [second])],
            { name: '業務' },
        );

        const result = searchLauncher([app], '案件');

        expect(result?.screens).toEqual([{ item: first, app }]);
    });

    it('同じパスのアイテムは、語に当たるものを絞った後で重複を除く(名前が違い、後ろだけが当たる)', () => {
        const first = item('cat-a-project', '/records/data/project', {
            name: '案件',
        });
        const second = item('cat-b-project', '/records/data/project', {
            name: '商談',
        });
        const app = group(
            'records',
            [folder('a', [first]), folder('b', [second])],
            { name: '業務' },
        );

        expect(searchLauncher([app], '商談')?.screens).toEqual([
            { item: second, app },
        ]);
    });

    it('別のアプリの同じパスのアイテムは、それぞれのアプリの行として出る', () => {
        const a = group('a', [item('a1', '/same')], { name: 'App A' });
        const b = group('b', [item('b1', '/same')], { name: 'App B' });

        expect(
            searchLauncher([a, b], 'app')?.screens.map(({ app }) => app.code),
        ).toEqual(['a', 'b']);
    });
});
