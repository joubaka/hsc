<script setup>
import { ofetch } from 'ofetch'
import { VNodeRenderer } from '@layouts/components/VNodeRenderer'
import { themeConfig } from '@themeConfig'

definePage({ meta: { layout: 'blank', public: true, memberGuest: true } })
const form = reactive({ email: '', password: '' })
const loading = ref(false)
const error = ref('')
const visible = ref(false)

async function login() {
  loading.value = true
  error.value = ''
  try {
    const response = await ofetch(`${import.meta.env.VITE_API_BASE_URL || '/api'}/member/auth/login`, { method: 'POST', body: form })
    localStorage.setItem('hsc_member_token', response.token)
    localStorage.setItem('hsc_member', JSON.stringify(response.member))
    location.href = `${import.meta.env.VITE_ROUTER_BASE || '/'}member-portal`
  } catch (exception) {
    error.value = exception?.data?.message || 'Unable to sign in to the member portal.'
  } finally { loading.value = false }
}
</script>

<template>
  <div class="member-auth d-flex align-center justify-center pa-6">
    <VCard width="470" elevation="12" class="pa-6">
      <VCardText class="text-center">
        <div class="d-flex justify-center align-center gap-3 mb-5"><VNodeRenderer :nodes="themeConfig.app.logo" /><h1 class="text-h4">Hermanus Sports Club</h1></div>
        <h2 class="text-h5 mb-2">Member portal</h2><p class="text-medium-emphasis">Memberships, accounts and court bookings in one place.</p>
      </VCardText>
      <VAlert v-if="error" type="error" variant="tonal" class="mb-4">{{ error }}</VAlert>
      <VForm @submit.prevent="login">
        <VTextField v-model="form.email" label="Email address" type="email" autocomplete="username" autofocus class="mb-4" />
        <VTextField v-model="form.password" label="Password" :type="visible ? 'text' : 'password'" autocomplete="current-password" :append-inner-icon="visible ? 'tabler-eye-off' : 'tabler-eye'" @click:append-inner="visible = !visible" />
        <VBtn type="submit" block size="large" class="mt-5" :loading="loading">Sign in</VBtn>
      </VForm>
      <VAlert color="info" variant="tonal" class="mt-6">Use the email address linked to your HSC member account. Contact the club office if your portal access has not been activated.</VAlert>
      <div class="text-center mt-5"><RouterLink to="/book-a-court">Visitor court booking</RouterLink><span class="mx-2">·</span><RouterLink to="/login">Administration</RouterLink></div>
    </VCard>
  </div>
</template>

<style scoped>.member-auth{min-height:100vh;background:radial-gradient(circle at top left,#e6f7f3 0,transparent 38%),linear-gradient(145deg,#f8fafc,#e9eef5)}</style>
