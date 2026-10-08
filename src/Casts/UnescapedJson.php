<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use JsonException;

/**
 * JSON column cast that keeps Unicode readable in the database (`Привіт`, not `П…`).
 *
 * @implements CastsAttributes<array<array-key, mixed>|null, array<array-key, mixed>|null>
 */
final class UnescapedJson implements CastsAttributes
{
    /**
     * @param array<string, mixed> $attributes
     *
     * @return array<array-key, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
