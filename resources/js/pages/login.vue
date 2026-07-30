<script setup>
import { $api } from '@/utils/api'
import { VNodeRenderer } from '@layouts/components/VNodeRenderer'
import { themeConfig } from '@themeConfig'

definePage({ meta: { layout: 'blank', public: true } })

const router = useRouter()
const route = useRoute()
const form = reactive({ email: '', password: '' })
const loading = ref(false)
const error = ref('')
const visible = ref(false)

async function login() {
  loading.value = true
  error.value = ''
  try {
    const response = await $api('/auth/login', { method: 'POST', body: form })
    localStorage.setItem('hsc_admin_token', response.token)
    localStorage.setItem('hsc_admin_user', JSON.stringify(response.user))
    const destination = route.query.redirect || ''
    const base = import.meta.env.VITE_ROUTER_BASE || '/'

    window.location.assign(`${base}${String(destination).replace(/^\/+/, '')}`)
  } catch (exception) {
    error.value = exception?.data?.message || 'Unable to sign in.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="auth-wrapper d-flex align-center justify-center pa-6">
    <VCard width="460" class="pa-6">
      <VCardText class="text-center">
        <div class="d-flex justify-center align-center gap-3 mb-6">
          <VNodeRenderer :nodes="themeConfig.app.logo" />
          <h1 class="text-h3">Hermanus Sports Club</h1>
        </div>
        <h4 class="text-h4 mb-2">Administration sign in</h4>
        <p class="text-medium-emphasis">Sign in with your authorised HSC administrator account.</p>
      </VCardText>
      <VCardText>
        <VAlert v-if="error" type="error" variant="tonal" class="mb-5">{{ error }}</VAlert>
        <VForm @submit.prevent="login">
          <VTextField v-model="form.email" label="Email address" type="email" autocomplete="username" autofocus class="mb-4" />
          <VTextField
            v-model="form.password"
            label="Password"
            :type="visible ? 'text' : 'password'"
            autocomplete="current-password"
            :append-inner-icon="visible ? 'tabler-eye-off' : 'tabler-eye'"
            class="mb-5"
            @click:append-inner="visible = !visible"
          />
          <VBtn block type="submit" :loading="loading">Sign in</VBtn>
        </VForm>
        <p class="text-caption text-medium-emphasis text-center mt-5 mb-0">Public registration is disabled. Contact an HSC super administrator for access.</p>
      </VCardText>
    </VCard>
  </div>
</template>

<style scoped>
.auth-wrapper { min-height: 100vh; background: rgb(var(--v-theme-background)); }
</style>
