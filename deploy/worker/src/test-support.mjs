// 入口の Worker の単体テストが共有する偽物と道具(テストのファイルではない。node --test の対象の *.test.mjs に合わない名前)。
import assert from 'node:assert/strict';
import { timingSafeEqual } from 'node:crypto';
import worker from './index.js';

// Node の Web Crypto に無い Workers の拡張を、同じ働き(長さが違えば例外、等しければ真)の node:crypto で替える。
// テストが呼ばれ方を見られるよう、置いたものを差し替えずに使う。
if (typeof crypto.subtle.timingSafeEqual !== 'function') {
    crypto.subtle.timingSafeEqual = (a, b) =>
        timingSafeEqual(toBytes(a), toBytes(b));
}

/** @param {ArrayBuffer | ArrayBufferView} value */
function toBytes(value) {
    return ArrayBuffer.isView(value)
        ? new Uint8Array(value.buffer, value.byteOffset, value.byteLength)
        : new Uint8Array(value);
}

export const ORIGIN = 'https://intra-deliver.example.workers.dev';

// テストのための固定の偽の値
export const SECRETS = {
    ENTRY_ID: 'test-visitor',
    ENTRY_PASSWORD: 'Fake-Pass-2345-abcd',
    ENTRY_SESSION_KEY: 'test-session-key-not-secret',
};

export const ENTRY_COOKIE = '__Host-entry';

/**
 * 受け取った要求を記録し、決めた応答(か例外)を返す偽の binding と、入口の secret のそろった env。
 * @param {(request: Request) => Response | Promise<Response>} respond
 * @param {Record<string, string | undefined>} [secrets]
 */
export function fakeApp(respond, secrets = SECRETS) {
    /** @type {Request[]} */
    const received = [];
    return {
        received,
        env: {
            ...secrets,
            APP: {
                fetch: async (/** @type {Request} */ request) => {
                    received.push(request);
                    return respond(request);
                },
            },
        },
    };
}

/**
 * ログインの POST の要求(本文は form)。
 * @param {Record<string, string>} fields
 */
export function loginRequest(fields) {
    return new Request(`${ORIGIN}/_entry/login`, {
        method: 'POST',
        body: new URLSearchParams(fields),
    });
}

/**
 * 正しい ID とパスワードでログインし、入口の cookie の組(`__Host-entry=…`)を返す。
 * @param {object} env
 * @param {Record<string, string>} [extra]
 */
export async function logIn(env, extra = {}) {
    const response = await worker.fetch(
        loginRequest({
            id: SECRETS.ENTRY_ID,
            password: SECRETS.ENTRY_PASSWORD,
            ...extra,
        }),
        env,
    );
    const setCookie = response.headers.get('Set-Cookie');
    if (response.status !== 303 || setCookie === null) {
        throw new Error(`ログインに合いませんでした(${response.status})`);
    }
    return setCookie.split(';')[0];
}

/**
 * 入口の cookie の組から値の 3 つの部分(訪問者の ID・期限・署名)を取る。
 * @param {string} pair
 */
export function cookieParts(pair) {
    const [visitorId, expiresAt, signature] = pair
        .slice(ENTRY_COOKIE.length + 1)
        .split('.');
    return { visitorId, expiresAt: Number(expiresAt), signature };
}

/**
 * ORIGIN の下の道への要求。
 * @param {string} path
 * @param {RequestInit & { headers?: Record<string, string> }} [init]
 */
export function request(path, init = {}) {
    return new Request(`${ORIGIN}${path}`, init);
}

/** @param {Response} response */
export function assertNoStore(response) {
    assert.equal(response.headers.get('Cache-Control'), 'no-store');
}

/**
 * 入口の cookie の 3 つの状態(有効・無効 = 署名を変えた・無い)の Cookie ヘッダー。
 * @param {object} env
 */
export async function cookieStates(env) {
    const valid = await logIn(env);
    const last = valid.at(-1) === '0' ? '1' : '0';
    return {
        valid: { Cookie: valid },
        invalid: { Cookie: `${valid.slice(0, -1)}${last}` },
        none: {},
    };
}

/**
 * ログイン画面のフォームが持つ `next` の値(HTML の文字参照を戻す)。
 * @param {string} html
 */
export function hiddenNext(html) {
    const match = html.match(
        /<input type="hidden" name="next" value="([^"]*)">/,
    );
    assert.ok(match, 'next の欄が無い');
    return match[1]
        .replaceAll('&quot;', '"')
        .replaceAll('&#39;', "'")
        .replaceAll('&lt;', '<')
        .replaceAll('&gt;', '>')
        .replaceAll('&amp;', '&');
}

/** @param {string} path */
export function getLoginPage(path) {
    return new Request(`${ORIGIN}${path}`);
}
