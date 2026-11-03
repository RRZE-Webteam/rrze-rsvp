import { __, _n, sprintf } from '@wordpress/i18n';
import type { Preview } from './types';

/** Render real offer data as non-interactive content, never as a booking form. */
export default function OfferPreview({ data }: { data: Preview }) {
    return <div className="rsvp-editor-preview">
        <div className="rsvp-editor-preview-label">{__('Preview — bookings are made on the published page', 'rrze-rsvp')}</div>
        <h3>{data.title}</h3>
        {data.description && <p className="rsvp-editor-description">{data.description}</p>}
        {data.location && <p>{data.location}</p>}
        {data.mode === 'course' ? <>
            <h4>{__('Choose a course date', 'rrze-rsvp')}</h4>
            {!data.dates?.length && <p>{__('No upcoming course dates are currently available.', 'rrze-rsvp')}</p>}
            <ul className="rsvp-editor-dates">{data.dates?.map(date => <li key={date.id}>
                <span className="rsvp-editor-radio" aria-hidden="true" />
                <div><strong>{date.label}</strong><span>{date.location}</span><span>{date.available
                    ? sprintf(_n('%d place available', '%d places available', date.remaining, 'rrze-rsvp'), date.remaining)
                    : __('Currently not bookable', 'rrze-rsvp')}</span></div>
            </li>)}</ul>
            {data.moreDates && <p>{__('Further dates appear on the published page.', 'rrze-rsvp')}</p>}
        </> : <div className="rsvp-editor-picker">
            <div><h4>{__('Date', 'rrze-rsvp')}</h4><strong>{data.month}</strong>
                <div className="rsvp-editor-calendar" aria-label={data.month}>
                    {data.weekdays?.map((day, index) => <strong key={`weekday-${index}`}>{day}</strong>)}
                    {Array.from({ length: data.offset ?? 0 }, (_, index) => <span key={`blank-${index}`} />)}
                    {data.days?.map(day => <span key={day.date} className={day.available ? 'is-available' : 'is-unavailable'}
                        aria-label={`${day.date}: ${day.available ? __('Available', 'rrze-rsvp') : __('Not available', 'rrze-rsvp')}`}>{day.day}</span>)}
                </div>
            </div>
            <div><h4>{__('Time', 'rrze-rsvp')}</h4>
                <p>{data.selectedDate || __('No available appointments this month.', 'rrze-rsvp')}</p>
                <ul className="rsvp-editor-times">{data.times?.map(time => <li key={time.label}>{time.label}
                    {data.mode === 'seat' && <small>{sprintf(_n('%d place available', '%d places available', time.remaining, 'rrze-rsvp'), time.remaining)}</small>}
                </li>)}</ul>
                {data.mode === 'seat' && !!data.times?.length && <p>{__('The seat selection follows after choosing a time.', 'rrze-rsvp')}</p>}
            </div>
        </div>}
    </div>;
}
