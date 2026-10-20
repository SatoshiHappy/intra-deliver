import { FormBlock, invalidProps } from '@/components/FormBlock';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

const SEND_BACK_ID = 'case-action-send-back-node';

/**
 * 差戻しの欄(差戻し先の選択と、差戻し先が定義で制限されているときの 1 行)。差戻しのダイアログだけで呼ぶ。value = 差戻し先のステップの id の文字列。
 */
export function CaseActionSendBackFields({
    targets,
    isRestricted,
    value,
    onChange,
    error,
}: {
    targets: { id: number; name: string }[];
    isRestricted: boolean;
    value: string;
    onChange: (value: string) => void;
    error: string | undefined;
}) {
    return (
        <>
            <FormBlock id={SEND_BACK_ID} label="差戻し先" error={error}>
                <Select value={value} onValueChange={onChange}>
                    <SelectTrigger
                        id={SEND_BACK_ID}
                        className="w-full"
                        {...invalidProps(SEND_BACK_ID, error)}
                    >
                        <SelectValue placeholder="選択してください" />
                    </SelectTrigger>
                    <SelectContent>
                        {targets.map((candidate) => (
                            <SelectItem
                                key={candidate.id}
                                value={String(candidate.id)}
                            >
                                {candidate.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </FormBlock>
            {isRestricted && (
                <p className="text-sm text-muted-foreground">
                    {`このステップで差戻しできるのは、定義で許可された${targets.map((candidate) => candidate.name).join('と')}のみです。`}
                </p>
            )}
        </>
    );
}
