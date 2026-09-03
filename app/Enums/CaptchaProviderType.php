<?php

namespace App\Enums;

enum CaptchaProviderType: string
{
    case CapMonster = 'capmonster';
    case TwoCaptcha = '2captcha';
    case CaptchaAi = 'captchaai';
    case CapSolver = 'capsolver';
    case SolveCaptcha = 'solvecaptcha';
    case InHouse = 'in_house';

    /**
     * The self-hosted solver, which mints a token locally and synchronously
     * instead of submitting a task to a vendor API and polling for the result.
     *
     * Callers that speak the vendor task protocol (createTask / getTaskResult /
     * getBalance / PollCaptchaTasksCommand) must skip this type — SolveCaptchaJob
     * completes an in-house request inline and it never reaches the poller.
     */
    public function isInHouse(): bool
    {
        return $this === self::InHouse;
    }
}
