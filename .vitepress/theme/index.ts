import { h } from 'vue'
import DefaultTheme from 'vitepress/theme'

import './custom.css'
import InBriefLink from './InBriefLink'
import { useImageZoom } from './imageZoom'

// The default theme, with the stylesheet beside this file layered over it and two
// behaviours added: clicking a diagram opens it at a readable size, and the
// summary gets an entry in the outline. custom.css says why for each rule, and
// each behaviour says in its own file why it is written here rather than
// installed or written into the Markdown.
//
// `aside-outline-before` is the theme's own slot, so the entry sits above the
// outline without this file knowing anything about how the outline is built.
export default {
    extends: DefaultTheme,
    Layout: () =>
        h(DefaultTheme.Layout, null, {
            'aside-outline-before': () => h(InBriefLink),
        }),
    setup: useImageZoom,
}
