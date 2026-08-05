<script setup>
import MemberForm from '@/components/members/MemberForm.vue'
import { $api } from '@/utils/api'

const loading = ref(false)
const meta = ref({ sport_codes: [], stats: {} })
const members = ref([])
const total = ref(0)
const page = ref(1)
const search = ref('')
const status = ref(null)
const sportCode = ref(null)
const formOpen = ref(false)
const profileOpen = ref(false)
const selected = ref(null)
const currentUser = JSON.parse(localStorage.getItem('hsc_admin_user') || '{}')
const canEditCentral = ['hsc_super_admin', 'hsc_admin'].includes(currentUser.role)

const cards = computed(() => [
  ['Total members', meta.value.stats.total ?? 0, 'tabler-users', 'primary'],
  ['Active', meta.value.stats.active ?? 0, 'tabler-user-check', 'success'],
  ['Juniors', meta.value.stats.juniors ?? 0, 'tabler-ball-tennis', 'info'],
  ['Active access', meta.value.stats.access ?? 0, 'tabler-lock-open', 'warning'],
])
const headers = [
  { title: 'Member', key: 'member' },
  { title: 'Sport codes', key: 'sports', sortable: false },
  { title: 'Contact', key: 'contact', sortable: false },
  { title: 'Access', key: 'access', sortable: false },
  { title: 'Status', key: 'status' },
  { title: '', key: 'actions', sortable: false },
]
const colours = { active: 'success', prospective: 'info', suspended: 'warning', resigned: 'secondary' }

async function loadMeta() {
  meta.value = await $api('/members/meta')
}
async function loadMembers() {
  loading.value = true
  try {
    const query = { page: page.value, per_page: 15 }
    if (search.value) query.search = search.value
    if (status.value) query.status = status.value
    if (sportCode.value) query.sport_code = sportCode.value
    const response = await $api('/members', { query })
    members.value = response.data
    total.value = response.total
  } finally {
    loading.value = false
  }
}
let timer
watch(search, () => {
  clearTimeout(timer)
  timer = setTimeout(() => { page.value = 1; loadMembers() }, 300)
})
watch([status, sportCode, page], loadMembers)

function addMember() {
  selected.value = null
  formOpen.value = true
}
function editMember(member) {
  selected.value = member
  profileOpen.value = false
  formOpen.value = true
}
async function viewMember(member) {
  selected.value = await $api(`/members/${member.id}`)
  profileOpen.value = true
}
async function saved(member) {
  formOpen.value = false
  await Promise.all([loadMeta(), loadMembers()])
  await viewMember(member)
}
const money = value => new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR' }).format(Number(value || 0))
const centsMoney = (value, currency = 'ZAR') => new Intl.NumberFormat('en-ZA', { style: 'currency', currency }).format(Number(value || 0) / 100)
const dateTime = value => value ? new Intl.DateTimeFormat('en-ZA', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—'
const label = value => (value || 'not recorded').replaceAll('_', ' ')

onMounted(() => Promise.all([loadMeta(), loadMembers()]))
</script>

<template>
  <div>
    <header class="hsc-page-header">
      <div>
        <h1 class="text-h3">Members</h1>
        <p class="text-body-1 text-medium-emphasis mb-0">One HSC profile, with memberships managed by each sport code.</p>
      </div>
      <VBtn v-if="canEditCentral" prepend-icon="tabler-user-plus" @click="addMember">Add member</VBtn>
    </header>

    <VRow class="mb-2">
      <VCol v-for="card in cards" :key="card[0]" cols="12" sm="6" lg="3">
        <VCard><VCardText class="d-flex align-center gap-4">
          <VAvatar :color="card[3]" variant="tonal" rounded><VIcon :icon="card[2]" /></VAvatar>
          <div><div class="text-h4">{{ card[1] }}</div><div class="text-body-2">{{ card[0] }}</div></div>
        </VCardText></VCard>
      </VCol>
    </VRow>

    <VCard>
      <VCardText>
        <VRow>
          <VCol cols="12" md="6"><VTextField v-model="search" label="Search name, member number, email or mobile" prepend-inner-icon="tabler-search" clearable hide-details /></VCol>
          <VCol cols="12" sm="6" md="3"><VSelect v-model="sportCode" label="Sport code" :items="meta.sport_codes" item-title="name" item-value="id" clearable hide-details /></VCol>
          <VCol cols="12" sm="6" md="3"><VSelect v-model="status" label="HSC status" :items="['prospective', 'active', 'suspended', 'resigned']" clearable hide-details /></VCol>
        </VRow>
      </VCardText>
      <VDivider />
      <VDataTableServer v-model:page="page" :headers="headers" :items="members" :items-length="total" :loading="loading" :items-per-page="15" hover @click:row="(_, row) => viewMember(row.item)">
        <template #item.member="{ item }">
          <div class="py-3"><div class="font-weight-medium">{{ item.display_name }}</div><div class="text-caption text-medium-emphasis">{{ item.member_number }}<span v-if="item.age !== null"> · Age {{ item.age }}</span></div></div>
        </template>
        <template #item.sports="{ item }">
          <div class="d-flex flex-wrap gap-1"><VChip v-for="code in item.sport_codes" :key="code.id" size="small" :style="{ color: code.colour }" variant="tonal">{{ code.name }}</VChip><span v-if="!item.sport_codes.length">None</span></div>
        </template>
        <template #item.contact="{ item }"><div>{{ item.email || '—' }}</div><div class="text-caption">{{ item.mobile }}</div></template>
        <template #item.access="{ item }"><VChip size="small" :color="item.access_credential?.status === 'active' ? 'success' : 'secondary'" variant="tonal">{{ (item.access_credential?.status || 'not issued').replace('_', ' ') }}</VChip></template>
        <template #item.status="{ item }"><VChip size="small" :color="colours[item.status]" variant="tonal">{{ item.status }}</VChip></template>
        <template #item.actions="{ item }"><VBtn icon="tabler-chevron-right" variant="text" size="small" :aria-label="`View ${item.display_name}`" @click.stop="viewMember(item)" /></template>
      </VDataTableServer>
    </VCard>

    <VDialog v-model="formOpen" max-width="1050" persistent scrollable>
      <VCard>
        <VCardTitle class="d-flex justify-space-between align-center pa-6"><span>{{ selected ? `Edit ${selected.display_name}` : 'Add member' }}</span><VBtn icon="tabler-x" variant="text" aria-label="Close member form" @click="formOpen = false" /></VCardTitle>
        <VDivider />
        <VCardText class="pa-6"><MemberForm v-if="formOpen" :member-id="selected?.id" :meta="meta" @saved="saved" @cancel="formOpen = false" /></VCardText>
      </VCard>
    </VDialog>

    <VDialog v-model="profileOpen" max-width="760" scrollable>
      <VCard v-if="selected">
        <div class="pa-6">
          <div class="d-flex justify-space-between"><div><div class="text-caption text-primary">{{ selected.member_number }}</div><h4 class="text-h4">{{ selected.display_name }}</h4></div><VBtn icon="tabler-x" variant="text" aria-label="Close member details" @click="profileOpen = false" /></div>
          <div class="d-flex gap-2 mt-3"><VChip :color="colours[selected.status]" variant="tonal">{{ selected.status }}</VChip><VChip v-if="selected.age !== null" variant="tonal">Age {{ selected.age }}</VChip></div>
          <VBtn v-if="canEditCentral" class="mt-5" block prepend-icon="tabler-edit" variant="tonal" @click="editMember(selected)">Edit central HSC record</VBtn>
        </div>
        <VDivider />
        <div class="pa-6">
          <h5 class="text-h5 mb-4">Memberships</h5>
          <VCard v-for="code in selected.sport_codes" :key="code.id" variant="outlined" class="mb-3"><VCardText>
            <div class="d-flex justify-space-between"><div><strong>{{ code.name }}</strong><div class="text-caption">{{ code.membership_category?.name || 'No category' }} · {{ code.pivot.role }}</div></div><VChip size="small" color="success" variant="tonal">{{ code.pivot.status }}</VChip></div>
            <div v-if="code.membership_category" class="d-flex justify-space-between text-body-2 mt-4"><span>Code {{ money(code.membership_category.code_fee) }} + HSC {{ money(code.membership_category.hsc_levy) }}</span><strong>{{ money(code.membership_category.total_fee) }}</strong></div>
          </VCardText></VCard>
          <VAlert v-if="!selected.sport_codes.length" type="info" variant="tonal">No sport memberships yet.</VAlert>
          <h5 class="text-h5 mt-7 mb-3">Contact & household</h5>
          <div class="text-body-2"><VIcon icon="tabler-mail" size="18" class="me-2" />{{ selected.email || 'No email' }}</div>
          <div class="text-body-2 mt-2"><VIcon icon="tabler-phone" size="18" class="me-2" />{{ selected.mobile || 'No mobile' }}</div>
          <div class="text-body-2 mt-2"><VIcon icon="tabler-home" size="18" class="me-2" />{{ selected.household?.name || 'No household' }}</div>
          <template v-if="canEditCentral">
            <h5 class="text-h5 mt-7 mb-3">Premises access</h5>
            <VChip :color="selected.access_credential?.status === 'active' ? 'success' : 'secondary'" variant="tonal">{{ (selected.access_credential?.status || 'not issued').replace('_', ' ') }}</VChip>
            <div v-if="selected.access_credential?.areas?.length" class="d-flex flex-wrap gap-1 mt-3"><VChip v-for="area in selected.access_credential.areas" :key="area" size="small">{{ area.replaceAll('_', ' ') }}</VChip></div>
            <p class="text-caption text-medium-emphasis mt-3">Access PINs are never displayed or stored in plain text.</p>
          </template>

          <h5 class="text-h5 mt-7 mb-3">Booking history</h5>
          <VCard v-for="booking in selected.bookings" :key="booking.id" variant="outlined" class="mb-3">
            <VCardText>
              <div class="d-flex justify-space-between align-start gap-3">
                <div>
                  <strong>{{ booking.facility?.name || 'Unknown facility' }}</strong>
                  <div class="text-caption">{{ booking.facility?.venue?.name || 'No venue' }} · {{ booking.booking_reference }}</div>
                </div>
                <VChip size="small" :color="booking.status === 'confirmed' ? 'success' : booking.status === 'cancelled' ? 'error' : 'warning'" variant="tonal">{{ label(booking.status) }}</VChip>
              </div>
              <div class="text-body-2 mt-3"><VIcon icon="tabler-calendar-time" size="18" class="me-2" />{{ dateTime(booking.starts_at) }} – {{ dateTime(booking.ends_at) }}</div>
              <div class="d-flex flex-wrap gap-2 mt-3">
                <VChip size="small" variant="tonal">Payment: {{ label(booking.payment_status) }}</VChip>
                <VChip size="small" variant="tonal">{{ centsMoney(booking.total_amount, booking.currency) }}</VChip>
                <VChip v-if="booking.attendance" size="small" variant="tonal">Check-in: {{ label(booking.attendance.status) }}</VChip>
              </div>
              <VAlert v-if="booking.lighting_reservations?.length" class="mt-3" color="warning" variant="tonal" density="compact" icon="tabler-bulb">
                <div v-for="light in booking.lighting_reservations" :key="light.id">
                  Lighting rental: {{ light.zone?.name || 'Court lights' }} · {{ label(light.status) }} · {{ centsMoney(light.amount, light.currency) }}
                  <div class="text-caption">{{ dateTime(light.scheduled_on_at) }} – {{ dateTime(light.scheduled_off_at) }}</div>
                </div>
              </VAlert>
            </VCardText>
          </VCard>
          <VAlert v-if="!selected.bookings?.length" type="info" variant="tonal">No booking history yet.</VAlert>
        </div>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
:deep(tbody tr) { cursor: pointer; }
</style>
