<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Api;

enum EventDirection: string
{
    case INCOMING = 'incoming';
    case OUTGOING = 'outgoing';
    case INTERNAL = 'internal';
}
