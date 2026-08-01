<script setup>
import { $api } from '@/utils/api'

definePage({ meta: { layout: 'blank', public: true } })

const route = useRoute()
const router = useRouter()
const data = ref(null)
const error = ref('')
const notice = ref('')
const reason = ref('')
const cancelled = ref(false)
const participants = ref([])

async function load() {
  try {
    data.value = await $api(`/public/booking/manage/${route.params.token}`)
    participants.value = (data.value.participants || []).map(item => ({ name: item.display_name, age_group: item.age_group || 'adult', role: item.role || 'guest', guardian_consent: item.age_group !== 'junior' }))
  } catch (e) {
    error.value = e?.data?.message || 'This booking link is unavailable.'
  }
}

function addParticipant() {
  participants.value.push({ name: '', age_group: 'adult', role: 'guest', guardian_consent: false })
}

async function saveParticipants() {
  try {
    error.value = ''
    data.value = await $api(`/public/booking/manage/${route.params.token}/participants`, { method: 'PUT', body: { participants: participants.value } })
    notice.value = 'Participants updated.'
  } catch (e) {
    error.value = e?.data?.message || 'Participants could not be updated.'
  }
}

async function checkIn() {
  try {
    error.value = ''
    data.value = await $api(`/public/booking/manage/${route.params.token}/check-in`, { method: 'POST' })
    notice.value = 'Check-in completed.'
  } catch (e) {
    error.value = e?.data?.message || 'Check-in is not available.'
  }
}

async function rotate() {
  try {
    const response = await $api(`/public/booking/manage/${route.params.token}/rotate`, { method: 'POST' })
    await router.replace(response.management_path)
  } catch (e) {
    error.value = e?.data?.message || 'The link could not be rotated.'
  }
}

async function cancel() {
  try {
    data.value = await $api(`/public/booking/manage/${route.params.token}/cancel`, { method: 'POST', body: { reason: reason.value } })
    cancelled.value = true
  } catch (e) {
    error.value = e?.data?.message || 'The booking could not be cancelled.'
  }
}

onMounted(load)
</script>

<template>
  <div class="manage">
    <main>
      <h1>Manage visitor booking</h1>
      <VAlert v-if="error" type="error" class="mb-4">{{ error }}</VAlert>
      <VAlert v-if="notice" type="success" class="mb-4">{{ notice }}</VAlert>
      <VAlert v-if="cancelled" type="success" title="Booking cancelled">Outcome: {{ data.outcome }} · Refund: {{ data.refund_status || 'not applicable' }}</VAlert>
      <VCard v-else-if="data">
        <VCardTitle>{{ data.facility?.name }}</VCardTitle>
        <VCardText>
          <p>{{ new Date(data.starts_at).toLocaleString() }} – {{ new Date(data.ends_at).toLocaleString() }}</p>
          <p>Status: {{ data.status }} · Payment: {{ data.payment_status }}</p>
          <p>Total: {{ data.currency }} {{ (data.amount / 100).toFixed(2) }} · Receipt: {{ data.receipt_number || 'pending' }}</p>
          <p>Lighting: {{ data.lighting_status || 'not required' }} · Check-in: {{ data.check_in?.status || 'not available' }}</p>
          <p v-if="data.temporary_access?.length">Temporary access is issued only after an eligible check-in. Current status: {{ data.temporary_access[0].status }}</p>
          <p>Cancellation deadline: {{ data.cancellation_deadline ? new Date(data.cancellation_deadline).toLocaleString() : 'See policy' }}</p>

          <VBtn v-if="data.check_in && !['checked_in', 'late_checked_in'].includes(data.check_in.status)" class="my-4" @click="checkIn">Check in</VBtn>

          <h2 class="text-h6 mt-4 mb-2">Participants</h2>
          <VRow v-for="(participant, index) in participants" :key="index">
            <VCol cols="12" md="5"><VTextField v-model="participant.name" label="Participant name" /></VCol>
            <VCol cols="12" md="3"><VSelect v-model="participant.age_group" :items="['adult', 'junior']" label="Age group" /></VCol>
            <VCol cols="12" md="4"><VCheckbox v-if="participant.age_group === 'junior'" v-model="participant.guardian_consent" label="Guardian consent" /></VCol>
          </VRow>
          <div class="actions"><VBtn variant="outlined" @click="addParticipant">Add participant</VBtn><VBtn :disabled="participants.some(item => !item.name)" @click="saveParticipants">Save participants</VBtn></div>

          <VTextField v-model="reason" class="mt-6" label="Cancellation reason" />
          <div class="actions"><VBtn color="error" :disabled="reason.length < 5" @click="cancel">Cancel booking</VBtn><VBtn variant="outlined" @click="rotate">Rotate secure link</VBtn></div>
        </VCardText>
      </VCard>
    </main>
  </div>
</template>

<style scoped>
.manage{min-height:100vh;background:#f5f4f8;padding:clamp(1rem,5vw,4rem)}
main{max-width:760px;margin:auto}
h1{margin-bottom:1.5rem}
.actions{display:flex;flex-wrap:wrap;gap:.75rem}
@media(max-width:600px){.manage{padding:1rem}.actions .v-btn{width:100%}}
</style>
