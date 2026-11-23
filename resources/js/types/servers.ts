export type ServerStatus =
    | 'provisioning'
    | 'booting'
    | 'starting'
    | 'online'
    | 'stopping'
    | 'stop_failed'
    | 'stopped'
    | 'failed';

/** Who sees a server (#5462): App\Enums\Visibility. */
export type Visibility = 'public' | 'private' | 'link';

/** A server as App\Servers\ServerPresenter shows it. */
export type ServerSummary = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    icon_url: string | null;
    visibility: Visibility;
    status: ServerStatus;
    status_label: string;
    address: string | null;
    /** The VPS's IP while online, also when address is its DNS name (#5338). */
    ip_address: string | null;
    /** Admins only: why the DNS name isn't set. */
    dns_error: string | null;
    players_online: number | null;
    idle_minutes: number;
    /** When the watchdog stops it if nobody is on (#5378); null unless online. */
    stops_at: string | null;
    /** Seconds left at the time of the answer (0 = due); null unless online. */
    stops_in_seconds: number | null;
    /** Median Start → Online of recent starts (#5379); null without history or once online. */
    typical_boot_seconds: number | null;
    /** Seconds since Start while starting, else null. */
    boot_elapsed_seconds: number | null;
    error: string | null;
    error_label: string | null;
    can_start: boolean;
    can_stop: boolean;
    can_force_delete: boolean;
};

export type ServerTypePrice = {
    hourly: string;
    monthly: string;
    /** Gross monthly EUR, for sorting. */
    sort: number;
};

export type ServerTypeOption = {
    name: string;
    label: string;
    /** The provider's category, e.g. "Shared vCPU" (#5321). */
    group: string;
    /** Only the locations where the type can still be ordered. */
    prices: Record<string, ServerTypePrice>;
};

export type ServerCatalog = {
    types: ServerTypeOption[];
    locations: { name: string; label: string }[];
    defaults: { server_type: string | null; location: string | null };
    error: string | null;
};

export type ServerDefinition = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    visibility: Visibility;
    /** A link-only server's share link (#5462), else null. */
    share_url?: string | null;
    icon_url: string | null;
    server_type: string;
    location: string;
    start_command: string;
    java_version: number;
    idle_minutes: number;
    subdomain: string | null;
    eula_accepted_at?: string | null;
    eula_accepted_by?: string | null;
    software: SoftwareValue;
    minecraft_version: string | null;
    /** Modpacks (#5352). */
    pack_url?: string | null;
    modrinth_project_id?: string | null;
    modrinth_version_id?: string | null;
    modrinth_title?: string | null;
    heap_mb?: number | null;
};

/** App\Servers\ServerConfigForm::props() (#5377). */
export type ServerConfig = {
    /** Each common setting as the next start will have it (the change, else the world's). */
    values: Record<string, string>;
    /** The world's own value of each common setting ('' when it has none). */
    world: Record<string, string>;
    /** The admin's other key=value changes, one per line. */
    extra: string;
    /** The world's other settings, shown read-only. */
    world_other: string[];
    whitelist: string;
    ops: string;
    run_sh: string;
    run_sh_pack: string | null;
    run_sh_changed: boolean;
    user_jvm_args: string;
    user_jvm_args_pack: string | null;
    user_jvm_args_changed: boolean;
    has_world_files: boolean;
    has_world: boolean;
};

export type SoftwareValue =
    | 'custom'
    | 'vanilla'
    | 'paper'
    | 'fabric'
    | 'pack_url'
    | 'modrinth';

/** What App\Http\Controllers\Admin\ServerController::softwareOptions() sends (#5351). */
export type SoftwareOptions = {
    options: { value: SoftwareValue; label: string }[];
    /** Newest first; empty when the source couldn't be reached. */
    versions: Record<string, string[]>;
    error: string | null;
};
