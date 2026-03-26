import { defineConfig } from 'vitepress'
import { readFileSync } from 'fs'
import { dirname, resolve } from 'path'
import { fileURLToPath } from 'url'

const __dirname = dirname(fileURLToPath(import.meta.url))

// Load custom Djot grammar for syntax highlighting
const djotGrammar = JSON.parse(
  readFileSync(resolve(__dirname, 'grammars/djot.tmLanguage.json'), 'utf-8')
)

export default defineConfig({
  title: 'djot-php',
  description: 'A PHP parser and converter for Djot markup language',

  base: '/djot-php/',

  head: [
    ['link', { rel: 'icon', href: '/djot-php/favicon.svg', type: 'image/svg+xml' }],
  ],

  markdown: {
    languages: [
      {
        ...djotGrammar,
        name: 'djot',
        aliases: ['dj', 'Djot'],
      },
    ],
  },

  themeConfig: {
    logo: '/logo.svg',

    nav: [
      { text: 'Guide', link: '/guide/', activeMatch: '/guide/' },
      { text: 'Extensions', link: '/extensions/', activeMatch: '/extensions/' },
      { text: 'Cookbook', link: '/cookbook/', activeMatch: '/cookbook/' },
      { text: 'Reference', link: '/reference/api', activeMatch: '/reference/' },
      { text: 'Playground', link: '/playground' },
      {
        text: 'Links',
        items: [
          { text: 'Sandbox', link: 'https://sandbox.dereuromark.de/sandbox/djot' },
          { text: 'WYSIWYG Editor', link: 'https://sandbox.dereuromark.de/sandbox/djot/wysiwyg' },
          { text: 'Djot Spec', link: 'https://djot.net/' },
          { text: 'Changelog', link: 'https://github.com/php-collective/djot-php/releases' },
          { text: 'Packagist', link: 'https://packagist.org/packages/php-collective/djot' },
          { text: 'Issues', link: 'https://github.com/php-collective/djot-php/issues' },
        ],
      },
    ],

    sidebar: {
      '/guide/': [
        {
          text: 'Introduction',
          items: [
            { text: 'Getting Started', link: '/guide/' },
            { text: 'Why Djot?', link: '/guide/why-djot' },
            { text: 'Syntax Reference', link: '/guide/syntax' },
          ],
        },
        {
          text: 'Features',
          items: [
            { text: 'Safe Mode', link: '/guide/safe-mode' },
            { text: 'Validation', link: '/guide/validation' },
            { text: 'Profiles', link: '/guide/profiles' },
            { text: 'Converters', link: '/guide/converters' },
          ],
        },
      ],
      '/extensions/': [
        {
          text: 'Extensions',
          link: '/extensions/',
        },
      ],
      '/cookbook/': [
        {
          text: 'Cookbook',
          items: [
            { text: 'HTML Customization', link: '/cookbook/' },
            { text: 'Syntax Highlighting', link: '/cookbook/syntax-highlighting' },
            { text: 'PlainText Renderer', link: '/cookbook/plaintext' },
            { text: 'Markdown Renderer', link: '/cookbook/markdown' },
            { text: 'ANSI Renderer', link: '/cookbook/ansi' },
          ],
        },
      ],
      '/reference/': [
        {
          text: 'Reference',
          items: [
            { text: 'API', link: '/reference/api' },
            { text: 'CLI', link: '/reference/cli' },
            { text: 'Architecture', link: '/reference/architecture' },
            { text: 'Enhancements', link: '/reference/enhancements' },
            { text: 'Performance', link: '/reference/performance' },
          ],
        },
      ],
    },

    socialLinks: [
      { icon: 'github', link: 'https://github.com/php-collective/djot-php' },
    ],

    search: {
      provider: 'local',
    },

    editLink: {
      pattern: 'https://github.com/php-collective/djot-php/edit/master/docs/:path',
      text: 'Edit this page on GitHub',
    },

    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright PHP Collective',
    },
  },
})
