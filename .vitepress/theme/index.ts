import DefaultTheme from 'vitepress/theme'

import './custom.css'
import { useImageZoom } from './imageZoom'

// The default theme, with the stylesheet beside this file layered over it and one
// behaviour added: clicking a diagram opens it at a readable size. custom.css says
// why for each rule, imageZoom.ts says why it is written here rather than
// installed. There is still no component overridden and no slot filled.
export default {
    extends: DefaultTheme,
    setup: useImageZoom,
}
