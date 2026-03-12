<?php

namespace ScubaDiabetes\Logbook;

class Plugin
{
    public static function init()
    {
        add_action('init', [self::class, 'boot']);
    }

    public static function boot()
    {
        // plugin logic
    }
}
