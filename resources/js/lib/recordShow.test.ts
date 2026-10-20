import { describe, expect, it } from 'vite-plus/test';
import {
    deleteRecordDescription,
    fileSizeText,
    fittingTabCount,
    isLinkableUrl,
    openPanelKey,
    revisionActorText,
    revisionOperation,
    selectedTabKey,
    urlWithQuery,
} from './recordShow';

const person = (id: number, label: string | null) => ({
    id,
    code: `u${id}`,
    label,
    affiliation: null,
});

describe('openPanelKey', () => {
    const keys = ['attachments', 'revisions', 'integration-notes'];

    it('帯の項目のキーだけを開いた欄にする', () => {
        expect(openPanelKey('/records/data/p/1?panel=revisions', keys)).toBe(
            'revisions',
        );
        expect(
            openPanelKey('/records/data/p/1?panel=integration-notes', keys),
        ).toBe('integration-notes');
    });

    it('帯に無いキー・無いクエリは閉じた形', () => {
        expect(openPanelKey('/records/data/p/1?panel=comments', keys)).toBe(
            null,
        );
        expect(openPanelKey('/records/data/p/1?panel=%3Cscript%3E', keys)).toBe(
            null,
        );
        expect(openPanelKey('/records/data/p/1', keys)).toBe(null);
    });
});

describe('selectedTabKey', () => {
    const keys = ['fields', 'details-milestones'];

    it('タブのキーだけを選び、ほかは詳細タブ', () => {
        expect(selectedTabKey('/x?tab=details-milestones', keys)).toBe(
            'details-milestones',
        );
        expect(selectedTabKey('/x?tab=related', keys)).toBe('fields');
        expect(selectedTabKey('/x', keys)).toBe('fields');
    });
});

describe('urlWithQuery', () => {
    it('ほかのクエリを保ったまま足す・替える・消す', () => {
        expect(
            urlWithQuery('/x?tab=fields&panel=revisions', { panel: null }),
        ).toBe('/x?tab=fields');
        expect(urlWithQuery('/x?panel=revisions', { tab: 'details-a' })).toBe(
            '/x?panel=revisions&tab=details-a',
        );
        expect(urlWithQuery('/x?tab=fields', { tab: 'details-a' })).toBe(
            '/x?tab=details-a',
        );
    });
});

describe('fittingTabCount', () => {
    it('全部が入れば全部', () => {
        expect(fittingTabCount([100, 100, 100], 300, 80)).toBe(3);
    });

    it('入らなければ「その他」の幅を残して先頭から', () => {
        expect(fittingTabCount([100, 100, 100], 299, 80)).toBe(2);
        expect(fittingTabCount([100, 100, 100], 250, 80)).toBe(1);
    });

    it('1 つも入らなくても 1 つは並べる', () => {
        expect(fittingTabCount([100, 100], 50, 80)).toBe(1);
    });
});

describe('fileSizeText', () => {
    it('1,024 ごとの単位で出す', () => {
        expect(fileSizeText(820)).toBe('820 B');
        expect(fileSizeText(59_392)).toBe('58 KB');
        expect(fileSizeText(1_468_006)).toBe('1.4 MB');
        expect(fileSizeText(2 * 1024 ** 3)).toBe('2.0 GB');
    });
});

describe('revisionActorText', () => {
    it('代理は「<実行者>(<代理元>さんの代理)」、ゲストは「ゲスト」、名称が無ければコード', () => {
        expect(
            revisionActorText({
                actor: person(1, '中村 美咲'),
                principal: person(2, '佐々木 陽太'),
            }),
        ).toBe('中村 美咲(佐々木 陽太さんの代理)');
        expect(revisionActorText({ actor: null, principal: null })).toBe(
            'ゲスト',
        );
        expect(
            revisionActorText({ actor: person(3, null), principal: null }),
        ).toBe('u3');
    });
});

describe('revisionOperation', () => {
    it('契機があればその語とステップ名、無ければ登録・編集', () => {
        expect(
            revisionOperation({
                origin: { operation: '承認', nodeName: '課長' },
                action: 'update',
            }),
        ).toEqual({ label: '承認', nodeName: '課長' });
        expect(revisionOperation({ origin: null, action: 'create' })).toEqual({
            label: '登録',
            nodeName: null,
        });
        expect(revisionOperation({ origin: null, action: 'update' })).toEqual({
            label: '編集',
            nodeName: null,
        });
    });
});

describe('deleteRecordDescription', () => {
    it('明細の項目が無ければ「明細・」を出さない', () => {
        expect(deleteRecordDescription('案件 A', true)).toBe(
            '案件 Aを削除しますか。明細・添付ファイル・コメント・更新履歴も一緒に削除されます。この操作は取り消せません。',
        );
        expect(deleteRecordDescription('案件 A', false)).toBe(
            '案件 Aを削除しますか。添付ファイル・コメント・更新履歴も一緒に削除されます。この操作は取り消せません。',
        );
    });
});

describe('isLinkableUrl', () => {
    it('アプリ内の絶対パスと http・https の URL(大文字・小文字を問わない)は通す', () => {
        for (const url of [
            '/records/data/x/1',
            '/a?b=c',
            'https://example.test/x',
            'HTTP://example.test/',
            '/',
        ]) {
            expect(isLinkableUrl(url), url).toBe(true);
        }
    });

    it('空・javascript:・data:・//host・\\ を含むもの・先頭の空白・改行・相対のパスは通さない', () => {
        for (const url of [
            '',
            'javascript:alert(1)',
            'JaVaScRiPt:x',
            'data:text/html,x',
            '//evil.test',
            '/\\evil.test',
            ' /records/data/x/1',
            '/records/data\n/x',
            'a/b',
            'java\tscript:alert(1)',
            '/records/data\u007f/x',
            '/records/data /x',
            'https://',
            'https://ok.test/a b',
            'mailto:a@example.test',
        ]) {
            expect(isLinkableUrl(url), JSON.stringify(url)).toBe(false);
        }
    });
});
