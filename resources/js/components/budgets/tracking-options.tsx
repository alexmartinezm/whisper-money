import { type MultiSelectOption } from '@/components/ui/multi-select';
import { buildCategoryTree, flattenCategoryTree } from '@/lib/category-tree';
import { cn } from '@/lib/utils';
import { getCategoryColorClasses, type Category } from '@/types/category';
import { LabelIcon } from '@/components/shared/label-icon';
import { getLabelColorClasses, type Label } from '@/types/label';
import * as Icons from 'lucide-react';

/**
 * The tracking pickers shared by the create and edit budget dialogs.
 *
 * Both need the same flattened, indented, colour-coded options, and the two
 * copies had already started to drift in how they named their inputs. Keeping
 * one builder means a category renders identically wherever it is chosen.
 */
export function categoryTrackingOptions(
    categories: Category[],
): MultiSelectOption[] {
    return flattenCategoryTree(buildCategoryTree(categories)).map(
        (category) => {
            const colorClasses = getCategoryColorClasses(category.color);
            const IconComponent = Icons[category.icon as keyof typeof Icons] as
                | Icons.LucideIcon
                | undefined;

            return {
                value: category.id,
                label: category.name,
                depth: category.depth,
                parentValue: category.parent_id,
                icon: IconComponent ? (
                    <IconComponent className="h-3 w-3 opacity-80" />
                ) : undefined,
                badgeClassName: cn(colorClasses.bg, colorClasses.text),
            };
        },
    );
}

export function labelTrackingOptions(labels: Label[]): MultiSelectOption[] {
    return labels.map((label) => {
        const colorClasses = getLabelColorClasses(label.color);

        return {
            value: label.id,
            label: label.name,
            icon: <LabelIcon label={label} className="h-3 w-3 opacity-80" />,
            badgeClassName: cn(colorClasses.bg, colorClasses.text),
        };
    });
}
