<script setup>
/* eslint-disable camelcase */
import { $api } from '@/utils/api'
import { useDisplay } from 'vuetify'

const { smAndDown } = useDisplay()
const meta = ref({ members: [] })
const facilities = ref([])
const bookings = ref([])
const result = ref(null)
const hold = ref(null)
const error = ref('')
const success = ref('')
const busy = ref(false)
const loadingSchedule = ref(false)
const activeTab = ref('calendar')
const selectedDate = ref(new Date(Date.now() + 86400000).toISOString().slice(0, 10))
const selectedSlot = ref(null)
const bookingDialog = ref(false)
const mobileFacilityId = ref(null)
const cancelOpen = ref(false)
const cancelTarget = ref(null)
const cancelPreview = ref(null)
const cancelReason = ref('')
const editOpen = ref(false)
const editTarget = ref(null)
const editForm = reactive({ facility_id: null, date: '', start_time: '', duration: 60, booked_for_name: '', notes: '', has_lighting: false, lighting_on_at: '', lighting_off_at: '', lighting_amount: 0 })

const dayStart = 6 * 60
const dayEnd = 22 * 60
const pixelsPerMinute = 1.5
const laneHeight = (dayEnd - dayStart) * pixelsPerMinute
const timeSlots = Array.from({ length: (dayEnd - dayStart) / 30 }, (_, index) => dayStart + index * 30)
const form = reactive({ recipient_type: 'member', member_id: null, booked_for_name: '', facility_id: null, facility_ids: [], date: selectedDate.value, start_time: '10:00', duration: 60, guest_count: 0, lighting_requested: false, no_charge: false })

const money = (value, currency = 'ZAR') => new Intl.NumberFormat('en-ZA', { style: 'currency', currency }).format((value || 0) / 100)
const minutesToTime = minutes => `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`

const timeToMinutes = value => { const [hours, minutes] = String(value).slice(0, 5).split(':').map(Number)

  return hours * 60 + minutes }

const localDate = value => new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Johannesburg' }).format(new Date(value))

const localMinutes = value => { const parts = new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Johannesburg', hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(new Date(value))

  return Number(parts.find(part => part.type === 'hour').value) * 60 + Number(parts.find(part => part.type === 'minute').value) }
const localDateTimeInput = value => { if (!value) return ''; const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Johannesburg', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(new Date(value)); const part = type => parts.find(row => row.type === type)?.value; return `${part('year')}-${part('month')}-${part('day')}T${part('hour')}:${part('minute')}` }

const formatDay = value => new Intl.DateTimeFormat('en-ZA', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Africa/Johannesburg' }).format(new Date(`${value}T12:00:00+02:00`))
const dayOfWeek = computed(() => new Date(`${selectedDate.value}T12:00:00+02:00`).getDay())
const visibleFacilities = computed(() => facilities.value.filter(item => item.active && item.bookable))
const displayedFacilities = computed(() => {
  if (!smAndDown.value) return visibleFacilities.value

  const selected = visibleFacilities.value.find(item => item.id === mobileFacilityId.value)

  return selected ? [selected] : visibleFacilities.value.slice(0, 1)
})
const selectedFacility = computed(() => facilities.value.find(item => item.id === form.facility_id))
const selectedFacilities = computed(() => visibleFacilities.value.filter(item => form.facility_ids.includes(item.id)))
const selectedCourtNames = computed(() => selectedFacilities.value.map(item => item.name).join(', '))
const activeBookings = computed(() => bookings.value.filter(item => localDate(item.starts_at) === selectedDate.value && !['cancelled', 'released_no_show'].includes(item.status)))
const selectedMember = computed(() => meta.value.members.find(item => item.id === form.member_id))
const recipientName = computed(() => form.recipient_type === 'custom' ? form.booked_for_name.trim() : selectedMember.value?.display_name)
const recipientReady = computed(() => form.recipient_type === 'custom' ? form.booked_for_name.trim().length >= 2 : Boolean(form.member_id))

const payload = (facilityId = form.facility_id) => {
  const start = new Date(`${form.date}T${form.start_time}:00+02:00`)

  return { member_id: form.recipient_type === 'member' ? form.member_id : null, booked_for_name: form.recipient_type === 'custom' ? form.booked_for_name.trim() : null, facility_id: facilityId, starts_at: start.toISOString(), ends_at: new Date(start.getTime() + form.duration * 60000).toISOString(), guest_count: form.guest_count, lighting_requested: form.lighting_requested }
}

const openingWindow = facility => facility.opening_hours?.find(row => row.active && Number(row.day_of_week) === dayOfWeek.value && (!row.effective_from || row.effective_from <= selectedDate.value) && (!row.effective_until || row.effective_until >= selectedDate.value))
const bookingAt = (facilityId, start, end) => activeBookings.value.find(item => item.facility_id === facilityId && localMinutes(item.starts_at) < end && localMinutes(item.ends_at) > start)

const slotUnavailable = (facility, start) => {
  const end = start + Number(form.duration)
  const hours = openingWindow(facility)
  if (!hours || start < timeToMinutes(hours.opens_at) || end > timeToMinutes(hours.closes_at)) return true

  return Boolean(bookingAt(facility.id, start, end))
}

const bookingStyle = item => ({ top: `${(localMinutes(item.starts_at) - dayStart) * pixelsPerMinute}px`, height: `${Math.max(30, (localMinutes(item.ends_at) - localMinutes(item.starts_at)) * pixelsPerMinute)}px` })
const bookingColor = item => item.status === 'confirmed' ? 'confirmed' : item.status === 'pending_payment' ? 'payment' : 'pending'

async function loadBase() {
  [meta.value, facilities.value] = await Promise.all([$api('/booking/facilities/meta'), $api('/booking/facilities')])
  mobileFacilityId.value ||= visibleFacilities.value[0]?.id || null
  await loadSchedule()
}
async function loadSchedule() {
  loadingSchedule.value = true
  error.value = ''
  try { bookings.value = (await $api(`/bookings?from=${selectedDate.value}&to=${selectedDate.value}`)).data }
  catch (exception) { error.value = exception?.data?.message || 'The court schedule could not be loaded.' }
  finally { loadingSchedule.value = false }
}
function moveDay(amount) {
  const date = new Date(`${selectedDate.value}T12:00:00+02:00`)

  date.setDate(date.getDate() + amount)
  selectedDate.value = date.toISOString().slice(0, 10)
}
function selectSlot(facility, start) {
  if (slotUnavailable(facility, start)) return
  form.facility_id = facility.id
  form.facility_ids = [facility.id]
  form.date = selectedDate.value
  form.start_time = minutesToTime(start)
  selectedSlot.value = { facility, start, end: start + Number(form.duration) }
  bookingDialog.value = true
  result.value = null
  hold.value = null
  error.value = ''
  success.value = ''
}
async function simulate() {
  busy.value = true
  error.value = ''
  try {
    const courtResults = await Promise.all(form.facility_ids.map(async facilityId => ({ facility: facilities.value.find(item => item.id === facilityId), evaluation: await $api('/booking/simulate', { method: 'POST', body: payload(facilityId) }) })))
    const first = courtResults[0]?.evaluation
    result.value = first ? { ...first, eligible: courtResults.every(row => row.evaluation.eligible), court_results: courtResults, price: { ...first.price, gross_amount: courtResults.reduce((sum, row) => sum + row.evaluation.price.gross_amount, 0), total_amount: courtResults.reduce((sum, row) => sum + row.evaluation.price.total_amount, 0) } } : null

    return result.value
  }
  catch (exception) { error.value = exception?.data?.message || 'Slot could not be evaluated.' }
  finally { busy.value = false }

  return null
}
async function createHold() {
  busy.value = true
  error.value = ''
  try { hold.value = await $api('/booking/holds', { method: 'POST', body: { ...payload(), idempotency_key: crypto.randomUUID() } }) }
  catch (exception) { error.value = exception?.data?.message || 'Hold could not be created.' }
  finally { busy.value = false }
}
async function confirm() {
  busy.value = true
  error.value = ''
  try { await $api(`/booking/holds/${hold.value.id}/confirm`, { method: 'POST', body: { confirmation_key: crypto.randomUUID(), participants: [] } }); hold.value = null; result.value = null; selectedSlot.value = null; await loadSchedule() }
  catch (exception) { error.value = exception?.data?.message || 'Booking could not be confirmed.' }
  finally { busy.value = false }
}
async function confirmBooking() {
  if (!recipientReady.value || !selectedSlot.value) return
  busy.value = true
  error.value = ''
  success.value = ''

  try {
    const evaluation = result.value || await simulate()

    result.value = evaluation
    if (!evaluation.eligible) return

    const activeHolds = await Promise.all(form.facility_ids.map(facilityId => $api('/booking/holds', { method: 'POST', body: { ...payload(facilityId), idempotency_key: crypto.randomUUID() } })))
    const confirmed = []
    for (const activeHold of activeHolds) confirmed.push(await $api(`/booking/holds/${activeHold.id}/confirm`, { method: 'POST', body: { confirmation_key: crypto.randomUUID(), participants: [], billing_strategy: form.no_charge ? 'no_charge' : 'invoice_each_occurrence' } }))
    success.value = `${confirmed.length} court booking${confirmed.length === 1 ? '' : 's'} confirmed successfully.`
    bookingDialog.value = false
    hold.value = null
    result.value = null
    selectedSlot.value = null
    await loadSchedule()
  }
  catch (exception) { error.value = exception?.data?.message || 'Booking could not be confirmed.' }
  finally { busy.value = false }
}
async function openCancellation(item) {
  busy.value = true
  error.value = ''
  try { cancelTarget.value = item; cancelReason.value = ''; cancelPreview.value = await $api(`/bookings/${item.id}/cancellation-preview`); cancelOpen.value = true }
  catch (exception) { error.value = exception?.data?.message || 'Cancellation could not be previewed.' }
  finally { busy.value = false }
}
function openEdit(item) {
  editTarget.value = item
  editForm.facility_id = item.facility_id
  editForm.date = localDate(item.starts_at)
  editForm.start_time = minutesToTime(localMinutes(item.starts_at))
  editForm.duration = Number(item.duration_minutes) || Math.round((new Date(item.ends_at) - new Date(item.starts_at)) / 60000)
  editForm.booked_for_name = item.booked_for_name || ''
  editForm.notes = item.notes || ''
  const lighting = item.lighting_reservations?.find(row => !['cancelled', 'off'].includes(row.status))
  editForm.has_lighting = Boolean(lighting)
  editForm.lighting_on_at = localDateTimeInput(lighting?.scheduled_on_at)
  editForm.lighting_off_at = localDateTimeInput(lighting?.scheduled_off_at)
  editForm.lighting_amount = lighting?.amount || 0
  editOpen.value = true
  error.value = ''
}
async function saveEdit() {
  busy.value = true
  error.value = ''
  try {
    const start = new Date(`${editForm.date}T${editForm.start_time}:00+02:00`)
    await $api(`/bookings/${editTarget.value.id}`, { method: 'PATCH', body: { facility_id: editForm.facility_id, starts_at: start.toISOString(), ends_at: new Date(start.getTime() + Number(editForm.duration) * 60000).toISOString(), booked_for_name: editTarget.value.member_id ? null : editForm.booked_for_name, notes: editForm.notes || null, lighting_on_at: editForm.has_lighting ? new Date(`${editForm.lighting_on_at}:00+02:00`).toISOString() : null, lighting_off_at: editForm.has_lighting ? new Date(`${editForm.lighting_off_at}:00+02:00`).toISOString() : null } })
    editOpen.value = false
    success.value = `Booking ${editTarget.value.booking_reference} updated.`
    await loadSchedule()
  } catch (exception) { error.value = exception?.data?.message || 'The booking could not be updated.' }
  finally { busy.value = false }
}
async function cancel() {
  if (!cancelReason.value.trim()) return
  busy.value = true
  error.value = ''
  try { await $api(`/bookings/${cancelTarget.value.id}/cancel`, { method: 'POST', body: { reason: cancelReason.value, idempotency_key: crypto.randomUUID() } }); cancelOpen.value = false; cancelTarget.value = null; cancelPreview.value = null; await loadSchedule() }
  catch (exception) { error.value = exception?.data?.message || 'Cancellation could not be completed.' }
  finally { busy.value = false }
}
async function checkIn(item) {
  busy.value = true
  error.value = ''
  try { await $api(`/bookings/${item.id}/check-in`, { method: 'POST', body: { method: 'member' } }); await loadSchedule() }
  catch (exception) { error.value = exception?.data?.message || 'Check-in could not be completed.' }
  finally { busy.value = false }
}

watch(selectedDate, async value => { form.date = value; selectedSlot.value = null; result.value = null; hold.value = null; await loadSchedule() })
watch(() => form.duration, value => {
  if (selectedSlot.value) selectedSlot.value.end = selectedSlot.value.start + Number(value)
  result.value = null
  hold.value = null
})
watch(() => [form.recipient_type, form.member_id, form.booked_for_name, form.facility_ids.join(','), form.guest_count, form.lighting_requested, form.no_charge], () => { if (form.recipient_type === 'custom') form.no_charge = true; result.value = null; hold.value = null })
onMounted(loadBase)
</script>

<template>
  <div class="booking-page">
    <div class="booking-header d-flex flex-wrap justify-space-between align-center gap-4 mb-5">
      <div>
        <h3 class="text-h3">
          Court bookings
        </h3><p class="text-medium-emphasis mb-0">
          Choose a court and time directly from the live schedule.
        </p>
      </div>
      <div class="date-navigation d-flex align-center gap-2">
        <VBtn
          icon="tabler-chevron-left"
          variant="tonal"
          size="small"
          aria-label="Previous day"
          @click="moveDay(-1)"
        /><VTextField
          v-model="selectedDate"
          type="date"
          density="compact"
          hide-details
          class="date-picker"
        /><VBtn
          icon="tabler-chevron-right"
          variant="tonal"
          size="small"
          aria-label="Next day"
          @click="moveDay(1)"
        /><VBtn
          variant="text"
          @click="selectedDate=new Date().toISOString().slice(0,10)"
        >
          Today
        </VBtn>
      </div>
    </div>
    <VAlert
      v-if="error"
      type="error"
      closable
      class="mb-4"
      @click:close="error=''"
    >
      {{ error }}
    </VAlert>
    <VAlert
      v-if="success"
      type="success"
      closable
      class="mb-4"
      @click:close="success=''"
    >
      {{ success }}
    </VAlert>

    <VTabs
      v-model="activeTab"
      class="mb-4"
    >
      <VTab value="calendar">
        <VIcon
          start
          icon="tabler-calendar"
        />Calendar
      </VTab><VTab value="bookings">
        <VIcon
          start
          icon="tabler-list"
        />My bookings
      </VTab>
    </VTabs>

    <VWindow v-model="activeTab">
      <VWindowItem value="calendar">
        <VCard class="calendar-card">
          <div class="schedule-toolbar">
            <div>
              <div class="text-h5">
                {{ formatDay(selectedDate) }}
              </div><div class="text-caption text-medium-emphasis">
                Times shown in South Africa time
              </div>
            </div>
            <div
              v-if="!smAndDown"
              class="toolbar-fields"
            >
              <VSelect
                v-model="form.member_id"
                :items="meta.members"
                item-title="display_name"
                item-value="id"
                label="Booking for"
                density="compact"
                hide-details
              /><VSelect
                v-model="form.duration"
                :items="[30,60,90,120]"
                label="Duration"
                density="compact"
                hide-details
                suffix="min"
              /><VTextField
                v-model.number="form.guest_count"
                type="number"
                min="0"
                label="Guests"
                density="compact"
                hide-details
              /><VSwitch
                v-model="form.lighting_requested"
                label="Lighting"
                density="compact"
                hide-details
                color="primary"
              />
            </div>
          </div>
          <div
            v-if="smAndDown"
            class="mobile-court-picker"
          >
            <VSelect
              v-model="mobileFacilityId"
              :items="visibleFacilities"
              item-title="name"
              item-value="id"
              label="Choose a court"
              prepend-inner-icon="tabler-building-community"
              hide-details
            />
            <div class="mobile-booking-hint">
              <VIcon
                icon="tabler-hand-click"
                size="20"
              /> Tap an available time below to book
            </div>
          </div>
          <div class="schedule-legend">
            <span><i class="available" />Available</span><span><i class="confirmed" />Confirmed</span><span><i class="payment" />Payment pending</span><span><i class="closed" />Closed / unavailable</span>
            <strong class="schedule-tip"><VIcon
              icon="tabler-pointer"
              size="16"
            /> {{ smAndDown ? 'Tap a time to book' : 'Hover to see a time, then click to book' }}</strong>
          </div>
          <VProgressLinear
            v-if="loadingSchedule"
            indeterminate
          />
          <div
            v-if="displayedFacilities.length"
            class="schedule-scroll"
          >
            <div
              class="schedule-grid"
              :style="{'--court-count':displayedFacilities.length}"
            >
              <div class="time-corner">
                Time
              </div>
              <div
                v-for="facility in displayedFacilities"
                :key="`head-${facility.id}`"
                class="court-head"
              >
                <strong>{{ facility.name }}</strong><small>{{ facility.venue?.name }} · {{ facility.indoor?'Indoor':'Outdoor' }}</small>
              </div>
              <div
                class="time-rail"
                :style="{height:`${laneHeight}px`}"
              >
                <span
                  v-for="time in timeSlots"
                  :key="time"
                  :class="{hour:time%60===0}"
                  :style="{top:`${(time-dayStart)*pixelsPerMinute}px`}"
                >{{ minutesToTime(time) }}</span>
              </div>
              <div
                v-for="facility in displayedFacilities"
                :key="facility.id"
                class="court-lane"
                :style="{height:`${laneHeight}px`}"
              >
                <button
                  v-for="time in timeSlots"
                  :key="time"
                  type="button"
                  class="slot-button"
                  :class="{hour:time%60===0,unavailable:slotUnavailable(facility,time),selected:selectedSlot?.facility.id===facility.id&&selectedSlot?.start===time}"
                  :style="{top:`${(time-dayStart)*pixelsPerMinute}px`,height:`${30*pixelsPerMinute}px`}"
                  :disabled="slotUnavailable(facility,time)"
                  :aria-label="`${facility.name} ${minutesToTime(time)} ${slotUnavailable(facility,time)?'unavailable':'available'}`"
                  @click="selectSlot(facility,time)"
                >
                  <span v-if="selectedSlot?.facility.id===facility.id&&selectedSlot?.start===time"><VIcon
                    size="16"
                    icon="tabler-check"
                  /> {{ form.duration }} min</span>
                  <span
                    v-else-if="!slotUnavailable(facility,time)"
                    class="slot-hint"
                  >{{ minutesToTime(time) }} · Book</span>
                </button>
                <button
                  v-for="item in activeBookings.filter(row=>row.facility_id===facility.id)"
                  :key="item.id"
                  type="button"
                  class="booking-block"
                  :class="bookingColor(item)"
                  :style="bookingStyle(item)"
                  :title="`${item.member?.display_name || item.booked_for_name || 'Custom booking'} · ${item.booking_reference} · ${item.status}`"
                  @click.stop.prevent="openEdit(item)"
                >
                  <strong>{{ minutesToTime(localMinutes(item.starts_at)) }}–{{ minutesToTime(localMinutes(item.ends_at)) }}</strong><span class="booking-member">{{ item.member?.display_name || item.booked_for_name || 'Custom booking' }}</span><small>{{ item.booking_reference }} · {{ item.status.replaceAll('_',' ') }}</small>
                </button>
              </div>
            </div>
          </div>
          <VAlert
            v-else-if="!loadingSchedule"
            type="info"
            variant="tonal"
            class="ma-5"
          >
            No bookable courts are available.
          </VAlert>
        </VCard>

        <VExpandTransition>
          <VCard
            v-if="false && selectedSlot"
            class="mt-4 selection-card"
          >
            <VCardTitle class="d-flex flex-wrap justify-space-between gap-2">
              <span>{{ selectedSlot.facility.name }} · {{ form.start_time }}–{{ minutesToTime(selectedSlot.end) }}</span><VBtn
                icon="tabler-x"
                size="small"
                variant="text"
                aria-label="Clear selection"
                @click="selectedSlot=null;result=null;hold=null"
              />
            </VCardTitle>
            <VCardText>
              <VAlert
                v-if="!form.member_id"
                type="info"
                variant="tonal"
                class="mb-4"
              >
                Choose who the booking is for in the toolbar above.
              </VAlert>
              <div
                v-else
                class="d-flex flex-wrap align-center gap-3 mb-4"
              >
                <VChip
                  prepend-icon="tabler-user"
                  color="primary"
                  variant="tonal"
                >
                  {{ selectedMember?.display_name }}
                </VChip><VChip prepend-icon="tabler-calendar">
                  {{ formatDay(form.date) }}
                </VChip><VChip prepend-icon="tabler-clock">
                  {{ form.duration }} minutes
                </VChip><VBtn
                  :loading="busy"
                  @click="simulate"
                >
                  Check rules and price
                </VBtn>
              </div>
              <VRow v-if="result">
                <VCol
                  cols="12"
                  md="7"
                >
                  <VAlert
                    :type="result.eligible?'success':'warning'"
                    variant="tonal"
                    class="mb-3"
                    :title="result.eligible?'Available to book':'This time cannot be booked'"
                  /><VList density="compact">
                    <VListItem
                      v-for="check in result.checks"
                      :key="check.code"
                      :prepend-icon="check.passed?'tabler-circle-check':'tabler-alert-circle'"
                      :title="check.code.replaceAll('_',' ')"
                      :subtitle="check.passed?'Passed':check.member_message"
                    />
                  </VList>
                </VCol><VCol
                  cols="12"
                  md="5"
                >
                  <VCard variant="tonal">
                    <VCardText>
                      <div class="text-caption">
                        Price
                      </div><div class="text-h4 mb-3">
                        {{ money(result.price.total_amount,result.price.currency) }}
                      </div><p><strong>Allowance:</strong> {{ result.allowance.minutes||0 }} min / {{ money(result.allowance.value_cents) }}</p><p><strong>Cancel by:</strong> {{ new Date(result.cancellation.cancellation_deadline).toLocaleString() }}</p><VBtn
                        v-if="result.eligible&&!hold"
                        block
                        :loading="busy"
                        @click="createHold"
                      >
                        Hold this time
                      </VBtn>
                    </VCardText>
                  </VCard>
                </VCol>
              </VRow>
              <VAlert
                v-if="hold"
                type="warning"
                variant="tonal"
                class="mt-4"
              >
                <div class="d-flex flex-wrap justify-space-between align-center gap-3">
                  <span>Reserved for you until {{ new Date(hold.expires_at).toLocaleTimeString() }}</span><VBtn
                    :loading="busy"
                    @click="confirm"
                  >
                    Confirm booking
                  </VBtn>
                </div>
              </VAlert>
            </VCardText>
          </VCard>
        </VExpandTransition>

        <VDialog
          v-model="bookingDialog"
          max-width="720"
          :fullscreen="smAndDown"
          persistent
        >
          <VCard v-if="selectedSlot">
            <VCardTitle class="booking-dialog-title">
              <div>
                <div class="text-h5">
                  Book {{ form.facility_ids.length > 1 ? `${form.facility_ids.length} courts` : selectedSlot.facility.name }}
                </div>
                <div class="text-body-2 text-medium-emphasis">
                  {{ formatDay(form.date) }} · {{ form.start_time }}–{{ minutesToTime(selectedSlot.end) }}
                </div>
              </div>
              <VBtn
                icon="tabler-x"
                variant="text"
                aria-label="Close booking"
                :disabled="busy"
                @click="bookingDialog=false;selectedSlot=null;result=null;hold=null"
              />
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-6">
              <div class="booking-steps mb-6">
                <span class="active"><b>1</b> Details</span>
                <span :class="{active:result}"><b>2</b> Review</span>
                <span :class="{active:result?.eligible}"><b>3</b> Confirm</span>
              </div>

              <VRow>
                <VCol
                  cols="12"
                  md="5"
                >
                  <VSelect
                    v-model="form.recipient_type"
                    :items="[{title:'Club member',value:'member'},{title:'Non-member / custom name',value:'custom'}]"
                    label="Booking type"
                  />
                </VCol>
                <VCol cols="12">
                  <VSelect
                    v-model="form.facility_ids"
                    :items="visibleFacilities"
                    item-title="name"
                    item-value="id"
                    label="Courts to book at this same date and time"
                    multiple
                    chips
                    closable-chips
                    hint="Select one or more courts. Availability is checked for every court before confirmation."
                    persistent-hint
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="7"
                >
                  <VSelect
                    v-if="form.recipient_type==='member'"
                    v-model="form.member_id"
                    :items="meta.members"
                    item-title="display_name"
                    item-value="id"
                    label="Who is this booking for?"
                    autofocus
                  />
                  <VTextField
                    v-else
                    v-model="form.booked_for_name"
                    label="Name for this booking"
                    placeholder="e.g. Hermanus High School"
                    autofocus
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="5"
                >
                  <VSelect
                    v-model="form.duration"
                    :items="[30,60,90,120]"
                    label="Duration"
                    suffix="minutes"
                  />
                </VCol>
                <VCol
                  cols="12"
                  sm="6"
                >
                  <VTextField
                    v-model.number="form.guest_count"
                    type="number"
                    min="0"
                    label="Number of guests"
                  />
                </VCol>
                <VCol
                  cols="12"
                  sm="6"
                >
                  <VSwitch
                    v-model="form.lighting_requested"
                    label="Add court lighting"
                    color="primary"
                  />
                </VCol>
                <VCol
                  v-if="meta.can_manage_central"
                  cols="12"
                >
                  <VSwitch
                    v-model="form.no_charge"
                    color="success"
                    label="Waive court rental"
                    hint="Court rental is free; selected lighting remains payable by everyone."
                    persistent-hint
                  />
                </VCol>
              </VRow>

              <VAlert
                v-if="!recipientReady"
                type="info"
                variant="tonal"
              >
                {{ form.recipient_type==='custom' ? 'Enter the non-member or group name to continue.' : 'Select a member to continue.' }}
              </VAlert>

              <template v-if="result">
                <VAlert
                  :type="result.eligible ? 'success' : 'warning'"
                  variant="tonal"
                  class="mb-4"
                  :title="result.eligible ? 'Available — ready to confirm' : 'This booking is not allowed'"
                >
                  {{ result.eligible ? 'The booking rules have passed.' : 'Review the failed checks below or choose another time.' }}
                </VAlert>
                <div
                  v-if="result.eligible"
                  class="booking-summary"
                >
                  <div><span>{{ form.facility_ids.length > 1 ? 'Courts' : 'Court' }}</span><strong>{{ selectedCourtNames }}</strong></div>
                  <div><span>Booked for</span><strong>{{ recipientName }}</strong></div>
                  <div><span>Time</span><strong>{{ form.start_time }}–{{ minutesToTime(selectedSlot.end) }}</strong></div>
                  <div><span>Total payable</span><strong class="text-primary">{{ form.no_charge ? money(result.price.lighting_amount,result.price.currency) : money(result.price.total_amount,result.price.currency) }}</strong></div>
                </div>
                <VAlert
                  v-if="result.eligible && form.no_charge"
                  type="success"
                  variant="tonal"
                  class="mt-4"
                >
                  Court rental is waived. Lighting of {{ money(result.price.lighting_amount,result.price.currency) }} remains payable.
                </VAlert>
                <VList
                  v-else
                  density="compact"
                >
                  <VListItem
                    v-for="check in result.checks.filter(item => !item.passed)"
                    :key="check.code"
                    prepend-icon="tabler-alert-circle"
                    :title="check.code.replaceAll('_',' ')"
                    :subtitle="check.member_message"
                  />
                </VList>
              </template>
            </VCardText>
            <VDivider />
            <VCardActions class="booking-actions pa-5">
              <VBtn
                variant="text"
                :disabled="busy"
                @click="bookingDialog=false;selectedSlot=null;result=null;hold=null"
              >
                Cancel
              </VBtn>
              <VSpacer />
              <VBtn
                v-if="!result?.eligible"
                color="primary"
                size="large"
                prepend-icon="tabler-search"
                :disabled="!recipientReady||!form.facility_ids.length"
                :loading="busy"
                @click="simulate"
              >
                Check availability and price
              </VBtn>
              <VBtn
                v-else
                color="success"
                size="large"
                prepend-icon="tabler-circle-check"
                :loading="busy"
                @click="confirmBooking"
              >
                {{ form.no_charge ? `Confirm · lights ${money(result.price.lighting_amount,result.price.currency)}` : `Confirm booking · ${money(result.price.total_amount,result.price.currency)}` }}
              </VBtn>
            </VCardActions>
          </VCard>
        </VDialog>
      </VWindowItem>

      <VWindowItem value="bookings">
        <VCard>
          <VCardTitle>My and authorized bookings</VCardTitle><VTable class="bookings-table">
            <thead><tr><th>Reference</th><th>Member</th><th>Court</th><th>Start</th><th>Status</th><th>Payment / lights</th><th /></tr></thead><tbody>
              <tr
                v-for="item in bookings"
                :key="item.id"
              >
                <td>{{ item.booking_reference }}</td><td>
                  <strong>{{ item.member?.display_name || item.booked_for_name || 'Custom booking' }}</strong><div class="text-caption text-medium-emphasis">
                    {{ item.member?.member_number }}
                  </div>
                </td><td>{{ item.facility.name }}</td><td>{{ new Date(item.starts_at).toLocaleString() }}</td><td>
                  {{ item.status.replaceAll('_',' ') }}<div v-if="item.attendance">
                    <small>Attendance: {{ item.attendance.status.replaceAll('_',' ') }}</small>
                  </div>
                </td><td><div>{{ item.payment_status.replaceAll('_',' ') }}</div><small v-if="item.lighting_reservations?.length">Lights: {{ item.lighting_reservations[0].status.replaceAll('_',' ') }}</small></td><td>
                  <div class="d-flex">
                    <VBtn
                      v-if="!['cancelled','completed'].includes(item.status)"
                      size="small"
                      variant="tonal"
                      prepend-icon="tabler-edit"
                      @click.stop.prevent="openEdit(item)"
                    >
                      Edit
                    </VBtn>
                    <VBtn
                      v-if="item.attendance&&!['checked_in','late_checked_in','cancelled','no_show'].includes(item.attendance.status)"
                      size="small"
                      variant="tonal"
                      :loading="busy"
                      @click="checkIn(item)"
                    >
                      Check in
                    </VBtn><VBtn
                      v-if="!['cancelled','completed'].includes(item.status)"
                      size="small"
                      variant="text"
                      :loading="busy"
                      @click="openCancellation(item)"
                    >
                      Cancel
                    </VBtn>
                  </div>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VWindowItem>
    </VWindow>

    <VDialog
      v-model="editOpen"
      max-width="680"
      :fullscreen="smAndDown"
      persistent
    >
      <VCard v-if="editTarget">
        <VCardTitle class="booking-dialog-title">
          <div><div class="text-h5">Edit {{ editTarget.booking_reference }}</div><div class="text-body-2 text-medium-emphasis">Change the court or time, then save.</div></div>
          <VBtn icon="tabler-x" variant="text" aria-label="Close edit" :disabled="busy" @click="editOpen=false" />
        </VCardTitle><VDivider /><VCardText class="pa-6">
          <VAlert type="info" variant="tonal" class="mb-5">Availability and booking rules are checked again. Changes that alter an existing invoice price require cancellation and a new booking.</VAlert>
          <VRow>
            <VCol cols="12"><VSelect v-model="editForm.facility_id" :items="visibleFacilities" item-title="name" item-value="id" label="Court" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="editForm.date" type="date" label="Date" /></VCol>
            <VCol cols="6" sm="3"><VTextField v-model="editForm.start_time" type="time" label="Start time" /></VCol>
            <VCol cols="6" sm="3"><VSelect v-model="editForm.duration" :items="[30,60,90,120]" label="Minutes" /></VCol>
            <template v-if="editForm.has_lighting">
              <VCol cols="12"><VAlert type="warning" variant="tonal">Lighting is charged separately to every player, including members. Adjusting these times recalculates the billable lighting window.</VAlert></VCol>
              <VCol cols="12" sm="6"><VTextField v-model="editForm.lighting_on_at" type="datetime-local" label="Lights switch on" /></VCol>
              <VCol cols="12" sm="6"><VTextField v-model="editForm.lighting_off_at" type="datetime-local" label="Lights switch off" /></VCol>
              <VCol cols="12"><div class="booking-summary"><div><span>Current lighting charge</span><strong>{{ money(editForm.lighting_amount) }}</strong></div><div><span>Rule</span><strong>Must cover the full booking</strong></div></div></VCol>
            </template>
            <VCol v-if="!editTarget.member_id" cols="12"><VTextField v-model="editForm.booked_for_name" label="Custom booking name" /></VCol>
            <VCol cols="12"><VTextarea v-model="editForm.notes" label="Admin notes (optional)" maxlength="1000" counter /></VCol>
          </VRow>
        </VCardText><VDivider /><VCardActions class="booking-actions pa-5">
          <VBtn variant="text" :disabled="busy" @click="editOpen=false">Close</VBtn><VSpacer /><VBtn color="primary" prepend-icon="tabler-device-floppy" :loading="busy" :disabled="!editForm.facility_id||!editForm.date||!editForm.start_time||(editForm.has_lighting&&(!editForm.lighting_on_at||!editForm.lighting_off_at))||(!editTarget.member_id&&editForm.booked_for_name.trim().length<2)" @click="saveEdit">Save changes</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog
      v-model="cancelOpen"
      max-width="620"
      persistent
    >
      <VCard v-if="cancelPreview">
        <VCardTitle class="pa-6">
          Cancel {{ cancelPreview.booking.booking_reference }}?
        </VCardTitle><VCardText>
          <p class="text-h6">
            {{ cancelPreview.booking.facility }}
          </p><p>{{ new Date(cancelPreview.booking.starts_at).toLocaleString() }} – {{ new Date(cancelPreview.booking.ends_at).toLocaleTimeString() }}</p><VDivider class="my-4" /><VRow>
            <VCol cols="6">
              <strong>Amount paid</strong><div>{{ money(cancelPreview.decision.amount_paid,cancelPreview.decision.currency) }}</div>
            </VCol><VCol cols="6">
              <strong>Policy band</strong><div>{{ cancelPreview.decision.policy_band.replaceAll('_',' ') }}</div>
            </VCol><VCol cols="6">
              <strong>Expected outcome</strong><div>{{ cancelPreview.decision.outcome_type.replaceAll('_',' ') }}</div>
            </VCol><VCol cols="6">
              <strong>Compensation</strong><div>{{ money(cancelPreview.decision.eligible_amount,cancelPreview.decision.currency) }} ({{ cancelPreview.decision.compensation_percentage }}%)</div>
            </VCol>
          </VRow><VAlert
            :type="cancelPreview.decision.requires_finance_review?'warning':'info'"
            class="my-4"
          >
            {{ cancelPreview.decision.explanation }}
          </VAlert><VTextarea
            v-model="cancelReason"
            label="Cancellation reason"
            maxlength="500"
            counter
          />
        </VCardText><VCardActions>
          <VSpacer /><VBtn
            variant="tonal"
            :disabled="busy"
            @click="cancelOpen=false"
          >
            Keep booking
          </VBtn><VBtn
            color="error"
            :loading="busy"
            :disabled="cancelReason.trim().length<3"
            @click="cancel"
          >
            Confirm cancellation
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.booking-dialog-title{display:flex;align-items:flex-start;justify-content:space-between;padding:1.5rem}.booking-steps{display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem}.booking-steps span{display:flex;align-items:center;gap:.5rem;color:rgb(var(--v-theme-on-surface-variant));font-size:.82rem}.booking-steps span::after{flex:1;height:2px;background:rgba(var(--v-border-color),var(--v-border-opacity));content:""}.booking-steps span:last-child::after{display:none}.booking-steps b{display:grid;width:28px;height:28px;place-items:center;border-radius:50%;background:rgba(var(--v-theme-on-surface),.08)}.booking-steps .active{color:rgb(var(--v-theme-primary));font-weight:700}.booking-steps .active b{background:rgb(var(--v-theme-primary));color:#fff}.booking-summary{display:grid;grid-template-columns:repeat(2,1fr);gap:1rem;padding:1rem;border:1px solid rgba(var(--v-border-color),var(--v-border-opacity));border-radius:8px;background:rgba(var(--v-theme-primary),.04)}.booking-summary div{display:flex;flex-direction:column;gap:.2rem}.booking-summary span{color:rgb(var(--v-theme-on-surface-variant));font-size:.75rem}
.date-picker{inline-size:155px}.schedule-toolbar{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:1rem;padding:1.15rem 1.25rem;border-block-end:1px solid rgba(var(--v-border-color),var(--v-border-opacity))}.toolbar-fields{display:grid;grid-template-columns:minmax(190px,1.4fr) 130px 100px 130px;align-items:center;gap:.75rem;min-inline-size:min(100%,650px)}.schedule-legend{display:flex;flex-wrap:wrap;gap:1.2rem;padding:.65rem 1.25rem;color:rgb(var(--v-theme-on-surface-variant));font-size:.78rem}.schedule-legend span{display:flex;align-items:center;gap:.4rem}.schedule-legend i{inline-size:13px;block-size:13px;border:1px solid rgba(var(--v-border-color),.7);border-radius:3px}.schedule-legend .available{background:rgb(var(--v-theme-surface))}.schedule-legend .confirmed{background:#4f7fd8}.schedule-legend .payment{background:#d69a31}.schedule-legend .closed{background:#e8eaed}.schedule-scroll{overflow:auto;max-block-size:68vh;border-block-start:1px solid rgba(var(--v-border-color),var(--v-border-opacity))}.schedule-grid{display:grid;grid-template-columns:72px repeat(var(--court-count),minmax(170px,1fr));min-inline-size:max-content}.time-corner,.court-head{position:sticky;z-index:8;inset-block-start:0;display:flex;min-block-size:62px;align-items:center;justify-content:center;border-block-end:1px solid rgba(var(--v-border-color),var(--v-border-opacity));background:rgb(var(--v-theme-surface))}.time-corner{z-index:10;inset-inline-start:0;color:rgb(var(--v-theme-on-surface-variant));font-size:.72rem;text-transform:uppercase}.court-head{flex-direction:column;border-inline-start:1px solid rgba(var(--v-border-color),var(--v-border-opacity))}.court-head small{color:rgb(var(--v-theme-on-surface-variant));font-weight:400}.time-rail{position:sticky;z-index:5;inset-inline-start:0;background:rgb(var(--v-theme-surface))}.time-rail span{position:absolute;inset-inline:0;padding-inline:.65rem;color:rgb(var(--v-theme-on-surface-variant));font-size:.72rem;transform:translateY(-.5em)}.court-lane{position:relative;border-inline-start:1px solid rgba(var(--v-border-color),var(--v-border-opacity));background:repeating-linear-gradient(to bottom,transparent 0,transparent 35px,rgba(var(--v-border-color),.35) 35px,rgba(var(--v-border-color),.35) 36px)}.slot-button{position:absolute;z-index:1;inset-inline:0;border:0;background:transparent;color:#fff;cursor:pointer}.slot-button:hover:not(:disabled){background:rgba(var(--v-theme-primary),.1);box-shadow:inset 0 0 0 2px rgba(var(--v-theme-primary),.45)}.slot-button.unavailable{background:repeating-linear-gradient(135deg,rgba(var(--v-theme-on-surface),.045),rgba(var(--v-theme-on-surface),.045) 5px,rgba(var(--v-theme-on-surface),.075) 5px,rgba(var(--v-theme-on-surface),.075) 10px);cursor:not-allowed}.slot-button.selected{z-index:3;background:rgb(var(--v-theme-primary));box-shadow:inset 0 0 0 2px rgb(var(--v-theme-primary-darken-1));font-weight:700}.booking-block{position:absolute;z-index:4;overflow:hidden;inset-inline:5px;width:calc(100% - 10px);min-block-size:30px;padding:.35rem .5rem;border:0;border-inline-start:4px solid rgba(0,0,0,.22);border-radius:6px;color:#fff;cursor:pointer;text-align:start;box-shadow:0 2px 5px rgba(0,0,0,.16)}.booking-block.confirmed{background:#4f7fd8}.booking-block.payment{background:#d69a31}.booking-block.pending{background:#7a6fb0}.booking-block strong,.booking-block span,.booking-block small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.booking-block span{font-size:.73rem}.booking-block small{opacity:.9;font-size:.66rem}.selection-card{border-inline-start:4px solid rgb(var(--v-theme-primary))}@media(max-width:960px){.toolbar-fields{grid-template-columns:1fr 1fr;min-inline-size:100%}.schedule-scroll{max-block-size:62vh}}@media(max-width:600px){.toolbar-fields{grid-template-columns:1fr}.schedule-toolbar{align-items:stretch}.date-picker{inline-size:140px}.court-head{min-inline-size:150px}.schedule-grid{grid-template-columns:62px repeat(var(--court-count),150px)}}
.schedule-tip{display:flex;align-items:center;gap:.4rem;margin-inline-start:auto;color:rgb(var(--v-theme-primary));font-size:.78rem}.schedule-scroll{border:1px solid rgba(var(--v-border-color),.7);border-radius:0 0 8px 8px;background:#fff}.schedule-grid{grid-template-columns:82px repeat(var(--court-count),minmax(185px,1fr))}.time-corner,.court-head{min-block-size:70px;border-block-end:2px solid rgba(var(--v-theme-primary),.25);box-shadow:0 3px 8px rgba(30,35,55,.08)}.court-head strong{color:rgb(var(--v-theme-on-surface));font-size:.92rem}.court-head small{max-inline-size:165px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.time-corner{color:rgb(var(--v-theme-on-surface));font-weight:800;letter-spacing:.04em}.time-rail{border-inline-end:2px solid rgba(var(--v-border-color),.8);box-shadow:3px 0 8px rgba(30,35,55,.06)}.time-rail span{padding-inline:.75rem;color:rgba(var(--v-theme-on-surface),.72);font-size:.76rem;font-weight:600}.time-rail span.hour{color:rgb(var(--v-theme-on-surface));font-size:.8rem;font-weight:800}.court-lane{background:repeating-linear-gradient(to bottom,#fff 0,#fff 44px,rgba(66,72,92,.20) 44px,rgba(66,72,92,.20) 45px)}.slot-button{border-block-start:1px solid rgba(66,72,92,.16);transition:background-color .12s ease,box-shadow .12s ease}.slot-button.hour{border-block-start:2px solid rgba(66,72,92,.38)}.slot-button:hover:not(:disabled){z-index:3;background:rgba(var(--v-theme-primary),.14);box-shadow:inset 0 0 0 2px rgb(var(--v-theme-primary)),0 3px 10px rgba(var(--v-theme-primary),.18)}.slot-hint{display:inline-flex;align-items:center;justify-content:center;padding:.2rem .45rem;border-radius:4px;background:rgb(var(--v-theme-primary));color:#fff;font-size:.72rem;font-weight:800;opacity:0;transform:translateY(2px);transition:opacity .12s ease,transform .12s ease}.slot-button:hover .slot-hint,.slot-button:focus-visible .slot-hint{opacity:1;transform:translateY(0)}.slot-button.unavailable{border-block-start-color:rgba(66,72,92,.1);background:repeating-linear-gradient(135deg,rgba(84,89,105,.07),rgba(84,89,105,.07) 6px,rgba(84,89,105,.13) 6px,rgba(84,89,105,.13) 12px)}.slot-button.selected{z-index:4;background:rgb(var(--v-theme-primary));box-shadow:inset 0 0 0 3px rgba(255,255,255,.55),0 4px 12px rgba(var(--v-theme-primary),.35)}.booking-block{inset-inline:7px;width:calc(100% - 14px);padding:.5rem .6rem;border-radius:7px;box-shadow:0 4px 10px rgba(30,35,55,.28)}@media(max-width:600px){.schedule-tip{inline-size:100%;margin-inline-start:0}.schedule-grid{grid-template-columns:70px repeat(var(--court-count),165px)}.time-rail span{padding-inline:.45rem}}
.booking-block .booking-member{margin-block:.1rem;font-size:.78rem;font-weight:800}
.booking-page{color:#29283a}.booking-page :deep(.text-medium-emphasis){color:#5a5868!important;opacity:1!important}.schedule-toolbar{background:#fff}.schedule-toolbar :deep(.v-label),.schedule-toolbar :deep(.v-field__input),.schedule-toolbar :deep(.v-select__selection-text){color:#373545!important;opacity:1!important}.schedule-toolbar :deep(.v-field__outline){--v-field-border-opacity:.55}.schedule-legend{border-block:1px solid #d1d3dc;background:#f7f8fb;color:#343242;font-weight:700}.schedule-legend span{opacity:1}.schedule-legend i{border:2px solid #656878}.schedule-legend .available{background:#fff}.schedule-legend .closed{background:repeating-linear-gradient(135deg,#d5d7de,#d5d7de 4px,#eef0f4 4px,#eef0f4 8px)}.court-head{background:#fff}.court-head strong{color:#272638}.court-head small{color:#595766!important;font-size:.7rem;font-weight:600;opacity:1}.time-rail{background:#fafbfc}.time-rail span{color:#53515f!important;opacity:1}.time-rail span.hour{color:#242334!important}.court-lane{border-inline-start:1px solid #c8cad2;background:repeating-linear-gradient(to bottom,#fff 0,#fff 44px,#cfd1d8 44px,#cfd1d8 45px)}.slot-button{border-block-start-color:#d6d8df}.slot-button.hour{border-block-start-color:#8d909d}.slot-button.unavailable{opacity:1;background:repeating-linear-gradient(135deg,#f1f2f5,#f1f2f5 6px,#dfe1e7 6px,#dfe1e7 12px)}.schedule-tip{color:#242044}.booking-block small{opacity:1}.booking-block.payment{background:#b97800}.booking-block.confirmed{background:#3565bd}
.mobile-court-picker{display:grid;gap:.75rem;padding:1rem;border-block-end:1px solid #d1d3dc;background:#fff}.mobile-booking-hint{display:flex;align-items:center;gap:.5rem;padding:.65rem .8rem;border-radius:7px;background:#eeefff;color:#242044;font-size:.86rem;font-weight:800}.booking-actions{z-index:5;background:#fff}
@media(max-width:600px){.booking-page{margin-inline:-.5rem}.booking-header{align-items:stretch!important;margin-block-end:1rem!important}.booking-header>div:first-child{inline-size:100%}.booking-header .text-h3{font-size:1.45rem!important;line-height:1.8rem}.date-navigation{display:grid!important;grid-template-columns:44px minmax(0,1fr) 44px 58px;inline-size:100%;gap:.4rem!important}.date-navigation .date-picker{inline-size:100%}.date-navigation :deep(.v-btn){min-inline-size:44px;min-block-size:44px}.booking-page :deep(.v-tabs){inline-size:100%}.booking-page :deep(.v-tab){flex:1}.calendar-card{border-radius:8px}.schedule-toolbar{padding:.9rem 1rem}.schedule-toolbar .text-h5{font-size:1rem!important}.mobile-court-picker :deep(.v-field){min-block-size:52px}.schedule-legend{gap:.55rem 1rem;padding:.65rem .8rem;font-size:.72rem}.schedule-tip{display:none}.schedule-scroll{max-block-size:66vh;border-radius:0;overscroll-behavior:contain}.schedule-grid{grid-template-columns:70px minmax(0,1fr)!important;min-inline-size:0;inline-size:100%}.court-head{min-inline-size:0!important}.court-head small{display:none}.time-corner,.court-head{min-block-size:56px}.time-rail span{padding-inline:.55rem;font-size:.73rem}.slot-hint{position:absolute;inset-inline-end:.55rem;opacity:1;transform:none;background:transparent;color:#373545;font-size:.7rem}.slot-button:hover:not(:disabled){background:rgba(var(--v-theme-primary),.08);box-shadow:inset 0 0 0 2px rgb(var(--v-theme-primary))}.slot-button:active:not(:disabled){background:rgba(var(--v-theme-primary),.18)}.booking-block{inset-inline:5px;width:calc(100% - 10px)}.booking-dialog-title{position:sticky;z-index:6;inset-block-start:0;padding:1rem;background:#fff}.booking-steps{margin-block-end:1rem!important}.booking-steps span{font-size:.7rem}.booking-steps span::after{display:none}.booking-actions{position:sticky;inset-block-end:0;display:grid!important;grid-template-columns:1fr;padding:1rem!important;box-shadow:0 -4px 14px rgba(30,35,55,.12)}.booking-actions .v-spacer{display:none}.booking-actions :deep(.v-btn){inline-size:100%;min-block-size:48px}.booking-actions :deep(.v-btn:first-child){order:2}.booking-summary{grid-template-columns:1fr 1fr}.bookings-table{overflow-x:auto}}
</style>
