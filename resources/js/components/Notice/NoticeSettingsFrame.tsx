import type { ReactNode } from 'react';

/**
 * 通知の設定の 2 つの画面の枠(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/利用者と管理の画面.md の 3.2・3.4・4.2)。
 */
export function NoticeSettingsFrame({
    title,
    lead,
    note,
    children,
}: {
    title: string;
    lead: string;
    note?: string;
    children: ReactNode;
}) {
    return (
        <div className="flex h-full flex-1 flex-col gap-6 p-4">
            <div className="flex flex-col gap-1">
                <h1 className="text-2xl font-semibold">{title}</h1>
                <p className="text-sm text-muted-foreground">{lead}</p>
            </div>
            <div className="flex flex-col gap-4">
                {note !== undefined && (
                    <p className="text-sm text-muted-foreground">{note}</p>
                )}
                {children}
            </div>
        </div>
    );
}
