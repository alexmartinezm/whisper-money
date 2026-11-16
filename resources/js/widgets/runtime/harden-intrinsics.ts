/**
 * The second line of defence behind the validator: before the first user
 * widget loads, the static side of every global a widget may name is made
 * read-only, so a widget that slipped past the validator still cannot swap
 * `Array.from`, `JSON.parse`, `Date.now` or `Math` itself for code of its own
 * that the app, React or a library would then call with their objects.
 *
 * - The global bindings (`globalThis.Math`, `globalThis.Date`…) become
 *   non-writable and non-configurable: `Math = {…}` cannot replace them.
 * - `Math`, `JSON` and `Intl`, and the constructors `Array`, `Object`,
 *   `Number`, `String`, `Boolean`, `Date`, `Map` and `Set`, are frozen, with
 *   every function they hold as an own property (the function objects, not
 *   what they return); for `Intl`, each constructor is frozen with its own
 *   statics too (`Intl.NumberFormat.supportedLocalesOf`).
 *
 * Static side only, on purpose. Prototype objects (`Object.prototype`,
 * `Array.prototype`, `Function.prototype`…) stay as they are: freezing them
 * triggers the "override mistake" in third-party code (assigning `toString`
 * or `constructor` on an instance throws once the inherited one is
 * read-only), which would break the app around the widgets. They are out of
 * a widget's reach anyway: `prototype`, `constructor` and `__proto__` are
 * forbidden property names and `get()` only reads own properties.
 *
 * It runs from registerWidgetModules(), so pages without user widgets (and
 * users who never load one) are untouched; it is idempotent, and an object a
 * browser will not freeze is skipped rather than breaking registration.
 */

/** Globals whose members a widget may read (see validator/allowlist.ts). */
const SHARED_OBJECTS = [
    'Math',
    'JSON',
    'Intl',
    'Array',
    'Object',
    'Number',
    'String',
    'Boolean',
    'Date',
    'Map',
    'Set',
] as const;

/** Globals a widget may name whose binding a write would replace. */
const SHARED_BINDINGS = [
    ...SHARED_OBJECTS,
    'parseInt',
    'parseFloat',
    'isNaN',
    'isFinite',
] as const;

let hardened = false;

function freeze(target: object): void {
    try {
        if (!Object.isFrozen(target)) {
            Object.freeze(target);
        }
    } catch {
        // An exotic object that refuses: the rest still gets frozen.
    }
}

/** The functions `target` holds as own data properties, `prototype` aside. */
function ownFunctions(target: object): object[] {
    const functions: object[] = [];

    for (const key of Reflect.ownKeys(target)) {
        if (key === 'prototype') {
            continue;
        }

        const descriptor = Object.getOwnPropertyDescriptor(target, key);

        if (descriptor && typeof descriptor.value === 'function') {
            functions.push(descriptor.value as object);
        }
    }

    return functions;
}

/**
 * Freeze `target` and each function it holds; `depth` 2 also freezes the
 * functions those functions hold (Intl's constructors and their statics).
 */
function freezeStatics(target: unknown, depth: number): void {
    if (
        (typeof target !== 'object' && typeof target !== 'function') ||
        target === null
    ) {
        return;
    }

    try {
        for (const value of ownFunctions(target)) {
            if (depth > 1) {
                freezeStatics(value, depth - 1);
            } else {
                freeze(value);
            }
        }
    } catch {
        // Reading the descriptors failed: still freeze the object itself.
    }

    freeze(target);
}

function lockBinding(name: string): void {
    try {
        const descriptor = Object.getOwnPropertyDescriptor(globalThis, name);

        if (descriptor?.configurable && 'value' in descriptor) {
            Object.defineProperty(globalThis, name, {
                value: descriptor.value,
                writable: false,
                enumerable: descriptor.enumerable,
                configurable: false,
            });
        }
    } catch {
        // The host refuses: the frozen object below still holds.
    }
}

/** Make the static side of the shared globals read-only, once per page. */
export function hardenIntrinsics(): void {
    if (hardened) {
        return;
    }

    hardened = true;

    const global = globalThis as unknown as Record<string, unknown>;

    for (const name of SHARED_BINDINGS) {
        lockBinding(name);
    }

    for (const name of SHARED_OBJECTS) {
        freezeStatics(global[name], name === 'Intl' ? 2 : 1);
    }
}

/** The globals hardenIntrinsics() freezes, for tests. */
export const HARDENED_GLOBALS: readonly string[] = SHARED_OBJECTS;
