import { readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import gettext from 'gettext-parser';
import ts from 'typescript';

const root = fileURLToPath(new URL('../', import.meta.url));
const domain = 'rrze-rsvp';
const handle = 'rrze-rsvp-editor-script';
const check = process.argv.includes('--check');

/**
 * Collect TypeScript source files in a stable order, including nested components.
 *
 * @param {string} directory Absolute source directory.
 * @returns {string[]} Absolute TS/TSX paths.
 */
function sourceFiles(directory) {
    const files = [];
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
        const path = join(directory, entry.name);
        if (entry.isDirectory()) files.push(...sourceFiles(path));
        else if (/\.tsx?$/.test(entry.name)) files.push(path);
    }
    return files.sort();
}

/**
 * Extract literal WordPress i18n calls with the TypeScript parser, not a regex.
 *
 * @returns {Map<string, object>} Unique messages, contexts, plurals and source references.
 */
function messages() {
    const entries = new Map();
    /**
     * Merge repeated message occurrences without losing their source references.
     *
     * @param {string} id Singular source text.
     * @param {string} context Gettext context, or an empty string.
     * @param {string} plural Plural source text, or an empty string.
     * @param {string} reference Relative source location.
     * @returns {void}
     */
    function add(id, context, plural, reference) {
        const key = context ? `${context}\u0004${id}` : id;
        const entry = entries.get(key) ?? { id, context, plural, references: new Set() };
        if (entry.plural !== plural) throw new Error(`Conflicting plural forms: ${id}`);
        entry.references.add(reference);
        entries.set(key, entry);
    }

    for (const path of sourceFiles(join(root, 'src/blocks/rsvp'))) {
        const source = ts.createSourceFile(path, readFileSync(path, 'utf8'), ts.ScriptTarget.Latest, true);
        const imports = new Map();
        for (const statement of source.statements) {
            if (!ts.isImportDeclaration(statement) || statement.moduleSpecifier.text !== '@wordpress/i18n') continue;
            const bindings = statement.importClause?.namedBindings;
            if (bindings && ts.isNamedImports(bindings)) {
                for (const binding of bindings.elements) imports.set(binding.name.text, binding.propertyName?.text ?? binding.name.text);
            }
        }
        /**
         * Inspect supported translation calls and recursively visit their children.
         *
         * @param {import('typescript').Node} node Current syntax node.
         * @returns {void}
         */
        function visit(node) {
            if (ts.isCallExpression(node) && ts.isIdentifier(node.expression)) {
                const name = imports.get(node.expression.text);
                const signature = { __: [1], _x: [2, 1], _n: [3, null, 1], _nx: [4, 3, 1] }[name];
                if (signature) {
                    const [domainIndex, contextIndex, pluralIndex] = signature;
                    const args = node.arguments;
                    const reference = `${relative(root, path)}:${source.getLineAndCharacterOfPosition(node.getStart()).line + 1}`;
                    for (const index of [0, domainIndex, contextIndex, pluralIndex]) {
                        if (index != null && (!args[index] || !ts.isStringLiteralLike(args[index]))) {
                            throw new Error(`Translation arguments must be string literals: ${reference}`);
                        }
                    }
                    if (args[domainIndex].text === domain) {
                        add(args[0].text, contextIndex != null ? args[contextIndex].text : '',
                            pluralIndex != null ? args[pluralIndex].text : '', reference);
                    }
                }
            }
            ts.forEachChild(node, visit);
        }
        visit(source);
    }
    const metadataPath = 'src/blocks/rsvp/block.json';
    const metadata = JSON.parse(readFileSync(join(root, metadataPath), 'utf8'));
    add(metadata.title, 'block title', '', metadataPath);
    add(metadata.description, 'block description', '', metadataPath);
    for (const keyword of metadata.keywords ?? []) add(keyword, 'block keyword', '', metadataPath);
    return entries;
}

/**
 * Add TS messages missed by WP-CLI to the POT without reformatting its PHP entries.
 *
 * @param {Map<string, object>} entries Extracted editor messages.
 * @returns {void}
 */
function updatePot(entries) {
    const path = join(root, 'languages/rrze-rsvp.pot');
    const contents = readFileSync(path, 'utf8');
    const catalog = gettext.po.parse(contents);
    let additions = '';
    for (const entry of entries.values()) {
        if (catalog.translations[entry.context]?.[entry.id]) continue;
        additions += `\n#: ${[...entry.references].join(' ')}\n`;
        if (/%(?:\d+\$)?[sd]/.test(entry.id)) additions += '#, javascript-format\n';
        if (entry.context) additions += `msgctxt ${JSON.stringify(entry.context)}\n`;
        additions += `msgid ${JSON.stringify(entry.id)}\n`;
        additions += entry.plural ? `msgid_plural ${JSON.stringify(entry.plural)}\nmsgstr[0] ""\nmsgstr[1] ""\n` : 'msgstr ""\n';
    }
    if (check && additions) throw new Error('The POT is missing editor strings. Run this script without --check.');
    if (additions) writeFileSync(path, contents.trimEnd() + '\n' + additions);
}

/**
 * Build a WordPress Jed catalog from the maintained PO file for one locale.
 * Missing or fuzzy editor translations fail rather than silently using English.
 *
 * @param {string} filename Locale PO filename.
 * @param {Map<string, object>} entries Extracted editor messages.
 * @returns {void}
 */
function buildLocale(filename, entries) {
    const catalog = gettext.po.parse(readFileSync(join(root, 'languages', filename)));
    const locale = filename.slice(`${domain}-`.length, -3);
    const data = { '': { domain, lang: locale, 'plural-forms': catalog.headers['plural-forms'] } };
    const pluralCount = Number(catalog.headers['plural-forms']?.match(/nplurals\s*=\s*(\d+)/)?.[1]);
    if (!pluralCount) throw new Error(`Missing Plural-Forms header: ${filename}`);
    const missing = [];
    for (const [key, entry] of entries) {
        const translated = catalog.translations[entry.context]?.[entry.id];
        const count = entry.plural ? pluralCount : 1;
        if (!translated || /\bfuzzy\b/.test(translated.comments?.flag ?? '')
            || translated.msgstr.length < count || translated.msgstr.slice(0, count).some(value => !value)) {
            missing.push(key);
            continue;
        }
        data[key] = translated.msgstr.slice(0, count);
    }
    if (missing.length) throw new Error(`${locale}: missing editor translations:\n${missing.join('\n')}`);
    const output = JSON.stringify({
        'translation-revision-date': catalog.headers['po-revision-date'],
        generator: 'RRZE-RSVP block translation builder',
        domain,
        source: 'build/blocks/rsvp/index.js',
        locale_data: { messages: data },
    }, null, 2) + '\n';
    const path = join(root, 'languages', `${domain}-${locale}-${handle}.json`);
    if (check) {
        if (readFileSync(path, 'utf8') !== output) throw new Error(`Outdated Jed catalog: ${path}`);
    } else {
        writeFileSync(path, output);
    }
    console.log(`${locale}: ${entries.size} editor messages ${check ? 'verified' : 'compiled'}.`);
}

const entries = messages();
updatePot(entries);
for (const file of readdirSync(join(root, 'languages')).sort()) {
    if (file.startsWith(`${domain}-`) && file.endsWith('.po')) buildLocale(file, entries);
}
