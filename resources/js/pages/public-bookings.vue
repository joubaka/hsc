<script setup>
import { $api } from '@/utils/api'

const data = ref({ bookings: [], visitors: [], settings: [], report: {}, authorization: {} })
const error = ref('')
const notice = ref('')
const visitorReasons = reactive({})
const canSaveSetting = setting => setting.sport_code_id === null ? data.value.authorization.can_manage_global_visitors : data.value.authorization.manageable_sport_codes === 'all' || data.value.authorization.manageable_sport_codes?.includes(setting.sport_code_id)

async function load() {
  try {
    data.value = await $api('/public-bookings')
  } catch (e) {
    error.value = e?.data?.message || 'Public bookings could not be loaded.'
  }
}

async function changeVisitorStatus(visitor, status) {
  try {
    error.value = ''
    await $api(`/public-bookings/visitors/${visitor.public_reference}/status`, { method: 'PATCH', body: { status, reason: visitorReasons[visitor.public_reference] || 'Administrator review outcome' } })
    notice.value = `Visitor marked ${status}.`
    await load()
  } catch (e) {
    error.value = e?.data?.message || 'Visitor status could not be changed.'
  }
}

async function saveSetting(setting) {
  try {
    error.value = ''
    const fields = ['enabled', 'email_verification_required', 'mobile_verification_required', 'payment_required', 'temporary_access_eligible', 'hold_minutes', 'maximum_active_holds', 'maximum_future_bookings', 'maximum_participants']
    const body = Object.fromEntries(fields.map(key => [key, setting[key]]))
    await $api(`/public-bookings/settings/${setting.id}`, { method: 'PATCH', body })
    notice.value = 'Public booking settings saved.'
    await load()
  } catch (e) {
    error.value = e?.data?.message || 'Settings could not be saved.'
  }
}

onMounted(load)
</script>

<template>
  <section>
    <div class="d-flex justify-space-between align-center mb-6"><div><h1>Public bookings</h1><p class="text-medium-emphasis">Visitor checkout, verification, payment, attendance and access operations.</p></div><VBtn @click="load">Refresh</VBtn></div>
    <VAlert v-if="error" type="error" class="mb-4">{{ error }}</VAlert>
    <VAlert v-if="notice" type="success" class="mb-4">{{ notice }}</VAlert>

    <VRow class="mb-4"><VCol v-for="(value, key) in data.report" :key="key" cols="6" md="3"><VCard><VCardText><div class="text-h4">{{ value }}</div><div>{{ key.replaceAll('_', ' ') }}</div></VCardText></VCard></VCol></VRow>

    <VCard class="mb-6"><VCardTitle>Bookings</VCardTitle><VTable class="public-table"><thead><tr><th>Booking</th><th>Visitor</th><th>Facility</th><th>Start</th><th>Amount</th><th>Payment</th><th>Status</th><th>Check-in</th></tr></thead><tbody><tr v-for="booking in data.bookings" :key="booking.booking_reference"><td>{{ booking.booking_reference }}</td><td>{{ booking.visitor_name }}</td><td>{{ booking.facility?.name }}</td><td>{{ new Date(booking.starts_at).toLocaleString() }}</td><td>{{ booking.currency }} {{ (booking.amount / 100).toFixed(2) }}</td><td>{{ booking.payment_status }}</td><td>{{ booking.status }}</td><td>{{ booking.check_in_state || 'n/a' }}</td></tr></tbody></VTable></VCard>

    <VCard class="mb-6"><VCardTitle>Visitor identities</VCardTitle><VTable class="public-table"><thead><tr><th>Visitor</th><th>Safe contact</th><th>Verification</th><th>Status</th><th v-if="data.authorization.can_manage_global_visitors">Review</th></tr></thead><tbody><tr v-for="visitor in data.visitors" :key="visitor.public_reference"><td>{{ visitor.name }}</td><td>{{ visitor.email }} · {{ visitor.mobile }}</td><td>Email: {{ visitor.email_verified ? 'verified' : 'pending' }} · Mobile: {{ visitor.mobile_verified ? 'verified' : 'pending' }}</td><td>{{ visitor.status }}</td><td v-if="data.authorization.can_manage_global_visitors"><VTextField v-model="visitorReasons[visitor.public_reference]" density="compact" label="Reason" hide-details/><div class="action-row"><VBtn size="small" @click="changeVisitorStatus(visitor, 'restricted')">Restrict</VBtn><VBtn size="small" variant="outlined" @click="changeVisitorStatus(visitor, 'verified')">Unblock</VBtn></div></td></tr></tbody></VTable></VCard>

    <VCard><VCardTitle>Public booking settings</VCardTitle><VCardText><VRow v-for="setting in data.settings" :key="setting.id" class="setting-row"><VCol cols="12"><strong>{{ setting.sport_code_id ? `Sport code ${setting.sport_code_id}` : 'HSC defaults' }}</strong></VCol><VCol cols="6" md="3"><VSwitch v-model="setting.enabled" :disabled="!canSaveSetting(setting)" label="Public booking" /></VCol><VCol cols="6" md="3"><VSwitch v-model="setting.email_verification_required" :disabled="!canSaveSetting(setting)" label="Email verification" /></VCol><VCol cols="6" md="3"><VSwitch v-model="setting.payment_required" :disabled="!canSaveSetting(setting)" label="Payment required" /></VCol><VCol cols="6" md="3"><VSwitch v-model="setting.temporary_access_eligible" :disabled="!canSaveSetting(setting)" label="Temporary access" /></VCol><VCol cols="6" md="3"><VTextField v-model.number="setting.hold_minutes" :disabled="!canSaveSetting(setting)" type="number" label="Hold minutes" /></VCol><VCol cols="6" md="3"><VTextField v-model.number="setting.maximum_active_holds" :disabled="!canSaveSetting(setting)" type="number" label="Active holds" /></VCol><VCol cols="6" md="3"><VTextField v-model.number="setting.maximum_future_bookings" :disabled="!canSaveSetting(setting)" type="number" label="Future bookings" /></VCol><VCol cols="6" md="3"><VTextField v-model.number="setting.maximum_participants" :disabled="!canSaveSetting(setting)" type="number" label="Participants" /></VCol><VCol cols="12"><VBtn :disabled="!canSaveSetting(setting)" @click="saveSetting(setting)">Save settings</VBtn></VCol></VRow></VCardText></VCard>
  </section>
</template>

<style scoped>
.action-row{display:flex;gap:.5rem;margin-top:.5rem}.setting-row{border-bottom:1px solid rgba(0,0,0,.12);padding-block:1rem}
@media(max-width:700px){.public-table{display:block;overflow-x:auto;white-space:nowrap}}
</style>
