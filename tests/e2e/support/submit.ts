import { expect } from '@playwright/test';
import type { Locator, Page, Request } from '@playwright/test';

// E2E のサーバーは 1 本で、負荷が高いと送信と移り先の GET の合計が既定の 5 秒を超える。送信と関係ない検査まで遅くならないよう、expect.timeout は上げずこの部品に閉じて延ばす。
const SUBMIT_WAIT_MS = 15_000;

function isSubmission(request: Request): boolean {
    return request.method() !== 'GET';
}

// 送信の 303 でなく移り先の GET の応答まで待つため、応答を鎖の元の要求で見分ける。
function originOf(request: Request): Request {
    let current = request;
    let previous = current.redirectedFrom();
    while (previous !== null) {
        current = previous;
        previous = current.redirectedFrom();
    }
    return current;
}

export async function submitAndExpectUrl(
    page: Page,
    button: Locator,
    url?: string | RegExp,
): Promise<void> {
    const startedAt = Date.now();
    const elapsed = () => `${Date.now() - startedAt}ms`;
    const remaining = () =>
        Math.max(SUBMIT_WAIT_MS - (Date.now() - startedAt), 1);

    const sent = page
        .waitForRequest(isSubmission, { timeout: SUBMIT_WAIT_MS })
        .catch((error: unknown) => {
            throw new Error(
                `送信の要求(GET でない要求)が押してから ${elapsed()} 経っても出なかった`,
                { cause: error },
            );
        });
    const last = page
        .waitForResponse(
            async (response) => {
                const first = await sent.catch(() => null);
                return (
                    first !== null &&
                    originOf(response.request()) === first &&
                    (response.status() < 300 || response.status() >= 400)
                );
            },
            { timeout: SUBMIT_WAIT_MS },
        )
        .catch((error: unknown) => {
            throw new Error(
                `送信のリダイレクトの鎖の最後の応答が押してから ${elapsed()} 経っても来なかった`,
                { cause: error },
            );
        });
    const [request, response] = await Promise.all([sent, last, button.click()]);
    const label = `${request.method()} ${request.url()}`;

    if (response.status() >= 400) {
        throw new Error(
            `送信の鎖の最後の応答が ${response.status()} だった(応答 = ${response.request().method()} ${response.url()}、送信 = ${label})`,
        );
    }
    if (url === undefined) {
        return;
    }
    try {
        await expect(page).toHaveURL(url, { timeout: remaining() });
    } catch (error) {
        throw new Error(
            `送信の応答(${response.status()})は来たが、URL が押してから ${elapsed()} 経っても移らなかった(${label})`,
            { cause: error },
        );
    }
}
