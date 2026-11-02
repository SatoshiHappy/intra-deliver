import type { Page, Route } from '@playwright/test';
import type { Artisan } from './test';
import { APPLY_URL } from './workflow-target-apply';

// 案件からの複製の E2E の手助け(見本の案件の id と状態を読み、開いた時の応答の props を書き換える)。

/** 見本の案件 WF-00000N の id */
export function caseIdOf(artisan: Artisan, number: number): string {
    const output = artisan([
        'tinker',
        '--execute',
        `echo App\\Models\\Workflow\\WorkflowCase::query()->where("case_number", ${number})->value("id");`,
    ]);
    const id = /(\d+)\s*$/.exec(output.trim())?.[1];

    if (id === undefined) {
        throw new Error(
            `WF-${String(number).padStart(6, '0')} の id を読めなかった: ${output}`,
        );
    }

    return id;
}

/** 案件の改訂番号・状態と、申請書のレコードの版の数(元の申請が変わらないことの確かめ) */
export function caseStateOf(artisan: Artisan, id: string): string {
    return artisan([
        'tinker',
        '--execute',
        [
            `$case = App\\Models\\Workflow\\WorkflowCase::query()->findOrFail(${id});`,
            '$revisions = App\\Models\\Records\\RecordRevision::query()',
            '->where("record_id", $case->record_id)->count();',
            'echo json_encode([$case->revision, $case->state, $revisions]);',
        ].join(''),
    ]).trim();
}

export type OpenedProps = {
    initial: {
        values: Record<string, unknown>;
        details: Record<string, { values: Record<string, unknown> }[]>;
    };
    targetField: string | null;
    targetValues: {
        values: Record<string, unknown>;
        details: Record<string, unknown>;
        clearedFields: unknown[];
    } | null;
    copy: { clearedFields: unknown[] } | null;
};

/** 最初の読み込みの HTML がページの props を持つ要素 */
const PAGE_DATA_OPEN = '<script data-page="app" type="application/json">';

/** サーバーと同じく、HTML に埋める JSON の < と > を逃がす(JSON_HEX_TAG) */
function hexTagged(json: string): string {
    return json.replaceAll('<', '\\u003C').replaceAll('>', '\\u003E');
}

/** 最初の読み込みの HTML の、ページの props を書き換える */
function rewrittenHtml(html: string, change: (props: OpenedProps) => void) {
    const start = html.indexOf(PAGE_DATA_OPEN) + PAGE_DATA_OPEN.length;
    const end = html.indexOf('</script>', start);

    if (start < PAGE_DATA_OPEN.length || end < 0) {
        throw new Error('ページの props を HTML に見つけられなかった');
    }

    const page = JSON.parse(html.slice(start, end));
    change(page.props as OpenedProps);

    return (
        html.slice(0, start) + hexTagged(JSON.stringify(page)) + html.slice(end)
    );
}

/**
 * クエリ copy の付いた申請の画面の GET の応答を書き換える(開いた後の GET には copy が無いので当たらない)。
 * 直に開く最初の読み込みは HTML、Inertia の移動は JSON で返る。
 */
export async function rewriteOpened(
    page: Page,
    change: (props: OpenedProps) => void,
) {
    await page.route(
        (url) => url.pathname === APPLY_URL && url.searchParams.has('copy'),
        async (route: Route) => {
            const response = await route.fetch();

            if (route.request().headers()['x-inertia'] !== undefined) {
                const body = await response.json();
                change(body.props as OpenedProps);
                await route.fulfill({ response, json: body });

                return;
            }

            const html = rewrittenHtml(await response.text(), change);
            await route.fulfill({ response, body: html });
        },
    );
}
