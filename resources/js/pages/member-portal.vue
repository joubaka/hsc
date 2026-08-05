<script setup>
import { $memberApi } from '@/utils/memberApi'
import { useDisplay } from 'vuetify'

definePage({ meta: { layout: 'blank', public: true, memberOnly: true } })
const { smAndDown } = useDisplay()
const loading = ref(true)
const tab = ref('home')
const data = ref({ member: {}, memberships: [], upcoming_bookings: [], recent_invoices: [], outstanding_balance: 0, tournaments: { entries: [], matches: [], orders_of_play: [], announcements: [] } })
const finance = ref({ invoices: [], payments: [] })
const bookings = ref([])
const facilities = ref([])
const messages = ref([])
const error = ref('')
const notice = ref('')
const profile = reactive({ preferred_name: '', mobile: '', emergency_contact_name: '', emergency_contact_mobile: '', email_consent: true, whatsapp_consent: false })
const booking = reactive({ facility_id: null, starts_at: '', ends_at: '', guest_count: 0, lighting_requested: false })
const quote = ref(null)
const bookingBusy = ref(false)
const profileBusy = ref(false)
const selectedMessage = ref(null)
const messageOpen = ref(false)
const scheduleLoading = ref(false)
const calendarBookings = ref([])
const selectedDate = ref(new Date(Date.now() + 86400000).toISOString().slice(0, 10))
const bookingDialog = ref(false)
const mobileFacilityId = ref(null)
const duration = ref(60)
const dayStart = 6 * 60
const dayEnd = 22 * 60
const pixelsPerMinute = 1.35
const laneHeight = (dayEnd - dayStart) * pixelsPerMinute
const timeSlots = Array.from({ length: (dayEnd - dayStart) / 30 }, (_, index) => dayStart + index * 30)

const money = (value, currency = 'ZAR') => new Intl.NumberFormat('en-ZA', { style: 'currency', currency }).format(Number(value || 0) / 100)
const dateTime = value => value ? new Intl.DateTimeFormat('en-ZA', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—'
const activeBookings = computed(() => bookings.value.filter(item => ['confirmed', 'pending_payment'].includes(item.status) && new Date(item.ends_at) >= new Date()))
const unreadMessages = computed(() => messages.value.filter(item => !item.read_at).length)
const tournamentMatchHeaders = [{ title: 'Tournament / event', key: 'event' }, { title: 'Round', key: 'round_name' }, { title: 'Opponent', key: 'opponent' }, { title: 'When', key: 'scheduled_at' }, { title: 'Court', key: 'facility.name' }, { title: 'Status', key: 'status' }, { title: 'Score', key: 'final_score' }]
const tournamentMatches = computed(() => (data.value.tournaments?.matches || []).map(item => {
  const memberId = data.value.member.id
  const mineIsEntry1 = item.entry1?.members?.some(member => member.id === memberId)
  const opponent = (mineIsEntry1 ? item.entry2 : item.entry1)?.members?.map(member => member.display_name).join(' / ') || 'To be decided'
  return { ...item, event: `${item.draw?.championship_event?.championship?.name || ''} · ${item.draw?.championship_event?.name || ''}`, opponent }
}))
const displayedFacilities = computed(() => {
  if (!smAndDown.value) return facilities.value
  const selected = facilities.value.find(item => item.id === mobileFacilityId.value)
  return selected ? [selected] : facilities.value.slice(0, 1)
})
const dayOfWeek = computed(() => new Date(`${selectedDate.value}T12:00:00+02:00`).getDay())
const minutesToTime = minutes => `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`
const timeToMinutes = value => { const [hour, minute] = String(value).slice(0, 5).split(':').map(Number); return hour * 60 + minute }
const localMinutes = value => { const parts = new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Johannesburg', hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(new Date(value)); return Number(parts.find(p => p.type === 'hour').value) * 60 + Number(parts.find(p => p.type === 'minute').value) }
const formatDay = value => new Intl.DateTimeFormat('en-ZA', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Africa/Johannesburg' }).format(new Date(`${value}T12:00:00+02:00`))
const openingWindow = facility => facility.opening_hours?.find(row => row.active && Number(row.day_of_week) === dayOfWeek.value && (!row.effective_from || row.effective_from <= selectedDate.value) && (!row.effective_until || row.effective_until >= selectedDate.value))
const bookingAt = (facilityId, start, end) => calendarBookings.value.find(item => item.facility_id === facilityId && localMinutes(item.starts_at) < end && localMinutes(item.ends_at) > start)
const slotUnavailable = (facility, start) => { const hours = openingWindow(facility); const end = start + Number(duration.value); return !hours || start < timeToMinutes(hours.opens_at) || end > timeToMinutes(hours.closes_at) || Boolean(bookingAt(facility.id, start, end)) }
const bookingStyle = item => ({ top: `${(localMinutes(item.starts_at) - dayStart) * pixelsPerMinute}px`, height: `${Math.max(30, (localMinutes(item.ends_at) - localMinutes(item.starts_at)) * pixelsPerMinute)}px` })
const bookingPayload = () => ({ ...booking, starts_at: new Date(`${booking.starts_at}:00+02:00`).toISOString(), ends_at: new Date(`${booking.ends_at}:00+02:00`).toISOString() })

async function load() {
  loading.value = true
  try {
    const [dashboard, bookingResponse, financeResponse, facilityResponse, messageResponse] = await Promise.all([$memberApi('/dashboard'), $memberApi('/bookings'), $memberApi('/finance'), $memberApi('/facilities'), $memberApi('/messages')])
    data.value = dashboard; bookings.value = bookingResponse.data; finance.value = financeResponse; facilities.value = facilityResponse; messages.value = messageResponse.data; mobileFacilityId.value ||= facilityResponse[0]?.id || null
    Object.assign(profile, Object.fromEntries(Object.keys(profile).map(key => [key, dashboard.member[key] ?? profile[key]])))
    localStorage.setItem('hsc_member', JSON.stringify(dashboard.member))
  } catch (exception) { error.value = exception?.data?.message || 'Your portal could not be loaded.' }
  finally { loading.value = false }
}
async function loadSchedule() { scheduleLoading.value = true; try { calendarBookings.value = await $memberApi(`/schedule?date=${selectedDate.value}`) } catch (exception) { error.value = exception?.data?.message || 'The court schedule could not be loaded.' } finally { scheduleLoading.value = false } }
function moveDay(days) { const date = new Date(`${selectedDate.value}T12:00:00+02:00`); date.setDate(date.getDate() + days); selectedDate.value = date.toISOString().slice(0, 10) }
function selectSlot(facility, start) { if (slotUnavailable(facility, start)) return; const localStart = `${selectedDate.value}T${minutesToTime(start)}`; booking.facility_id = facility.id; booking.starts_at = localStart; booking.ends_at = `${selectedDate.value}T${minutesToTime(start + Number(duration.value))}`; quote.value = null; bookingDialog.value = true }
async function saveProfile() {
  profileBusy.value = true; error.value = ''; notice.value = ''
  try { data.value.member = await $memberApi('/profile', { method: 'PUT', body: profile }); notice.value = 'Your profile has been updated.' }
  catch (exception) { error.value = exception?.data?.message || 'Profile could not be saved.' }
  finally { profileBusy.value = false }
}
async function simulate() {
  bookingBusy.value = true; error.value = ''; quote.value = null
  try { quote.value = await $memberApi('/bookings/simulate', { method: 'POST', body: bookingPayload() }) }
  catch (exception) { error.value = exception?.data?.message || 'This booking could not be quoted.' }
  finally { bookingBusy.value = false }
}
async function createBooking() {
  bookingBusy.value = true; error.value = ''; notice.value = ''
  try {
    const hold = await $memberApi('/bookings/holds', { method: 'POST', body: { ...bookingPayload(), idempotency_key: crypto.randomUUID() } })
    await $memberApi(`/bookings/holds/${hold.id}/confirm`, { method: 'POST', body: { confirmation_key: crypto.randomUUID(), participants: [] } })
    notice.value = 'Your booking has been created.'; quote.value = null; bookingDialog.value = false; Object.assign(booking, { facility_id: null, starts_at: '', ends_at: '', guest_count: 0, lighting_requested: false }); await load(); await loadSchedule()
  } catch (exception) { error.value = exception?.data?.message || 'The booking could not be completed.' }
  finally { bookingBusy.value = false }
}
async function cancelBooking(item) {
  if (!confirm(`Cancel booking ${item.booking_reference}?`)) return
  try { await $memberApi(`/bookings/${item.id}/cancel`, { method: 'POST', body: { reason: 'Cancelled by member through portal', idempotency_key: crypto.randomUUID() } }); notice.value = 'Booking cancelled.'; await load() }
  catch (exception) { error.value = exception?.data?.message || 'The booking could not be cancelled.' }
}
async function logout() { try { await $memberApi('/logout', { method: 'POST' }) } finally { localStorage.removeItem('hsc_member_token'); localStorage.removeItem('hsc_member'); location.href = `${import.meta.env.VITE_ROUTER_BASE || '/'}member-login` } }
async function openMessage(item) { selectedMessage.value = item; messageOpen.value = true; if (!item.read_at) { const updated = await $memberApi(`/messages/${item.id}/read`, { method: 'POST' }); Object.assign(item, updated) } }
watch(selectedDate, loadSchedule)
watch(tab, value => { if (value === 'book') loadSchedule() })
onMounted(() => { if (!localStorage.getItem('hsc_member_token')) location.href = `${import.meta.env.VITE_ROUTER_BASE || '/'}member-login`; else load().then(loadSchedule) })
</script>

<template>
  <div class="portal-shell">
    <header class="portal-header px-5 py-4"><div><div class="text-caption opacity-70">HERMANUS SPORTS CLUB</div><div class="text-h5 font-weight-bold">Member portal</div></div><div class="d-flex align-center gap-3"><span class="d-none d-sm-inline">{{ data.member.display_name }}</span><VBtn icon="tabler-logout" variant="text" color="white" aria-label="Log out" @click="logout" /></div></header>
    <VProgressLinear v-if="loading" indeterminate />
    <main class="portal-main pa-4 pa-md-7">
      <VAlert v-if="error" type="error" closable class="mb-5" @click:close="error=''">{{ error }}</VAlert><VAlert v-if="notice" type="success" closable class="mb-5" @click:close="notice=''">{{ notice }}</VAlert>
      <VTabs v-model="tab" class="mb-6" show-arrows><VTab value="home">Overview</VTab><VTab value="tournaments">My tournaments</VTab><VTab value="book">Book a court</VTab><VTab value="bookings">My bookings</VTab><VTab value="finance">Accounts</VTab><VTab value="messages">Messages <VBadge v-if="unreadMessages" :content="unreadMessages" color="error" inline /></VTab><VTab value="profile">Profile</VTab></VTabs>
      <VWindow v-model="tab">
        <VWindowItem value="home"><h1 class="text-h3 mb-1">Welcome, {{ data.member.preferred_name || data.member.first_name }}</h1><p class="text-medium-emphasis mb-6">Member {{ data.member.member_number }}</p><VRow><VCol cols="12" md="4"><VCard color="primary"><VCardText><div class="text-overline">Outstanding balance</div><div class="text-h3">{{ money(data.outstanding_balance) }}</div></VCardText></VCard></VCol><VCol cols="12" md="4"><VCard><VCardText><div class="text-overline">Active memberships</div><div class="text-h3">{{ data.memberships.filter(m => m.status === 'active').length }}</div></VCardText></VCard></VCol><VCol cols="12" md="4"><VCard><VCardText><div class="text-overline">Upcoming bookings</div><div class="text-h3">{{ data.upcoming_bookings.length }}</div></VCardText></VCard></VCol></VRow><VRow class="mt-2"><VCol cols="12" md="7"><VCard><VCardTitle>Next bookings</VCardTitle><VList><VListItem v-for="item in data.upcoming_bookings" :key="item.id" :title="item.facility?.name" :subtitle="dateTime(item.starts_at)"><template #append><VChip size="small" variant="tonal">{{ item.status.replaceAll('_',' ') }}</VChip></template></VListItem><VListItem v-if="!data.upcoming_bookings.length" title="No upcoming bookings" /></VList></VCard></VCol><VCol cols="12" md="5"><VCard><VCardTitle>Memberships</VCardTitle><VList><VListItem v-for="item in data.memberships" :key="item.id" :title="item.sport_code?.name" :subtitle="item.category?.name"><template #append><VChip size="small" color="success" variant="tonal">{{ item.status }}</VChip></template></VListItem></VList></VCard></VCol></VRow></VWindowItem>
        <VWindowItem value="tournaments"><VRow><VCol cols="12"><VAlert v-for="item in data.tournaments?.announcements" :key="item.id" :type="item.priority === 'urgent' ? 'error' : item.priority === 'important' ? 'warning' : 'info'" variant="tonal" class="mb-3" :title="item.title">{{ item.message }}</VAlert><VCard><VCardTitle>My matches</VCardTitle><VDataTable :items="tournamentMatches" :headers="tournamentMatchHeaders"><template #item.scheduled_at="{item}">{{ item.scheduled_at ? dateTime(item.scheduled_at) : 'To be scheduled' }}</template><template #item.status="{item}"><VChip size="small" variant="tonal">{{ item.status.replaceAll('_',' ') }}</VChip></template></VDataTable></VCard></VCol><VCol cols="12" lg="5"><VCard><VCardTitle>My entries</VCardTitle><VList><VListItem v-for="item in data.tournaments?.entries" :key="item.id" :title="item.event?.name" :subtitle="item.event?.championship?.name"><template #append><VChip size="small" variant="tonal">{{ item.status }}</VChip></template></VListItem><VListItem v-if="!data.tournaments?.entries?.length" title="No championship entries" /></VList></VCard></VCol><VCol cols="12" lg="7"><VCard><VCardTitle>Published order of play</VCardTitle><VExpansionPanels variant="accordion"><VExpansionPanel v-for="publication in data.tournaments?.orders_of_play" :key="publication.id" :title="`${publication.play_date} · version ${publication.version}`"><VExpansionPanelText><VList density="compact"><VListItem v-for="match in publication.snapshot" :key="match.match_id" :title="`${match.time} · ${match.facility} · ${match.event}`" :subtitle="`${match.round}: ${match.competitors.join(' vs ')}`" /></VList></VExpansionPanelText></VExpansionPanel></VExpansionPanels><VCardText v-if="!data.tournaments?.orders_of_play?.length" class="text-medium-emphasis">No order of play has been published yet.</VCardText></VCard></VCol></VRow></VWindowItem>
        <VWindowItem value="book">
          <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
            <div><h2 class="text-h4">Court calendar</h2><p class="text-medium-emphasis mb-0">Choose an available time directly from the live schedule.</p></div>
            <div class="d-flex align-center gap-2"><VBtn icon="tabler-chevron-left" variant="tonal" aria-label="Previous day" @click="moveDay(-1)" /><VTextField v-model="selectedDate" type="date" label="Booking date" hide-details density="compact" class="member-date" /><VBtn icon="tabler-chevron-right" variant="tonal" aria-label="Next day" @click="moveDay(1)" /><VBtn variant="text" @click="selectedDate=new Date().toISOString().slice(0,10)">Today</VBtn></div>
          </div>
          <VCard>
            <VCardText class="calendar-tools"><div><div class="text-h5">{{ formatDay(selectedDate) }}</div><div class="text-caption">Times shown in South Africa time · Court hire is free for members</div></div><VSelect v-model="duration" :items="[30,60,90,120]" label="Duration" suffix="min" density="compact" hide-details /><VTextField v-model.number="booking.guest_count" type="number" min="0" max="20" label="Guests" density="compact" hide-details /><VSwitch v-model="booking.lighting_requested" label="Lighting" density="compact" hide-details /></VCardText>
            <div v-if="smAndDown" class="mobile-court-picker"><VSelect v-model="mobileFacilityId" :items="facilities" item-title="name" item-value="id" label="Choose a court" hide-details /></div>
            <div class="calendar-legend"><span><i class="free" />Available</span><span><i class="confirmed" />Confirmed</span><span><i class="payment" />Payment pending</span><span><i class="closed" />Closed / unavailable</span><strong>Hover to see a time, then click to book</strong></div>
            <VProgressLinear v-if="scheduleLoading" indeterminate />
            <div v-if="displayedFacilities.length" class="member-schedule-scroll">
              <div class="member-schedule" :style="{'--court-count':displayedFacilities.length}">
                <div class="time-corner">Time</div><div v-for="facility in displayedFacilities" :key="`head-${facility.id}`" class="court-head"><strong>{{ facility.name }}</strong><small>{{ facility.venue?.name }} · {{ facility.indoor ? 'Indoor' : 'Outdoor' }}</small></div>
                <div class="time-rail" :style="{height:`${laneHeight}px`}"><span v-for="time in timeSlots" :key="time" :class="{hour:time%60===0}" :style="{top:`${(time-dayStart)*pixelsPerMinute}px`}">{{ minutesToTime(time) }}</span></div>
                <div v-for="facility in displayedFacilities" :key="facility.id" class="court-lane" :style="{height:`${laneHeight}px`}">
                  <button v-for="time in timeSlots" :key="time" type="button" class="slot-button" :class="{unavailable:slotUnavailable(facility,time),hour:time%60===0}" :style="{top:`${(time-dayStart)*pixelsPerMinute}px`,height:`${30*pixelsPerMinute}px`}" :disabled="slotUnavailable(facility,time)" @click="selectSlot(facility,time)"><span v-if="!slotUnavailable(facility,time)" class="slot-hint">{{ minutesToTime(time) }}</span></button>
                  <div v-for="item in calendarBookings.filter(row=>row.facility_id===facility.id)" :key="`${item.id}-${item.starts_at}`" class="booking-block" :class="item.status==='pending_payment'?'payment':'confirmed'" :style="bookingStyle(item)"><strong>{{ minutesToTime(localMinutes(item.starts_at)) }}–{{ minutesToTime(localMinutes(item.ends_at)) }}</strong><span>{{ item.label }}</span><small>{{ item.is_mine ? item.status.replaceAll('_',' ') : 'unavailable' }}</small></div>
                </div>
              </div>
            </div><VAlert v-else type="info" variant="tonal" class="ma-5">No bookable facilities are available for your active memberships.</VAlert>
          </VCard>
          <VDialog v-model="bookingDialog" max-width="650" persistent><VCard><VCardTitle>Confirm your booking</VCardTitle><VCardText><VAlert type="info" variant="tonal" class="mb-5"><strong>{{ facilities.find(f=>f.id===booking.facility_id)?.name }}</strong><br>{{ dateTime(booking.starts_at) }} – {{ new Date(booking.ends_at).toLocaleTimeString('en-ZA',{hour:'2-digit',minute:'2-digit'}) }}<br><strong>Court hire: Free for members</strong></VAlert><VRow><VCol cols="12" sm="6"><VTextField v-model.number="booking.guest_count" type="number" min="0" max="20" label="Number of guests" /></VCol><VCol cols="12" sm="6"><VSwitch v-model="booking.lighting_requested" label="Court lighting required" /></VCol></VRow><VAlert v-if="quote" class="mt-3" :type="quote.eligible?'success':'warning'" variant="tonal"><template v-if="quote.eligible"><strong>Available · {{ quote.price?.total_amount ? `${money(quote.price.total_amount,quote.price.currency)} extras` : 'Free' }}</strong><div v-if="quote.price?.total_amount">The court is free; this amount is for selected guest or lighting extras.</div><div v-else>No payment or invoice is required.</div></template><template v-else>{{ quote.failures?.[0]?.member_message || 'This time is unavailable.' }}</template></VAlert></VCardText><VCardActions><VSpacer /><VBtn variant="text" @click="bookingDialog=false;quote=null">Close</VBtn><VBtn v-if="!quote" :loading="bookingBusy" @click="simulate">Check booking</VBtn><VBtn v-else-if="quote.eligible" :loading="bookingBusy" @click="createBooking">Confirm booking</VBtn></VCardActions></VCard></VDialog>
        </VWindowItem>
        <VWindowItem value="bookings"><VCard><VCardTitle>My bookings</VCardTitle><VDataTable :items="bookings" :headers="[{title:'Reference',key:'booking_reference'},{title:'Facility',key:'facility.name'},{title:'Starts',key:'starts_at'},{title:'Status',key:'status'},{title:'Amount',key:'total_amount'},{title:'',key:'actions'}]"><template #item.starts_at="{item}">{{ dateTime(item.starts_at) }}</template><template #item.status="{item}"><VChip size="small" variant="tonal">{{ item.status.replaceAll('_',' ') }}</VChip></template><template #item.total_amount="{item}">{{ money(item.total_amount,item.currency) }}</template><template #item.actions="{item}"><VBtn v-if="activeBookings.some(b=>b.id===item.id)" size="small" color="error" variant="text" @click="cancelBooking(item)">Cancel</VBtn></template></VDataTable></VCard></VWindowItem>
        <VWindowItem value="finance"><VRow><VCol cols="12" lg="8"><VCard><VCardTitle>Invoices</VCardTitle><VDataTable :items="finance.invoices" :headers="[{title:'Invoice',key:'invoice_number'},{title:'Issued',key:'issued_on'},{title:'Status',key:'status'},{title:'Total',key:'total_amount'},{title:'Outstanding',key:'outstanding_amount'}]"><template #item.total_amount="{item}">{{ money(item.total_amount,item.currency) }}</template><template #item.outstanding_amount="{item}"><strong>{{ money(item.outstanding_amount,item.currency) }}</strong></template></VDataTable></VCard></VCol><VCol cols="12" lg="4"><VCard><VCardTitle>Payments</VCardTitle><VList><VListItem v-for="item in finance.payments" :key="item.id" :title="money(item.amount,item.currency)" :subtitle="`${item.received_on} · ${item.payment_method}`"><template #append><VChip size="small" variant="tonal">{{ item.status }}</VChip></template></VListItem><VListItem v-if="!finance.payments.length" title="No payments recorded" /></VList></VCard></VCol></VRow></VWindowItem>
        <VWindowItem value="messages"><VCard><VCardTitle>Messages</VCardTitle><VList lines="three"><VListItem v-for="item in messages" :key="item.id" :title="item.subject" :subtitle="new Date(item.created_at).toLocaleString('en-ZA')" :class="{'message-unread':!item.read_at}" prepend-icon="tabler-mail" @click="openMessage(item)"><template #append><VChip v-if="!item.read_at" size="small" color="primary">New</VChip></template></VListItem><VListItem v-if="!messages.length" title="No messages yet" subtitle="Booking confirmations, reminders and club announcements will appear here." /></VList></VCard><VDialog v-model="messageOpen" max-width="700"><VCard v-if="selectedMessage"><VCardTitle class="pa-6">{{ selectedMessage.subject }}</VCardTitle><VDivider/><VCardText class="pa-6 message-body" v-html="selectedMessage.body_html"/><VCardActions><VSpacer/><VBtn @click="messageOpen=false">Close</VBtn></VCardActions></VCard></VDialog></VWindowItem>
        <VWindowItem value="profile"><VCard max-width="800"><VCardTitle>My profile</VCardTitle><VCardText><VAlert type="info" variant="tonal" class="mb-5">Contact the club office to change your legal name, email address or date of birth.</VAlert><VRow><VCol cols="12" sm="6"><VTextField v-model="profile.preferred_name" label="Preferred name" /></VCol><VCol cols="12" sm="6"><VTextField v-model="profile.mobile" label="Mobile number" /></VCol><VCol cols="12" sm="6"><VTextField v-model="profile.emergency_contact_name" label="Emergency contact" /></VCol><VCol cols="12" sm="6"><VTextField v-model="profile.emergency_contact_mobile" label="Emergency contact mobile" /></VCol><VCol cols="12" sm="6"><VSwitch v-model="profile.email_consent" label="Email communications" /></VCol><VCol cols="12" sm="6"><VSwitch v-model="profile.whatsapp_consent" label="WhatsApp communications" /></VCol></VRow><VBtn :loading="profileBusy" @click="saveProfile">Save profile</VBtn></VCardText></VCard></VWindowItem>
      </VWindow>
    </main>
  </div>
</template>

<style scoped>
.portal-shell{min-height:100vh;background:#f4f6f8}.portal-header{background:linear-gradient(120deg,#075e54,#0b7b6d);color:white;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:20}.portal-main{max-width:1400px;margin:auto}.member-date{inline-size:160px}.calendar-tools{display:grid;grid-template-columns:1fr 140px 110px 130px;align-items:center;gap:1rem}.mobile-court-picker{padding:1rem;border-block-start:1px solid #d6d9df}.calendar-legend{display:flex;flex-wrap:wrap;gap:1.2rem;padding:.75rem 1.25rem;border-block:1px solid #d6d9df;background:#f8f9fb;font-size:.8rem}.calendar-legend span{display:flex;align-items:center;gap:.4rem}.calendar-legend strong{margin-inline-start:auto;color:#242044}.calendar-legend i{width:14px;height:14px;border:1px solid #777;border-radius:3px}.calendar-legend .free{background:#fff}.calendar-legend .confirmed{background:#3565bd}.calendar-legend .payment{background:#b97800}.calendar-legend .closed{background:repeating-linear-gradient(135deg,#d5d7de,#d5d7de 4px,#eef0f4 4px,#eef0f4 8px)}.member-schedule-scroll{overflow:auto;max-block-size:68vh;border:1px solid #aeb3bd}.member-schedule{display:grid;grid-template-columns:82px repeat(var(--court-count),minmax(185px,1fr));min-inline-size:max-content}.time-corner,.court-head{position:sticky;z-index:9;inset-block-start:0;display:flex;min-block-size:70px;align-items:center;justify-content:center;border-block-end:2px solid #b8c4c1;background:#fff}.time-corner{z-index:11;inset-inline-start:0;font-size:.75rem;font-weight:800;text-transform:uppercase}.court-head{flex-direction:column;border-inline-start:1px solid #d5d8de}.court-head small{color:#666;font-size:.7rem}.time-rail{position:sticky;z-index:6;inset-inline-start:0;border-inline-end:2px solid #ccd1d5;background:#fafbfc}.time-rail span{position:absolute;inset-inline:0;padding-inline:.65rem;color:#555;font-size:.73rem;transform:translateY(-.5em)}.time-rail span.hour{color:#222;font-weight:800}.court-lane{position:relative;border-inline-start:1px solid #ccd1d5;background:repeating-linear-gradient(to bottom,#fff 0,#fff 39.5px,#d4d7dc 39.5px,#d4d7dc 40.5px)}.slot-button{position:absolute;z-index:1;inset-inline:0;border:0;border-block-start:1px solid rgba(70,75,85,.15);background:transparent;cursor:pointer}.slot-button.hour{border-block-start:2px solid rgba(60,65,75,.38)}.slot-button:hover:not(:disabled){z-index:3;background:rgba(53,101,189,.14);box-shadow:inset 0 0 0 2px #3565bd}.slot-button.unavailable{background:repeating-linear-gradient(135deg,rgba(90,95,105,.06),rgba(90,95,105,.06) 6px,rgba(90,95,105,.12) 6px,rgba(90,95,105,.12) 12px);cursor:not-allowed}.slot-hint{padding:.15rem .4rem;border-radius:4px;background:#3565bd;color:#fff;font-size:.7rem;font-weight:700;opacity:0}.slot-button:hover .slot-hint,.slot-button:focus-visible .slot-hint{opacity:1}.booking-block{position:absolute;z-index:5;overflow:hidden;inset-inline:6px;width:calc(100% - 12px);min-block-size:30px;padding:.4rem .5rem;border-radius:6px;color:#fff;pointer-events:none;box-shadow:0 3px 8px rgba(0,0,0,.2)}.booking-block.confirmed{background:#3565bd}.booking-block.payment{background:#b97800}.booking-block strong,.booking-block span,.booking-block small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.booking-block span{font-size:.7rem}.booking-block small{font-size:.65rem}
@media(max-width:700px){.portal-main{padding:1rem!important}.calendar-tools{grid-template-columns:1fr}.calendar-tools>div:first-child{grid-column:1/-1}.calendar-legend strong{inline-size:100%;margin:0}.member-schedule{grid-template-columns:68px minmax(220px,1fr);min-inline-size:0}.court-head small{display:none}.member-date{inline-size:135px}.slot-hint{opacity:1;background:transparent;color:#37413f;position:absolute;inset-inline-end:.4rem}}
.message-unread{background:rgba(11,123,109,.07);font-weight:700}.message-body :deep(table){max-width:100%}.message-body :deep(td){vertical-align:top}
</style>
