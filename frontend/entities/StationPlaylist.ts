import {
    PlaylistGroupAllowedRequests,
    PlaylistOrders,
    PlaylistSources,
    PlaylistTypes,
} from "~/entities/ApiInterfaces.ts";

export interface PlaylistBreadcrumb {
    id: number;
    name: string;
}

export interface StationPlaylistGroupMemberEnriched {
    id: number;
    name: string;
    weight: number;
    consecutive_plays: number;
    play_full_cycle: boolean;
    allowed_requests: PlaylistGroupAllowedRequests;
    source: PlaylistSources;
    order: PlaylistOrders;
    num_songs?: number;
    is_enabled: boolean;
    playlists: StationPlaylistGroupMemberEnriched[];
}

export interface StationPlaylistEnriched {
    id: number;
    name: string;
    description?: string;
    source: PlaylistSources;
    order: PlaylistOrders;
    type: PlaylistTypes;
    weight: number;
    play_per_songs?: number;
    play_per_minutes?: number;
    play_per_hour_minute?: number;
    num_songs?: number;
    is_jingle?: boolean;
    is_enabled: boolean;
    include_in_on_demand?: boolean;
    schedule_items: unknown[];
    playlists: StationPlaylistGroupMemberEnriched[];
    links: {
        self?: string;
        members?: string;
        [key: string]: string | undefined;
    };
}
