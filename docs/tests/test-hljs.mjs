/**
 * Tests for Djot highlight.js grammar
 *
 * Tests that the hljs-djot.js grammar correctly highlights Djot syntax.
 * Covers all patterns defined in the grammar plus edge cases.
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
    name: 'Heading level 2',
    input: '## Heading',
    expectClasses: ['hljs-section'],
  },
  {
    name: 'Heading level 3',
    input: '### Heading',
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
    name: 'Strong with content',
    input: '*bold text here*',
    expectClasses: ['hljs-strong'],
  },
  {
    name: 'Emphasis text',
    input: '_emphasis_',
    expectClasses: ['hljs-emphasis'],
  },
  {
    name: 'Emphasis with content',
    input: '_italic text here_',
    expectClasses: ['hljs-emphasis'],
  },

  // ==================== CODE ====================
  {
    name: 'Inline code',
    input: '`code`',
    expectClasses: ['hljs-code'],
  },
  {
    name: 'Inline code with content',
    input: '`console.log(x)`',
    expectClasses: ['hljs-code'],
  },
  {
    name: 'Double backtick code',
    input: '`` `nested` ``',
    expectClasses: ['hljs-code'],
  },
  {
    name: 'Triple backtick code',
    input: '``` ``double`` ```',
    expectClasses: ['hljs-code'],
  },
  {
    name: 'Code fence start',
    input: '```js',
    expectClasses: ['hljs-keyword'],
  },
  {
    name: 'Code fence start without language',
    input: '```',
    expectClasses: ['hljs-keyword'],
  },
  {
    name: 'Code fence with raw format',
    input: '``` =html',
    expectClasses: ['hljs-keyword'],
  },
  {
    name: 'Code fence with 4 backticks',
    input: '````python',
    expectClasses: ['hljs-keyword'],
  },
  {
    name: 'Code fence end',
    input: '```',
    expectClasses: ['hljs-keyword'],
  },

  // ==================== LINKS ====================
  {
    name: 'Inline link',
    input: '[text](https://example.com)',
    expectClasses: ['hljs-link'],
  },
  {
    name: 'Inline link with title',
    input: '[text](https://example.com "Title")',
    expectClasses: ['hljs-link'],
  },
  {
    name: 'Link with attributes',
    input: '[text](url){.external}',
    expectClasses: ['hljs-link'],
  },
  {
    name: 'Empty link text',
    input: '[](https://example.com)',
    expectClasses: ['hljs-link'],
  },
  {
    name: 'Reference link',
    input: '[text][ref]',
    expectClasses: ['hljs-link'],
  },
  {
    name: 'Shortcut reference link',
    input: '[ref][]',
    expectClasses: ['hljs-link'],
  },
  {
    name: 'Autolink URL',
    input: '<https://example.com>',
    expectClasses: ['hljs-link'],
  },
  {
    name: 'Autolink HTTP',
    input: '<http://example.com>',
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
  {
    name: 'Image with title',
    input: '![alt](image.png "Title")',
    expectClasses: ['hljs-link'],
  },
  {
    name: 'Image with attributes',
    input: '![alt](image.png){width=100}',
    expectClasses: ['hljs-link'],
  },
  {
    name: 'Image empty alt',
    input: '![](image.png)',
    expectClasses: ['hljs-link'],
  },

  // ==================== LISTS ====================
  {
    name: 'Bullet list with dash',
    input: '- item',
    expectClasses: ['hljs-bullet'],
  },
  {
    name: 'Bullet list with asterisk',
    input: '* item',
    expectClasses: ['hljs-bullet'],
  },
  {
    name: 'Bullet list with plus',
    input: '+ item',
    expectClasses: ['hljs-bullet'],
  },
  {
    name: 'Numbered list with dot',
    input: '1. item',
    expectClasses: ['hljs-bullet'],
  },
  {
    name: 'Numbered list with paren',
    input: '1) item',
    expectClasses: ['hljs-bullet'],
  },
  {
    name: 'Numbered list double digit',
    input: '10. item',
    expectClasses: ['hljs-bullet'],
  },
  {
    name: 'Task list checked lowercase',
    input: '- [x] done',
    expectClasses: ['hljs-bullet'],
  },
  {
    name: 'Task list checked uppercase',
    input: '- [X] done',
    expectClasses: ['hljs-bullet'],
  },
  {
    name: 'Task list unchecked',
    input: '- [ ] todo',
    expectClasses: ['hljs-bullet'],
  },
  {
    name: 'Indented list item',
    input: '  - nested',
    expectClasses: ['hljs-bullet'],
  },

  // ==================== BLOCKQUOTES ====================
  {
    name: 'Blockquote',
    input: '> quoted text',
    expectClasses: ['hljs-quote'],
  },
  {
    name: 'Blockquote marker only',
    input: '>',
    expectClasses: ['hljs-quote'],
  },

  // ==================== DIVS ====================
  {
    name: 'Div block start with class',
    input: '::: warning',
    expectClasses: ['hljs-keyword'],
  },
  {
    name: 'Div block start without class',
    input: ':::',
    expectClasses: ['hljs-keyword'],
  },
  {
    name: 'Div block with 4 colons',
    input: ':::: note',
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
    name: 'Table row with content',
    input: '| Cell 1 | Cell 2 | Cell 3 |',
    expectClasses: ['hljs-string'],
  },
  {
    name: 'Table row with attributes',
    input: '| A | B |{.highlight}',
    expectClasses: ['hljs-string'],
  },
  {
    name: 'Table separator',
    input: '|---|---|',
    expectClasses: ['hljs-meta'],
  },
  {
    name: 'Table separator with alignment',
    input: '|:--|:--:|--:|',
    expectClasses: ['hljs-meta'],
  },
  {
    name: 'Table separator with spaces',
    input: '| --- | --- |',
    expectClasses: ['hljs-meta'],
  },

  // ==================== COMMENTS ====================
  {
    name: 'Inline comment',
    input: '{% comment %}',
    expectClasses: ['hljs-comment'],
  },
  {
    name: 'Inline comment with content',
    input: '{% this is a comment %}',
    expectClasses: ['hljs-comment'],
  },
  {
    name: 'Fenced comment',
    input: '%%%',
    expectClasses: ['hljs-comment'],
  },
  {
    name: 'Fenced comment with 4 percent',
    input: '%%%%',
    expectClasses: ['hljs-comment'],
  },

  // ==================== HIGHLIGHT/INSERT/DELETE ====================
  {
    name: 'Highlight',
    input: '{=highlighted=}',
    expectClasses: ['hljs-addition'],
  },
  {
    name: 'Highlight with content',
    input: '{=important text=}',
    expectClasses: ['hljs-addition'],
  },
  {
    name: 'Insert',
    input: '{+inserted+}',
    expectClasses: ['hljs-addition'],
  },
  {
    name: 'Insert with content',
    input: '{+new text here+}',
    expectClasses: ['hljs-addition'],
  },
  {
    name: 'Delete',
    input: '{-deleted-}',
    expectClasses: ['hljs-deletion'],
  },
  {
    name: 'Delete with content',
    input: '{-old text here-}',
    expectClasses: ['hljs-deletion'],
  },

  // ==================== SUPER/SUBSCRIPT ====================
  {
    name: 'Superscript single char',
    input: '^2^',
    expectClasses: ['hljs-built_in'],
  },
  {
    name: 'Superscript word',
    input: '^super^',
    expectClasses: ['hljs-built_in'],
  },
  {
    name: 'Subscript single char',
    input: '~2~',
    expectClasses: ['hljs-built_in'],
  },
  {
    name: 'Subscript word',
    input: '~sub~',
    expectClasses: ['hljs-built_in'],
  },
  {
    name: 'Braced superscript',
    input: '{^super text^}',
    expectClasses: ['hljs-built_in'],
  },
  {
    name: 'Braced subscript',
    input: '{~sub text~}',
    expectClasses: ['hljs-built_in'],
  },

  // ==================== MATH ====================
  {
    name: 'Inline math',
    input: '$`x + y`$',
    expectClasses: ['hljs-formula'],
  },
  {
    name: 'Inline math with formula',
    input: '$`E = mc^2`$',
    expectClasses: ['hljs-formula'],
  },
  {
    name: 'Display math',
    input: '$$`x^2`$$',
    expectClasses: ['hljs-formula'],
  },
  {
    name: 'Display math multiline',
    input: '$$`\\sum_{i=1}^n`$$',
    expectClasses: ['hljs-formula'],
  },

  // ==================== DEFINITIONS ====================
  {
    name: 'Reference definition',
    input: '[ref]: https://example.com',
    expectClasses: ['hljs-symbol'],
  },
  {
    name: 'Reference definition with title',
    input: '[ref]: https://example.com "Title"',
    expectClasses: ['hljs-symbol'],
  },
  {
    name: 'Footnote reference',
    input: '[^1]',
    expectClasses: ['hljs-symbol'],
  },
  {
    name: 'Named footnote reference',
    input: '[^note]',
    expectClasses: ['hljs-symbol'],
  },
  {
    name: 'Footnote definition',
    input: '[^1]: footnote text',
    expectClasses: ['hljs-symbol'],
  },
  {
    name: 'Named footnote definition',
    input: '[^note]: footnote content',
    expectClasses: ['hljs-symbol'],
  },
  {
    name: 'Abbreviation definition',
    input: '*[HTML]: Hyper Text Markup Language',
    expectClasses: ['hljs-symbol'],
  },
  {
    name: 'Abbreviation definition short',
    input: '*[CSS]: Cascading Style Sheets',
    expectClasses: ['hljs-symbol'],
  },

  // ==================== ATTRIBUTES ====================
  {
    name: 'Block attribute class',
    input: '{.class}',
    expectClasses: ['hljs-attr'],
  },
  {
    name: 'Block attribute id',
    input: '{#id}',
    expectClasses: ['hljs-attr'],
  },
  {
    name: 'Block attribute combined',
    input: '{.class #id}',
    expectClasses: ['hljs-attr'],
  },
  {
    name: 'Block attribute key-value',
    input: '{key=value}',
    expectClasses: ['hljs-attr'],
  },
  {
    name: 'Block attribute complex',
    input: '{.warning #alert role=alert}',
    expectClasses: ['hljs-attr'],
  },
  {
    name: 'Span with class',
    input: '[text]{.highlight}',
    expectClasses: ['hljs-string'],
  },
  {
    name: 'Span with id',
    input: '[text]{#unique}',
    expectClasses: ['hljs-string'],
  },

  // ==================== HORIZONTAL RULES ====================
  {
    name: 'Horizontal rule dashes',
    input: '---',
    expectClasses: ['hljs-meta'],
  },
  {
    name: 'Horizontal rule asterisks',
    input: '***',
    expectClasses: ['hljs-meta'],
  },
  {
    name: 'Horizontal rule underscores',
    input: '___',
    expectClasses: ['hljs-meta'],
  },
  {
    name: 'Long horizontal rule',
    input: '-----',
    expectClasses: ['hljs-meta'],
  },

  // ==================== SYMBOLS ====================
  {
    name: 'Symbol heart',
    input: ':heart:',
    expectClasses: ['hljs-symbol'],
  },
  {
    name: 'Symbol arrow',
    input: ':arrow_right:',
    expectClasses: ['hljs-symbol'],
  },
  {
    name: 'Symbol with numbers',
    input: ':num123:',
    expectClasses: ['hljs-symbol'],
  },

  // ==================== SMART PUNCTUATION ====================
  {
    name: 'Em dash',
    input: 'wait---what',
    expectClasses: ['hljs-punctuation'],
  },
  {
    name: 'Em dash standalone',
    input: '---',
    // Note: matches horizontal rule at start of line, but em dash inline
    expectClasses: ['hljs-meta'], // horizontal rule takes precedence
  },
  {
    name: 'En dash',
    input: '1--10',
    expectClasses: ['hljs-punctuation'],
  },
  {
    name: 'En dash in text',
    input: 'pages 10--20',
    expectClasses: ['hljs-punctuation'],
  },
  {
    name: 'Ellipsis',
    input: 'wait...',
    expectClasses: ['hljs-punctuation'],
  },
  {
    name: 'Ellipsis in sentence',
    input: 'and then... silence',
    expectClasses: ['hljs-punctuation'],
  },

  // ==================== ESCAPES ====================
  {
    name: 'Escaped asterisk',
    input: '\\*not bold\\*',
    expectClasses: ['hljs-symbol'],
  },
  {
    name: 'Escaped bracket',
    input: '\\[not a link\\]',
    expectClasses: ['hljs-symbol'],
  },
  {
    name: 'Escaped backslash',
    input: '\\\\',
    expectClasses: ['hljs-symbol'],
  },
  {
    name: 'Escaped underscore',
    input: '\\_not italic\\_',
    expectClasses: ['hljs-symbol'],
  },

  // ==================== RAW FORMAT ====================
  {
    name: 'Raw format html',
    input: '{=html}',
    expectClasses: ['hljs-meta'],
  },
  {
    name: 'Raw format latex',
    input: '{=latex}',
    expectClasses: ['hljs-meta'],
  },

  // ==================== CAPTIONS ====================
  {
    name: 'Caption',
    input: '^ Caption text',
    expectClasses: ['hljs-title'],
  },
  {
    name: 'Caption with formatting',
    input: '^ Figure 1: Description',
    expectClasses: ['hljs-title'],
  },

  // ==================== DEFINITION LISTS ====================
  {
    name: 'Definition term',
    input: ': definition text',
    expectClasses: ['hljs-title'],
  },
  {
    name: 'Definition with content',
    input: ': This is the definition',
    expectClasses: ['hljs-title'],
  },

  // ==================== LINE BLOCKS ====================
  {
    name: 'Line block',
    input: '| poetry line',
    expectClasses: ['hljs-string'],
  },
  {
    name: 'Line block with content',
    input: '| Roses are red',
    expectClasses: ['hljs-string'],
  },

  // ==================== FRONTMATTER ====================
  {
    name: 'Frontmatter delimiter',
    input: '---',
    expectClasses: ['hljs-meta'],
  },

  // ==================== HARD LINE BREAK ====================
  {
    name: 'Hard line break',
    input: 'text\\',
    expectClasses: ['hljs-meta'],
  },

  // ==================== EDGE CASES ====================
  {
    name: 'Multiple inline elements',
    input: '*bold* and _italic_',
    expectClasses: ['hljs-strong', 'hljs-emphasis'],
  },
  {
    name: 'Link in text',
    input: 'See [this link](url) for more',
    expectClasses: ['hljs-link'],
  },
  {
    name: 'Code in text',
    input: 'Use `code` here',
    expectClasses: ['hljs-code'],
  },
  {
    name: 'Multiple symbols',
    input: ':heart: and :star:',
    expectClasses: ['hljs-symbol'],
  },

  // ==================== EDGE CASES ====================
  {
    name: 'Underscore in variable name should not be emphasis',
    input: 'some_var_name',
    rejectClasses: ['hljs-emphasis'],
  },
  {
    name: 'Asterisk in middle of word should not be strong',
    input: 'un*frigging*believable',
    rejectClasses: ['hljs-strong'],
  },
  {
    name: 'Emphasis at start of text',
    input: '_emphasized_ text',
    expectClasses: ['hljs-emphasis'],
  },
  {
    name: 'Strong at start of text',
    input: '*strong* text',
    expectClasses: ['hljs-strong'],
  },
  {
    name: 'Emphasis after punctuation',
    input: 'Hello, _world_!',
    expectClasses: ['hljs-emphasis'],
  },
  {
    name: 'Strong after punctuation',
    input: 'Hello, *world*!',
    expectClasses: ['hljs-strong'],
  },
  {
    name: 'Nested emphasis in strong',
    input: '*_bold italic_*',
    expectClasses: ['hljs-strong', 'hljs-emphasis'],
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

    // Check expected classes are present
    if (test.expectClasses) {
      for (const expectedClass of test.expectClasses) {
        if (!html.includes(expectedClass)) {
          testPassed = false;
          errors.push(`  Expected class "${expectedClass}" not found in output`);
          errors.push(`  Got: ${html}`);
        }
      }
    }

    // Check rejected classes are NOT present
    if (test.rejectClasses) {
      for (const rejectedClass of test.rejectClasses) {
        if (html.includes(rejectedClass)) {
          testPassed = false;
          errors.push(`  Unexpected class "${rejectedClass}" found in output`);
          errors.push(`  Got: ${html}`);
        }
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
