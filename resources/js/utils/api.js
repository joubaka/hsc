import { ofetch } from 'ofetch'

export const $api = ofetch.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api',
  async onRequest({ options }) {
    const accessToken = localStorage.getItem('hsc_admin_token')
    if (accessToken) {
      options.headers = new Headers(options.headers)
      options.headers.set('Authorization', `Bearer ${accessToken}`)
    }
  },
  async onResponseError({ response }) {
    if (response.status === 401 && !location.pathname.endsWith('/login')) {
      localStorage.removeItem('hsc_admin_token')
      location.href = `${import.meta.env.VITE_ROUTER_BASE || '/'}login`
    }
  },
})
