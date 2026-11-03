import { createBlock, registerBlockType } from '@wordpress/blocks';
import type { BlockConfiguration } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';
import { rsvpIcon } from './icon';
import { legacyCourseAttributes } from './identity';
import type { RsvpAttributes } from './types';
import { variations } from './variations';
import './editor.scss';

/** Keep public forms dynamic and avoid serializing participant data or nonces into content. */
function save(): null { return null; }

registerBlockType<RsvpAttributes>(metadata as BlockConfiguration<RsvpAttributes>, {
    icon: rsvpIcon,
    variations,
    edit: Edit,
    save,
    transforms: {
        from: [{
            type: 'block',
            blocks: ['rrze-rsvp/course-booking'],
            /** Preserve the selected course when the editor explicitly converts a legacy course block. */
            transform: (attributes: unknown) => createBlock('rrze/rsvp', legacyCourseAttributes(attributes)),
        }],
    },
});
