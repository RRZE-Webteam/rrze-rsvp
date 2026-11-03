/**
 * Keep the TypeScript block build isolated from the existing frontend and mail assets.
 * WordPress Scripts supplies JSX compilation, metadata copying and dependency extraction.
 */
const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
    ...defaultConfig,
    output: {
        ...defaultConfig.output,
        path: path.resolve(__dirname, 'build/blocks'),
    },
    // The editor follows WordPress's supported browsers, independently of legacy frontend bundles.
    target: ['web', 'es2020'],
};
