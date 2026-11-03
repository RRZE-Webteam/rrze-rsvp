/** Check the persisted routing identifier without treating it as an authorization token. */
export function isInstanceId(value: string): boolean {
    return /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/.test(value);
}

/** Generate a UUID for one block, including browsers without crypto.randomUUID. */
export function createInstanceId(): string {
    if (typeof globalThis.crypto.randomUUID === 'function') return globalThis.crypto.randomUUID();
    const bytes = globalThis.crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/** Keep the mounted original's identifier when a copy is inserted before it in the document. */
export function instanceOwner(candidates: string[], claimedOwner?: string): string | undefined {
    return claimedOwner && candidates.includes(claimedOwner) ? claimedOwner : candidates[0];
}

/** Map the supported legacy course attribute while discarding malformed identifiers. */
export function legacyCourseAttributes(attributes: unknown): {
    mode: 'course'; resourceId: number; instanceId: string;
} {
    const value = attributes && typeof attributes === 'object' && 'courseId' in attributes ? attributes.courseId : 0;
    const id = typeof value === 'number' || (typeof value === 'string' && /^\d+$/.test(value)) ? Number(value) : 0;
    return { mode: 'course', resourceId: Number.isSafeInteger(id) && id > 0 ? id : 0, instanceId: createInstanceId() };
}
