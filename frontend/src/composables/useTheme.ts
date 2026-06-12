import { ref, watch } from 'vue'

const theme = ref<'light' | 'dark'>(
  (localStorage.getItem('theme') as 'light' | 'dark') || 'dark'
)

function applyTheme() {
  document.documentElement.setAttribute('data-bs-theme', theme.value)
}

watch(theme, (val) => {
  localStorage.setItem('theme', val)
  applyTheme()
})

// Apply on load
applyTheme()

export function useTheme() {
  function toggle() {
    theme.value = theme.value === 'light' ? 'dark' : 'light'
  }
  return { theme, toggle }
}
