import { __ } from '@wordpress/i18n';
import type { BlockVariation } from '@wordpress/blocks';
import type { RsvpAttributes } from './types';

/** One inserter entry; variations appear only inside the RSVP setup flow. */
export const variations: BlockVariation<RsvpAttributes>[] = [
    { name: 'course', title: __('Course registration', 'rrze-rsvp'),
        description: __('Register for individual dates of an existing course.', 'rrze-rsvp'),
        attributes: { mode: 'course', resourceId: 0, instanceId: '' }, scope: ['block'], isActive: ['mode'] },
    { name: 'consultation', title: __('Consultation appointment', 'rrze-rsvp'),
        description: __('Book a consultation time without selecting a seat.', 'rrze-rsvp'),
        attributes: { mode: 'consultation', resourceId: 0, instanceId: '' }, scope: ['block'], isActive: ['mode'] },
    { name: 'seat', title: __('Seat booking', 'rrze-rsvp'),
        description: __('Reserve a seat in a room for a selected time.', 'rrze-rsvp'),
        attributes: { mode: 'seat', resourceId: 0, instanceId: '' }, scope: ['block'], isActive: ['mode'] },
];
