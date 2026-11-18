import { Head, usePage } from '@inertiajs/react';
import { NoticeSettingSurfaces } from '@/components/Notice/NoticeSettingSurfaces';
import { NoticeSettingsFrame } from '@/components/Notice/NoticeSettingsFrame';
import { GUEST_TEXT, ownSurfaceViews } from '@/lib/Notice/noticeSettings';
import { show, update } from '@/routes/notice/settings';
import type { NoticeSettingsProps } from '@/types';

/**
 * 利用者の画面「通知の設定」(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/利用者と管理の画面.md の 3 節)。
 * ゲストの画面は 3.4(ゲストかは共有プロパティ account で分かる)。
 */
export default function Settings({ surfaces }: NoticeSettingsProps) {
    const { account } = usePage().props;

    return (
        <>
            <Head title="通知の設定" />
            <NoticeSettingsFrame
                title="通知の設定"
                lead="通知の種類ごとに、ミュートするかを設定します。ミュートした通知は、既読で届きます。"
                note={account.user === null ? GUEST_TEXT : undefined}
            >
                <NoticeSettingSurfaces
                    surfaces={ownSurfaceViews(surfaces)}
                    url={update.url()}
                    valueKey="muted"
                />
            </NoticeSettingsFrame>
        </>
    );
}

Settings.layout = {
    breadcrumbs: [{ title: '通知の設定', href: show() }],
};
