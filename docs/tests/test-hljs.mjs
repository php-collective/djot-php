/**
 * Tests for Djot highlight.js grammar
 *
 * Tests that the hljs-djot.js grammar correctly highlights Djot syntax.
 */

import hljs from 'highlight.js';
import { readFileSync } from 'fs';
import { dirname, resolve } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));

// Load and register the Djot grammar
const grammarPath = resolve(__dirname, '../public/assets/hljs-djot.js');
const grammarCode = readFileSync(grammarPath, 'utf-8');

// Execute the grammar code to get the language definition
const djotGrammar = eval(`
  (function() {
    ${grammarCode.replace('(function() {', '').replace(/\}\)\(\);?\s*$/, '')}
    return djot;
  })()
`);

hljs.registerLanguage('djot', djotGrammar);

/**
 * Test cases: each test has input and expected CSS classes that should appear
 */
const testCases = [
  // ==================== HEADINGS ====================
  {
    name: 'Heading level 1',
    input: '# Heading',
    expectClasses: ['hljs-section'],
  },
  {
    name: 'Heading level 6',
    input: '###### Heading',
    expectClasses: ['hljs-section'],
  },

  // ==================== EMPHASIS ====================
  {
    name: 'Strong text',
    input: '*strong*',
    expectClasses: ['hljs-strong'],
  },
  {
    name: 'Emphasis text',
    input: '_emphasis_',
    expectClasses: ['hljs-emphasis'],
  },

  // ==================== CODE ====================
  {
    name: 'Inline code',
    input: '`code`',
    expectClasses: ['hljs-code'],
  },
  {
    name: 'Double backtick code',
    input: '`` `nested` ``',
    expectClasses: ['hljs-code'],
  },
  {
    name: 'Code fence start',
    input: '```js',
    expectClasses: ['hljs-keyword'],
  },
  {
    name: 'Code fence with raw format',
    input: '``` =html',
    expectClasses: ['hljs-keyword'],
  },

  // ==================== LINKS ====================
  {
    name: 'Inline link',
    input: '[text](https://example.com)',
    expectClasses: ['hljs-link'],
  },
  {
    name: 'Reference link',
    input: '[text][ref]',
    expectClasses: ['hljs-link'],
  },
  {
    name: 'Autolink URL',
    input: '<https://example.com>',
    expectClasses: ['hljs-link'],
  },
  {
    name: 'Email autolink',
    input: '<user@example.com>',
    expectClasses: ['hljs-link'],
  },

  // ==================== IMAGES ====================
  {
    name: 'Image',
    input: '![alt](image.png)',
    expectClasses: ['hljs-link'],
  },

  // ==================== LISTS ====================
  {
    name: 'Bullet list',
    input: '- item',
    expectClasses: ['hljs-bullet'],
  },
  {
    name: 'Numbered list',
    input: '1. item',
    expectClasses: ['hljs-bullet'],
  },
  {
    name: 'Task list checked',
    input: '- [x] done',
    expectClasses: ['hljs-bullet'],
  },
  {
    name: 'Task list unchecked',
    input: '- [ ] todo',
    expectClasses: ['hljs-bullet'],
  },

  // ==================== BLOCKQUOTES ====================
  {
    name: 'Blockquote',
    input: '> quoted text',
    expectClasses: ['hljs-quote'],
  },

  // ==================== DIVS ====================
  {
    name: 'Div block start',
    input: '::: warning',
    expectClasses: ['hljs-keyword'],
  },
  {
    name: 'Div block end',
    input: ':::',
    expectClasses: ['hljs-keyword'],
  },

  // ==================== TABLES ====================
  {
    name: 'Table row',
    input: '| A | B |',
    expectClasses: ['hljs-string'],
  },
  {
    name: 'Table separator',
    input: '|---|---|',
    expectClasses: ['hljs-meta'],
  },

  // ==================== COMMENTS ====================
  {
    name: 'Inline comment',
    input: '{% comment %}',
    expectClasses: ['hljs-comment'],
  },
  {
    name: 'Fenced comment',
    input: '%%%',
    expectClasses: ['hljs-comment'],
  },

  // ==================== HIGHLIGHT/INSERT/DELETE ====================
  {
    name: 'Highlight',
    input: '{=highlighted=}',
    expectClasses: ['hljs-addition'],
  },
  {
    name: 'Insert',
    input: '{+inserted+}',
    expectClasses: ['hljs-addition'],
  },
  {
    name: 'Delete',
    input: '{-deleted-}',
    expectClasses: ['hljs-deletion'],
  },

  // ==================== SUPER/SUBSCRIPT ====================
  {
    name: 'Superscript',
    input: '^2^',
    expectClasses: ['hljs-built_in'],
  },
  {
    name: 'Subscript',
    input: '~2~',
    expectClasses: ['hljs-built_in'],
  },
  {
    name: 'Braced superscript',
    input: '{^super^}',
    expectClasses: ['hljs-built_in'],
  },
  {
    name: 'Braced subscript',
    input: '{~sub~}',
    expectClasses: ['hljs-built_in'],
  },

  // ==================== MATH ====================
  {
    name: 'Inline math',
    input: '$`x + y`$',
    expectClasses: ['hljs-formula'],
  },
  {
    name: 'Display math',
    input: '$$`x^2`$$',
    expectClasses: ['hljs-formula'],
  },

  // ==================== DEFINITIONS ====================
  {
    name: 'Reference definition',
    input: '[ref]: https://example.com',
    expectClasses: ['hljs-symbol'],
  },
  {
    name: 'Footnote reference',
    input: '[^1]',
    expectClasses: ['hljs-symbol'],
  },
  {
    name: 'Footnote definition',
    input: '[^1]: footnote text',
    expectClasses: ['hljs-symbol'],
  },
  {
    name: 'Abbreviation definition',
    input: '*[HTML]: Hyper Text Markup Language',
    expectClasses: ['hljs-symbol'],
  },

  // ==================== ATTRIBUTES ====================
  {
    name: 'Block attribute',
    input: '{.class #id}',
    expectClasses: ['hljs-attr'],
  },
  {
    name: 'Span with attributes',
    input: '[text]{.highlight}',
    expectClasses: ['hljs-string'],
  },

  // ==================== MISC ====================
  {
    name: 'Horizontal rule',
    input: '---',
    expectClasses: ['hljs-meta'],
  },
  {
    name: 'Symbol',
    input: ':heart:',
    expectClasses: ['hljs-symbol'],
  },
  {
    name: 'Em dash',
    input: 'wait---what',
    expectClasses: ['hljs-punctuation'],
  },
  {
    name: 'En dash',
    input: '1--10',
    expectClasses: ['hljs-punctuation'],
  },
  {
    name: 'Ellipsis',
    input: 'wait...',
    expectClasses: ['hljs-punctuation'],
  },
  {
    name: 'Escape',
    input: '\\*escaped\\*',
    expectClasses: ['hljs-symbol'],
  },
  {
    name: 'Raw format marker',
    input: '{=html}',
    expectClasses: ['hljs-meta'],
  },
  {
    name: 'Caption',
    input: '^ Caption text',
    expectClasses: ['hljs-title'],
  },
  {
    name: 'Definition term',
    input: ': definition',
    expectClasses: ['hljs-title'],
  },
  {
    name: 'Line block',
    input: '| poetry line',
    expectClasses: ['hljs-string'],
  },
  {
    name: 'Frontmatter',
    input: '---',
    expectClasses: ['hljs-meta'],
  },
];

/**
 * Run all tests
 */
function runTests() {
  let passed = 0;
  let failed = 0;

  for (const test of testCases) {
    const result = hljs.highlight(test.input, { language: 'djot' });
    const html = result.value;

    let testPassed = true;
    const errors = [];

    for (const expectedClass of test.expectClasses) {
      if (!html.includes(expectedClass)) {
        testPassed = false;
        errors.push(`  Expected class "${expectedClass}" not found in output`);
        errors.push(`  Got: ${html}`);
      }
    }

    if (testPassed) {
      console.log(`✓ ${test.name}`);
      passed++;
    } else {
      console.log(`✗ ${test.name}`);
      errors.forEach(e => console.log(e));
      failed++;
    }
  }

  console.log(`\n${passed} passed, ${failed} failed`);
  process.exit(failed > 0 ? 1 : 0);
}

runTests();
