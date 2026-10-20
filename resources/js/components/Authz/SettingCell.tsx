import { GridCell } from '@/components/Authz/GridCells';
import { NoSettingMark, SettingMark } from '@/components/Authz/SettingMark';
import { RESULT_LABELS } from '@/lib/Authz/labels';
import { nameOrCode } from '@/lib/nameOrCode';
import type { AuthzGridSetting } from '@/types';

/**
 * 「設定」タブのセル(行 × 対象者 × アクション)。継承は title に置き場の表示名(無ければコード)、読み上げ用の文字を添える。
 * setting が無ければ設定なし。band = 該当の帯。
 */
export function SettingCell({
    setting,
    band,
}: {
    setting: AuthzGridSetting | undefined;
    band: boolean;
}) {
    if (setting === undefined) {
        return (
            <GridCell band={band} className="text-center">
                <span className="relative inline-flex">
                    <NoSettingMark />
                    <span className="sr-only">設定なし</span>
                </span>
            </GridCell>
        );
    }

    const effectLabel = RESULT_LABELS[setting.effect];
    const place =
        setting.inheritedFrom === null
            ? null
            : nameOrCode(setting.inheritedFrom);

    return (
        <GridCell band={band} className="text-center">
            <span
                className="relative inline-flex"
                title={place === null ? undefined : `${place}から継承`}
            >
                <SettingMark
                    effect={setting.effect}
                    inherited={place !== null}
                />
                <span className="sr-only">
                    {place === null
                        ? effectLabel
                        : `${effectLabel}(${place}から継承)`}
                </span>
            </span>
        </GridCell>
    );
}
