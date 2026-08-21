<?php

namespace App\Enums;

enum RecordingDeletionOutcome: string
{
    case Complete = 'complete';
    case AlreadyComplete = 'already_complete';
    case Incomplete = 'incomplete';
    case SkippedProtected = 'skipped_protected';
    case SkippedDeadline = 'skipped_deadline';

    public function completed(): bool
    {
        return in_array($this, [self::Complete, self::AlreadyComplete], true);
    }
}
