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
    public const string DATA_KEY = '__mail_log_relations';

    private const int REMEMBER_LIMIT = 100;

    /** @var array<string, list<Model>> notification id => models */
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

        $models = self::models([
            ...self::declared($event->notification),
            ...self::notifiable($event->notifiable),
        ]);

        if ($models === []) {
            return;
        }

        self::$notifications[$id] = $models;

        if (count(self::$notifications) > self::REMEMBER_LIMIT) {
            array_shift(self::$notifications);
        }
    }

    /**
     * The models of a message that is being sent.
     *
     * @param array<array-key, mixed> $data the view data of the `MessageSending` event
     *
     * @return list<Model>
     */
    public static function forSending(array $data): array
    {
        if (!self::enabled()) {
            return [];
        }

        $models = [];
        $declared = $data[self::DATA_KEY] ?? null;

        if (is_iterable($declared)) {
            $models = [...$declared];
        }

        $id = $data['__laravel_notification_id'] ?? null;

        if (is_string($id) && isset(self::$notifications[$id])) {
            $models = [...$models, ...self::$notifications[$id]];
        }

        return self::models($models);
    }

    /**
     * The models of a mailable that has just been queued.
     *
     * @return list<Model>
     */
    public static function forQueuedMailable(Mailable $mailable): array
    {
        return self::enabled() ? self::models(self::declared($mailable)) : [];
    }

    /**
     * The models of a notification that has just been queued.
     *
     * @return list<Model>
     */
    public static function forQueuedNotification(SendQueuedNotifications $job): array
    {
        if (!self::enabled()) {
            return [];
        }

        $models = self::declared($job->notification);

        foreach ($job->notifiables as $notifiable) {
            $models = [...$models, ...self::notifiable($notifiable)];
        }

        return self::models($models);
    }

    /**
     * Only saved models, once each, no more than `relations.max`.
     *
     * @param iterable<mixed> $items
     *
     * @return list<Model>
     */
    public static function models(iterable $items): array
    {
        $max = max(1, (int) config('filament-mail-log.relations.max', 20));
        $models = [];

        foreach ($items as $item) {
            if (!$item instanceof Model || !$item->exists || $item->getKey() === null) {
                continue;
            }

            $models[$item->getMorphClass().'#'.$item->getKey()] ??= $item;

            if (count($models) >= $max) {
                break;
            }
        }

        return array_values($models);
    }

    /**
     * @return list<Model>
     */
    private static function declared(object $source): array
    {
        if (!$source instanceof RelatesToMailLog) {
            return [];
        }

        $models = [];

        foreach ($source->mailLogRelations() as $model) {
            $models[] = $model;
        }

        return self::models($models);
    }

    /**
     * @return list<Model>
     */
    private static function notifiable(mixed $notifiable): array
    {
        if (!(bool) config('filament-mail-log.relations.notifiable', true) || $notifiable instanceof AnonymousNotifiable) {
            return [];
        }

        return self::models([$notifiable]);
    }

    private static function enabled(): bool
    {
        return (bool) config('filament-mail-log.relations.enabled', true) && TrackingSchema::hasRelationsTable();
    }
}
