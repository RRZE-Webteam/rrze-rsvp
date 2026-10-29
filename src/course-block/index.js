import './style.scss';

const { createElement: el, Fragment, useEffect, useState } = window.wp.element;
const { InspectorControls, useBlockProps } = window.wp.blockEditor;
const { SelectControl, Spinner, Notice, PanelBody } = window.wp.components;
const labels = window.rrzeRsvpCourseEditor.labels;

/**
 * Render a course selector without submitting registrations from the editor.
 *
 * @param {Object} props Block attributes and their setter.
 * @returns {Object} React editor view.
 */
function CourseEditor({ attributes, setAttributes }) {
    const blockProps = useBlockProps({ className: 'rrze-rsvp-course-editor' });
    const [courses, setCourses] = useState([]);
    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);

    /** @returns {Function} Cleanup that ignores late responses after unmounting. */
    function loadCourses() {
        let active = true;
        window.wp.apiFetch({ path: '/rrze-rsvp/v1/courses' }).then(
            /** @param {Array} result Authorized course labels. @returns {void} */
            function loaded(result) {
                if (active) {
                    setCourses(result);
                    setLoading(false);
                }
            },
            /** @returns {void} Explain unavailable editor access. */
            function rejected() {
                if (active) {
                    setFailed(true);
                    setLoading(false);
                }
            }
        );
        /** @returns {void} Stop state updates once the block has unmounted. */
        return function cleanup() { active = false; };
    }
    useEffect(loadCourses, []);

    /** @param {string} value Selected course ID. @returns {void} */
    function selectCourse(value) { setAttributes({ courseId: Number.parseInt(value, 10) || 0 }); }

    /** @param {Object} course Private selector row. @returns {Object} Select option. */
    function option(course) {
        return { value: course.id, label: course.title + (course.status === 'publish' ? '' : ' (' + labels.draft + ')') };
    }

    /** @returns {Object} Course selection shared by canvas and inspector sidebar. */
    function courseSelector() {
        if (loading) { return el('p', null, el(Spinner), labels.loading); }
        if (failed) { return el(Notice, { status: 'error', isDismissible: false }, labels.error); }
        return el(Fragment, null, el(SelectControl, {
            label: labels.select,
            value: attributes.courseId,
            options: [{ value: 0, label: labels.select }].concat(courses.map(option)),
            onChange: selectCourse,
            __nextHasNoMarginBottom: true,
            __next40pxDefaultSize: true,
        }), !courses.length ? el('p', null, labels.empty) : null);
    }

    return el(Fragment, null,
        el(InspectorControls, null,
            el(PanelBody, { title: labels.title, initialOpen: true }, courseSelector())
        ),
        el('div', blockProps,
            el('strong', null, labels.title),
            courseSelector(),
            el('p', { className: 'rrze-rsvp-course-editor-help' }, labels.preview)
        )
    );
}

/** @returns {null} All frontend content is rendered dynamically by PHP. */
function save() { return null; }

window.wp.blocks.registerBlockType('rrze-rsvp/course-booking', {
    apiVersion: 3,
    title: labels.title,
    description: labels.description,
    category: 'widgets',
    icon: 'calendar-alt',
    attributes: { courseId: { type: 'integer', default: 0 } },
    supports: { html: false },
    edit: CourseEditor,
    save,
});
