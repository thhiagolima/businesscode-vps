import axios, { type AxiosInstance } from 'axios'
import { useAuthStore } from '@/stores/auth'
import router from '@/router'

const client: AxiosInstance = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api/v1',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
  withCredentials: true,
})

let interceptorsInstalled = false

export function useApi() {
  const auth = useAuthStore()

  if (!interceptorsInstalled) {
    client.interceptors.request.use(config => {
      if (auth.token) {
        config.headers.Authorization = `Bearer ${auth.token}`
      }
      return config
    })

    client.interceptors.response.use(
      response => {
        const body = response.data
        if (body && typeof body === 'object' && 'meta' in body) {
          return { data: (body as any).data, meta: (body as any).meta } as any
        }
        return body?.data ?? body
      },
      error => {
        const url: string = error.config?.url ?? ''
        // Não fazer logout automático em rotas de autenticação para evitar loop infinito:
        // - /auth/login retornando 401/422 → exibir mensagem de erro normalmente
        // - /auth/logout retornando 401 → token já inválido, sem necessidade de re-logout
        const isAuthRoute = url.includes('/auth/')
        if (error.response?.status === 401 && !isAuthRoute) {
          auth.clearSession()
          router.replace('/login')
        }
        return Promise.reject(error)
      }
    )

    interceptorsInstalled = true
  }

  const get = <T = unknown>(url: string, params?: Record<string, unknown>): Promise<T> =>
    client.get(url, { params })

  const post = <T = unknown>(url: string, data?: Record<string, unknown> | FormData): Promise<T> =>
    client.post(url, data)

  const put = <T = unknown>(url: string, data?: Record<string, unknown>): Promise<T> =>
    client.put(url, data)

  const patch = <T = unknown>(url: string, data?: Record<string, unknown>): Promise<T> =>
    client.patch(url, data)

  const del = <T = unknown>(url: string): Promise<T> =>
    client.delete(url)

  const upload = <T = unknown>(url: string, formData: FormData): Promise<T> =>
    client.post(url, formData, { headers: { 'Content-Type': 'multipart/form-data' } })

  return { get, post, put, patch, del, upload }
}
