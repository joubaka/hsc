<script setup>
import { $api } from '@/utils/api'

const items = ref([])
const meta = ref({ members: [], sport_codes: [], periods: [] })
const createOpen = ref(false)
const detail = ref(null)
const audit = ref([])
const status = ref(null)
const decision = ref(null)
const reason = ref('')
const administratorNotes = ref('')
const acting = ref(false)
const errors = ref('')
const form = reactive({
  member_id: null, sport_code_id: null, membership_category_id: null, membership_period_id: null,
  application_type: 'new_membership', applicant_notes: '', joining_fee: 0, discount: 0,
  override_reason: '', guardian_member_id: null, guardian_name: '', guardian_relationship: '',
  guardian_email: '', guardian_mobile: '', guardian_consent: false, independent_access_permitted: false,
})
const selectedCode = computed(() => meta.value.sport_codes.find(code => code.id === form.sport_code_id))
const selectedMember = computed(() => meta.value.members.find(member => member.id === form.member_id))
const selectedPeriod = computed(() => meta.value.periods.find(period => period.id === form.membership_period_id))
const ageAtPeriodStart = computed(() => {
  if (!selectedMember.value?.date_of_birth || !selectedPeriod.value?.starts_on) return null
  const birth = new Date(selectedMember.value.date_of_birth)
  const start = new Date(selectedPeriod.value.starts_on)
  let age = start.getFullYear() - birth.getFullYear()
  if (start < new Date(start.getFullYear(), birth.getMonth(), birth.getDate())) age--
  return age
})
const junior = computed(() => ageAtPeriodStart.value !== null && ageAtPeriodStart.value < 18)
const householdGuardians = computed(() => meta.value.members.filter(member => {
  if (!selectedMember.value?.household_id || member.household_id !== selectedMember.value.household_id || !member.date_of_birth) return false
  const birth = new Date(member.date_of_birth)
  const start = new Date(selectedPeriod.value?.starts_on || Date.now())
  return start.getFullYear() - birth.getFullYear() >= 18 && member.id !== selectedMember.value.id
}))
const money = cents => new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR' }).format((cents || 0) / 100)

async function load() {
  const [list, info] = await Promise.all([
    $api('/membership-applications', { query: status.value ? { status: status.value } : {} }),
    $api('/membership-applications/meta'),
  ])
  items.value = list.data
  meta.value = info
}
async function create() {
  errors.value = ''
  try {
    await $api('/membership-applications', { method: 'POST', body: form })
    createOpen.value = false
    await load()
  } catch (error) { errors.value = error?.data?.message || 'Please check the application information.' }
}
async function view(item) {
  const [application, history] = await Promise.all([
    $api(`/membership-applications/${item.id}`),
    $api(`/membership-applications/${item.id}/audit`),
  ])
  detail.value = application
  audit.value = history
}
async function runAction(action, actionReason = null) {
  acting.value = true
  errors.value = ''
  try {
    await $api(`/membership-applications/${detail.value.id}/action`, {
      method: 'POST',
      body: { action, reason: actionReason, administrator_notes: administratorNotes.value },
    })
    decision.value = null
    reason.value = ''
    administratorNotes.value = ''
    detail.value = null
    await load()
  } catch (error) { errors.value = error?.data?.message || 'The action could not be completed.' }
  finally { acting.value = false }
}
watch(status, load)
onMounted(load)
</script>

<template>
  <div>
    <div class="d-flex flex-wrap justify-space-between align-center gap-3 mb-6">
      <div><h3 class="text-h3">Membership applications</h3><p class="text-medium-emphasis mb-0">Review applications before activating a sport membership.</p></div>
      <VBtn prepend-icon="tabler-plus" @click="createOpen = true">New application</VBtn>
    </div>
    <VCard>
      <VCardText><VSelect v-model="status" label="Status" :items="['draft','submitted','under_review','information_required','approved','rejected','withdrawn','cancelled']" clearable hide-details style="max-width: 300px" /></VCardText>
      <VTable><thead><tr><th>Member</th><th>Sport / category</th><th>Type</th><th>Period</th><th>Status</th><th>Total</th><th /></tr></thead><tbody>
        <tr v-for="application in items" :key="application.id"><td>{{ application.member.display_name }}<div class="text-caption">{{ application.member.member_number }}</div></td><td>{{ application.sport_code.name }} · {{ application.category.name }}</td><td class="text-capitalize">{{ application.application_type.replaceAll('_',' ') }}</td><td>{{ application.period.name }}</td><td><VChip size="small" variant="tonal">{{ application.status.replaceAll('_',' ') }}</VChip></td><td>{{ application.total_payable_cents === null ? 'Draft pricing' : money(application.total_payable_cents) }}</td><td><VBtn icon="tabler-chevron-right" variant="text" @click="view(application)" /></td></tr>
      </tbody></VTable>
    </VCard>

    <VDialog v-model="createOpen" max-width="850" scrollable><VCard><VCardTitle class="pa-6">New membership application</VCardTitle><VCardText>
      <VAlert v-if="errors" type="error" variant="tonal" class="mb-4">{{ errors }}</VAlert>
      <VRow><VCol cols="12" md="6"><VSelect v-model="form.member_id" label="Member" :items="meta.members" :item-title="m => `${m.first_name} ${m.last_name} (${m.member_number})`" item-value="id" /></VCol><VCol cols="12" md="6"><VSelect v-model="form.application_type" label="Application type" :items="['new_membership','additional_sport','renewal','category_change','reinstatement']" /></VCol><VCol cols="12" md="4"><VSelect v-model="form.sport_code_id" label="Sport code" :items="meta.sport_codes" item-title="name" item-value="id" /></VCol><VCol cols="12" md="4"><VSelect v-model="form.membership_category_id" label="Category" :items="selectedCode?.categories || []" item-title="name" item-value="id" /></VCol><VCol cols="12" md="4"><VSelect v-model="form.membership_period_id" label="Period" :items="meta.periods" item-title="name" item-value="id" /></VCol><VCol cols="12"><VTextarea v-model="form.applicant_notes" label="Application notes" /></VCol>
        <template v-if="junior"><VCol cols="12"><VAlert type="info" variant="tonal">Junior age {{ ageAtPeriodStart }} at period start. Select an adult household guardian or enter an external guardian.</VAlert></VCol><VCol cols="12"><VSelect v-model="form.guardian_member_id" label="Existing household guardian" :items="householdGuardians" :item-title="m => `${m.first_name} ${m.last_name}`" item-value="id" clearable /></VCol><template v-if="!form.guardian_member_id"><VCol cols="12" md="6"><VTextField v-model="form.guardian_name" label="External guardian name" /></VCol><VCol cols="12" md="6"><VTextField v-model="form.guardian_relationship" label="Relationship" /></VCol><VCol cols="12" md="6"><VTextField v-model="form.guardian_email" label="Guardian email" /></VCol><VCol cols="12" md="6"><VTextField v-model="form.guardian_mobile" label="Guardian mobile" /></VCol></template><VCol cols="12"><VCheckbox v-model="form.guardian_consent" label="Guardian consent recorded" /><VCheckbox v-model="form.independent_access_permitted" label="Independent premises access may be considered (does not grant access)" /></VCol></template>
      </VRow>
    </VCardText><VCardActions class="pa-6"><VSpacer /><VBtn variant="tonal" @click="createOpen = false">Cancel</VBtn><VBtn @click="create">Create draft</VBtn></VCardActions></VCard></VDialog>

    <VNavigationDrawer :model-value="Boolean(detail)" location="right" temporary width="540" @update:model-value="value => { if (!value) detail = null }"><div v-if="detail" class="pa-6"><div class="d-flex justify-space-between"><h4 class="text-h4">{{ detail.member.display_name }}</h4><VBtn icon="tabler-x" variant="text" @click="detail = null" /></div><p>{{ detail.member.member_number }} · {{ detail.sport_code.name }} · {{ detail.category.name }} · {{ detail.period.name }}</p><VChip variant="tonal">{{ detail.status.replaceAll('_',' ') }}</VChip><VDivider class="my-5" /><h5 class="text-h5 mb-3">Fee snapshot</h5><p>Membership dates: {{ detail.period.starts_on.slice(0,10) }} – {{ detail.period.ends_on.slice(0,10) }}<br>Sport fee: {{ money(detail.sport_code_fee_cents) }}<br>HSC levy: {{ money(detail.hsc_levy_cents) }}<br>Joining fee: {{ money(detail.joining_fee_cents) }}<br>Discount: {{ money(detail.discount_cents) }}<br>Billing: {{ detail.billing_frequency || 'Frozen on submission' }}</p><h4>Total: {{ money(detail.total_payable_cents) }}</h4><VDivider class="my-5" /><div class="d-flex flex-wrap gap-2"><VBtn v-if="['draft','information_required'].includes(detail.status)" @click="runAction('submit')">Submit</VBtn><VBtn v-if="detail.status === 'submitted'" @click="runAction('begin_review')">Begin review</VBtn><VBtn v-if="detail.status === 'under_review'" color="success" @click="decision = 'approve'">Approve</VBtn><VBtn v-if="detail.status === 'under_review'" color="error" variant="tonal" @click="decision = 'reject'">Reject</VBtn><VBtn v-if="detail.status === 'under_review'" variant="tonal" @click="runAction('request_information')">Request information</VBtn></div><VDivider class="my-5" /><h5 class="text-h5 mb-3">Audit history</h5><VTimeline side="end" density="compact"><VTimelineItem v-for="event in audit" :key="event.id" size="small"><div class="font-weight-medium">{{ event.action.replaceAll('.', ' · ').replaceAll('_', ' ') }}</div><div class="text-caption">{{ event.user?.name || 'System' }} · {{ new Date(event.created_at).toLocaleString() }}</div></VTimelineItem></VTimeline></div></VNavigationDrawer>

    <VDialog :model-value="Boolean(decision)" max-width="620" @update:model-value="value => { if (!value) decision = null }"><VCard v-if="detail"><VCardTitle class="pa-6">{{ decision === 'approve' ? 'Confirm application approval' : 'Reject application' }}</VCardTitle><VCardText><VAlert v-if="errors" type="error" variant="tonal" class="mb-4">{{ errors }}</VAlert><template v-if="decision === 'approve'"><VAlert type="warning" variant="tonal" class="mb-4">Approval creates an historical membership and cannot be reversed here.</VAlert><p><strong>{{ detail.member.display_name }} ({{ detail.member.member_number }})</strong><br>{{ detail.sport_code.name }} · {{ detail.category.name }} · {{ detail.period.name }}<br>{{ detail.period.starts_on.slice(0,10) }} – {{ detail.period.ends_on.slice(0,10) }}<br>Total: <strong>{{ money(detail.total_payable_cents) }}</strong></p></template><VTextarea v-else v-model="reason" label="Rejection reason *" /><VTextarea v-model="administratorNotes" label="Administrator note (optional)" /></VCardText><VCardActions class="pa-6"><VSpacer /><VBtn variant="tonal" @click="decision = null">Cancel</VBtn><VBtn :color="decision === 'approve' ? 'success' : 'error'" :disabled="decision === 'reject' && !reason" :loading="acting" @click="runAction(decision, reason)">{{ decision === 'approve' ? 'Approve membership' : 'Confirm rejection' }}</VBtn></VCardActions></VCard></VDialog>
  </div>
</template>
