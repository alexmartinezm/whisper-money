import {
    destroy,
    update,
} from '@/actions/App/Http/Controllers/RecurringSeriesController';
import { RecurringCadenceBadge } from '@/components/recurring/recurring-cadence-badge';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/hooks/use-locale';
import { monthlyEquivalent } from '@/lib/recurring';
import { cn } from '@/lib/utils';
import { type RecurringSeries } from '@/types/recurring';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { Check, MoreHorizontal, Pencil, Trash2, Undo2 } from 'lucide-react';
import { useState } from 'react';

interface Props {
    series: RecurringSeries;
}

export function RecurringSeriesRow({ series }: Props) {
    const locale = useLocale();
    const [renaming, setRenaming] = useState(false);
    const [name, setName] = useState(series.display_name);
    const isIgnored = series.user_state === 'ignored';

    function patch(payload: Record<string, string>) {
        router.patch(update(series.id).url, payload, {
            preserveScroll: true,
        });
    }

    function submitRename() {
        const trimmed = name.trim();

        if (trimmed === '' || trimmed === series.display_name) {
            setRenaming(false);
            return;
        }

        patch({ display_name: trimmed });
        setRenaming(false);
    }

    return (
        <div
            className={cn(
                'flex items-center justify-between gap-3 px-4 py-3',
                isIgnored && 'opacity-50',
            )}
        >
            <div className="flex min-w-0 flex-col gap-1">
                <div className="flex min-w-0 items-center gap-2">
                    <span className="truncate font-medium">
                        {series.display_name}
                    </span>
                    {series.user_state === 'confirmed' && (
                        <Badge variant="outline" className="font-normal">
                            {__('Confirmed')}
                        </Badge>
                    )}
                    {series.status === 'lapsed' && (
                        <Badge variant="outline" className="font-normal">
                            {__('Lapsed')}
                        </Badge>
                    )}
                </div>
                <span className="text-xs text-muted-foreground">
                    {series.category?.name ?? __('Uncategorized')} ·{' '}
                    {__('Last charged :date', {
                        date: formatDateMedium(series.last_occurred_on, locale),
                    })}
                </span>
            </div>

            <div className="flex shrink-0 items-center gap-3">
                <RecurringCadenceBadge cadence={series.cadence} />
                <div className="flex flex-col items-end">
                    <AmountDisplay
                        amountInCents={series.expected_amount}
                        currencyCode={series.currency_code}
                        size="sm"
                        weight="medium"
                    />
                    <span className="text-xs text-muted-foreground">
                        {series.amount_is_variable && `${__('approx.')} · `}
                        {__(':amount/mo', {
                            amount: new Intl.NumberFormat(locale, {
                                style: 'currency',
                                currency: series.currency_code,
                                maximumFractionDigits: 0,
                            }).format(
                                monthlyEquivalent(
                                    series.expected_amount,
                                    series.cadence,
                                ) / 100,
                            ),
                        })}
                    </span>
                </div>

                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={__('Actions for :name', {
                                name: series.display_name,
                            })}
                        >
                            <MoreHorizontal className="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            onClick={() => {
                                setName(series.display_name);
                                setRenaming(true);
                            }}
                        >
                            <Pencil className="size-4" />
                            {__('Rename')}
                        </DropdownMenuItem>
                        {series.user_state !== 'confirmed' && (
                            <DropdownMenuItem
                                onClick={() =>
                                    patch({ user_state: 'confirmed' })
                                }
                            >
                                <Check className="size-4" />
                                {__('Confirm')}
                            </DropdownMenuItem>
                        )}
                        {isIgnored ? (
                            <DropdownMenuItem
                                onClick={() =>
                                    patch({ user_state: 'detected' })
                                }
                            >
                                <Undo2 className="size-4" />
                                {__('Restore')}
                            </DropdownMenuItem>
                        ) : (
                            <DropdownMenuItem
                                onClick={() => patch({ user_state: 'ignored' })}
                            >
                                <Undo2 className="size-4" />
                                {__('Ignore')}
                            </DropdownMenuItem>
                        )}
                        <DropdownMenuItem
                            variant="destructive"
                            onClick={() =>
                                router.delete(destroy(series.id).url, {
                                    preserveScroll: true,
                                })
                            }
                        >
                            <Trash2 className="size-4" />
                            {__('Delete')}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <Dialog open={renaming} onOpenChange={setRenaming}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{__('Rename series')}</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor={`rename-${series.id}`}>
                            {__('Name')}
                        </Label>
                        <Input
                            id={`rename-${series.id}`}
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            maxLength={255}
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setRenaming(false)}
                        >
                            {__('Cancel')}
                        </Button>
                        <Button onClick={submitRename}>{__('Save')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
