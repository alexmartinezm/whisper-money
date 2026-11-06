import { LabelCombobox } from '@/components/shared/label-combobox';
import { type PickerDrawer } from '@/components/ui/adaptive-popover';
import { type Label } from '@/types/label';
import { __ } from '@/utils/i18n';
import { type ReactElement } from 'react';

interface BulkLabelSelectProps {
    labels: Label[];
    /** The label picked, to add to the ones each row has. */
    onLabelsChange: (labelIds: string[]) => void;
    onRemoveAllLabels: () => void;
    onLabelCreated?: (label: Label) => void;
    disabled?: boolean;
    /** A custom trigger, e.g. a button of the bulk actions bar. */
    trigger?: ReactElement;
    /** Opens the picker as a bottom drawer, as the phone's bar does. */
    drawer?: PickerDrawer;
}

/**
 * Nothing stays ticked: each pick is one label to add, never the list the rows
 * should end up with, so picking again can't take a label back off them.
 */
export function BulkLabelSelect({
    labels,
    onLabelsChange,
    onRemoveAllLabels,
    onLabelCreated,
    disabled = false,
    trigger,
    drawer,
}: BulkLabelSelectProps) {
    return (
        <LabelCombobox
            value={[]}
            onValueChange={onLabelsChange}
            labels={labels}
            disabled={disabled}
            trigger={trigger}
            drawer={drawer}
            placeholder={__('Add labels')}
            triggerClassName="h-9 w-[180px] min-h-9"
            allowCreate={true}
            onRemoveAll={onRemoveAllLabels}
            onLabelCreated={onLabelCreated}
        />
    );
}
