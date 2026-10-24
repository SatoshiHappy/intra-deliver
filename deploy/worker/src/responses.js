// Worker が自分で返す応答(キャッシュさせない文字列と案内ページ)。設計 = docs の 設計/公開の配備/入口/ログイン.md 5.9.1、案内ページは 入口/README.md 5.3。
import { UNAVAILABLE_PAGE } from './unavailable-page.js';

// Worker が返す応答はどれもキャッシュさせない(入口/ログイン.md 5.9.1)
export const NO_STORE = { 'Cache-Control': 'no-store' };

/**
 * @param {string} text
 * @param {number} status
 * @param {Record<string, string>} [headers]
 * @returns {Response}
 */
export function plainText(text, status, headers = {}) {
    return new Response(text, {
        status,
        headers: {
            ...NO_STORE,
            'Content-Type': 'text/plain; charset=utf-8',
            ...headers,
        },
    });
}

/** @returns {Response} */
export function unavailable() {
    return new Response(UNAVAILABLE_PAGE, {
        status: 503,
        headers: {
            ...NO_STORE,
            'Content-Type': 'text/html; charset=utf-8',
        },
    });
}
