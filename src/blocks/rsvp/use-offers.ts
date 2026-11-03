import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { Mode, ResourcePage, Preview } from './types';

/** Keep data and failure messages attached to the exact request that produced them. */
interface RequestState<T> {
    key: string;
    data: T | null;
    loading: boolean;
    error: string;
}

/** Normalize a REST failure without exposing transport details to editors. */
function errorMessage(error: unknown): string {
    return error && typeof error === 'object' && 'message' in error && typeof error.message === 'string'
        ? error.message : __('Offers could not be loaded. Please try again.', 'rrze-rsvp');
}

/** Debounce resource searches and ignore responses for superseded mode/search/page requests. */
export function useOffers(mode: Mode) {
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [retry, setRetry] = useState(0);
    const [state, setState] = useState<RequestState<ResourcePage>>({ key: '', data: null, loading: false, error: '' });
    const requestKey = JSON.stringify([mode, search, page, retry]);
    const current = !!mode && state.key === requestKey;
    const result = current ? state.data : null;

    /** Reset pagination when the selected booking type changes. */
    useEffect(() => { setSearch(''); setPage(1); }, [mode]);
    /** Fetch one page after a short typing pause; abort on every dependency change. */
    useEffect(() => {
        if (!mode) return;
        const controller = new AbortController();
        let active = true;
        setState({ key: requestKey, data: null, loading: true, error: '' });
        const timer = window.setTimeout(() => {
            const query = new URLSearchParams({ mode, search, page: String(page) });
            apiFetch<ResourcePage>({ path: `/rrze-rsvp/v1/editor/resources?${query}`, signal: controller.signal })
                .then(data => { if (active) setState({ key: requestKey, data, loading: false, error: '' }); })
                .catch(reason => { if (active) setState({ key: requestKey, data: null, loading: false, error: errorMessage(reason) }); });
        }, 250);
        return () => { active = false; window.clearTimeout(timer); controller.abort(); };
    }, [mode, search, page, requestKey]);

    /** Begin a new search on its first results page. */
    function filter(value: string) { setSearch(value); setPage(1); }
    /** Retry the failed request without losing the editor's search. */
    function reload() { setRetry(value => value + 1); }
    return { items: result?.items ?? [], hasMore: result?.hasMore ?? false,
        loading: !!mode && (!current || state.loading), error: current ? state.error : '',
        search, page, filter, setPage, reload };
}

/** Read a passive preview; an older request can never replace a newer selection. */
export function usePreview(mode: Mode, id: number) {
    const [state, setState] = useState<RequestState<Preview>>({ key: '', data: null, loading: false, error: '' });
    const [retry, setRetry] = useState(0);
    const enabled = !!mode && id > 0;
    const requestKey = JSON.stringify([mode, id, retry]);
    const current = enabled && state.key === requestKey;
    const data = current && state.data?.id === id && state.data.mode === mode ? state.data : null;
    /** Clear stale content and fetch only when both variation and resource are selected. */
    useEffect(() => {
        if (!enabled) return;
        const controller = new AbortController();
        let active = true;
        setState({ key: requestKey, data: null, loading: true, error: '' });
        apiFetch<Preview>({ path: `/rrze-rsvp/v1/editor/preview?mode=${mode}&id=${id}`, signal: controller.signal })
            .then(result => {
                if (!active) return;
                if (result.id !== id || result.mode !== mode) {
                    setState({ key: requestKey, data: null, loading: false, error: errorMessage(null) });
                    return;
                }
                setState({ key: requestKey, data: result, loading: false, error: '' });
            })
            .catch(reason => { if (active) setState({ key: requestKey, data: null, loading: false, error: errorMessage(reason) }); });
        return () => { active = false; controller.abort(); };
    }, [mode, id, enabled, requestKey]);
    /** Reload the current resource, preserving the saved selection even on failure. */
    function reload() { setRetry(value => value + 1); }
    return { data, loading: enabled && (!current || state.loading), error: current ? state.error : '', reload };
}
