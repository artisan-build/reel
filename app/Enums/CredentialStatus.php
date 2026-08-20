<?php

namespace App\Enums;

enum CredentialStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
