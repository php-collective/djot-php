import DefaultTheme from 'vitepress/theme'
import DjotPlayground from '../components/DjotPlayground.vue'
import OutputTabs from '../components/OutputTabs.vue'
import './custom.css'

export default {
  extends: DefaultTheme,
  enhanceApp({ app }) {
    app.component('DjotPlayground', DjotPlayground)
    app.component('OutputTabs', OutputTabs)
  },
}
