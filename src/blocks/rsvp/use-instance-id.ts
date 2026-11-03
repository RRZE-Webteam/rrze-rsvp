import { store as blockEditorStore } from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { createInstanceId, instanceOwner, isInstanceId } from './identity';
import type { RsvpAttributes } from './types';

/** Mounted owners preserve the original ID even if its duplicate is inserted above it. */
const owners = new Map<string, string>();

/**
 * Persist one stable UUID per editor block and replace IDs copied by duplication or paste.
 * Descendant blocks are included; repeated synced-pattern rendering is handled by PHP.
 */
export function useInstanceId(clientId: string, instanceId: string,
    setAttributes: (attributes: Partial<RsvpAttributes>) => void): void {
    const candidateIds = useSelect(select => {
        const editor = select(blockEditorStore);
        return editor.getBlocksByName('rrze/rsvp')
            .filter(id => editor.getBlockAttributes(id)?.instanceId === instanceId).join(',');
    }, [instanceId]);

    /** Allocate only missing, invalid or duplicated identifiers; selection changes retain the UUID. */
    useEffect(() => {
        const candidates = candidateIds ? candidateIds.split(',') : [clientId];
        const owner = instanceOwner(candidates, owners.get(instanceId));
        if (!isInstanceId(instanceId) || owner !== clientId) {
            const nextId = createInstanceId();
            owners.set(nextId, clientId);
            setAttributes({ instanceId: nextId });
        } else {
            owners.set(instanceId, clientId);
        }
    }, [clientId, instanceId, candidateIds, setAttributes]);

    /** Release mounted ownership when a block leaves the editor, without changing saved attributes. */
    useEffect(() => () => {
        owners.forEach((owner, id) => { if (owner === clientId) owners.delete(id); });
    }, [clientId]);
}
