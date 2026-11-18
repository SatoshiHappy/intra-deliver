import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import { toastErrors } from '@/lib/flashToast';
import { cn } from '@/lib/utils';
import {
    distinctErrors,
    SETTING_PROPS,
    settingPayload,
} from '@/lib/Notice/noticeSettings';
import type {
    NoticeSettingRowView,
    NoticeSettingSurfaceView,
    SettingValueKey,
} from '@/lib/Notice/noticeSettings';

function NoticeSettingRow({
    row,
    saving,
    onChange,
}: {
    row: NoticeSettingRowView;
    saving: boolean;
    onChange: (type: string, checked: boolean) => void;
}) {
    return (
        <li
            className={cn(
                'flex items-center justify-between gap-4 border-t py-3',
                'first:border-t-0 md:[&:nth-child(2)]:border-t-0',
            )}
        >
            <div className="flex items-center gap-2">
                <span className="text-sm font-medium">{row.label}</span>
                {row.badge !== null && (
                    <Badge variant="secondary">{row.badge}</Badge>
                )}
            </div>
            <div className="flex items-center gap-3">
                <Switch
                    checked={row.checked}
                    disabled={saving || row.locked}
                    aria-label={row.switchName}
                    onCheckedChange={(next) => onChange(row.type, next)}
                />
                <span className="w-24 text-sm text-muted-foreground">
                    {row.word}
                </span>
            </div>
        </li>
    );
}

function NoticeSettingSurface({
    surface,
    saving,
    onChange,
}: {
    surface: NoticeSettingSurfaceView;
    saving: boolean;
    onChange: (type: string, checked: boolean) => void;
}) {
    return (
        <section
            aria-label={surface.heading}
            className="flex flex-col gap-2 rounded-xl border bg-card p-5 text-card-foreground"
        >
            <h2 className="text-lg font-semibold">{surface.heading}</h2>
            {/* 2 列と線の引き方 = docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/利用者と管理の画面.md の 3.2 */}
            <ul className="grid md:grid-cols-2 md:gap-x-12">
                {surface.rows.map((row) => (
                    <NoticeSettingRow
                        key={row.type}
                        row={row}
                        saving={saving}
                        onChange={onChange}
                    />
                ))}
            </ul>
        </section>
    );
}

/**
 * 保存(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/利用者と管理の画面.md の 3.3)。
 * 保存の間に別の行を押せると、その保存が前の保存の応答を捨てるため、保存の間は全スイッチを押せなくする。
 */
function useSettingSave(url: string, valueKey: SettingValueKey) {
    const [saving, setSaving] = useState(false);
    // saving は再描画の後にしか効かず、その前の 2 つ目のクリックを止められないので、同期の印で止める
    const inFlight = useRef(false);

    const save = (type: string, checked: boolean) => {
        if (inFlight.current) {
            return;
        }

        inFlight.current = true;
        router.put(url, settingPayload(valueKey, type, checked), {
            only: SETTING_PROPS,
            preserveScroll: true,
            preserveState: true,
            onStart: () => setSaving(true),
            onFinish: () => {
                inFlight.current = false;
                setSaving(false);
            },
            onError: (errors) => toastErrors(distinctErrors(errors)),
        });
    };

    return { saving, save };
}

export function NoticeSettingSurfaces({
    surfaces,
    url,
    valueKey,
}: {
    surfaces: NoticeSettingSurfaceView[];
    url: string;
    valueKey: SettingValueKey;
}) {
    const { saving, save } = useSettingSave(url, valueKey);

    return (
        <>
            {surfaces.map((surface) => (
                <NoticeSettingSurface
                    key={surface.prefix}
                    surface={surface}
                    saving={saving}
                    onChange={save}
                />
            ))}
        </>
    );
}
