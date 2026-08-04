import { ofetch } from 'ofetch'

export const $memberApi = ofetch.create({
  baseURL: `${import.meta.env.VITE_API_BASE_URL || '/api'}/member`,
  async onRequest({ options }) {
    const token = localStorage.getItem('hsc_member_token')
    if (token) {
      options.headers = new Headers(options.headers)
      options.headers.set('Authorization', `Bearer ${token}`)
    }
  },
  async onResponseError({ response }) {
    if (response.status === 401 && !location.pathname.endsWith('/member-login')) {
      localStorage.removeItem('hsc_member_token')
      localStorage.removeItem('hsc_member')
      location.href = `${import.meta.env.VITE_ROUTER_BASE || '/'}member-login`
    }
  },
})
