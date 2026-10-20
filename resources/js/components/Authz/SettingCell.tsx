import { GridCell } from '@/components/Authz/GridCells';
import { NoSettingMark, SettingMark } from '@/components/Authz/SettingMark';
import { RESULT_LABELS } from '@/lib/Authz/labels';
import { nameOrCode } from '@/lib/nameOrCode';
import { cn } from '@/lib/utils';
import type { AuthzGridSetting } from '@/types';

/** 押せる形のセル(編集中)。saving の間は押せない */
export type CellPress = { onPress: () => void; saving: boolean };

/**
 * セルの中身。継承は title に置き場の表示名(無ければコード)、読み上げ用の文字を添える。setting が無ければ設定なし。
 */
function SettingContent({
    setting,
}: {
    setting: AuthzGridSetting | undefined;
}) {
    if (setting === undefined) {
        return (
            <span className="relative inline-flex">
                <NoSettingMark />
                <span className="sr-only">設定なし</span>
            </span>
        );
    }

    const effectLabel = RESULT_LABELS[setting.effect];
    const place =
        setting.inheritedFrom === null
            ? null
            : nameOrCode(setting.inheritedFrom);

    return (
        <span
            className="relative inline-flex"
            title={place === null ? undefined : `${place}から継承`}
        >
            <SettingMark effect={setting.effect} inherited={place !== null} />
            <span className="sr-only">
                {place === null
                    ? effectLabel
                    : `${effectLabel}(${place}から継承)`}
            </span>
        </span>
    );
}

/**
 * 「設定」タブのセル(行 × 対象者 × アクション)。band = 該当の帯。press があれば編集中の押せる形(カーソルと hover)。
 */
export function SettingCell({
    setting,
    band,
    press,
}: {
    setting: AuthzGridSetting | undefined;
    band: boolean;
    press: CellPress | null;
}) {
    return (
        <GridCell band={band} className="text-center">
            {press === null ? (
                <SettingContent setting={setting} />
            ) : (
                <button
                    type="button"
                    disabled={press.saving}
                    onClick={press.onPress}
                    className={cn(
                        'inline-flex size-8 cursor-pointer items-center justify-center rounded-md',
                        'outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50',
                        'disabled:cursor-wait disabled:opacity-50',
                    )}
                >
                    <SettingContent setting={setting} />
                </button>
            )}
        </GridCell>
    );
}
