// 入口の Worker の要求の扱いの表(docs の 設計/公開の配備/入口/ログイン.md 5.9.1)の行 6〜10(app への中継と入口の cookie が無効・無い要求)の単体テスト。
// 行 1〜5 は entry-table.test.mjs、入口の cookie の確かめ(5.9.3)は entry-cookie.test.mjs。
import assert from 'node:assert/strict';
import { test } from 'node:test';
import worker from './index.js';
import {
    assertNoStore,
    cookieStates,
    fakeApp,
    request,
} from './test-support.mjs';

await test('行 6: ほかの道は、入口の cookie が有効なら画面の通信とメソッドによらず app へ中継する', async () => {
    const app = fakeApp(() => new Response('from-app'));
    const { valid } = await cookieStates(app.env);

    for (const extra of [
        { 'X-Inertia': 'true' },
        { 'X-Requested-With': 'XMLHttpRequest' },
        {},
    ]) {
        for (const method of ['GET', 'POST']) {
            const response = await worker.fetch(
                request('/records', {
                    method,
                    headers: { ...valid, ...extra },
                }),
                app.env,
            );

            assert.equal(response.status, 200);
            assert.equal(await response.text(), 'from-app');
        }
    }
    assert.equal(app.received.length, 6);
});

await test('行 7: 入口の cookie が無効・無い Inertia の訪問は 409 と X-Inertia-Location(GET・HEAD だけ next つき)', async () => {
    const app = fakeApp(() => new Response('ok'));
    const { invalid, none } = await cookieStates(app.env);

    for (const headers of [invalid, none]) {
        for (const [method, location] of [
            ['GET', '/_entry/login?next=%2Frecords%3Fx%3D1'],
            ['HEAD', '/_entry/login?next=%2Frecords%3Fx%3D1'],
            ['POST', '/_entry/login'],
            ['PUT', '/_entry/login'],
        ]) {
            const response = await worker.fetch(
                request('/records?x=1', {
                    method,
                    headers: {
                        ...headers,
                        'X-Inertia': 'true',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                }),
                app.env,
            );

            assert.equal(response.status, 409, method);
            assertNoStore(response);
            assert.equal(
                response.headers.get('X-Inertia-Location'),
                location,
                method,
            );
            assert.equal(response.headers.get('Location'), null);
        }
    }
    assert.equal(app.received.length, 0);
});

await test('行 8: 入口の cookie が無効・無く X-Inertia が無い XMLHttpRequest の取得は、GET でも 401', async () => {
    const app = fakeApp(() => new Response('ok'));
    const { invalid, none } = await cookieStates(app.env);

    for (const headers of [invalid, none]) {
        for (const method of ['GET', 'POST']) {
            const response = await worker.fetch(
                request('/notifications', {
                    method,
                    headers: {
                        ...headers,
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                }),
                app.env,
            );

            assert.equal(response.status, 401, method);
            assertNoStore(response);
            assert.equal(response.headers.get('Location'), null);
            assert.notEqual(await response.text(), '');
        }
    }
    assert.equal(app.received.length, 0);
});

await test('行 9: 入口の cookie が無効・無いほかの GET・HEAD は 302 でログイン画面へ(X-Requested-With がほかの値でも)', async () => {
    const app = fakeApp(() => new Response('ok'));
    const { invalid, none } = await cookieStates(app.env);

    for (const headers of [invalid, none]) {
        for (const method of ['GET', 'HEAD']) {
            for (const extra of [{}, { 'X-Requested-With': 'fetch' }]) {
                const response = await worker.fetch(
                    request('/records?x=1', {
                        method,
                        headers: { ...headers, ...extra },
                    }),
                    app.env,
                );

                assert.equal(response.status, 302, method);
                assertNoStore(response);
                assert.equal(
                    response.headers.get('Location'),
                    '/_entry/login?next=%2Frecords%3Fx%3D1',
                );
            }
        }
    }
    assert.equal(app.received.length, 0);
});

await test('行 10: 入口の cookie が無効・無いほかのメソッドは 401(本文を読まない)', async () => {
    const app = fakeApp(() => new Response('ok'));
    const { invalid, none } = await cookieStates(app.env);

    for (const headers of [invalid, none]) {
        for (const method of ['POST', 'PUT', 'DELETE']) {
            const incoming = request('/records', {
                method,
                headers: { ...headers, 'X-Requested-With': 'fetch' },
                body: 'title=x',
            });

            const response = await worker.fetch(incoming, app.env);

            assert.equal(response.status, 401, method);
            assertNoStore(response);
            assert.equal(incoming.bodyUsed, false);
        }
    }
    assert.equal(app.received.length, 0);
});
