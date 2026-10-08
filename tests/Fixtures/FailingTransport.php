<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Fixtures;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

class FailingTransport extends AbstractTransport
{
    protected function doSend(SentMessage $message): void
    {
        throw new TransportException('SMTP said no: password=hunter2');
    }

    public function __toString(): string
    {
        return 'failing';
    }
}
