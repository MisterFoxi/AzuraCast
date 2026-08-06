<?php

declare(strict_types=1);

namespace App\Radio\Enums;

enum LiquidsoapQueues: string
{
    case Requests = 'requests';
    case Interrupting = 'interrupting_requests';
    case TopOfHour = 'toth_requests';

    public static function default(): self
    {
        return self::Requests;
    }
}
