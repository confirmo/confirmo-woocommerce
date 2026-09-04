<?php

/**
 * Plugin Name: Confirmo test helper — force the Subscribe module off
 *
 * Installed only in the test environment, never shipped.
 *
 * The Subscribe module reads its toggle at `plugins_loaded`, so a test cannot
 * turn it off and watch what happens — by then the module has booted. WordPress
 * loads must-use plugins before regular ones, which is early enough to answer
 * the option before the module ever asks.
 *
 * Active only when CONFIRMO_FORCE_SUBSCRIBE_OFF is set in the environment, so it
 * changes nothing about an ordinary test run.
 */

if (getenv('CONFIRMO_FORCE_SUBSCRIBE_OFF') !== '1') {
    return;
}

add_filter('pre_option_confirmo_subscribe_config_options', static function () {
    return ['enabled' => 'no'];
});

// Read back by the probe, so a run cannot pass because this file quietly stopped
// working and the module was on after all.
define('CONFIRMO_SUBSCRIBE_FORCED_OFF', true);
