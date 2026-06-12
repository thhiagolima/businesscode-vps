import { ref, onUnmounted } from 'vue'

type PollEntry = {
  id: string
  fn: () => Promise<void>
  interval: number
  timer: ReturnType<typeof setInterval> | null
}

const entries = ref<PollEntry[]>([])
let paused = false
let visibilityHandler: (() => void) | null = null

function bindVisibility() {
  if (visibilityHandler) return
  visibilityHandler = () => {
    if (document.hidden) pauseAll()
    else resumeAll()
  }
  document.addEventListener('visibilitychange', visibilityHandler)
}

function pauseAll() {
  paused = true
  entries.value.forEach(e => {
    if (e.timer) { clearInterval(e.timer); e.timer = null }
  })
}

function resumeAll() {
  paused = false
  entries.value.forEach(e => {
    if (!e.timer) {
      e.fn()
      e.timer = setInterval(e.fn, e.interval)
    }
  })
}

export function useConversationPolling() {
  bindVisibility()

  function startPolling(id: string, fn: () => Promise<void>, intervalMs: number) {
    stopPolling(id)
    const entry: PollEntry = { id, fn, interval: intervalMs, timer: null }
    if (!paused) {
      entry.timer = setInterval(fn, intervalMs)
    }
    entries.value.push(entry)
  }

  function stopPolling(id: string) {
    const idx = entries.value.findIndex(e => e.id === id)
    if (idx >= 0) {
      const e = entries.value[idx]
      if (e.timer) clearInterval(e.timer)
      entries.value.splice(idx, 1)
    }
  }

  function stopAll() {
    entries.value.forEach(e => { if (e.timer) clearInterval(e.timer) })
    entries.value = []
    // Remove the visibility listener when no consumers remain
    if (visibilityHandler) {
      document.removeEventListener('visibilitychange', visibilityHandler)
      visibilityHandler = null
    }
  }

  onUnmounted(stopAll)

  return { startPolling, stopPolling, stopAll }
}
