import { defineConfig } from '@maizzle/framework';

export default defineConfig( {
	content: [ 'mail/emails/**/*.vue' ],
	output: {
		path: 'build/email',
		extension: 'html',
	},
	css: {
		inline: true,
		purge: {
			// These elements are inserted by PHP after the Maizzle build.
			safelist: [ '.rrze-email-button-cell', '.rrze-email-button-link' ],
		},
		safe: true,
		shorthand: true,
	},
	html: {
		decodeEntities: true,
		format: true,
		minify: false,
	},
} );
