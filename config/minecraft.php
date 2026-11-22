<?php

return [
    // Spending guard (#5276): at most this many VPSes at once, across every server.
    // `?:`, not env()'s default: an empty `MVM_MAX_RUNNING=` line would skip it.
    'max_running' => (int) (env('MVM_MAX_RUNNING') ?: 2),

    // How often (seconds) a page view may ask the provider how a starting VPS is doing.
    // The scheduler's servers:advance runs every minute whether anyone is looking or not.
    'check_interval' => is_numeric(env('MVM_CHECK_INTERVAL')) ? (int) env('MVM_CHECK_INTERVAL') : 10,

    // Default for new servers (#5276): the cheapest type with at least this much RAM,
    // in the first location in this country.
    'default_memory_gb' => 8,
    'default_country' => 'DE',

    // Minecraft's port, used to tell when a server is up.
    'port' => 25565,

    // Java versions offered on the server form (Ubuntu 24.04 openjdk packages).
    'java_versions' => [17, 21, 25],
];
