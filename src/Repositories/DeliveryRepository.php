<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Repositories;

use Asignua\FilamentMailLog\Enums\DeliveryStatus;
use Asignua\FilamentMailLog\Enums\SuppressionReason;
use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Models\MailLogEvent;
use Asignua\FilamentMailLog\Models\MailSuppression;
use Asignua\FilamentMailLog\Webhooks\DeliveryEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Queries of the delivery tracking: events, the denormalized status, the suppression list.
 */
class DeliveryRepository
{
    /**
     * @return Builder<MailLogEvent>
     */
    public function events(): Builder
    {
        return MailLogEvent::query();
    }

    /**
     * @return Builder<MailSuppression>
     */
    public function suppressions(): Builder
    {
        return MailSuppression::query();
    }

    /**
     * The log row an event is about: by the correlation id the plugin stamped on the message first (exact),
     * else by the message id of the transport (the newest row wins when a provider reuses an id).
     */
    public function findLog(DeliveryEvent $event): ?MailLog
    {
        $query = MailLog::query();

        if ($event->logIds !== []) {
            // The plugin writes ULIDs in upper case; a provider may hand them back in either case, and a
            // case-sensitive database (PostgreSQL, SQLite) would not match across the two.
            $ulids = [];

            foreach ($event->logIds as $id) {
                $ulids[] = strtoupper($id);
                $ulids[] = strtolower($id);
            }

            $log = (clone $query)->whereIn('ulid', $ulids)->first();

            if ($log !== null) {
                return $log;
            }
        }

        $ids = [];

        foreach ($event->messageIds as $id) {
            $ids[] = $id;
            $ids[] = '<'.$id.'>';
        }

        return $ids === [] ? null : $query->whereIn('message_id', $ids)->latest('id')->first();
    }

    /**
     * Stores the event. False when the provider already delivered it (retry, duplicate): the pair
     * (provider, provider_event_id) is unique.
     */
    public function storeEvent(MailLog $log, DeliveryEvent $event): bool
    {
        if ($this->events()->where('provider', $event->provider)->where('provider_event_id', $event->id)->exists()) {
            return false;
        }

        try {
            $row = new MailLogEvent;
            $row->mail_log_id = (int) $log->getKey();
            $row->type = $event->type;
            $row->bounce = $event->bounce;
            $row->provider = $event->provider;
            $row->provider_event_id = $event->id;
            $row->recipient = $event->recipient === null ? null : mb_substr($event->recipient, 0, 254);
            $row->detail = $event->detail;
            $row->payload = $event->payload === [] ? null : $event->payload;
            $row->occurred_at = Carbon::instance($event->occurredAt);
            $row->save();
        } catch (UniqueConstraintViolationException) {
            // Two deliveries of the same event raced between the check and the insert.
            return false;
        }

        return true;
    }

    /**
     * Moves the denormalized status forward, never backwards (see {@see DeliveryStatus} for the order).
     * One conditional UPDATE, so two webhooks racing each other still end on the higher status.
     */
    public function advanceStatus(MailLog $log, DeliveryEvent $event): bool
    {
        $status = DeliveryStatus::fromEvent($event->type, $event->bounce);

        return MailLog::query()
            ->whereKey($log->getKey())
            ->where(fn (Builder $query): Builder => $query->whereNull('delivery_status')->orWhereIn('delivery_status', $status->replaces()))
            ->update([
                'delivery_status' => $status->value,
                'delivery_status_at' => Carbon::instance($event->occurredAt),
            ]) > 0;
    }

    /**
     * Adds an address to the suppression list. An address that is already there keeps its first reason.
     */
    public function suppress(string $email, SuppressionReason $reason, ?string $provider, ?int $mailLogId, ?string $detail): void
    {
        $email = mb_strtolower(trim($email));

        if ($email === '' || $this->suppressions()->where('email', $email)->exists()) {
            return;
        }

        try {
            $row = new MailSuppression;
            $row->email = mb_substr($email, 0, 254);
            $row->reason = $reason;
            $row->provider = $provider;
            $row->mail_log_id = $mailLogId;
            $row->detail = $detail === null ? null : mb_substr($detail, 0, 500);
            $row->save();
        } catch (UniqueConstraintViolationException) {
            // Raced with another webhook for the same address.
        }
    }

    /**
     * The given addresses (lower case) that are on the list.
     *
     * @param list<string> $emails
     *
     * @return list<string>
     */
    public function suppressedAmong(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        /** @var list<string> $found */
        $found = $this->suppressions()->whereIn('email', array_values(array_unique($emails)))->pluck('email')->map(fn (mixed $email): string => mb_strtolower((string) $email))->all();

        return $found;
    }

    /**
     * Deletes the events of the given log rows. The foreign key does it on MySQL / PostgreSQL; this covers a
     * database that does not enforce it (SQLite without foreign keys) so no event is left without a message.
     *
     * @param list<int> $logIds
     */
    public function deleteEventsOf(array $logIds): int
    {
        return $logIds === [] ? 0 : $this->events()->whereIn('mail_log_id', $logIds)->delete();
    }
}
