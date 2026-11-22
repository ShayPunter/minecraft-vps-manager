export type ServerStatus =
    | 'provisioning'
    | 'booting'
    | 'starting'
    | 'online'
    | 'stopping'
    | 'stop_failed'
    | 'stopped'
    | 'failed';

/** A server as App\Servers\ServerPresenter shows it. */
export type ServerSummary = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    icon_url: string | null;
    is_public: boolean;
    status: ServerStatus;
    status_label: string;
    address: string | null;
    players_online: number | null;
    idle_minutes: number;
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
    is_public: boolean;
    icon_url: string | null;
    server_type: string;
    location: string;
    start_command: string;
    java_version: number;
    idle_minutes: number;
};
