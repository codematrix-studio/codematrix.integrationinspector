<?php

declare(strict_types=1);

namespace CodeMatrix\IntegrationInspector\Api;

enum FinalizeResult: string
{
    case RECORDED = 'recorded';
    case ALREADY_FINALIZED = 'already_finalized';
    case NOT_RECORDED = 'not_recorded';
    case STORAGE_UNAVAILABLE = 'storage_unavailable';
}
