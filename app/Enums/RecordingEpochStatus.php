<?php

namespace App\Enums;

enum RecordingEpochStatus: string
{
    case Active = 'active';
    case Failed = 'failed';
}
