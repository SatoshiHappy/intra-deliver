// 入口の Worker(index.js)の訪問者の ID の単体テスト(設計 = docs の 設計/公開の配備/入口/ログイン.md 5.9.6)。VPC サービスの binding は偽物に置き換える。
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { inspect } from 'node:util';
import worker from './index.js';
import { cookieParts, fakeApp, logIn, ORIGIN } from './test-support.mjs';

const UUID_PATTERN =
    /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

/**
 * console.log・error・warn を記録に替え、出た文を返す(ID の値が出ないかを見るため、引数を文字にしてつなぐ)。
 * @param {import('node:test').TestContext} t
 */
function captureLogs(t) {
    /** @type {Record<'log' | 'error' | 'warn', string[]>} */
    const logs = { log: [], error: [], warn: [] };
    for (const level of /** @type {const} */ (['log', 'error', 'warn'])) {
        t.mock.method(console, level, (/** @type {unknown[]} */ ...args) => {
            logs[level].push(args.map((arg) => inspect(arg)).join(' '));
        });
    }
    return logs;
}

/**
 * 入口の cookie を持ち、訪問者が X-Visitor-Id を付けた要求(本文は持たない。node の Request は本文の流れの中継に duplex の指定を求め、Workers と違うため)。
 * @param {string} method
 * @param {string} entry 入口の cookie の組
 */
function visitorRequest(method, entry) {
    return new Request(`${ORIGIN}/meetings`, {
        method,
        headers: { 'X-Visitor-Id': 'forged-by-the-visitor', Cookie: entry },
    });
}

await test('訪問者が付けた X-Visitor-Id を消し、GET・HEAD を含むすべての中継に入口の cookie の ID を付け、ID をログに出さない', async (t) => {
    const app = fakeApp(() => new Response('ok'));
    const entry = await logIn(app.env);
    const { visitorId } = cookieParts(entry);
    const logs = captureLogs(t);

    for (const method of ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE']) {
        const response = await worker.fetch(
            visitorRequest(method, entry),
            app.env,
        );

        assert.equal(response.status, 200, method);
        assert.equal(app.received.at(-1)?.method, method);
        assert.equal(
            app.received.at(-1)?.headers.get('X-Visitor-Id'),
            visitorId,
            method,
        );
    }
    assert.deepEqual(logs, { log: [], error: [], warn: [] });
});

await test('訪問者の ID は UUID の形で、ログインのたびに新しい値になる', async () => {
    const app = fakeApp(() => new Response('ok'));

    const first = cookieParts(await logIn(app.env)).visitorId;
    const second = cookieParts(await logIn(app.env)).visitorId;

    assert.match(first, UUID_PATTERN);
    assert.match(second, UUID_PATTERN);
    assert.notEqual(first, second);
});

await test('入口の cookie が無効な要求は、訪問者が付けた X-Visitor-Id ごと app へ渡さない', async () => {
    const app = fakeApp(() => new Response('ok'));

    const response = await worker.fetch(
        visitorRequest('GET', '__Host-entry=garbage'),
        app.env,
    );

    assert.equal(response.status, 302);
    assert.equal(app.received.length, 0);
});
