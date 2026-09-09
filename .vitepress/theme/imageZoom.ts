import { onContentUpdated } from 'vitepress'
import { onMounted, onUnmounted } from 'vue'

/*
 * Clicking a diagram opens it over the page, wide enough to read.
 *
 * The prose column is 800px and several diagrams are wider than that or taller
 * than a screen, so the page renders them scaled down to a size where the labels
 * stop being readable. They are SVGs, so there is nothing lost in scaling them
 * back up: the overlay gives them the viewport's width and lets the reader scroll
 * a tall one, rather than fitting the whole picture onto the screen and shrinking
 * a decision tree to a thumbnail.
 *
 * Written here rather than installed. The behaviour is one listener and one
 * element; the alternative is a package in the stack for the life of the project,
 * and the parts of it we would not use are the parts that would surprise us on an
 * upgrade.
 */

const OVERLAY_CLASS = 'diagram-zoom'
const OPEN_CLASS = 'diagram-zoom-open'

let overlay: HTMLDivElement | null = null
let overlayImage: HTMLImageElement | null = null
let opener: HTMLImageElement | null = null

function buildOverlay(): HTMLDivElement {
    const element = document.createElement('div')
    element.className = OVERLAY_CLASS
    element.hidden = true
    element.setAttribute('role', 'dialog')
    element.setAttribute('aria-modal', 'true')
    // Named, because a dialog announced as an unnamed dialog tells a screen
    // reader's user that something opened and nothing about what.
    element.setAttribute('aria-label', 'Diagram, enlarged')
    element.tabIndex = -1

    overlayImage = document.createElement('img')
    element.append(overlayImage)

    // Anywhere closes it. There is nothing else in here to click, and a reader
    // who has finished with a diagram should not have to find a target.
    element.addEventListener('click', close)

    document.body.append(element)

    return element
}

function open(image: HTMLImageElement): void {
    overlay ??= buildOverlay()

    if (!overlayImage) {
        return
    }

    overlayImage.src = image.currentSrc || image.src
    overlayImage.alt = image.alt
    opener = image

    overlay.hidden = false
    // The page behind must not scroll under the overlay, and the overlay itself
    // is what scrolls when a diagram is taller than the screen.
    document.documentElement.classList.add(OPEN_CLASS)
    // `aria-modal` promises that what is behind the dialog is unreachable, and
    // on its own it promises it to a screen reader only: Tab still walks the
    // page under the overlay. `inert` is what makes the promise true, for the
    // keyboard as well, and it costs one attribute against a focus trap of our
    // own.
    appRoot()?.setAttribute('inert', '')
    overlay.focus()
}

function close(): void {
    if (!overlay || overlay.hidden) {
        return
    }

    overlay.hidden = true
    document.documentElement.classList.remove(OPEN_CLASS)
    appRoot()?.removeAttribute('inert')

    // Back to the image that opened it. Without this a keyboard reader who
    // enlarges a diagram halfway down a long page lands on the body and walks
    // the whole page again to get back to where they were.
    opener?.focus()
    opener = null
}

function appRoot(): HTMLElement | null {
    return document.getElementById('app')
}

/*
 * A diagram is an image alone in its own paragraph, which is what a Markdown
 * image on a line of its own produces. The badges at the top of the README are
 * the counter-example this has to exclude: they sit several to a paragraph and
 * each one is wrapped in a link, so enlarging one would open the overlay and
 * follow the link from the same click, and each would become a tab stop
 * announced as a button in front of the link that actually does something.
 *
 * The `<a>` check is redundant against the parent test and kept anyway: it is
 * the part that must not be lost if the paragraph test is ever relaxed.
 */
function isDiagram(target: EventTarget | null): target is HTMLImageElement {
    if (!(target instanceof HTMLImageElement) || target.closest('.vp-doc') === null) {
        return false
    }

    if (target.closest('a') !== null) {
        return false
    }

    const paragraph = target.parentElement

    return paragraph?.tagName === 'P' && paragraph.childElementCount === 1
}

function onClick(event: MouseEvent): void {
    if (isDiagram(event.target)) {
        open(event.target)
    }
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        close()

        return
    }

    if (event.key !== 'Enter' && event.key !== ' ') {
        return
    }

    if (isDiagram(event.target)) {
        event.preventDefault()
        open(event.target)
    }
}

/*
 * The listeners are delegated, so they survive every navigation without being
 * rebound. What does have to run again on each page is the pair of attributes that
 * make an image reachable from the keyboard, since the images themselves are
 * replaced: `onContentUpdated` is the hook VitePress provides for exactly that,
 * and it fires on the first render as well as on every route change.
 */
export function useImageZoom(): void {
    onMounted(() => {
        document.addEventListener('click', onClick)
        document.addEventListener('keydown', onKeydown)
    })

    onUnmounted(() => {
        document.removeEventListener('click', onClick)
        document.removeEventListener('keydown', onKeydown)
        close()
    })

    onContentUpdated(() => {
        close()

        document.querySelectorAll<HTMLImageElement>('.vp-doc p > img:only-child').forEach((image) => {
            if (image.closest('a') !== null) {
                return
            }

            image.tabIndex = 0
            image.setAttribute('role', 'button')
        })
    })
}
