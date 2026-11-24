<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Api;

enum RecordingStatus: string
{
    case RECORDED = 'recorded';
    case DISABLED = 'disabled';
    case INVALID_INPUT = 'invalid_input';
    case STORAGE_UNAVAILABLE = 'storage_unavailable';
}
