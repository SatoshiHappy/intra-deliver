// 公開の入口の Worker。自前のログイン画面で入口を確かめ、入口の cookie が有効な要求を VPC サービス(Tunnel の先の app)へ中継する。
// 設計 = docs の 設計/公開の配備/入口/README.md 5.2・5.3、入口/ログイン.md 5.9。
import { visitorIdFromCookie } from './entry-session.js';
import { loginLocation, METHODS_WITH_NEXT, entryRoute } from './login-route.js';
import { relay } from './relay.js';
import { NO_STORE, plainText, unavailable } from './responses.js';

/**
 * @typedef {object} Env
 * @property {{ fetch: (request: Request) => Promise<Response> }} APP VPC サービスの binding(wrangler.jsonc の vpc_services)
 * @property {string} [ENTRY_ID] 入口の ID(Worker の secret。入口/ログイン.md 5.9.4)
 * @property {string} [ENTRY_PASSWORD] 入口のパスワード(同上)
 * @property {string} [ENTRY_SESSION_KEY] 入口の cookie の署名の鍵(同上)
 */

/** @typedef {{ id: string, password: string, sessionKey: string }} Secrets */

// Worker の道の接頭辞。cookie によらず app へ中継しない(入口/ログイン.md 5.9.1)
const ENTRY_PATH_PREFIX = '/_entry/';

const LOGIN_REQUIRED_TEXT =
    'ログインが必要です。ページを読み込み直してください。';

/**
 * 入口の secret。1 つでも無いか空なら、欠けた名前をログに出して null(値は出さない。入口/ログイン.md 5.9.1)。
 * @param {Env} env
 * @returns {Secrets | null}
 */
function entrySecrets(env) {
    /** @param {unknown} value */
    const text = (value) => (typeof value === 'string' ? value : '');
    const values = {
        ENTRY_ID: text(env.ENTRY_ID),
        ENTRY_PASSWORD: text(env.ENTRY_PASSWORD),
        ENTRY_SESSION_KEY: text(env.ENTRY_SESSION_KEY),
    };
    const missing = Object.entries(values)
        .filter(([, value]) => value === '')
        .map(([name]) => name);
    if (missing.length > 0) {
        console.error(`入口の secret が欠けています: ${missing.join(', ')}`);
        return null;
    }
    return {
        id: values.ENTRY_ID,
        password: values.ENTRY_PASSWORD,
        sessionKey: values.ENTRY_SESSION_KEY,
    };
}

/**
 * 入口の cookie が無効・無い要求。画面の通信の種類とメソッドで、ログイン画面へ移すか 401 にする(入口/ログイン.md 5.9.1)。
 * @param {Request} request
 * @param {URL} url
 * @returns {Response}
 */
function withoutSession(request, url) {
    if (request.headers.has('X-Inertia')) {
        return new Response(null, {
            status: 409,
            headers: {
                ...NO_STORE,
                'X-Inertia-Location': loginLocation(request, url),
            },
        });
    }
    if (
        request.headers.get('X-Requested-With') === 'XMLHttpRequest' ||
        !METHODS_WITH_NEXT.has(request.method)
    ) {
        return plainText(LOGIN_REQUIRED_TEXT, 401);
    }
    return new Response(null, {
        status: 302,
        headers: { ...NO_STORE, Location: loginLocation(request, url) },
    });
}

export default {
    /**
     * @param {Request} request
     * @param {Env} env
     * @returns {Promise<Response>}
     */
    async fetch(request, env) {
        // secret が欠けたら、ログインも中継もしない(閉じる側に倒す。入口/ログイン.md 5.9.1)
        const secrets = entrySecrets(env);
        if (secrets === null) {
            return unavailable();
        }

        const url = new URL(request.url);
        if (url.pathname.startsWith(ENTRY_PATH_PREFIX)) {
            return entryRoute(request, url, secrets);
        }

        const visitorId = await visitorIdFromCookie(
            request.headers,
            secrets.sessionKey,
        );
        if (visitorId === null) {
            return withoutSession(request, url);
        }
        return relay(request, url, env, visitorId);
    },
};
