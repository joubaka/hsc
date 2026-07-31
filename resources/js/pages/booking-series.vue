<script setup>
import { $api } from '@/utils/api'

const series = ref([])
const facilities = ref([])
const meta = ref({ members: [] })
const selected = ref(null)
const preview = ref(null)
const finance = ref(null)
const history = ref([])
const busy = ref(false)
const error = ref('')
const createOpen = ref(false)
const mutationOpen = ref(false)
const mutationType = ref('cancel')
const reason = ref('')
const targetOccurrence = ref(null)
const form = reactive({
  member_id: null, facility_id: null, series_type: 'member_recurring',
  starts_on: new Date(Date.now() + 86400000).toISOString().slice(0, 10), ends_on: '',
  start_time: '10:00', duration_minutes: 60, timezone: 'Africa/Johannesburg',
  recurrence_frequency: 'weekly', recurrence_interval: 1, days_of_week: [6],
  occurrence_limit: 2, guest_count: 0, billing_strategy: 'invoice_each_occurrence', lighting_requested: false,
})
const mutation = reactive({ facility_id: null, starts_at: '', scope: 'occurrence', all_or_nothing: false })
const money = value => new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR' }).format((value || 0) / 100)
const label = value => (value || '').replaceAll('_', ' ')

async function load() {
  busy.value = true
  error.value = ''
  try {
    const [rows, facilityRows, metadata] = await Promise.all([$api('/booking-series'), $api('/booking/facilities'), $api('/booking/facilities/meta')])
    series.value = rows.data
    facilities.value = facilityRows.data || facilityRows
    meta.value = metadata
  } catch (e) {
    error.value = e?.data?.message || 'Recurring bookings could not be loaded.'
  } finally { busy.value = false }
}

async function openSeries(item) {
  busy.value = true
  error.value = ''
  try {
    const [detail, financial, audit] = await Promise.all([
      $api(`/booking-series/${item.id}`),
      $api(`/booking-series/${item.id}/financial-summary`),
      $api(`/booking-series/${item.id}/audit-history`),
    ])
    selected.value = detail
    finance.value = financial
    history.value = audit
    preview.value = null
  } catch (e) { error.value = e?.data?.message || 'Series details could not be loaded.' }
  finally { busy.value = false }
}

async function createSeries() {
  busy.value = true
  error.value = ''
  try {
    const facility = facilities.value.find(row => row.id === form.facility_id)
    const created = await $api('/booking-series', { method: 'POST', body: {
      ...form, sport_code_id: facility.sport_code_id, ends_on: form.ends_on || null,
      participant_template: [], booking_type: 'court', idempotency_key: crypto.randomUUID(),
    } })
    createOpen.value = false
    await load()
    await openSeries(created)
  } catch (e) { error.value = e?.data?.message || 'The recurring request could not be created.' }
  finally { busy.value = false }
}

async function runPreview() {
  busy.value = true
  try { preview.value = await $api(`/booking-series/${selected.value.id}/preview`) }
  catch (e) { error.value = e?.data?.message || 'Preview failed.' }
  finally { busy.value = false }
}

async function workflow(action) {
  busy.value = true
  try {
    if (action === 'submit') await $api(`/booking-series/${selected.value.id}/submit`, { method: 'POST' })
    else await $api(`/booking-series/${selected.value.id}/action`, { method: 'POST', body: { action, reason: reason.value || null, partial: false } })
    reason.value = ''
    await openSeries(selected.value)
    await load()
  } catch (e) { error.value = e?.data?.message || 'The workflow action could not be completed.' }
  finally { busy.value = false }
}

function openMutation(type, occurrence = null) {
  mutationType.value = type
  targetOccurrence.value = occurrence
  reason.value = ''
  mutation.scope = occurrence ? 'occurrence' : 'all_future'
  mutation.facility_id = occurrence?.booking?.facility_id || selected.value.facility_id
  mutation.starts_at = occurrence?.starts_at?.slice(0, 16) || ''
  mutationOpen.value = true
}

async function saveMutation() {
  busy.value = true
  error.value = ''
  try {
    const common = { reason: reason.value, idempotency_key: crypto.randomUUID(), series_version: selected.value.version }
    if (mutationType.value === 'cancel') {
      await $api(`/booking-series/${selected.value.id}/cancel`, { method: 'POST', body: {
        ...common, scope: mutation.scope, effective_occurrence_id: targetOccurrence.value?.id, all_or_nothing: mutation.all_or_nothing,
      } })
    } else if (mutationType.value === 'move' && targetOccurrence.value) {
      await $api(`/booking-series/occurrences/${targetOccurrence.value.id}/move`, { method: 'POST', body: {
        ...common, facility_id: mutation.facility_id, starts_at: mutation.starts_at ? new Date(mutation.starts_at).toISOString() : undefined,
      } })
    } else if (mutationType.value === 'move') {
      await $api(`/booking-series/${selected.value.id}/move`, { method: 'POST', body: {
        ...common, facility_id: mutation.facility_id, scope: mutation.scope, partial: !mutation.all_or_nothing,
      } })
    } else {
      await $api(`/booking-series/occurrences/${targetOccurrence.value.id}/amend`, { method: 'POST', body: {
        ...common, facility_id: mutation.facility_id, starts_at: new Date(mutation.starts_at).toISOString(),
      } })
    }
    mutationOpen.value = false
    await openSeries(selected.value)
    await load()
  } catch (e) { error.value = e?.data?.message || 'The booking change could not be completed. Original bookings were preserved.' }
  finally { busy.value = false }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-6">
      <div><h3 class="text-h3">Recurring bookings</h3><p class="text-medium-emphasis">Member requests, block bookings, approvals, conflicts and series finance.</p></div>
      <VBtn prepend-icon="tabler-calendar-plus" @click="createOpen=true">Request recurring booking</VBtn>
    </div>
    <VAlert v-if="error" type="error" closable class="mb-4" @click:close="error=''">{{ error }}</VAlert>
    <VRow>
      <VCol cols="12" :lg="selected ? 5 : 12">
        <VCard>
          <VCardTitle class="d-flex align-center"><span>My and authorized series</span><VSpacer/><VProgressCircular v-if="busy" indeterminate size="22"/></VCardTitle>
          <VTable>
            <thead><tr><th>Reference</th><th>Facility</th><th>Pattern</th><th>Status</th><th>Occurrences</th></tr></thead>
            <tbody><tr v-for="item in series" :key="item.id" class="cursor-pointer" @click="openSeries(item)">
              <td>{{ item.series_reference }}</td><td>{{ item.facility?.name }}</td>
              <td>{{ label(item.recurrence_frequency) }} · every {{ item.recurrence_interval }}</td>
              <td><VChip size="small" :color="item.approval_status==='approved'?'success':item.approval_status==='rejected'?'error':'warning'">{{ label(item.approval_status) }}</VChip></td>
              <td>{{ item.occurrences_count }}</td>
            </tr></tbody>
          </VTable>
        </VCard>
      </VCol>
      <VCol v-if="selected" cols="12" lg="7">
        <VCard>
          <VCardTitle class="d-flex align-center"><span>{{ selected.series_reference }}</span><VSpacer/><VBtn icon="tabler-x" variant="text" @click="selected=null"/></VCardTitle>
          <VCardText>
            <VRow><VCol cols="6"><strong>Member</strong><div>{{ selected.member?.first_name }} {{ selected.member?.last_name }}</div></VCol>
              <VCol cols="6"><strong>Facility</strong><div>{{ selected.facility?.name }}</div></VCol>
              <VCol cols="6"><strong>Status</strong><div>{{ label(selected.approval_status) }} · version {{ selected.version }}</div></VCol>
              <VCol cols="6"><strong>Billing</strong><div>{{ label(selected.billing_strategy) }}</div></VCol>
              <VCol cols="6"><strong>Lighting</strong><div>{{ selected.lighting_requested ? 'Requested per occurrence' : 'Only when required' }}</div></VCol></VRow>
            <div class="d-flex flex-wrap gap-2 my-4">
              <VBtn size="small" variant="tonal" @click="runPreview">Preview</VBtn>
              <VBtn v-if="['draft','information_required'].includes(selected.approval_status)" size="small" @click="workflow('submit')">Submit</VBtn>
              <VBtn v-if="selected.approval_status==='submitted'" size="small" variant="tonal" @click="workflow('begin_review')">Begin review</VBtn>
              <VBtn v-if="['submitted','under_review'].includes(selected.approval_status)" size="small" color="success" @click="workflow('approve')">Approve valid</VBtn>
              <VBtn v-if="['submitted','under_review'].includes(selected.approval_status)" size="small" color="error" variant="tonal" @click="reason='Rejected by administrator';workflow('reject')">Reject</VBtn>
              <VBtn v-if="selected.status==='active'" size="small" variant="tonal" @click="openMutation('move')">Move future</VBtn>
              <VBtn v-if="selected.status==='active'" size="small" color="error" variant="tonal" @click="openMutation('cancel')">Cancel future</VBtn>
            </div>
            <VAlert v-if="preview" :type="preview.summary.conflicted?'warning':'success'" class="mb-4">
              {{ preview.summary.available }} available · {{ preview.summary.conflicted }} conflicts · {{ money(preview.summary.estimated_total_amount) }} payable
            </VAlert>
            <h4 class="text-h6 mb-2">Occurrences</h4>
            <VTable density="compact"><thead><tr><th>Date</th><th>Time</th><th>Status</th><th>Financial</th><th/></tr></thead>
              <tbody><tr v-for="occ in selected.occurrences" :key="occ.id">
                <td>{{ new Date(occ.starts_at).toLocaleDateString() }}</td><td>{{ new Date(occ.starts_at).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) }}</td>
                <td><VChip size="x-small" :color="occ.operational_conflict_at?'error':occ.status==='confirmed'?'success':'default'">{{ occ.operational_conflict_reason ? label(occ.operational_conflict_reason) : label(occ.status) }}</VChip></td>
                <td>{{ money(occ.booking?.total_amount) }}</td><td class="text-no-wrap"><VBtn v-if="occ.status==='confirmed'" icon="tabler-edit" size="x-small" variant="text" @click="openMutation('amend',occ)"/><VBtn v-if="occ.status==='confirmed'" icon="tabler-arrows-exchange" size="x-small" variant="text" @click="openMutation('move',occ)"/><VBtn v-if="occ.status==='confirmed'" icon="tabler-trash" color="error" size="x-small" variant="text" @click="openMutation('cancel',occ)"/></td>
              </tr></tbody></VTable>
            <VDivider class="my-4"/>
            <h4 class="text-h6">Financial summary</h4><p>{{ money(finance?.invoiced_amount) }} invoiced · {{ money(finance?.paid_amount) }} paid · {{ money(finance?.credited_amount) }} credited · {{ money(finance?.outstanding_amount) }} outstanding</p>
            <h4 class="text-h6 mt-4">Safe audit history</h4><VTimeline side="end" density="compact"><VTimelineItem v-for="row in history" :key="row.id" size="x-small"><strong>{{ label(row.event) }}</strong><div class="text-caption">{{ row.actor }} · {{ new Date(row.occurred_at).toLocaleString() }}</div><div v-if="row.reason">{{ row.reason }}</div></VTimelineItem></VTimeline>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="createOpen" max-width="780" persistent><VCard><VCardTitle>Recurring booking request</VCardTitle><VCardText><VRow>
      <VCol cols="12" md="6"><VSelect v-model="form.member_id" :items="meta.members" item-title="display_name" item-value="id" label="Member"/></VCol>
      <VCol cols="12" md="6"><VSelect v-model="form.facility_id" :items="facilities" item-title="name" item-value="id" label="Facility"/></VCol>
      <VCol cols="12" md="6"><VSelect v-model="form.series_type" :items="['member_recurring','coach_block','league_team','social_group','club_programme','tournament','other']" label="Series type"/></VCol>
      <VCol cols="12" md="6"><VSelect v-model="form.billing_strategy" :items="['invoice_each_occurrence','one_invoice_for_series','allowance_covered','no_charge']" label="Billing strategy"/></VCol>
      <VCol cols="12"><VSwitch v-model="form.lighting_requested" color="warning" label="Request court lighting for eligible occurrences"/><div class="text-caption text-medium-emphasis">Required evening lighting is applied automatically. Every occurrence is revalidated before approval.</div></VCol>
      <VCol cols="6" md="3"><VTextField v-model="form.starts_on" type="date" label="Starts"/></VCol><VCol cols="6" md="3"><VTextField v-model="form.ends_on" type="date" label="Ends (optional)"/></VCol>
      <VCol cols="6" md="3"><VTextField v-model="form.start_time" type="time" label="Start time"/></VCol><VCol cols="6" md="3"><VSelect v-model="form.duration_minutes" :items="[30,60,90,120]" label="Minutes"/></VCol>
      <VCol cols="12" md="4"><VSelect v-model="form.recurrence_frequency" :items="['daily','weekly','monthly_fixed','monthly_ordinal']" label="Recurrence"/></VCol>
      <VCol cols="6" md="4"><VTextField v-model.number="form.recurrence_interval" min="1" max="12" type="number" label="Every"/></VCol><VCol cols="6" md="4"><VTextField v-model.number="form.occurrence_limit" min="1" max="104" type="number" label="Occurrence limit"/></VCol>
    </VRow></VCardText><VCardActions><VSpacer/><VBtn variant="tonal" @click="createOpen=false">Close</VBtn><VBtn :loading="busy" :disabled="!form.member_id||!form.facility_id" @click="createSeries">Create draft</VBtn></VCardActions></VCard></VDialog>

    <VDialog v-model="mutationOpen" max-width="640" persistent><VCard><VCardTitle>{{ label(mutationType) }} {{ targetOccurrence?'occurrence':'future occurrences' }}</VCardTitle><VCardText>
      <VSelect v-if="mutationType==='cancel'" v-model="mutation.scope" :items="targetOccurrence?['occurrence','this_and_future']:['all_future','entire_unstarted']" label="Scope"/>
      <VSelect v-else v-model="mutation.facility_id" :items="facilities" item-title="name" item-value="id" label="Destination facility"/>
      <VTextField v-if="targetOccurrence&&mutationType!=='cancel'" v-model="mutation.starts_at" type="datetime-local" label="New date and time"/>
      <VCheckbox v-if="!targetOccurrence" v-model="mutation.all_or_nothing" label="All or nothing"/>
      <VTextarea v-model="reason" label="Reason" maxlength="500" counter/>
      <VAlert type="info" variant="tonal">Every affected booking is rechecked. If a replacement fails, its original booking remains secured.</VAlert>
    </VCardText><VCardActions><VSpacer/><VBtn variant="tonal" :disabled="busy" @click="mutationOpen=false">Close</VBtn><VBtn :color="mutationType==='cancel'?'error':'primary'" :loading="busy" :disabled="reason.trim().length<3" @click="saveMutation">Confirm</VBtn></VCardActions></VCard></VDialog>
  </div>
</template>
