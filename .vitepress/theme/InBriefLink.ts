import { onContentUpdated } from 'vitepress'
import { defineComponent, h, ref } from 'vue'

/*
 * Puts the summary into the outline, without putting a heading in the Markdown.
 *
 * Every document opens with an `In brief` blockquote and the guide is explicit
 * that it must not be a heading: the site builds its outline from headings, and
 * one identical entry on every page would be noise. That reasoning holds for the
 * list of sections; it does not hold for the thing a reader wants to scroll back
 * to. So the entry is rendered here, into the theme's own slot above the
 * outline, and the Markdown keeps a blockquote that GitHub renders the same way
 * it always has.
 *
 * The link hides itself on a page with no summary rather than pointing at
 * whatever sits under the title there.
 */

const SUMMARY = '.vp-doc h1 + blockquote'
const ANCHOR = 'in-brief'

export default defineComponent({
    name: 'InBriefLink',

    setup() {
        const present = ref(false)

        onContentUpdated(() => {
            const summary = document.querySelector<HTMLElement>(SUMMARY)

            if (summary) {
                summary.id = ANCHOR
            }

            present.value = summary !== null
        })

        return () =>
            present.value
                ? h('a', { class: 'in-brief-link', href: `#${ANCHOR}` }, 'In brief')
                : null
    },
})
