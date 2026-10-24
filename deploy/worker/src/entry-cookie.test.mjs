// 入口の cookie の確かめ(docs の 設計/公開の配備/入口/ログイン.md 5.9.3)の単体テスト。
// 要求の扱いの表は entry-table.test.mjs と entry-table-session.test.mjs、ログインは login.test.mjs、訪問者の ID は visitor-id.test.mjs。
import assert from 'node:assert/strict';
import { test } from 'node:test';
import worker from './index.js';
import {
    cookieParts,
    ENTRY_COOKIE,
    fakeApp,
    logIn,
    request,
    SECRETS,
} from './test-support.mjs';

await test('入口の cookie: 署名・ID・期限を変えたもの、形の崩れたもの、ほかの鍵で署名したものは無効', async () => {
    const app = fakeApp(() => new Response('ok'));
    const valid = await logIn(app.env);
    const { visitorId, expiresAt, signature } = cookieParts(valid);
    const flipped = signature.at(-1) === '0' ? '1' : '0';
    const otherKey = fakeApp(() => new Response('ok'), {
        ...SECRETS,
        ENTRY_SESSION_KEY: 'other-test-session-key',
    });
    const signedWithOtherKey = await logIn(otherKey.env);

    const invalidValues = [
        `${visitorId}.${expiresAt}.${signature.slice(0, -1)}${flipped}`,
        `0b9e8f3a-6c2d-4d1e-9a7b-3f5c1e2d4a6b.${expiresAt}.${signature}`,
        `${visitorId}.${expiresAt + 1}.${signature}`,
        `${visitorId}.${expiresAt}`,
        `${visitorId}.${expiresAt}.${signature}.extra`,
        `${visitorId}.${expiresAt}.${signature.toUpperCase()}`,
        `${visitorId}.0${expiresAt}.${signature}`,
        `"${visitorId}.${expiresAt}.${signature}"`,
        '',
        'garbage',
    ];
    for (const value of invalidValues) {
        const response = await worker.fetch(
            request('/records', {
                headers: { Cookie: `${ENTRY_COOKIE}=${value}` },
            }),
            app.env,
        );
        assert.equal(response.status, 302, value);
    }

    const response = await worker.fetch(
        request('/records', { headers: { Cookie: signedWithOtherKey } }),
        app.env,
    );
    assert.equal(response.status, 302);
    assert.equal(app.received.length, 0);
});

await test('入口の cookie: 名前は大文字と小文字を区別し、同じ名前が 2 つあれば最初のものだけを見る', async () => {
    const app = fakeApp(() => new Response('ok'));
    const valid = await logIn(app.env);
    const value = valid.slice(ENTRY_COOKIE.length + 1);

    const lower = await worker.fetch(
        request('/', { headers: { Cookie: `__host-entry=${value}` } }),
        app.env,
    );
    const invalidFirst = await worker.fetch(
        request('/', {
            headers: { Cookie: `${ENTRY_COOKIE}=garbage; ${valid}` },
        }),
        app.env,
    );
    const validFirst = await worker.fetch(
        request('/', {
            headers: { Cookie: `${valid}; ${ENTRY_COOKIE}=garbage` },
        }),
        app.env,
    );

    assert.equal(lower.status, 302);
    assert.equal(invalidFirst.status, 302);
    assert.equal(validFirst.status, 200);
});

await test('入口の cookie: 期限(ログイン + 30 日)の 1 ms 前までは有効で、期限と同じか過ぎたら無効(GET は 302・Inertia は 409・POST は 401)', async (t) => {
    const loggedInAt = 1_790_000_000_000;
    t.mock.timers.enable({ apis: ['Date'], now: loggedInAt });
    const app = fakeApp(() => new Response('ok'));
    const valid = await logIn(app.env);
    const { expiresAt } = cookieParts(valid);
    assert.equal(expiresAt, loggedInAt / 1000 + 30 * 24 * 60 * 60);

    for (const [now, expected] of [
        [expiresAt * 1000 - 1, [200, 200, 200]],
        [expiresAt * 1000, [302, 409, 401]],
        [expiresAt * 1000 + 1, [302, 409, 401]],
    ]) {
        t.mock.timers.setTime(now);
        const statuses = [];
        for (const init of [
            {},
            { headers: { 'X-Inertia': 'true' } },
            { method: 'POST' },
        ]) {
            const response = await worker.fetch(
                request('/records', {
                    ...init,
                    headers: { ...init.headers, Cookie: valid },
                }),
                app.env,
            );
            statuses.push(response.status);
        }
        assert.deepEqual(statuses, expected, String(now));
    }
});

await test('入口の cookie: 期限はログインの後に使っても延ばさない(中継の応答に Set-Cookie を足さない)', async () => {
    const app = fakeApp(() => new Response('ok'));
    const valid = await logIn(app.env);

    const response = await worker.fetch(
        request('/records', { headers: { Cookie: valid } }),
        app.env,
    );

    assert.equal(response.status, 200);
    assert.equal(response.headers.get('Set-Cookie'), null);
});
