<?php

class WC_Confirmo_Subscribe_Log
{
    const SOURCE = 'confirmo-subscribe';

    public static function error(string $message): void
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->error($message, ['source' => self::SOURCE]);
        }
    }
}
