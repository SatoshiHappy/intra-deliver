// app(VPC サービスの先)への中継。設計 = docs の 設計/公開の配備/入口/README.md 5.2・5.3、入口/ログイン.md 5.9.3・5.9.6。
import { dropEntryCookie } from './entry-session.js';
import { unavailable } from './responses.js';

// VPC サービスへ渡す宛先。接続先は VPC サービスの設定が決め、この host は Host ヘッダーになり、port は無視される。
const ORIGIN = 'http://app:8080';

// 案内ページに替える状態(中継の層・app の停止やメンテナンスモード)。app 自身のそれ以外の 4xx・5xx はそのまま返す。
const UNAVAILABLE_STATUSES = new Set([502, 503, 504]);

// 訪問者の ID を app へ渡すヘッダー。訪問者が付けた値は必ず消す(入口/README.md 5.2、入口/ログイン.md 5.9.6)
const VISITOR_ID_HEADER = 'X-Visitor-Id';

/**
 * app が信じる転送ヘッダー(Laravel の trustProxies の For・Host・Proto)と訪問者の ID を、訪問者が付けてきた値に足さず置き換え、
 * 入口の cookie を落とす。app が信じるヘッダーを変えるときは、ここも変える。
 * @param {Request} request
 * @param {URL} url
 * @param {string} visitorId
 * @returns {Request}
 */
function toOriginRequest(request, url, visitorId) {
    const headers = new Headers(request.headers);
    const clientIp = request.headers.get('CF-Connecting-IP');

    dropEntryCookie(headers);
    headers.set(VISITOR_ID_HEADER, visitorId);
    headers.set('X-Forwarded-Host', url.host);
    headers.set('X-Forwarded-Proto', 'https');
    if (clientIp === null) {
        headers.delete('X-Forwarded-For');
    } else {
        headers.set('X-Forwarded-For', clientIp);
    }

    return new Request(`${ORIGIN}${url.pathname}${url.search}`, {
        method: request.method,
        headers,
        body: request.body,
        redirect: 'manual',
    });
}

/**
 * @param {Request} request
 * @param {URL} url
 * @param {import('./index.js').Env} env
 * @param {string} visitorId
 * @returns {Promise<Response>}
 */
export async function relay(request, url, env, visitorId) {
    let response;
    try {
        response = await env.APP.fetch(
            toOriginRequest(request, url, visitorId),
        );
    } catch (error) {
        console.error('中継に失敗しました', error);
        return unavailable();
    }

    if (UNAVAILABLE_STATUSES.has(response.status)) {
        // 本文は使わないので読み捨てる。捨てるのに失敗しても、案内ページを返すことは変えない(記録だけ残す)
        try {
            await response.body?.cancel();
        } catch (error) {
            console.error('中継の応答の本文を捨てられませんでした', error);
        }
        return unavailable();
    }

    return response;
}
