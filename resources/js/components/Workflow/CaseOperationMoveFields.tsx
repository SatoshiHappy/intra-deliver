import { FieldHelp, FormBlock } from '@/components/FormBlock';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { CaseActionNodeSelect } from '@/components/Workflow/CaseActionNodeSelect';
import { CaseActionCallout } from '@/components/Workflow/CaseActionNotices';
import type { OperationFormState } from '@/hooks/Workflow/use-operation-form';
import { movableDirectionsOf } from '@/lib/Workflow/caseOperations';
import {
    MOVE_DIRECTIONS,
    MOVE_TYPE_HELP,
    blockedDirectionHelpOf,
    moveCalloutOf,
} from '@/lib/Workflow/caseOperationTexts';
import type { WorkflowCaseMoveNode } from '@/types';

const DIRECTION_ID = 'case-operation-direction';
const BACK_ID = 'case-operation-back';

type Props = Pick<OperationFormState, 'form' | 'errors'>;

/**
 * 移動の種類の選択肢 1 つ(戻る・進むが押せないときは、ラベルの横の「?」に理由)。
 */
function DirectionItem({
    value,
    label,
    isDisabled,
    blockedHelp,
}: {
    value: string;
    label: string;
    isDisabled: boolean;
    blockedHelp: string | null;
}) {
    const id = `${DIRECTION_ID}-${value}`;

    return (
        <div className="flex items-center gap-2">
            <RadioGroupItem id={id} value={value} disabled={isDisabled} />
            <Label htmlFor={id}>{label}</Label>
            {blockedHelp !== null && (
                <FieldHelp id={id} label={label}>
                    {blockedHelp}
                </FieldHelp>
            )}
        </div>
    );
}

/**
 * 移動の種類の切替(戻る・進む・終了)。選べない種類は押せない(戻る = 戻り先の候補が無い、進む = 次が指定するステップ)。
 */
function DirectionField({
    node,
    form,
    errors,
}: Props & { node: WorkflowCaseMoveNode | undefined }) {
    const movable = movableDirectionsOf(node);

    return (
        <FormBlock
            id={DIRECTION_ID}
            label="移動の種類"
            labelAddon={<Badge variant="secondary">必須</Badge>}
            help={MOVE_TYPE_HELP}
            error={errors.direction}
        >
            <RadioGroup
                value={form.data.direction}
                onValueChange={(next) => {
                    const choice = MOVE_DIRECTIONS.find(
                        (one) => one.value === next,
                    );

                    if (choice !== undefined) {
                        form.setData('direction', choice.value);
                    }
                }}
                aria-labelledby={`${DIRECTION_ID}-label`}
                className="flex gap-6"
            >
                {MOVE_DIRECTIONS.map(({ value, label }) => (
                    <DirectionItem
                        key={value}
                        value={value}
                        label={label}
                        isDisabled={!movable[value]}
                        blockedHelp={blockedDirectionHelpOf(value, node)}
                    />
                ))}
            </RadioGroup>
        </FormBlock>
    );
}

/**
 * ステップを移動するダイアログの欄(移動の種類・戻るのとき戻り先・注意の囲み)。
 */
export function MoveFields(
    props: Props & { node: WorkflowCaseMoveNode | undefined },
) {
    const { node, form, errors } = props;

    return (
        <>
            <DirectionField {...props} />
            {form.data.direction === 'back' && (
                <CaseActionNodeSelect
                    id={BACK_ID}
                    label="戻り先"
                    nodes={(node?.backNodes ?? []).map((back) => ({
                        id: back.nodeId,
                        name: back.name,
                    }))}
                    value={form.data.send_back_node_id}
                    onChange={(value) =>
                        form.setData('send_back_node_id', value)
                    }
                    error={errors.send_back_node_id}
                />
            )}
            <CaseActionCallout {...moveCalloutOf(form.data.direction)} />
        </>
    );
}
