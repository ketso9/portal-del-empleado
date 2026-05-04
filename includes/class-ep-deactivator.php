<?php

class EP_Deactivator
{
    public static function deactivate()
    {
        wp_clear_scheduled_hook('ep_daily_license_sync');
        flush_rewrite_rules();
    }
}
