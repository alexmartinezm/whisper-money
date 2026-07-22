import { AccountName } from '@/components/accounts/account-name';
import { BankLogo } from '@/components/bank-logo';
import { SortableGrid } from '@/components/sortable-grid';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { AccountWithMetrics } from '@/hooks/use-dashboard-data';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';
import { ChartColumnBig, Eye, EyeOff } from 'lucide-react';

interface AccountsManagerDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** All manageable accounts, in display order. */
    accounts: AccountWithMetrics[];
    isHidden: (account: AccountWithMetrics) => boolean;
    isIncludedInNetWorth: (account: AccountWithMetrics) => boolean;
    onReorder: (orderedIds: string[]) => void;
    onToggleVisibility: (id: string, hidden: boolean) => void;
    onToggleNetWorth: (id: string, included: boolean) => void;
}

export function AccountsManagerDialog({
    open,
    onOpenChange,
    accounts,
    isHidden,
    isIncludedInNetWorth,
    onReorder,
    onToggleVisibility,
    onToggleNetWorth,
}: AccountsManagerDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{__('Edit accounts')}</DialogTitle>
                    <DialogDescription>
                        {__(
                            'Toggle dashboard visibility, net worth inclusion, and drag to reorder.',
                        )}
                    </DialogDescription>
                </DialogHeader>

                <SortableGrid
                    className="flex flex-col gap-1"
                    items={accounts}
                    getId={(account) => account.id}
                    onReorder={onReorder}
                    renderItem={(account, dragHandle) => {
                        const hidden = isHidden(account);
                        const includedInNetWorth =
                            isIncludedInNetWorth(account);
                        return (
                            <div className="flex items-center gap-3 rounded-md px-2 py-2 hover:bg-muted">
                                <BankLogo
                                    src={account.bank?.logo ?? null}
                                    name={account.bank?.name}
                                    fallback="icon"
                                    className={cn(
                                        'size-7 shrink-0',
                                        hidden && 'opacity-40',
                                    )}
                                />
                                <AccountName
                                    account={account}
                                    className={cn(
                                        'flex-1 truncate text-sm',
                                        hidden && 'text-muted-foreground',
                                    )}
                                />
                                {account.type !== 'credit_card' && (
                                    <button
                                        type="button"
                                        onClick={() =>
                                            onToggleNetWorth(
                                                account.id,
                                                !includedInNetWorth,
                                            )
                                        }
                                        aria-label={
                                            includedInNetWorth
                                                ? __('Exclude from net worth')
                                                : __('Include in net worth')
                                        }
                                        aria-pressed={includedInNetWorth}
                                        className={cn(
                                            'text-muted-foreground transition-colors hover:text-foreground',
                                            !includedInNetWorth && 'opacity-40',
                                        )}
                                    >
                                        <ChartColumnBig className="size-5" />
                                    </button>
                                )}
                                <button
                                    type="button"
                                    onClick={() =>
                                        onToggleVisibility(account.id, !hidden)
                                    }
                                    aria-label={
                                        hidden
                                            ? __('Show on dashboard')
                                            : __('Hide from dashboard')
                                    }
                                    aria-pressed={!hidden}
                                    className="text-muted-foreground transition-colors hover:text-foreground"
                                >
                                    {hidden ? (
                                        <EyeOff className="size-5" />
                                    ) : (
                                        <Eye className="size-5" />
                                    )}
                                </button>
                                {dragHandle}
                            </div>
                        );
                    }}
                />
            </DialogContent>
        </Dialog>
    );
}
