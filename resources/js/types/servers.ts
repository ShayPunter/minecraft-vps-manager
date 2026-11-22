export type ServerStatus =
    | 'provisioning'
    | 'booting'
    | 'starting'
    | 'online'
    | 'stopping'
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
    error: string | null;
    can_start: boolean;
};

export type ServerTypeOption = {
    name: string;
    label: string;
    prices: Record<string, string>;
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
};
