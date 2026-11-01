<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Support;

use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Repositories\MailLogRelationRepository;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Throwable;

/**
 * Human text for "the record a message is about": the title Filament's resource gives it, else a `name` /
 * `title`, else `Order #12`. A model that no longer exists (or a class that was renamed) still gets a label.
 */
final class RelatedLabel
{
    /**
     * @return list<string>
     */
    public static function forLog(MailLog $log): array
    {
        $labels = [];

        foreach ($log->relatedLinks()->orderBy('related_type')->orderBy('related_id')->get() as $link) {
            $model = self::find($link->related_type, $link->related_id);

            $labels[] = $model === null
                ? self::typeLabel($link->related_type).' #'.$link->related_id
                : self::forModel($model);
        }

        return $labels;
    }

    public static function forModel(Model $model): string
    {
        $type = self::typeLabel($model->getMorphClass());

        try {
            $resource = Filament::getModelResource($model::class);

            if ($resource !== null) {
                $title = $resource::getRecordTitle($model);

                if ((is_string($title) || $title instanceof \Stringable) && (string) $title !== '') {
                    return $type.': '.$title;
                }
            }
        } catch (Throwable) {
            // No panel, or a resource that can not title the record: fall through.
        }

        foreach (['name', 'title', 'number', 'reference'] as $attribute) {
            $value = $model->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $type.': '.$value;
            }
        }

        return $type.' #'.$model->getKey();
    }

    /**
     * `App\Models\Order` becomes "Order"; a morph alias stays as written.
     */
    public static function typeLabel(string $morphClass): string
    {
        $class = Relation::getMorphedModel($morphClass) ?? $morphClass;

        return class_basename($class);
    }

    /**
     * Options of the "related to" filter: the types that have at least one link.
     *
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        $options = [];

        foreach (app(MailLogRelationRepository::class)->distinctTypes() as $type) {
            $options[$type] = self::typeLabel($type);
        }

        return $options;
    }

    private static function find(string $morphClass, int|string $id): ?Model
    {
        try {
            $class = Relation::getMorphedModel($morphClass) ?? $morphClass;

            if (!class_exists($class) || !is_subclass_of($class, Model::class)) {
                return null;
            }

            return $class::query()->find($id);
        } catch (Throwable) {
            return null;
        }
    }
}
