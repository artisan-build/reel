<?php

namespace App\Enums;

enum RecordingSessionStatus: string
{
    case Recording = 'recording';
    case Closing = 'closing';
    case Compacting = 'compacting';
    case Ready = 'ready';
    case Deleting = 'deleting';
    case Deleted = 'deleted';
    case Failed = 'failed';
}
