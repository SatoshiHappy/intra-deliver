// Worker の道(`/_entry/` で始まる)の要求と、ログイン画面への道。設計 = docs の 設計/公開の配備/入口/ログイン.md 5.9.1・5.9.3。
import { credentialsMatch, newSessionCookie } from './entry-session.js';
import { loginPage } from './login-page.js';
import { NO_STORE, plainText } from './responses.js';

const LOGIN_PATH = '/_entry/login';

// 戻り先(`next`)を持たせるメソッド(入口/ログイン.md 5.9.1)
export const METHODS_WITH_NEXT = new Set(['GET', 'HEAD']);

const LOGIN_PAGE_HEADERS = {
    ...NO_STORE,
    'Content-Type': 'text/html; charset=utf-8',
    'Content-Security-Policy':
        "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
    'X-Content-Type-Options': 'nosniff',
    'Referrer-Policy': 'no-referrer',
};

const NOT_FOUND_TEXT = 'ページが見つかりません。';
const METHOD_NOT_ALLOWED_TEXT = 'この操作は受け付けていません。';

/**
 * 確かめを通した戻り先の絶対 URL(origin まで含める理由 = 入口/ログイン.md 5.9.1 の `next`)。
 * @param {unknown} next
 * @param {string} origin
 * @returns {string}
 */
function safeNext(next, origin) {
    const fallback = `${origin}/`;
    if (typeof next !== 'string') {
        return fallback;
    }
    let resolved;
    try {
        resolved = new URL(next, origin);
    } catch {
        return fallback;
    }
    return resolved.origin === origin ? resolved.href : fallback;
}

/**
 * ログイン画面への道(`next` の付け方 = 入口/ログイン.md 5.9.1)。
 * @param {Request} request
 * @param {URL} url
 */
export function loginLocation(request, url) {
    if (!METHODS_WITH_NEXT.has(request.method)) {
        return LOGIN_PATH;
    }
    const query = new URLSearchParams({ next: `${url.pathname}${url.search}` });
    return `${LOGIN_PATH}?${query}`;
}

/**
 * @param {Request} request
 * @param {string} next
 * @param {boolean} failed
 * @returns {Response}
 */
function loginPageResponse(request, next, failed) {
    const body = request.method === 'HEAD' ? null : loginPage({ next, failed });
    return new Response(body, {
        status: failed ? 401 : 200,
        headers: LOGIN_PAGE_HEADERS,
    });
}

/**
 * ログインの POST(入口/ログイン.md 5.9.1・5.9.3)。
 * @param {Request} request
 * @param {URL} url
 * @param {import('./index.js').Secrets} secrets
 * @returns {Promise<Response>}
 */
async function login(request, url, secrets) {
    /** @type {FormData | null} */
    let form = null;
    try {
        form = await request.formData();
    } catch {
        // 入口/ログイン.md 5.9.3 の比べ方(形の読めない本文)
    }
    const next = safeNext(form?.get('next'), url.origin);
    const matches = await credentialsMatch(
        form?.get('id') ?? null,
        form?.get('password') ?? null,
        secrets,
    );
    if (!matches) {
        return loginPageResponse(request, next, true);
    }
    return new Response(null, {
        status: 303,
        headers: {
            ...NO_STORE,
            Location: next,
            'Set-Cookie': await newSessionCookie(secrets.sessionKey),
        },
    });
}

/**
 * Worker の道(`/_entry/` で始まる)の要求(入口/ログイン.md 5.9.1)。
 * @param {Request} request
 * @param {URL} url
 * @param {import('./index.js').Secrets} secrets
 * @returns {Promise<Response>}
 */
export async function entryRoute(request, url, secrets) {
    if (url.pathname !== LOGIN_PATH) {
        return plainText(NOT_FOUND_TEXT, 404);
    }
    if (METHODS_WITH_NEXT.has(request.method)) {
        const next = safeNext(url.searchParams.get('next'), url.origin);
        return loginPageResponse(request, next, false);
    }
    if (request.method === 'POST') {
        return login(request, url, secrets);
    }
    return plainText(METHOD_NOT_ALLOWED_TEXT, 405, {
        Allow: 'GET, HEAD, POST',
    });
}
