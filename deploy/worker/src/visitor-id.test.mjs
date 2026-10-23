// 入口の Worker(index.js)の訪問者の ID の単体テスト(設計 = docs の 設計/公開の配備/入口/Access.md 5.9.6)。VPC サービスの binding は偽物に置き換える。
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { inspect } from 'node:util';
import worker from './index.js';
import { fakeApp } from './test-support.mjs';

const VISITOR_ID = '0b9e8f3a-6c2d-4d1e-9a7b-3f5c1e2d4a6b';

/**
 * getIdentity を差し替えた、Access が通した要求の ctx。呼ばれた回数を数える。
 * @param {() => Promise<object | undefined>} getIdentity
 */
function accessedWith(getIdentity) {
    const calls = { count: 0 };
    return {
        calls,
        ctx: {
            access: {
                aud: 'test-aud',
                getIdentity: () => {
                    calls.count += 1;
                    return getIdentity();
                },
            },
        },
    };
}

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
 * @param {Record<'log' | 'error' | 'warn', string[]>} logs
 */
function assertNoVisitorIdIn(logs) {
    for (const line of [...logs.log, ...logs.error, ...logs.warn]) {
        assert.equal(line.includes(VISITOR_ID), false, line);
    }
}

/**
 * 訪問者が X-Visitor-Id を付けた要求(本文は持たない。node の Request は本文の流れの中継に duplex の指定を求め、Workers と違うため)。
 * @param {string} method
 */
function visitorRequest(method) {
    return new Request('https://intra-deliver.example.workers.dev/meetings', {
        method,
        headers: { 'X-Visitor-Id': 'forged-by-the-visitor' },
    });
}

await test('訪問者が付けた X-Visitor-Id は GET でも消し、GET と HEAD では getIdentity() を呼ばない', async (t) => {
    const logs = captureLogs(t);
    for (const method of ['GET', 'HEAD']) {
        const app = fakeApp(() => new Response('ok'));
        const access = accessedWith(async () => ({ user_uuid: VISITOR_ID }));

        const response = await worker.fetch(
            visitorRequest(method),
            app.env,
            access.ctx,
        );

        assert.equal(response.status, 200);
        assert.equal(app.received[0].headers.get('X-Visitor-Id'), null);
        assert.equal(access.calls.count, 0, method);
    }
    assert.deepEqual(logs, { log: [], error: [], warn: [] });
});

await test('POST で user_uuid が取れたら、訪問者が付けた値を消してその値を置き、待ちの時間だけをログに出す', async (t) => {
    const logs = captureLogs(t);
    const app = fakeApp(() => new Response('ok'));
    const access = accessedWith(async () => ({
        user_uuid: VISITOR_ID,
        email: 'recruiter@example.com',
    }));

    const response = await worker.fetch(
        visitorRequest('POST'),
        app.env,
        access.ctx,
    );

    assert.equal(response.status, 200);
    assert.equal(access.calls.count, 1);
    assert.equal(app.received[0].headers.get('X-Visitor-Id'), VISITOR_ID);
    assert.equal(logs.log.length, 1);
    assert.match(logs.log[0], /\d+ ms/);
    assert.deepEqual([logs.error, logs.warn], [[], []]);
    assertNoVisitorIdIn(logs);
});

await test('getIdentity() が例外のときは、付けずに中継してエラーのログを出す', async (t) => {
    const logs = captureLogs(t);
    const app = fakeApp(() => new Response('ok'));
    const access = accessedWith(async () => {
        throw new Error('access unavailable');
    });

    const response = await worker.fetch(
        visitorRequest('POST'),
        app.env,
        access.ctx,
    );

    assert.equal(response.status, 200);
    assert.equal(app.received[0].headers.get('X-Visitor-Id'), null);
    assert.equal(logs.error.length, 1);
    assert.equal(logs.log.length, 1);
});

await test('getIdentity() が待ちの上限(2,000 ms)を超えたら、付けずに中継してエラーのログを出す', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const logs = captureLogs(t);
    const app = fakeApp(() => new Response('ok'));
    const access = accessedWith(() => new Promise(() => {}));

    const pending = worker.fetch(visitorRequest('POST'), app.env, access.ctx);
    // 上限の直前までは中継しない(上限が短ければ中継まで進み切るよう、マイクロタスクを十分に回す)
    t.mock.timers.tick(1999);
    for (let i = 0; i < 20; i++) {
        await Promise.resolve();
    }
    assert.equal(app.received.length, 0);
    t.mock.timers.tick(1);
    const response = await pending;

    assert.equal(response.status, 200);
    assert.equal(app.received[0].headers.get('X-Visitor-Id'), null);
    assert.equal(logs.error.length, 1);
    assert.match(logs.error[0], /2000 ms/);
});

await test('サービストークンでない身元で user_uuid が空なら、付けずに中継して warn のログを出す', async (t) => {
    for (const identity of [
        { user_uuid: '' },
        { service_token_status: false },
        undefined,
    ]) {
        const logs = captureLogs(t);
        const app = fakeApp(() => new Response('ok'));
        const access = accessedWith(async () => identity);

        const response = await worker.fetch(
            visitorRequest('POST'),
            app.env,
            access.ctx,
        );

        assert.equal(response.status, 200);
        assert.equal(app.received[0].headers.get('X-Visitor-Id'), null);
        assert.deepEqual(
            [logs.warn.length, logs.error.length],
            [1, 0],
            JSON.stringify(identity),
        );
        t.mock.restoreAll();
    }
});

await test('サービストークンの身元(service_token_status が真)なら、付けずに中継して warn も error も出さない', async (t) => {
    const logs = captureLogs(t);
    const app = fakeApp(() => new Response('ok'));
    const access = accessedWith(async () => ({
        user_uuid: '',
        service_token_status: true,
    }));

    const response = await worker.fetch(
        visitorRequest('POST'),
        app.env,
        access.ctx,
    );

    assert.equal(response.status, 200);
    assert.equal(app.received[0].headers.get('X-Visitor-Id'), null);
    assert.deepEqual([logs.warn, logs.error], [[], []]);
});
