// 入口の確かめ(全員共通の ID・パスワード)と入口の cookie の作りと確かめ。設計 = docs の 設計/公開の配備/入口/ログイン.md 5.9.3・5.9.6。

/** 入口の cookie の名前。`__Host-` の接頭辞で Secure・Path=/・Domain 無しをブラウザに強いる(入口/ログイン.md 5.9.3) */
const ENTRY_COOKIE = '__Host-entry';

// 入口の cookie の長さ(秒)。期限はログインの時刻 + この長さで、使っている間も延ばさない(入口/ログイン.md 5.9.3)
const SESSION_SECONDS = 30 * 24 * 60 * 60;

// cookie の値の形(入口/ログイン.md 5.9.3)。引用符なしで置ける文字だけにし、署名する文字列の書き方を 1 通りに絞る
const COOKIE_VALUE_PATTERN =
    /^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.([1-9][0-9]{0,11})\.([0-9a-f]{64})$/;

const encoder = new TextEncoder();

/**
 * Workers の Web Crypto の拡張(標準の型に無い)。比べる 2 つの長さが違うと例外になる(入口/ログイン.md 5.9.3)。
 * @typedef {SubtleCrypto & {
 *     timingSafeEqual: (a: ArrayBuffer | ArrayBufferView, b: ArrayBuffer | ArrayBufferView) => boolean
 * }} WorkersSubtleCrypto
 */

/**
 * 2 つの文字列を、どちらも SHA-256 にしてから時間が値によらない比べ方で比べる(長さの違いを比べる時間に出さない)。
 * @param {string} given
 * @param {string} expected
 * @returns {Promise<boolean>}
 */
async function sameText(given, expected) {
    const [givenDigest, expectedDigest] = await Promise.all([
        crypto.subtle.digest('SHA-256', encoder.encode(given)),
        crypto.subtle.digest('SHA-256', encoder.encode(expected)),
    ]);
    const subtle = /** @type {WorkersSubtleCrypto} */ (crypto.subtle);
    return subtle.timingSafeEqual(givenDigest, expectedDigest);
}

/**
 * 入力の ID とパスワードが secret と合うか。欄が欠けたときも空の文字で両方を比べ、比べ方をそろえる
 * (secret は空でないので合わない)。どちらが違うかは返さない(入口/ログイン.md 5.9.3)。
 * @param {FormDataEntryValue | null} id
 * @param {FormDataEntryValue | null} password
 * @param {{ id: string, password: string }} expected
 * @returns {Promise<boolean>}
 */
export async function credentialsMatch(id, password, expected) {
    const given = {
        id: typeof id === 'string' ? id : '',
        password: typeof password === 'string' ? password : '',
    };
    const [idMatches, passwordMatches] = await Promise.all([
        sameText(given.id, expected.id),
        sameText(given.password, expected.password),
    ]);
    return (
        typeof id === 'string' &&
        typeof password === 'string' &&
        idMatches &&
        passwordMatches
    );
}

/**
 * @param {string} sessionKey secret ENTRY_SESSION_KEY(文字のバイト列をそのまま HMAC の鍵にする)
 * @returns {Promise<CryptoKey>}
 */
function hmacKey(sessionKey) {
    return crypto.subtle.importKey(
        'raw',
        encoder.encode(sessionKey),
        { name: 'HMAC', hash: 'SHA-256' },
        false,
        ['sign', 'verify'],
    );
}

/**
 * @param {string} visitorId
 * @param {number} expiresAt
 */
function signedPart(visitorId, expiresAt) {
    return encoder.encode(`${visitorId}.${expiresAt}`);
}

/** @param {ArrayBuffer} buffer */
function toHex(buffer) {
    return Array.from(new Uint8Array(buffer), (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');
}

/** @param {string} hex 偶数桁の 16 進(形は COOKIE_VALUE_PATTERN で確かめ済み) */
function fromHex(hex) {
    const bytes = new Uint8Array(hex.length / 2);
    for (let i = 0; i < bytes.length; i++) {
        bytes[i] = Number.parseInt(hex.slice(i * 2, i * 2 + 2), 16);
    }
    return bytes;
}

/**
 * ログインに合った訪問者に渡す Set-Cookie の値。訪問者の ID はログインのたびに新しく作る(入口/ログイン.md 5.9.6)。
 * @param {string} sessionKey
 * @returns {Promise<string>}
 */
export async function newSessionCookie(sessionKey) {
    const visitorId = crypto.randomUUID();
    const expiresAt = Math.floor(Date.now() / 1000) + SESSION_SECONDS;
    const signature = await crypto.subtle.sign(
        'HMAC',
        await hmacKey(sessionKey),
        signedPart(visitorId, expiresAt),
    );
    const value = `${visitorId}.${expiresAt}.${toHex(signature)}`;
    return `${ENTRY_COOKIE}=${value}; Secure; HttpOnly; SameSite=Lax; Path=/; Max-Age=${SESSION_SECONDS}`;
}

/**
 * 1 本の Cookie ヘッダーの `;` で区切った組。値の `,` では分けない(入口/ログイン.md 5.9.7 の 3)。
 * @param {string} header
 * @returns {{ name: string, pair: string }[]}
 */
function cookiePairs(header) {
    return header
        .split(';')
        .map((pair) => pair.trim())
        .filter((pair) => pair !== '')
        .map((pair) => {
            const equals = pair.indexOf('=');
            return { name: equals === -1 ? pair : pair.slice(0, equals), pair };
        });
}

/**
 * 有効な入口の cookie が持つ訪問者の ID。無効なら null(有効の条件 = 入口/ログイン.md 5.9.3)。
 * @param {Headers} headers
 * @param {string} sessionKey
 * @returns {Promise<string | null>}
 */
export async function visitorIdFromCookie(headers, sessionKey) {
    const header = headers.get('Cookie');
    if (header === null) {
        return null;
    }
    const entry = cookiePairs(header).find(({ name }) => name === ENTRY_COOKIE);
    const match = entry?.pair
        .slice(ENTRY_COOKIE.length + 1)
        .match(COOKIE_VALUE_PATTERN);
    if (!match) {
        return null;
    }

    const [, visitorId, expiresText, signatureHex] = match;
    const expiresAt = Number(expiresText);
    const signatureMatches = await crypto.subtle.verify(
        'HMAC',
        await hmacKey(sessionKey),
        fromHex(signatureHex),
        signedPart(visitorId, expiresAt),
    );
    if (!signatureMatches || expiresAt * 1000 <= Date.now()) {
        return null;
    }
    return visitorId;
}

/**
 * app へ中継する要求の 1 本の Cookie ヘッダーから入口の cookie を落とし、残りが無ければヘッダーを消す。
 * app は入口の資格情報を要らないので、受け取る先を Worker で終える(入口/ログイン.md 5.9.3)。cookie の名前は大文字と小文字を区別する。
 * @param {Headers} headers
 */
export function dropEntryCookie(headers) {
    const header = headers.get('Cookie');
    if (header === null) {
        return;
    }
    const kept = cookiePairs(header)
        .filter(({ name }) => name !== ENTRY_COOKIE)
        .map(({ pair }) => pair);
    if (kept.length === 0) {
        headers.delete('Cookie');
    } else {
        headers.set('Cookie', kept.join('; '));
    }
}
