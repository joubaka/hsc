<script setup>
import { $api } from '@/utils/api'
definePage({ meta: { layout: 'blank', public: true } })
const date = ref(new Date().toISOString().slice(0, 10))
const facilities = ref([])
const loading = ref(false)
const error = ref('')
async function load() {
  loading.value = true; error.value = ''
  try {
    const from = new Date(`${date.value}T00:00:00`)
    const to = new Date(from); to.setDate(to.getDate() + 7)
    facilities.value = (await $api(`/public/availability?from=${encodeURIComponent(from.toISOString())}&to=${encodeURIComponent(to.toISOString())}`)).facilities
  } catch (exception) { error.value = exception?.data?.message || 'Availability could not be loaded.' }
  finally { loading.value = false }
}
onMounted(load)
</script>
<template>
  <div class="public-booking">
    <header><div><h1>Hermanus Sports Club</h1><p>Live court availability</p></div><VBtn to="/login" variant="tonal">Member sign in</VBtn></header>
    <main><div class="d-flex flex-wrap gap-3 align-center mb-6"><VTextField v-model="date" type="date" label="Week starting" hide-details style="max-width:230px" /><VBtn :loading="loading" @click="load">View availability</VBtn></div>
      <VAlert v-if="error" type="error" class="mb-4">{{ error }}</VAlert>
      <VAlert type="info" variant="tonal" class="mb-5">Availability is privacy-safe. Sign in with your linked HSC member account to hold or book a court.</VAlert>
      <div class="facility-grid"><VCard v-for="facility in facilities" :key="facility.id"><VCardTitle>{{ facility.name }}</VCardTitle><VCardSubtitle>{{ facility.venue?.name }} · {{ facility.facility_type.replaceAll('_',' ') }}</VCardSubtitle><VCardText><p>{{ facility.description }}</p><div class="d-flex flex-wrap gap-2 my-3"><VChip v-for="tag in facility.tags" :key="tag.id" size="small">{{ tag.name }}</VChip></div><p class="text-medium-emphasis">{{ facility.blocked_slots.length }} unavailable slot(s) in this week</p></VCardText></VCard></div>
    </main>
  </div>
</template>
<style scoped>
.public-booking{min-height:100vh;background:#f5f4f8;color:#2f2b3d}.public-booking header{display:flex;justify-content:space-between;gap:1rem;align-items:center;padding:1.2rem clamp(1rem,5vw,5rem);background:white}.public-booking main{max-width:1200px;margin:auto;padding:2rem clamp(1rem,4vw,3rem)}.facility-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem}
</style>
