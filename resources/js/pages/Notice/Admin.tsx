import { Head } from '@inertiajs/react';
import { NoticeSettingSurfaces } from '@/components/Notice/NoticeSettingSurfaces';
import { NoticeSettingsFrame } from '@/components/Notice/NoticeSettingsFrame';
import { adminSurfaceViews } from '@/lib/Notice/noticeSettings';
import { show, update } from '@/routes/notice/admin';
import type { NoticeAdminProps } from '@/types';

/**
 * 管理の画面「通知の管理」(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/利用者と管理の画面.md の 4 節)。
 * 束を許可されたゲストも開けるので、ゲストの文は持たない。
 */
export default function Admin({ surfaces }: NoticeAdminProps) {
    return (
        <>
            <Head title="通知の管理" />
            <NoticeSettingsFrame
                title="通知の管理"
                lead="通知の種類ごとに、必須にするかを設定します。必須にした通知は、利用者の設定によらず届きます。"
            >
                <NoticeSettingSurfaces
                    surfaces={adminSurfaceViews(surfaces)}
                    url={update.url()}
                />
            </NoticeSettingsFrame>
        </>
    );
}

Admin.layout = {
    breadcrumbs: [{ title: '通知の管理', href: show() }],
};
