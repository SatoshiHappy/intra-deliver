import type { Page, Route } from '@playwright/test';

// 開いた時の GET の応答のページの props を書き換える手助け(サーバーの確かめは Feature テストが持つので、画面の描画の筋は応答を書き換えて作る)。

/** 最初の読み込みの HTML がページの props を持つ要素 */
const PAGE_DATA_OPEN = '<script data-page="app" type="application/json">';

/** サーバーと同じく、HTML に埋める JSON の < と > を逃がす(JSON_HEX_TAG) */
function hexTagged(json: string): string {
    return json.replaceAll('<', '\\u003C').replaceAll('>', '\\u003E');
}

/** 最初の読み込みの HTML の、ページの props を書き換える */
function rewrittenHtml<Props>(html: string, change: (props: Props) => void) {
    const start = html.indexOf(PAGE_DATA_OPEN) + PAGE_DATA_OPEN.length;
    const end = html.indexOf('</script>', start);

    if (start < PAGE_DATA_OPEN.length || end < 0) {
        throw new Error('ページの props を HTML に見つけられなかった');
    }

    const page = JSON.parse(html.slice(start, end));
    change(page.props as Props);

    return (
        html.slice(0, start) + hexTagged(JSON.stringify(page)) + html.slice(end)
    );
}

/**
 * matches に当たる URL の GET の応答の props を書き換える。直に開く最初の読み込みは HTML、Inertia の移動は JSON で返る。
 */
export async function rewriteProps<Props>(
    page: Page,
    matches: (url: URL) => boolean,
    change: (props: Props) => void,
) {
    await page.route(matches, async (route: Route) => {
        if (route.request().method() !== 'GET') {
            await route.fallback();

            return;
        }

        const response = await route.fetch();

        if (route.request().headers()['x-inertia'] !== undefined) {
            const body = await response.json();
            change(body.props as Props);
            await route.fulfill({ response, json: body });

            return;
        }

        const html = rewrittenHtml(await response.text(), change);
        await route.fulfill({ response, body: html });
    });
}
