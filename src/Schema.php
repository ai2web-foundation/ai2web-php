<?php

declare(strict_types=1);

namespace Ai2Web;

/**
 * Minimal JSON-Schema-subset validator for action input schemas. Port of @ai2web/core
 * validateSchema: pragmatic (object with typed/required properties, primitives, arrays,
 * enum) rather than the whole of JSON Schema. Used by Server to validate incoming
 * requests against an action's declared input_schema.
 */
final class Schema
{
    /**
     * @return array{valid:bool,errors:string[]}
     */
    public static function validate(mixed $value, mixed $schema, string $path = 'input'): array
    {
        $errors = [];
        if (!is_array($schema) || $schema === []) {
            return ['valid' => true, 'errors' => $errors];
        }

        $declared = $schema['type'] ?? null;
        if ($declared !== null) {
            $ok = $declared === 'integer' ? (is_int($value) && !is_bool($value)) : self::typeOf($value) === $declared;
            if (!$ok) {
                $errors[] = "$path: expected $declared, got " . self::typeOf($value);
                return ['valid' => false, 'errors' => $errors]; // wrong base type: stop
            }
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $errors[] = "$path: value is not one of the allowed options";
        }

        $isObject = is_array($value) && self::typeOf($value) === 'object';
        if (($declared === 'object' || ($declared === null && $isObject)) && $isObject) {
            $props = $schema['properties'] ?? [];
            foreach (($schema['required'] ?? []) as $key) {
                if (!array_key_exists($key, $value)) {
                    $errors[] = "$path.$key: required";
                }
            }
            foreach ($props as $key => $sub) {
                if (array_key_exists($key, $value)) {
                    $r = self::validate($value[$key], $sub, "$path.$key");
                    $errors = array_merge($errors, $r['errors']);
                }
            }
        }

        if (($declared === 'array' || ($declared === null && self::typeOf($value) === 'array')) && self::typeOf($value) === 'array' && isset($schema['items'])) {
            foreach ($value as $i => $item) {
                $r = self::validate($item, $schema['items'], $path . "[$i]");
                $errors = array_merge($errors, $r['errors']);
            }
        }

        return ['valid' => count($errors) === 0, 'errors' => $errors];
    }

    private static function typeOf(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return 'boolean';
        }
        if (is_int($v) || is_float($v)) {
            return 'number';
        }
        if (is_string($v)) {
            return 'string';
        }
        if (is_array($v)) {
            // JSON objects and arrays both decode to PHP arrays. Treat a sequential list
            // as 'array' and an associative array as 'object'. An empty array is ambiguous;
            // treat it as 'object' since action input schemas are object-shaped.
            if ($v === []) {
                return 'object';
            }
            return array_is_list($v) ? 'array' : 'object';
        }
        return 'unknown';
    }
}
