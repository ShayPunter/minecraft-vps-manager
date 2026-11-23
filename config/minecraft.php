<?php

return [
    // Spending guard (#5276): at most this many VPSes at once, across every server.
    // `?:`, not env()'s default: an empty `MVM_MAX_RUNNING=` line would skip it.
    'max_running' => (int) (env('MVM_MAX_RUNNING') ?: 2),

    // Starts by guests (not signed in: a public server's card, a share link) (#5526): each
    // server may be started this often a day (UTC) by guests (0 = guests can't start), and
    // a guest start must leave this many of the max_running slots free for signed-in players.
    'guest_starts_per_day' => is_numeric(env('MVM_GUEST_STARTS_PER_DAY')) ? (int) env('MVM_GUEST_STARTS_PER_DAY') : 3,
    'guest_free_slots' => is_numeric(env('MVM_GUEST_FREE_SLOTS')) ? (int) env('MVM_GUEST_FREE_SLOTS') : 1,

    // How often (seconds) a page view may ask the provider how a starting VPS is doing.
    // The scheduler's servers:advance runs every minute whether anyone is looking or not.
    'check_interval' => is_numeric(env('MVM_CHECK_INTERVAL')) ? (int) env('MVM_CHECK_INTERVAL') : 10,

    // Default for new servers (#5276): the cheapest type with at least this much RAM,
    // in the first location in this country.
    'default_memory_gb' => 8,
    'default_country' => 'DE',

    // Minecraft's port, used to tell when a server is up.
    'port' => 25565,

    // Watchdog (#5283). Idle minutes before a stop are set per server (default 15).
    // A run that isn't online this long after Start is deleted and marked failed.
    'boot_timeout_minutes' => (int) (env('MVM_BOOT_TIMEOUT_MINUTES') ?: 20),
    // A start that installs a modpack (#5352) downloads it, every mod and runs the loader's
    // installer first (All the Mods 10: 1.26 GB), so it gets longer.
    'pack_boot_timeout_minutes' => (int) (env('MVM_PACK_BOOT_TIMEOUT_MINUTES') ?: 45),
    // A start is given up as soon as systemd has restarted a crashing Minecraft this often (#5376).
    'crash_loop_restarts' => 3,
    // Alert once when a run has been up longer than this (#5276: 8 hours).
    'max_run_hours' => (int) (env('MVM_MAX_RUN_HOURS') ?: 8),
    // A stop still "stopping" after this long (the queue worker died) becomes stop_failed.
    // Above StopServerRun's timeout (45 min), since a big world can take that long to upload.
    'stuck_stop_minutes' => 50,
    // World storage (#5288), in seconds: prepare.sh waits for cloud-init and downloads the world;
    // save.sh zips, uploads and reads the world back. Below PrepareServerRun's and
    // StopServerRun's job timeouts, which are below the queue's retry_after.
    'prepare_timeout' => 1200,
    'pack_prepare_timeout' => 2400,
    'save_timeout' => 2400,
    // Where alerts go: this address (else every admin's email), plus a Discord webhook if set.
    'alert_mail' => env('MVM_ALERT_MAIL') ?: null,
    'alert_discord_webhook' => env('MVM_ALERT_DISCORD_WEBHOOK') ?: null,

    // Java versions offered on the server form (Ubuntu 24.04 openjdk packages).
    'java_versions' => [17, 21, 25],
];
