import { BlockControls, InspectorControls, useBlockProps } from '@wordpress/block-editor';
import type { BlockEditProps } from '@wordpress/blocks';
import { Button, ComboboxControl, Notice, PanelBody, Placeholder, SelectControl, Spinner, ToolbarButton, ToolbarGroup } from '@wordpress/components';
import { useRef, useState } from '@wordpress/element';
import type { FocusEvent } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { rsvpIcon } from './icon';
import OfferPreview from './preview';
import type { Mode, Resource, RsvpAttributes } from './types';
import { useInstanceId } from './use-instance-id';
import { useOffers, usePreview } from './use-offers';
import { variations } from './variations';

/** Read the translated title of a configured variation. */
function modeTitle(mode: Mode): string {
    return variations.find(variation => variation.name === mode)?.title ?? __('RSVP', 'rrze-rsvp');
}

/** Distinguish unpublished resources without exposing backend status codes in the interface. */
function statusLabel(status: string): string {
    if (status === 'draft') return __('Draft', 'rrze-rsvp');
    if (status === 'pending') return __('Pending review', 'rrze-rsvp');
    if (status === 'future') return __('Scheduled', 'rrze-rsvp');
    if (status === 'private') return __('Private', 'rrze-rsvp');
    return '';
}

/** Explain the next step using the resource type the editor selected. */
function selectionLabel(mode: Mode): string {
    return mode === 'course' ? __('Select a course', 'rrze-rsvp') : mode === 'consultation'
        ? __('Select a consultation room', 'rrze-rsvp') : __('Select a room', 'rrze-rsvp');
}

/** Present the three registered variations inside WordPress's standard block placeholder. */
function ModePicker({ onSelect }: { onSelect: (mode: Mode) => void }) {
    return <Placeholder className="rsvp-editor-setup" isColumnLayout
        label={__('What would you like visitors to book?', 'rrze-rsvp')}
        instructions={__('Choose a booking type, then select an existing offer.', 'rrze-rsvp')}>
        <div className="rsvp-editor-variations">
            {variations.map(variation => <Button key={variation.name} variant="secondary"
                onClick={() => onSelect(variation.attributes?.mode ?? '')}>
                <strong>{variation.title}</strong><span>{variation.description}</span>
            </Button>)}
        </div>
    </Placeholder>;
}

/** Props shared by the canvas resource search and its loading/error states. */
interface OfferSelectorProps {
    mode: Mode;
    selectedId: number;
    selectedResource: Resource | null;
    offers: ReturnType<typeof useOffers>;
    onSelect: (resource: Resource | null) => void;
}

/** Render a native combobox backed by bounded server-side search and explicit result pagination. */
function OfferSelector({ mode, selectedId, selectedResource, offers, onSelect }: OfferSelectorProps) {
    const ignoreFocusReset = useRef(false);
    const resources = selectedResource && !offers.items.some(resource => resource.id === selectedResource.id)
        ? [selectedResource, ...offers.items] : offers.items;
    const options = resources.map(resource => ({ value: String(resource.id),
        label: statusLabel(resource.status) ? `${resource.title} (${statusLabel(resource.status)})` : resource.title }));

    /** Resolve selections only against authorized options and preserve the selected item between pages. */
    function select(value: string | null | undefined): void {
        if (!value) { onSelect(null); return; }
        const resource = resources.find(item => String(item.id) === value);
        if (resource) onSelect(resource);
    }

    /** ComboboxControl emits an empty filter on focus; preserve the requested results page then. */
    function captureFocus(event: FocusEvent<HTMLDivElement>): void {
        if (event.target.tagName === 'INPUT') ignoreFocusReset.current = true;
    }

    /** Apply actual text edits while allowing keyboard users to return to paginated search results. */
    function filter(value: string): void {
        const focusReset = ignoreFocusReset.current && value === '';
        ignoreFocusReset.current = false;
        if (!focusReset && value !== offers.search) offers.filter(value);
    }

    return <div className="rsvp-editor-selection" onFocusCapture={captureFocus}>
        <ComboboxControl label={selectionLabel(mode)} value={selectedId ? String(selectedId) : null}
            options={options} onChange={select} onFilterValueChange={filter}
            placeholder={__('Search offers', 'rrze-rsvp')} isLoading={offers.loading}
            help={offers.search ? sprintf(__('Search results for “%s”', 'rrze-rsvp'), offers.search) : undefined}
            __next40pxDefaultSize __nextHasNoMarginBottom />
        <div aria-live="polite" aria-busy={offers.loading}>
            {offers.loading ? <p className="rsvp-editor-loading" role="status"><Spinner />{__('Loading offers…', 'rrze-rsvp')}</p>
                : offers.error ? <Notice status="error" isDismissible={false}>
                    <p>{offers.error}</p><Button variant="secondary" onClick={offers.reload}>{__('Try again', 'rrze-rsvp')}</Button>
                </Notice> : <>
                    {!offers.items.length && <Notice status="info" isDismissible={false}>
                        {offers.search ? __('No offers match your search.', 'rrze-rsvp')
                            : __('No matching offers are available. Create the course or room in RSVP first, or ask your administrator for access.', 'rrze-rsvp')}
                    </Notice>}
                    {(offers.page > 1 || offers.hasMore) && <div className="rsvp-editor-pagination">
                        <Button variant="secondary" disabled={offers.page === 1} onClick={() => offers.setPage(offers.page - 1)}>{__('Previous page', 'rrze-rsvp')}</Button>
                        <span>{sprintf(__('Page %d', 'rrze-rsvp'), offers.page)}</span>
                        <Button variant="secondary" disabled={!offers.hasMore} onClick={() => offers.setPage(offers.page + 1)}>{__('Next page', 'rrze-rsvp')}</Button>
                    </div>}
                </>}
        </div>
    </div>;
}

/**
 * Configure an existing offer and display its live, passive preview in the block editor.
 * Booking settings remain on the original resource; this block stores only mode, ID and routing UUID.
 */
export default function Edit({ attributes, setAttributes, clientId }: BlockEditProps<RsvpAttributes>) {
    const { mode, resourceId, instanceId } = attributes;
    const props = useBlockProps({ className: 'rrze-rsvp-editor' });
    const offers = useOffers(mode);
    const preview = usePreview(mode, resourceId);
    const [choosingResource, setChoosingResource] = useState(false);
    useInstanceId(clientId, instanceId, setAttributes);

    /** Start a different booking type without carrying a resource ID into the wrong renderer. */
    function changeMode(nextMode: Mode): void {
        if (nextMode === mode) return;
        setAttributes({ mode: nextMode, resourceId: 0 });
        setChoosingResource(false);
    }

    /** Bind one authorized resource, keeping the block identity stable. */
    function selectResource(resource: Resource | null): void {
        setAttributes({ resourceId: resource?.id ?? 0 });
        setChoosingResource(false);
    }

    /** Leave the change-selection view without modifying the stored resource. */
    function keepSelection(): void { setChoosingResource(false); }

    const selecting = !!mode && (!resourceId || choosingResource);
    return <>
        {mode && <InspectorControls><PanelBody title={__('Booking', 'rrze-rsvp')} initialOpen>
            <div className="rrze-rsvp-editor-sidebar">
                <SelectControl label={__('Booking type', 'rrze-rsvp')} value={mode}
                    options={variations.map(variation => ({ label: variation.title, value: variation.name }))}
                    onChange={value => changeMode(value as Mode)} __next40pxDefaultSize __nextHasNoMarginBottom />
                <OfferSelector mode={mode} selectedId={resourceId} selectedResource={preview.data} offers={offers} onSelect={selectResource} />
                {preview.data?.editUrl && <Button variant="link" href={preview.data.editUrl} target="_blank" rel="noopener noreferrer">
                    {__('Edit offer (opens in a new tab)', 'rrze-rsvp')}
                </Button>}
                <p>{__('Dates, capacity and booking rules are managed on the selected offer.', 'rrze-rsvp')}</p>
            </div>
        </PanelBody></InspectorControls>}
        {mode && <BlockControls><ToolbarGroup>
            <ToolbarButton onClick={() => setChoosingResource(true)}>{__('Change offer', 'rrze-rsvp')}</ToolbarButton>
            <ToolbarButton onClick={() => changeMode('')}>{__('Change booking type', 'rrze-rsvp')}</ToolbarButton>
        </ToolbarGroup></BlockControls>}
        <div {...props}>
            <header className="rsvp-editor-heading">{rsvpIcon}<strong>{modeTitle(mode)}</strong></header>
            {!mode ? <ModePicker onSelect={changeMode} /> : selecting ? <>
                <OfferSelector mode={mode} selectedId={resourceId} selectedResource={preview.data} offers={offers} onSelect={selectResource} />
                {!!resourceId && <Button variant="tertiary" onClick={keepSelection}>{__('Keep current selection', 'rrze-rsvp')}</Button>}
            </> : <>
                <div className="rsvp-editor-actions">
                    <Button variant="secondary" onClick={() => setChoosingResource(true)}>{__('Change offer', 'rrze-rsvp')}</Button>
                    <Button variant="tertiary" onClick={preview.reload} disabled={preview.loading}>{__('Refresh preview', 'rrze-rsvp')}</Button>
                    {preview.data?.editUrl && <Button variant="link" href={preview.data.editUrl} target="_blank" rel="noopener noreferrer">
                        {__('Edit offer (opens in a new tab)', 'rrze-rsvp')}
                    </Button>}
                </div>
                {preview.loading && <p className="rsvp-editor-loading" role="status"><Spinner />{__('Loading preview…', 'rrze-rsvp')}</p>}
                {preview.error && <Notice status="error" isDismissible={false}>
                    <p>{preview.error}</p><p>{__('The saved selection is kept. Retry or choose another offer.', 'rrze-rsvp')}</p>
                    <Button variant="secondary" onClick={preview.reload}>{__('Try again', 'rrze-rsvp')}</Button>
                </Notice>}
                {preview.data && <>
                    {preview.data.unpublished && <Notice status="warning" isDismissible={false}>
                        {__('This offer is not published. Visitors can book it once it has been published.', 'rrze-rsvp')}
                    </Notice>}
                    <OfferPreview data={preview.data} />
                </>}
            </>}
        </div>
    </>;
}
