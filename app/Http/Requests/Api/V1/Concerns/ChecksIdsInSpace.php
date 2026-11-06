<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Services\SpaceResolver;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * For the form requests whose fields point at other objects of the request's
 * space: as in the app, each must be one of the caller's own there, so an id
 * from another space, or another member's, is refused like one that does not
 * exist.
 */
trait ChecksIdsInSpace
{
    /** @var array<string, array<string, string>> */
    private array $idsFoundInSpace = [];

    /**
     * A rule passing an id of one of the caller's own $model in the request's
     * space.
     *
     * @param  class-string<Model>  $model  an Account, Category or Label
     * @param  string  $noun  how the object is named in the message
     */
    protected function inSpace(string $model, string $noun): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($model, $noun): void {
            if (! is_string($value) || ! array_key_exists(mb_strtolower($value), $this->idsFoundInSpace($model, $attribute))) {
                $fail("The :attribute is not {$noun} of yours in this space.");
            }
        };
    }

    /**
     * An id that passed inSpace(), as it is stored: the lookup ignores case, so
     * the value sent may not match the row it found.
     *
     * @param  class-string<Model>  $model
     */
    protected function idInSpace(string $model, string $attribute, string $value): string
    {
        return $this->idsFoundInSpace($model, $attribute)[mb_strtolower($value)] ?? $value;
    }

    /**
     * The rules for a list of ids of the caller's own $model in the request's
     * space. An oversized list gets none for its items: Laravel expands them
     * per item before the cap on the list is ever read, so a huge list would
     * cost its `distinct` and its lookup just to be refused.
     *
     * @param  'present'|'sometimes'  $presence
     * @param  class-string<Model>  $model
     * @return array<string, array<mixed>>
     */
    protected function idListInSpace(string $field, string $presence, int $max, string $model, string $noun): array
    {
        $ids = $this->input($field);

        return [
            $field => [$presence, 'array', 'list', "max:{$max}"],
            ...is_array($ids) && count($ids) > $max ? [] : ["{$field}.*" => ['string', 'distinct:ignore_case', $this->inSpace($model, $noun)]],
        ];
    }

    /**
     * The validated list of ids in $field, as stored.
     *
     * @param  class-string<Model>  $model
     * @return list<string>
     */
    protected function idListAsStored(string $model, string $field): array
    {
        return array_map(
            fn (string $id): string => $this->idInSpace($model, "{$field}.*", $id),
            array_values($this->validated($field, [])),
        );
    }

    /**
     * The ids of the caller's own $model in the request's space among every
     * value sent for the field $attribute belongs to (`splits.*.label_ids.*`
     * for `splits.1.label_ids.0`), each keyed by its lowercase form. Looked up
     * in one query per field, so a list of ids costs the same as a single one.
     *
     * @param  class-string<Model>  $model
     * @return array<string, string>
     */
    private function idsFoundInSpace(string $model, string $attribute): array
    {
        $field = (string) preg_replace('/(?<=^|\.)\d+(?=\.|$)/', '*', $attribute);

        return $this->idsFoundInSpace[$model.'|'.$field] ??= $this->lookUpIdsInSpace($model, $field);
    }

    /**
     * @param  class-string<Model>  $model
     * @return array<string, string>
     */
    private function lookUpIdsInSpace(string $model, string $field): array
    {
        $sent = array_values(array_filter(
            Arr::flatten(Arr::wrap(data_get($this->all(), $field))),
            fn (mixed $id): bool => is_string($id) && Str::isUuid($id),
        ));

        if ($sent === []) {
            return [];
        }

        return app(SpaceResolver::class)->ownedForApiRequest($this, $model::query())->whereKey($sent)->pluck('id')
            ->mapWithKeys(fn (string $id): array => [mb_strtolower($id) => $id])
            ->all();
    }
}
