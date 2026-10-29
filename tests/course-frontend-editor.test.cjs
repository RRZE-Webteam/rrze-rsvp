const test = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');

/**
 * Walk a minimal React element tree without depending on a specific DOM renderer.
 *
 * @param {Object} element Rendered element.
 * @param {string} type Desired component or tag.
 * @returns {Array} Matching descendants including the root.
 */
function descendants(element, type) {
    if (!element || typeof element !== 'object') { return []; }
    return (element.type === type ? [element] : []).concat((element.children || []).flatMap(
        /** @param {Object} child Nested element. @returns {Array} Matching descendants. */
        function visit(child) { return descendants(child, type); }
    ));
}

test('API v3 block retains Gutenberg wrapper attributes and offers a working inspector selector',
    /** @returns {void} Validate the editor integration contract without submitting bookings. */
    function editorIntegration() {
        let registration;
        let stateIndex = 0;
        let saved;
        const state = [[{ id: 12, title: 'First aid', status: 'publish' }], false, false];
        const source = readFileSync(require.resolve('../src/course-block/index.js'), 'utf8').replace("import './style.scss';", '');
        vm.runInNewContext(source, {
            window: {
                rrzeRsvpCourseEditor: { labels: { title: 'Course booking', select: 'Select a course', preview: 'Preview only' } },
                wp: {
                    element: {
                        Fragment: 'Fragment',
                        /** @param {string} type Element type. @param {Object} props Element attributes. @param {...Object} children Elements. @returns {Object} React-like tree. */
                        createElement(type, props, ...children) { return { type, props, children }; },
                        /** @returns {Array} Course fixture and an inert state setter. */
                        useState() { return [state[stateIndex++], /** @returns {void} No asynchronous changes in this render fixture. */ function ignore() {}]; },
                        /** @returns {void} API permission/loading behavior is covered separately. */
                        useEffect() {},
                    },
                    blockEditor: {
                        InspectorControls: 'InspectorControls',
                        /** @param {Object} props Caller-supplied block properties. @returns {Object} Required editor wrapper markers. */
                        useBlockProps(props) { return { ...props, 'data-block': 'editor-block-id', tabIndex: 0, ref: 'gutenberg-ref' }; },
                    },
                    components: { SelectControl: 'SelectControl', Spinner: 'Spinner', Notice: 'Notice', PanelBody: 'PanelBody' },
                    blocks: {
                        /** @param {string} name Registered block name. @param {Object} settings Editor implementation. @returns {void} */
                        registerBlockType(name, settings) { registration = { name, settings }; },
                    },
                },
            },
        });
        assert.equal(registration.name, 'rrze-rsvp/course-booking');
        assert.equal(registration.settings.apiVersion, 3);
        assert.equal(registration.settings.save(), null, 'The editor must not serialize an active registration form.');
        const tree = registration.settings.edit({ attributes: { courseId: 12 },
            /** @param {Object} attributes Updated block selection. @returns {void} */
            setAttributes(attributes) { saved = attributes; },
        });
        const wrapper = descendants(tree, 'div')[0];
        assert.equal(wrapper.props['data-block'], 'editor-block-id');
        assert.equal(wrapper.props.ref, 'gutenberg-ref');
        assert.equal(wrapper.props.tabIndex, 0);
        const inspector = descendants(tree, 'InspectorControls')[0];
        const selector = descendants(inspector, 'SelectControl')[0];
        assert.equal(selector.props.value, 12);
        assert.equal(selector.props.options[1].label, 'First aid');
        selector.props.onChange('27');
        assert.equal(saved.courseId, 27, 'Sidebar selection updates the persistent integer course ID.');
        assert.equal(descendants(tree, 'form').length, 0, 'Editor preview cannot register a participant.');
    }
);
