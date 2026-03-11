<?php

if (!defined('ABSPATH')) {
    exit;
}

class UIX_DF_Rec_Result_Codes
{
    public static function is_success($code)
    {
        return in_array($code, ['000.000.000', '000.100.110', '000.200.100', '000.100.112'], true);
    }

    public static function is_hard_decline($code)
    {
        return in_array($code, ['100.400.147', '800.100.151'], true);
    }
}
