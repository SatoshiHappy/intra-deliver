import { nameOrCode } from '@/lib/nameOrCode';

/** アプリのホームの入口が渡すアプリ(docs の 設計/メニュー/ホーム.md 6.1 の props の app)。全体のホームは null */
export type HomeApp = { name: string };

/** あいさつ欄の見出し(h1)の中身。app = アプリの名前だけ、guest = ゲストへのあいさつ、user = 氏名(無ければコード)を添えたあいさつ */
export type HomeHeading =
    | { kind: 'app'; name: string }
    | { kind: 'guest' }
    | { kind: 'user'; name: string };

/** ページの題(`<Head title>`)。アプリのホームはアプリの名前、全体のホームは「ホーム」(6.1・6.7) */
export function homeTitle(app: HomeApp | null): string {
    return app === null ? 'ホーム' : app.name;
}

/**
 * あいさつ欄の見出し(6.1 の「あいさつ欄の見出し」)。アプリのホームはアプリの名前(あいさつの語は使わない)、全体のホームはゲストか利用者のあいさつ。
 */
export function homeHeading(
    app: HomeApp | null,
    user: { code: string; name: string | null } | null,
): HomeHeading {
    if (app !== null) {
        return { kind: 'app', name: app.name };
    }

    return user === null
        ? { kind: 'guest' }
        : { kind: 'user', name: nameOrCode(user) };
}
