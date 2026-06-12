type Variant = 'success' | 'error' | 'warning' | 'info'

const ensureContainer = (): HTMLElement => {
  let el = document.getElementById('tabler-toast-container') as HTMLElement | null
  if (!el) {
    el = document.createElement('div')
    el.id = 'tabler-toast-container'
    el.style.position = 'fixed'
    el.style.top = '1rem'
    el.style.right = '1rem'
    el.style.zIndex = '1080'
    document.body.appendChild(el)
  }
  return el
}

const variantClass = (v: Variant) =>
  ({
    success: 'text-bg-success',
    error: 'text-bg-danger',
    warning: 'text-bg-warning text-dark',
    info: 'text-bg-info text-dark',
  }[v] ?? 'text-bg-secondary')

const iconFor = (v: Variant) =>
  ({
    success: 'ti-check',
    error: 'ti-alert-circle',
    warning: 'ti-alert-triangle',
    info: 'ti-info-circle',
  }[v] ?? 'ti-info-circle')

const show = (variant: Variant, message: string, delay = 3500) => {
  const container = ensureContainer()
  const el = document.createElement('div')
  el.className = `toast ${variantClass(variant)} show align-items-center border-0 mb-2`
  el.setAttribute('role', 'alert')
  el.setAttribute('aria-live', 'assertive')
  el.setAttribute('aria-atomic', 'true')
  el.innerHTML = `
    <div class="d-flex">
      <div class="toast-body d-flex align-items-center">
        <i class="ti ${iconFor(variant)} me-2"></i> ${message}
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  `
  container.appendChild(el)
  const ToastCtor = (window as any)?.bootstrap?.Toast
  if (ToastCtor) {
    const toast = new ToastCtor(el, { delay })
    toast.show()
    el.addEventListener('hidden.bs.toast', () => el.remove())
  } else {
    // Fallback: exibe e remove após delay
    setTimeout(() => el.remove(), delay)
  }
}

export function useToast() {
  return {
    success: (msg: string) => show('success', msg),
    error: (msg: string) => show('error', msg),
    warning: (msg: string) => show('warning', msg),
    info: (msg: string) => show('info', msg),
  }
}
