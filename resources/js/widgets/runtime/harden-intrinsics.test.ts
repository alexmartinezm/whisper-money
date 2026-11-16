// @vitest-environment node
import { describe, expect, it } from 'vitest';
import { HARDENED_GLOBALS, hardenIntrinsics } from './harden-intrinsics';

describe('hardenIntrinsics', () => {
    hardenIntrinsics();

    it.each(HARDENED_GLOBALS)(
        'freezes %s and the functions it holds',
        (name) => {
            const target = (globalThis as unknown as Record<string, object>)[
                name
            ];

            expect(Object.isFrozen(target)).toBe(true);

            for (const key of Reflect.ownKeys(target)) {
                const value = Object.getOwnPropertyDescriptor(
                    target,
                    key,
                )?.value;

                if (key !== 'prototype' && typeof value === 'function') {
                    expect(Object.isFrozen(value)).toBe(true);
                }
            }
        },
    );

    it('freezes the Intl constructors with their statics', () => {
        expect(Object.isFrozen(Intl.NumberFormat)).toBe(true);
        expect(Object.isFrozen(Intl.NumberFormat.supportedLocalesOf)).toBe(
            true,
        );
        expect(() => {
            (
                Intl.NumberFormat as unknown as Record<string, unknown>
            ).supportedLocalesOf = () => [];
        }).toThrow(TypeError);
    });

    it('stops writes to statics and to the global bindings', () => {
        const global = globalThis as unknown as Record<string, unknown>;

        expect(() => {
            (Array as unknown as Record<string, unknown>).from = () => [];
        }).toThrow(TypeError);
        expect(() => {
            (JSON as unknown as Record<string, unknown>).parse = () => null;
        }).toThrow(TypeError);
        expect(() => {
            (Date as unknown as Record<string, unknown>).now = () => 0;
        }).toThrow(TypeError);
        expect(() => {
            global.Math = {};
        }).toThrow(TypeError);
        expect(() => {
            global.parseInt = () => 0;
        }).toThrow(TypeError);
        expect(Array.from('ab')).toEqual(['a', 'b']);
        expect(new Intl.NumberFormat('en-US').format(1234)).toBe('1,234');
    });

    it('leaves prototypes alone, so the override mistake never bites the app', () => {
        for (const prototype of [
            Object.prototype,
            Array.prototype,
            Function.prototype,
            Date.prototype,
            Map.prototype,
        ]) {
            expect(Object.isFrozen(prototype)).toBe(false);
        }

        const instance = { toString: () => 'own' };

        expect(String(instance)).toBe('own');
    });

    it('is idempotent', () => {
        expect(() => hardenIntrinsics()).not.toThrow();
    });
});
