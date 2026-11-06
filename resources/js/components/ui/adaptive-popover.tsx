import {
    Drawer,
    DrawerContent,
    DrawerDescription,
    DrawerHeader,
    DrawerTitle,
    DrawerTrigger,
} from '@/components/ui/drawer';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { type ComponentProps, type ReactNode } from 'react';

/**
 * Above the toaster, which sonner pins at 999999999.
 *
 * A panel is opened on purpose and closes on its own; a toast is ambient, and
 * the categorize prompt in particular never leaves until it is answered. On the
 * phone this also puts the drawer over the toast rather than under it: the
 * drawer owns the bottom of the screen, and that is exactly where the toast sits.
 */
const ABOVE_TOASTS = 'z-[1000000000]';

/**
 * A `Command` picker in a drawer: a taller list than the popover's 300px, and
 * rows tall enough for a thumb.
 */
export const DRAWER_COMMAND_CLASS =
    '[&_[cmdk-list]]:max-h-[55vh] [&_[cmdk-item]]:min-h-11';

/** A picker opened as a bottom drawer, with the header it shows there. */
export interface PickerDrawer {
    title: string;
    description?: string;
}

/**
 * A picker's panel, with the caller deciding which one: a popover, or a bottom
 * drawer for the callers that want one (the phone's bulk-actions bar).
 */
export function PopoverOrDrawer({
    asDrawer,
    open,
    onOpenChange,
    trigger,
    title,
    description,
    children,
    align = 'end',
    modal = false,
    className,
    drawerClassName,
}: {
    asDrawer: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    trigger: ReactNode;
    /** The drawer's name, read out to a screen reader. */
    title: string;
    /** Shows the drawer's header, title included, with this line under it. */
    description?: string;
    children: ReactNode;
    align?: ComponentProps<typeof PopoverContent>['align'];
    modal?: boolean;
    /** Classes of the popover. */
    className?: string;
    /** Classes of the drawer's body. */
    drawerClassName?: string;
}) {
    if (asDrawer) {
        return (
            <Drawer open={open} onOpenChange={onOpenChange}>
                <DrawerTrigger asChild>{trigger}</DrawerTrigger>
                <DrawerContent className={ABOVE_TOASTS}>
                    <DrawerHeader
                        className={description ? 'text-left' : 'sr-only'}
                    >
                        <DrawerTitle>{title}</DrawerTitle>
                        {description && (
                            <DrawerDescription>{description}</DrawerDescription>
                        )}
                    </DrawerHeader>
                    <div
                        className={cn('overflow-y-auto pb-3', drawerClassName)}
                    >
                        {children}
                    </div>
                </DrawerContent>
            </Drawer>
        );
    }

    return (
        <Popover modal={modal} open={open} onOpenChange={onOpenChange}>
            <PopoverTrigger asChild>{trigger}</PopoverTrigger>
            <PopoverContent
                align={align}
                className={cn('overflow-hidden p-0', ABOVE_TOASTS, className)}
            >
                {children}
            </PopoverContent>
        </Popover>
    );
}
