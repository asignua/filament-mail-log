<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Support;

use Asignua\FilamentMailLog\Contracts\RelatesToMailLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\SendQueuedNotifications;

/**
 * Finds the models a message is about. Three sources: what the Mailable or the Notification itself declares
 * ({@see RelatesToMailLog}), and the notifiable of a mail notification.
 *
 * A Mailable is not part of the mail events, so a declared list travels in the view data under {@see self::DATA_KEY}
 * (see {@see \Asignua\FilamentMailLog\Concerns\HasMailLogRelations}); a notification is remembered when it starts
 * to send ({@see self::rememberNotification()}) and found again by the id Laravel puts in the same view data.
 */
final class MailRelations
{
    // A relation is carried around as a `[morph class, key]` pair, never as the model: a model in a queued
    // payload would drag all its attributes (personal data) along, and a deleted one would still look saved.
    public const string DATA_KEY = '__mail_log_relations';

    private const int REMEMBER_LIMIT = 100;

    /** @var array<string, list<array{0: string, 1: int|string}>> notification id => refs */
    private static array $notifications = [];

    public static function reset(): void
    {
        self::$notifications = [];
    }

    /**
     * Keeps what the mail channel needs later: the notification has only its id in the view data of the message.
     */
    public static function rememberNotification(NotificationSending $event): void
    {
        if ($event->channel !== 'mail' || !self::enabled()) {
            return;
        }

        $id = $event->notification->id ?? null;

        if (!is_string($id) || $id === '') {
            return;
        }

        $refs = self::refs([
            ...self::declared($event->notification),
            ...self::notifiable($event->notifiable),
        ]);

        if ($refs === []) {
            return;
        }

        self::$notifications[$id] = $refs;

        if (count(self::$notifications) > self::REMEMBER_LIMIT) {
            array_shift(self::$notifications);
        }
    }

    /**
     * The models of a message that is being sent.
     *
     * @param array<array-key, mixed> $data the view data of the `MessageSending` event
     *
     * @return list<array{0: string, 1: int|string}>
     */
    public static function forSending(array $data): array
    {
        if (!self::enabled()) {
            return [];
        }

        $refs = [];
        $declared = $data[self::DATA_KEY] ?? null;

        if (is_iterable($declared)) {
            $refs = [...$declared];
        }

        $id = $data['__laravel_notification_id'] ?? null;

        if (is_string($id) && isset(self::$notifications[$id])) {
            $refs = [...$refs, ...self::$notifications[$id]];
        }

        return self::refs($refs);
    }

    /**
     * The models of a mailable that has just been queued.
     *
     * @return list<array{0: string, 1: int|string}>
     */
    public static function forQueuedMailable(Mailable $mailable): array
    {
        return self::enabled() ? self::refs(self::declared($mailable)) : [];
    }

    /**
     * The models of a notification that has just been queued.
     *
     * @return list<array{0: string, 1: int|string}>
     */
    public static function forQueuedNotification(SendQueuedNotifications $job): array
    {
        if (!self::enabled()) {
            return [];
        }

        $refs = self::declared($job->notification);

        foreach ($job->notifiables as $notifiable) {
            $refs = [...$refs, ...self::notifiable($notifiable)];
        }

        return self::refs($refs);
    }

    /**
     * The `[morph class, key]` pair of a saved model; null for an unsaved one.
     *
     * @return array{0: string, 1: int|string}|null
     */
    public static function refOf(mixed $model): ?array
    {
        if (!$model instanceof Model || !$model->exists || !is_scalar($model->getKey())) {
            return null;
        }

        $key = $model->getKey();

        return [$model->getMorphClass(), is_int($key) ? $key : (string) $key];
    }

    /**
     * Only valid pairs (a model counts as its pair), once each, no more than `relations.max`.
     *
     * @param iterable<mixed> $items models and / or `[morph class, key]` pairs
     *
     * @return list<array{0: string, 1: int|string}>
     */
    public static function refs(iterable $items): array
    {
        $max = max(1, (int) config('filament-mail-log.relations.max', 20));
        $refs = [];

        foreach ($items as $item) {
            $ref = $item instanceof Model ? self::refOf($item) : $item;

            if (!is_array($ref) || !isset($ref[0], $ref[1]) || !is_string($ref[0]) || !is_scalar($ref[1]) || (string) $ref[1] === '') {
                continue;
            }

            $refs[$ref[0].'#'.$ref[1]] ??= [$ref[0], is_int($ref[1]) ? $ref[1] : (string) $ref[1]];

            if (count($refs) >= $max) {
                break;
            }
        }

        return array_values($refs);
    }

    /**
     * What a Mailable / Notification declares: the models of `mailLogRelations()` plus the pairs of `relatedTo()`.
     *
     * @return list<array{0: string, 1: int|string}>
     */
    public static function declaredBy(object $source): array
    {
        return self::declared($source);
    }

    /**
     * @return list<array{0: string, 1: int|string}>
     */
    private static function declared(object $source): array
    {
        if (!$source instanceof RelatesToMailLog) {
            return [];
        }

        $items = [];

        foreach ($source->mailLogRelations() as $model) {
            $items[] = $model;
        }

        if (method_exists($source, 'mailLogRelatedRefs')) {
            $items = [...$items, ...(array) $source->mailLogRelatedRefs()];
        }

        return self::refs($items);
    }

    /**
     * @return list<array{0: string, 1: int|string}>
     */
    private static function notifiable(mixed $notifiable): array
    {
        if (!(bool) config('filament-mail-log.relations.notifiable', true) || $notifiable instanceof AnonymousNotifiable) {
            return [];
        }

        return self::refs([$notifiable]);
    }

    private static function enabled(): bool
    {
        return (bool) config('filament-mail-log.relations.enabled', true) && TrackingSchema::hasRelationsTable();
    }
}
