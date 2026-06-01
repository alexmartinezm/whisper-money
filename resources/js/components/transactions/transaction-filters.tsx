import { __ } from '@/utils/i18n';
import { format } from 'date-fns';
import * as Icons from 'lucide-react';
import { Check, ChevronsUpDown, Tag, X } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';

import { AccountName } from '@/components/accounts/account-name';
import { SavedFilters } from '@/components/transactions/saved-filters';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import { Label as FormLabel } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { type Account } from '@/types/account';
import { type Category, getCategoryColorClasses } from '@/types/category';
import { getLabelColorClasses, type Label } from '@/types/label';
import { type TransactionFilters as FiltersType } from '@/types/transaction';
import { CategoryIcon } from '../shared/category-combobox';

interface TransactionFiltersProps {
    filters: FiltersType;
    onFiltersChange: (filters: FiltersType) => void;
    categories: Category[];
    labels: Label[];
    accounts: Account[];
    isKeySet: boolean;
    actions?: ReactNode;
    hideAccountFilter?: boolean;
    hideSearch?: boolean;
    inlineCategoryLabel?: boolean;
}

export function TransactionFilters({
    filters,
    onFiltersChange,
    categories,
    labels,
    accounts,
    actions,
    hideAccountFilter = false,
    hideSearch = false,
    inlineCategoryLabel = false,
}: TransactionFiltersProps) {
    const [isOpen, setIsOpen] = useState(false);
    const [searchText, setSearchText] = useState(filters.searchText);
    const [creditorName, setCreditorName] = useState(filters.creditorName);
    const [debtorName, setDebtorName] = useState(filters.debtorName);

    // Debounce text filter updates
    useEffect(() => {
        const timer = setTimeout(() => {
            if (
                searchText !== filters.searchText ||
                creditorName !== filters.creditorName ||
                debtorName !== filters.debtorName
            ) {
                onFiltersChange({
                    ...filters,
                    searchText,
                    creditorName,
                    debtorName,
                });
            }
        }, 300);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [searchText, creditorName, debtorName]);

    // Sync local state when filters change externally
    useEffect(() => {
        if (filters.searchText !== searchText) {
            setSearchText(filters.searchText);
        }
        if (filters.creditorName !== creditorName) {
            setCreditorName(filters.creditorName);
        }
        if (filters.debtorName !== debtorName) {
            setDebtorName(filters.debtorName);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [filters.searchText, filters.creditorName, filters.debtorName]);

    function handleCategoryToggle(categoryId: string) {
        const newCategoryIds = filters.categoryIds.includes(categoryId)
            ? filters.categoryIds.filter((id) => id !== categoryId)
            : [...filters.categoryIds, categoryId];

        onFiltersChange({ ...filters, categoryIds: newCategoryIds });
    }

    function handleAccountToggle(accountId: string) {
        const newAccountIds = filters.accountIds.includes(accountId)
            ? filters.accountIds.filter((id) => id !== accountId)
            : [...filters.accountIds, accountId];

        onFiltersChange({ ...filters, accountIds: newAccountIds });
    }

    function handleLabelToggle(labelId: string) {
        const newLabelIds = filters.labelIds.includes(labelId)
            ? filters.labelIds.filter((id) => id !== labelId)
            : [...filters.labelIds, labelId];

        onFiltersChange({ ...filters, labelIds: newLabelIds });
    }

    function clearFilters() {
        setSearchText('');
        setCreditorName('');
        setDebtorName('');
        onFiltersChange({
            dateFrom: null,
            dateTo: null,
            amountMin: null,
            amountMax: null,
            categoryIds: [],
            accountIds: [],
            labelIds: [],
            creditorName: '',
            debtorName: '',
            searchText: '',
        });
    }

    const activeFilterCount =
        (filters.dateFrom ? 1 : 0) +
        (filters.dateTo ? 1 : 0) +
        (filters.amountMin !== null ? 1 : 0) +
        (filters.amountMax !== null ? 1 : 0) +
        (filters.creditorName ? 1 : 0) +
        (filters.debtorName ? 1 : 0) +
        (inlineCategoryLabel
            ? 0
            : filters.categoryIds.length + filters.labelIds.length) +
        (hideAccountFilter ? 0 : filters.accountIds.length);

    return (
        <div className="space-y-4">
            <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
                <div className="flex w-full flex-wrap items-center gap-2">
                    {!hideSearch && (
                        <Input
                            placeholder={__('Search description or notes...')}
                            value={searchText}
                            onChange={(e) => setSearchText(e.target.value)}
                            className="min-w-0 flex-1 md:min-w-[350px]"
                        />
                    )}

                    {inlineCategoryLabel && (
                        <>
                            <CategoryMultiSelect
                                categories={categories}
                                selectedIds={filters.categoryIds}
                                onToggle={handleCategoryToggle}
                                triggerClassName="w-[230px]"
                            />
                            <LabelMultiSelect
                                labels={labels}
                                selectedIds={filters.labelIds}
                                onToggle={handleLabelToggle}
                                triggerClassName="w-[210px]"
                            />
                        </>
                    )}

                    <div className="flex items-center gap-2 lg:ml-auto">
                        <Popover open={isOpen} onOpenChange={setIsOpen}>
                            <PopoverTrigger asChild>
                                <Button variant="outline">
                                    {__('Filters')}

                                    {activeFilterCount > 0 && (
                                        <Badge
                                            variant="secondary"
                                            className="ml-2 rounded-full px-1.5 py-0.5"
                                        >
                                            {activeFilterCount}
                                        </Badge>
                                    )}
                                </Button>
                            </PopoverTrigger>
                            <PopoverContent
                                className="max-h-[600px] w-96 overflow-y-auto"
                                align="start"
                            >
                                <div className="space-y-4">
                                    <div className="flex items-center justify-between">
                                        <h4 className="font-medium">
                                            {__('Filters')}
                                        </h4>
                                    </div>

                                    <div className="space-y-2">
                                        <FormLabel>{__('Date')}</FormLabel>
                                        <div className="grid grid-cols-2 gap-2 pt-2">
                                            <Input
                                                type="date"
                                                value={
                                                    filters.dateFrom
                                                        ? format(
                                                              filters.dateFrom,
                                                              'yyyy-MM-dd',
                                                          )
                                                        : ''
                                                }
                                                onChange={(e) =>
                                                    onFiltersChange({
                                                        ...filters,
                                                        dateFrom: e.target.value
                                                            ? new Date(
                                                                  e.target
                                                                      .value,
                                                              )
                                                            : null,
                                                    })
                                                }
                                                placeholder={__('From')}
                                            />

                                            <Input
                                                type="date"
                                                value={
                                                    filters.dateTo
                                                        ? format(
                                                              filters.dateTo,
                                                              'yyyy-MM-dd',
                                                          )
                                                        : ''
                                                }
                                                onChange={(e) =>
                                                    onFiltersChange({
                                                        ...filters,
                                                        dateTo: e.target.value
                                                            ? new Date(
                                                                  e.target
                                                                      .value,
                                                              )
                                                            : null,
                                                    })
                                                }
                                                placeholder={__('To')}
                                            />
                                        </div>
                                    </div>

                                    <div className="space-y-2">
                                        <FormLabel>{__('Amount')}</FormLabel>
                                        <div className="grid grid-cols-2 gap-2 pt-2">
                                            <Input
                                                type="number"
                                                step="0.01"
                                                value={filters.amountMin ?? ''}
                                                onChange={(e) =>
                                                    onFiltersChange({
                                                        ...filters,
                                                        amountMin: e.target
                                                            .value
                                                            ? parseFloat(
                                                                  e.target
                                                                      .value,
                                                              )
                                                            : null,
                                                    })
                                                }
                                                placeholder={__('Min')}
                                            />

                                            <Input
                                                type="number"
                                                step="0.01"
                                                value={filters.amountMax ?? ''}
                                                onChange={(e) =>
                                                    onFiltersChange({
                                                        ...filters,
                                                        amountMax: e.target
                                                            .value
                                                            ? parseFloat(
                                                                  e.target
                                                                      .value,
                                                              )
                                                            : null,
                                                    })
                                                }
                                                placeholder={__('Max')}
                                            />
                                        </div>
                                    </div>

                                    <div className="space-y-2">
                                        <FormLabel>
                                            {__('Counterparties')}
                                        </FormLabel>
                                        <div className="grid grid-cols-2 gap-2 pt-2">
                                            <Input
                                                value={creditorName}
                                                onChange={(e) =>
                                                    setCreditorName(
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder={__(
                                                    'Creditor name',
                                                )}
                                            />
                                            <Input
                                                value={debtorName}
                                                onChange={(e) =>
                                                    setDebtorName(
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder={__('Debtor name')}
                                            />
                                        </div>
                                    </div>

                                    {!inlineCategoryLabel && (
                                        <div className="space-y-2">
                                            <FormLabel>
                                                {__('Categories')}
                                            </FormLabel>
                                            <div className="pt-2">
                                                <CategoryMultiSelect
                                                    categories={categories}
                                                    selectedIds={
                                                        filters.categoryIds
                                                    }
                                                    onToggle={
                                                        handleCategoryToggle
                                                    }
                                                    triggerClassName="w-full"
                                                />
                                            </div>
                                        </div>
                                    )}

                                    {!inlineCategoryLabel && (
                                        <div className="space-y-2">
                                            <FormLabel>
                                                {__('Labels')}
                                            </FormLabel>
                                            <div className="pt-2">
                                                <LabelMultiSelect
                                                    labels={labels}
                                                    selectedIds={
                                                        filters.labelIds
                                                    }
                                                    onToggle={handleLabelToggle}
                                                    triggerClassName="w-full"
                                                />
                                            </div>
                                        </div>
                                    )}

                                    {!hideAccountFilter && (
                                        <div className="space-y-2">
                                            <FormLabel>
                                                {__('Accounts')}
                                            </FormLabel>
                                            <div className="flex flex-wrap gap-2 pt-2">
                                                {accounts.map((account) => {
                                                    const isSelected =
                                                        filters.accountIds.includes(
                                                            account.id,
                                                        );
                                                    return (
                                                        <Badge
                                                            key={account.id}
                                                            variant={
                                                                isSelected
                                                                    ? 'default'
                                                                    : 'outline'
                                                            }
                                                            className="cursor-pointer px-2 py-1"
                                                            onClick={() =>
                                                                handleAccountToggle(
                                                                    account.id,
                                                                )
                                                            }
                                                        >
                                                            <AccountName
                                                                account={
                                                                    account
                                                                }
                                                                length={{
                                                                    min: 6,
                                                                    max: 28,
                                                                }}
                                                            />
                                                        </Badge>
                                                    );
                                                })}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </PopoverContent>
                        </Popover>

                        <SavedFilters
                            filters={filters}
                            onLoad={onFiltersChange}
                        />

                        {activeFilterCount > 0 && (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={clearFilters}
                                className="h-9"
                            >
                                <X className="mr-1 h-4 w-4" />
                                {__('Clear')}
                            </Button>
                        )}
                    </div>
                </div>

                {actions ? <div className="w-full">{actions}</div> : null}
            </div>
        </div>
    );
}

interface CategoryMultiSelectProps {
    categories: Category[];
    selectedIds: string[];
    onToggle: (id: string) => void;
    triggerClassName?: string;
}

function CategoryMultiSelect({
    categories,
    selectedIds,
    onToggle,
    triggerClassName,
}: CategoryMultiSelectProps) {
    const [open, setOpen] = useState(false);
    // Snapshot the selected-first order when the dropdown opens so items don't
    // reshuffle as the user toggles; it recomputes on the next open.
    const [orderedCategories, setOrderedCategories] = useState<Category[]>(() =>
        sortSelectedFirst(categories, selectedIds),
    );
    const isUncategorizedSelected = selectedIds.includes(
        UNCATEGORIZED_CATEGORY_ID,
    );

    function handleOpenChange(next: boolean) {
        if (next) {
            setOrderedCategories(sortSelectedFirst(categories, selectedIds));
        }
        setOpen(next);
    }

    return (
        <Popover open={open} onOpenChange={handleOpenChange}>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    role="combobox"
                    className={cn('justify-between', triggerClassName)}
                >
                    {selectedIds.length > 0 ? (
                        <span className="truncate">
                            {selectedIds.length === 1
                                ? __('1 category selected')
                                : __(':count categories selected', {
                                      count: selectedIds.length,
                                  })}
                        </span>
                    ) : (
                        <span className="text-muted-foreground">
                            {__('Categories')}
                        </span>
                    )}
                    <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-full p-0" align="start">
                <Command>
                    <CommandInput placeholder={__('Search categories...')} />
                    <CommandList>
                        <CommandEmpty>{__('No category found.')}</CommandEmpty>
                        <CommandItem
                            onSelect={() => onToggle(UNCATEGORIZED_CATEGORY_ID)}
                        >
                            <div
                                className={cn(
                                    'mr-1 flex size-4 items-center justify-center rounded-sm border border-primary p-1',
                                    isUncategorizedSelected
                                        ? 'bg-primary/10 text-primary-foreground'
                                        : 'opacity-50 [&_svg]:invisible',
                                )}
                            >
                                <Check className="size-3" />
                            </div>
                            <div className="flex items-center gap-2">
                                <div className="flex h-5 w-5 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                                    <Icons.HelpCircle className="h-3 w-3 text-zinc-500" />
                                </div>
                                {__('Uncategorized')}
                            </div>
                        </CommandItem>
                        {orderedCategories.map((category) => {
                            const isSelected = selectedIds.includes(
                                category.id,
                            );
                            const colorClasses = getCategoryColorClasses(
                                category.color,
                            );
                            return (
                                <CommandItem
                                    key={category.id}
                                    onSelect={() => onToggle(category.id)}
                                >
                                    <div
                                        className={cn(
                                            'mr-1 flex size-4 items-center justify-center rounded-sm border border-primary p-1',
                                            isSelected
                                                ? 'bg-primary/10 text-primary-foreground'
                                                : 'opacity-50 [&_svg]:invisible',
                                        )}
                                    >
                                        <Check className="size-3" />
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <div
                                            className={cn(
                                                'flex h-5 w-5 items-center justify-center rounded-full',
                                                colorClasses.bg,
                                            )}
                                        >
                                            <CategoryIcon category={category} />
                                        </div>
                                        <span>{category.name}</span>
                                    </div>
                                </CommandItem>
                            );
                        })}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

interface LabelMultiSelectProps {
    labels: Label[];
    selectedIds: string[];
    onToggle: (id: string) => void;
    triggerClassName?: string;
}

function LabelMultiSelect({
    labels,
    selectedIds,
    onToggle,
    triggerClassName,
}: LabelMultiSelectProps) {
    const [open, setOpen] = useState(false);
    // Snapshot the selected-first order when the dropdown opens so items don't
    // reshuffle as the user toggles; it recomputes on the next open.
    const [orderedLabels, setOrderedLabels] = useState<Label[]>(() =>
        sortSelectedFirst(labels, selectedIds),
    );

    function handleOpenChange(next: boolean) {
        if (next) {
            setOrderedLabels(sortSelectedFirst(labels, selectedIds));
        }
        setOpen(next);
    }

    return (
        <Popover open={open} onOpenChange={handleOpenChange}>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    role="combobox"
                    className={cn('justify-between', triggerClassName)}
                >
                    {selectedIds.length > 0 ? (
                        <span className="truncate">
                            {selectedIds.length === 1
                                ? __('1 label selected')
                                : __(':count labels selected', {
                                      count: selectedIds.length,
                                  })}
                        </span>
                    ) : (
                        <span className="text-muted-foreground">
                            {__('Labels')}
                        </span>
                    )}
                    <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-full p-0" align="start">
                <Command>
                    <CommandInput placeholder={__('Search labels...')} />
                    <CommandList>
                        <CommandEmpty>{__('No labels found.')}</CommandEmpty>
                        {orderedLabels.map((label) => {
                            const isSelected = selectedIds.includes(label.id);
                            const colorClasses = getLabelColorClasses(
                                label.color,
                            );
                            return (
                                <CommandItem
                                    key={label.id}
                                    onSelect={() => onToggle(label.id)}
                                >
                                    <div
                                        className={cn(
                                            'mr-1 flex size-4 items-center justify-center rounded-sm border border-primary p-1',
                                            isSelected
                                                ? 'bg-primary/10 text-primary-foreground'
                                                : 'opacity-50 [&_svg]:invisible',
                                        )}
                                    >
                                        <Check className="size-3" />
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <div
                                            className={cn(
                                                'flex h-5 w-5 items-center justify-center rounded-full',
                                                colorClasses.bg,
                                            )}
                                        >
                                            <Tag
                                                className={cn(
                                                    'h-3 w-3',
                                                    colorClasses.text,
                                                )}
                                            />
                                        </div>
                                        <span>{label.name}</span>
                                    </div>
                                </CommandItem>
                            );
                        })}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

const UNCATEGORIZED_CATEGORY_ID = 'uncategorized';

/** Returns selected items first, preserving the original order within each group. */
function sortSelectedFirst<T extends { id: string }>(
    items: T[],
    selectedIds: string[],
): T[] {
    return [
        ...items.filter((item) => selectedIds.includes(item.id)),
        ...items.filter((item) => !selectedIds.includes(item.id)),
    ];
}
