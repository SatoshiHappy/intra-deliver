// 入口のログイン画面・ログインの POST の単体テスト(docs の 設計/公開の配備/入口/ログイン.md 5.9.1・5.9.2・5.9.3)。
// 戻り先(`next`)は next.test.mjs。
import assert from 'node:assert/strict';
import { test } from 'node:test';
import worker from './index.js';
import {
    fakeApp,
    getLoginPage,
    hiddenNext,
    loginRequest,
    ORIGIN,
    SECRETS,
} from './test-support.mjs';

const NOTICE = '公開デモのデータは、毎日 4:00 に初期データへ戻ります。';

const ERROR_TEXT = 'ID かパスワードが違います。';

/** @param {Response} response */
function assertLoginPageHeaders(response) {
    assert.equal(
        response.headers.get('Content-Type'),
        'text/html; charset=utf-8',
    );
    assert.equal(
        response.headers.get('Content-Security-Policy'),
        "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
    );
    assert.equal(response.headers.get('X-Content-Type-Options'), 'nosniff');
    assert.equal(response.headers.get('Referrer-Policy'), 'no-referrer');
    assert.equal(response.headers.get('Cache-Control'), 'no-store');
}

/**
 * 合わない ID・パスワードの組と、欄の欠けた・form でない本文のログインの要求(ラベルつき)。
 * @returns {[string, Request][]}
 */
function rejectedLoginRequests() {
    return [
        [
            'ID だけ違う',
            loginRequest({ id: 'other', password: SECRETS.ENTRY_PASSWORD }),
        ],
        [
            'パスワードだけ違う',
            loginRequest({ id: SECRETS.ENTRY_ID, password: 'other' }),
        ],
        ['両方違う', loginRequest({ id: 'other', password: 'other' })],
        ['ID の欄が無い', loginRequest({ password: SECRETS.ENTRY_PASSWORD })],
        ['パスワードの欄が無い', loginRequest({ id: SECRETS.ENTRY_ID })],
        ['両方の欄が無い', loginRequest({})],
        [
            '大文字と小文字が違う',
            loginRequest({
                id: SECRETS.ENTRY_ID.toUpperCase(),
                password: SECRETS.ENTRY_PASSWORD,
            }),
        ],
        [
            'JSON の本文',
            new Request(`${ORIGIN}/_entry/login`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id: SECRETS.ENTRY_ID,
                    password: SECRETS.ENTRY_PASSWORD,
                }),
            }),
        ],
        [
            'Content-Type の無い本文',
            new Request(`${ORIGIN}/_entry/login`, {
                method: 'POST',
                body: new Blob([
                    `id=${SECRETS.ENTRY_ID}&password=${SECRETS.ENTRY_PASSWORD}`,
                ]),
            }),
        ],
        [
            '本文が無い',
            new Request(`${ORIGIN}/_entry/login`, { method: 'POST' }),
        ],
    ];
}

await test('ログイン画面: 日本語の HTML で、製品名・知らせの文・欄・ボタンを持ち、外の資源とスクリプトを持たない', async () => {
    const app = fakeApp(() => new Response('ok'));

    const response = await worker.fetch(getLoginPage('/_entry/login'), app.env);

    assert.equal(response.status, 200);
    assertLoginPageHeaders(response);
    const html = await response.text();
    assert.match(html, /<html lang="ja">/);
    assert.match(html, /イントラデリバー/);
    assert.ok(html.includes(NOTICE));
    assert.match(html, /<form method="post" action="\/_entry\/login">/);
    assert.match(
        html,
        /<input id="id" name="id" type="text" autocomplete="username"/,
    );
    assert.match(
        html,
        /<input id="password" name="password" type="password" autocomplete="current-password"/,
    );
    assert.match(html, /<label for="id">ID<\/label>/);
    assert.match(html, /<label for="password">パスワード<\/label>/);
    assert.match(html, /<button type="submit">ログイン<\/button>/);
    assert.equal(html.includes(ERROR_TEXT), false);
    assert.doesNotMatch(html, /<script|<link|\bsrc=|\bhref=|@import|url\(/i);
    assert.equal(hiddenNext(html), `${ORIGIN}/`);
    assert.equal(app.received.length, 0);
});

await test('ログイン: 正しい ID とパスワードで 303、入口の cookie の属性がすべてあり、Location は同じ origin の絶対 URL', async () => {
    const app = fakeApp(() => new Response('ok'));

    const response = await worker.fetch(
        loginRequest({
            id: SECRETS.ENTRY_ID,
            password: SECRETS.ENTRY_PASSWORD,
        }),
        app.env,
    );

    assert.equal(response.status, 303);
    assert.equal(response.headers.get('Cache-Control'), 'no-store');
    assert.equal(response.headers.get('Location'), `${ORIGIN}/`);
    const setCookie = response.headers.get('Set-Cookie') ?? '';
    const [pair, ...attributes] = setCookie.split(';').map((s) => s.trim());
    assert.match(pair, /^__Host-entry=[^;\s]+$/);
    assert.deepEqual(attributes.toSorted(), [
        'HttpOnly',
        'Max-Age=2592000',
        'Path=/',
        'SameSite=Lax',
        'Secure',
    ]);
    assert.equal(app.received.length, 0);
});

await test('ログイン: ID・パスワードの違い・欄の欠け・form でない本文は、どれも 401 とログイン画面(誤りの文つき)で cookie を置かない', async () => {
    const app = fakeApp(() => new Response('ok'));
    const cases = rejectedLoginRequests();

    for (const [label, incoming] of cases) {
        const response = await worker.fetch(incoming, app.env);

        assert.equal(response.status, 401, label);
        assertLoginPageHeaders(response);
        assert.equal(response.headers.get('Set-Cookie'), null, label);
        assert.equal(response.headers.get('Location'), null, label);
        const html = await response.text();
        assert.ok(html.includes(ERROR_TEXT), label);
        assert.ok(html.includes(NOTICE), label);
    }
    assert.equal(app.received.length, 0);
});

await test('ログイン: 入力の長さによらず、ID とパスワードの両方を SHA-256 の後に timingSafeEqual で比べる', async (t) => {
    const app = fakeApp(() => new Response('ok'));
    const compare = t.mock.method(crypto.subtle, 'timingSafeEqual');

    for (const fields of [
        { id: SECRETS.ENTRY_ID, password: SECRETS.ENTRY_PASSWORD },
        { id: 'a', password: 'x'.repeat(1000) },
        { id: 'x'.repeat(1000), password: '' },
        {},
    ]) {
        compare.mock.resetCalls();

        await worker.fetch(loginRequest(fields), app.env);

        assert.equal(compare.mock.callCount(), 2, JSON.stringify(fields));
        for (const call of compare.mock.calls) {
            const [a, b] = call.arguments;
            assert.deepEqual([a.byteLength, b.byteLength], [32, 32]);
        }
    }
});

await test('ログイン画面の HEAD は本文を持たず、GET と同じヘッダー', async () => {
    const app = fakeApp(() => new Response('ok'));

    const response = await worker.fetch(
        new Request(`${ORIGIN}/_entry/login`, { method: 'HEAD' }),
        app.env,
    );

    assert.equal(response.status, 200);
    assertLoginPageHeaders(response);
    assert.equal(await response.text(), '');
});
