<?php

namespace App\Enums;

/**
 * Closes a due date without a transaction: paid some other way, or not due
 * this time.
 */
enum OccurrenceResolution: string
{
    case Paid = 'paid';
    case Skipped = 'skipped';
}
