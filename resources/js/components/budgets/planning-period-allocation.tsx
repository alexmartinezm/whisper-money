import { updatePeriod } from '@/actions/App/Http/Controllers/BudgetController';
import { AmountInput } from '@/components/ui/amount-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { type FormEvent, useEffect, useRef, useState } from 'react';

interface PlanningPeriodAllocationProps {
    budgetId: string;
    periodId: string;
    allocatedAmount: number;
    currencyCode: string;
}

export function PlanningPeriodAllocation({
    budgetId,
    periodId,
    allocatedAmount,
    currencyCode,
}: PlanningPeriodAllocationProps) {
    const [allocation, setAllocation] = useState(allocatedAmount);
    const allocationRef = useRef(allocatedAmount);
    const [isSaving, setIsSaving] = useState(false);

    useEffect(() => {
        allocationRef.current = allocatedAmount;
        setAllocation(allocatedAmount);
    }, [allocatedAmount, periodId]);

    const handleAllocationChange = (value: number) => {
        allocationRef.current = value;
        setAllocation(value);
    };

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setIsSaving(true);
        router.patch(
            updatePeriod({ budget: budgetId, period: periodId }).url,
            { allocated_amount: allocationRef.current },
            { onFinish: () => setIsSaving(false) },
        );
    };

    return (
        <form aria-label={__('Planning allocation')} onSubmit={handleSubmit}>
            <div className="flex flex-col gap-3 rounded-lg border bg-card p-4 sm:flex-row sm:items-end">
                <div className="flex-1 space-y-2">
                    <Label htmlFor="planning-allocated-amount">
                        {__('Allocated Amount')}
                    </Label>
                    <AmountInput
                        id="planning-allocated-amount"
                        value={allocation}
                        onChange={handleAllocationChange}
                        onInputChange={handleAllocationChange}
                        currencyCode={currencyCode}
                        placeholder="0.00"
                        required
                    />
                </div>
                <Button type="submit" disabled={isSaving}>
                    {isSaving ? __('Saving...') : __('Save allocation')}
                </Button>
            </div>
        </form>
    );
}
