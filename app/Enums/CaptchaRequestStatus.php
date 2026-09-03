<?php

namespace App\Enums;

enum CaptchaRequestStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Claimed = 'claimed';
    case Failed = 'failed';
    case Expired = 'expired';
}
