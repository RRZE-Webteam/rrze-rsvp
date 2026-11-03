/** The empty mode represents a newly inserted, unconfigured block. */
export type Mode = '' | 'course' | 'consultation' | 'seat';

/** Persisted attributes; offer settings remain in their original resources. */
export interface RsvpAttributes extends Record<string, unknown> {
    mode: Mode;
    resourceId: number;
    instanceId: string;
}

/** An object-level authorized resource label. */
export interface Resource {
    id: number;
    title: string;
    status: string;
    editUrl: string;
}

/** Bounded search result, deliberately excluding private booking details. */
export interface ResourcePage {
    items: Resource[];
    hasMore: boolean;
}

/** Passive preview data with no submission tokens or participant information. */
export interface Preview extends Resource {
    mode: Exclude<Mode, ''>;
    unpublished: boolean;
    description: string;
    location?: string;
    dates?: { id: number; label: string; location: string; available: boolean; remaining: number }[];
    moreDates?: boolean;
    month?: string;
    offset?: number;
    weekdays?: string[];
    days?: { day: number; date: string; available: boolean }[];
    selectedDate?: string;
    times?: { label: string; remaining: number }[];
}
