<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  championship: { type: Object, required: true },
  eventId: { type: Number, default: null },
  eventName: { type: String, default: '' },
})
const open = ref(false)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref(null)
const data = ref({ championship: {}, facilities: [], members: [] })
const tab = ref('schedule')
const tournamentStart = value => String(value || '').slice(0, 10)
const selectedDate = ref(tournamentStart(props.championship.starts_on))
const session = ref({ name: 'Morning session', play_date: tournamentStart(props.championship.starts_on), starts_at: '08:00', ends_at: '18:00', facility_ids: [], slot_minutes: 30 })
const availability = ref({ member_id: null, unavailable_from: '', unavailable_until: '', reason: '' })
const block = ref({ facility_id: null, starts_at: '', ends_at: '', reason: '' })
const announcement = ref({ title: '', message: '', audience: 'competitors', priority: 'normal', channels: ['portal','email'] })
const scheduleForm = ref({})
const resultForm = ref({})
const editingSessionId = ref(null)
const sessionMessage = ref('')

const matches = computed(() => (data.value.championship.events || []).filter(event => !props.eventId || event.id === props.eventId).flatMap(event =>
  (event.draws || []).flatMap(draw =>
    (draw.matches || []).map(match => ({ ...match, event_name: event.name, event, draw })),
  ),
))
const queue = computed(() => matches.value.filter(match => ['ready_to_schedule','postponed'].includes(match.status) && match.entry1_id && match.entry2_id))
const dayMatches = computed(() => matches.value.filter(match => match.scheduled_at?.slice(0,10) === selectedDate.value).sort((a,b) => a.scheduled_at.localeCompare(b.scheduled_at)))
const deskMatches = computed(() => matches.value.filter(match => ['scheduled','called','live','suspended','completed'].includes(match.status)).sort((a,b)=>(a.scheduled_at||'').localeCompare(b.scheduled_at||'')))
const publications = computed(() => (data.value.championship.schedule_publications || []).filter(item => item.play_date?.slice(0,10)===selectedDate.value))
const participant = entry => entry?.members?.map(member => member.display_name).join(' / ') || 'TBD'
const memberItems = computed(() => data.value.members.map(member => ({ title: member.display_name || [member.preferred_name||member.first_name,member.last_name].filter(Boolean).join(' '), value: member.id })))
const sessionCourts = item => (item.facility_ids || []).map(id => data.value.facilities.find(facility => facility.id === id)?.name).filter(Boolean).join(', ') || 'No courts selected'

watch(open, value => { if (value) load() })
async function load(){ loading.value=true;error.value='';try{data.value=await $api(`/championships/${props.championship.id}/operations`)}catch(e){fail(e)}finally{loading.value=false} }
function fail(e){error.value=e?.data?.message||Object.values(e?.data?.errors||{})?.flat()?.[0]||'The tournament operation could not be completed.'}
async function send(url, options={}){saving.value=true;error.value='';try{const response=await $api(url,options);await load();return response||true}catch(e){fail(e);return false}finally{saving.value=false}}
function resetSessionForm(){editingSessionId.value=null;session.value={name:'Morning session',play_date:tournamentStart(props.championship.starts_on),starts_at:'08:00',ends_at:'18:00',facility_ids:[],slot_minutes:30}}
function editSession(item){editingSessionId.value=item.id;sessionMessage.value='';session.value={name:item.name,play_date:String(item.play_date).slice(0,10),starts_at:String(item.starts_at).slice(0,5),ends_at:String(item.ends_at).slice(0,5),facility_ids:[...(item.facility_ids||[])],slot_minutes:item.slot_minutes}}
async function addSession(){sessionMessage.value='';const url=editingSessionId.value?`/championship-playing-sessions/${editingSessionId.value}`:`/championships/${props.championship.id}/operations/sessions`;const response=await send(url,{method:editingSessionId.value?'PATCH':'POST',body:session.value});if(response){sessionMessage.value=editingSessionId.value?'Session updated successfully.':'Session added successfully.';resetSessionForm()}}
async function deleteSession(item){if(!window.confirm(`Delete “${item.name}”? Matches already scheduled in this period will not be removed.`))return;sessionMessage.value='';if(await send(`/championship-playing-sessions/${item.id}`,{method:'DELETE'})){if(editingSessionId.value===item.id)resetSessionForm();sessionMessage.value='Session deleted successfully.'}}
async function addAvailability(){if(await send(`/championships/${props.championship.id}/operations/availability`,{method:'POST',body:availability.value}))availability.value={member_id:null,unavailable_from:'',unavailable_until:'',reason:''}}
async function addBlock(){if(await send(`/championships/${props.championship.id}/operations/court-blocks`,{method:'POST',body:block.value}))block.value={facility_id:null,starts_at:'',ends_at:'',reason:''}}
async function schedule(match){const form=scheduleForm.value[match.id]||{};await send(`/championship-matches/${match.id}/schedule`,{method:'POST',body:form})}
async function unschedule(match){await send(`/championship-matches/${match.id}/schedule`,{method:'DELETE'})}
async function autoSchedule(){notice.value=null;const response=await send(`/championships/${props.championship.id}/operations/auto-schedule`,{method:'POST'});if(response)notice.value=response}
async function desk(match,action){await send(`/championship-matches/${match.id}/desk`,{method:'PATCH',body:{action}})}
async function recordResult(match){await send(`/championship-matches/${match.id}/result`,{method:'POST',body:resultForm.value[match.id]||{}})}
async function verify(match){await send(`/championship-matches/${match.id}/verify`,{method:'POST'})}
async function publish(){await send(`/championships/${props.championship.id}/operations/order-of-play`,{method:'POST',body:{play_date:selectedDate.value,change_summary:`Order of play for ${selectedDate.value}`}})}
async function announce(){if(await send(`/championships/${props.championship.id}/operations/announcements`,{method:'POST',body:announcement.value}))announcement.value={title:'',message:'',audience:'competitors',priority:'normal',channels:['portal','email']}}
</script>

<template>
  <span><VBtn prepend-icon="tabler-dashboard" color="primary" variant="tonal" :size="eventId?'small':undefined" @click="open=true">{{eventId?'Event operations':'Tournament operations'}}</VBtn>
    <VDialog v-model="open" scrollable content-class="operations-dialog"><VCard :loading="loading" class="operations-card">
      <VCardTitle class="operations-title d-flex align-center pa-5"><VIcon icon="tabler-tournament" class="me-3"/><div><div>{{eventId?eventName:championship.name}} operations</div><div class="text-caption text-medium-emphasis">{{eventId?'Schedule, run and record this event':'Tournament schedule, sessions, order of play and communications'}}</div></div><VSpacer/><VBtn icon="tabler-x" variant="text" @click="open=false"/></VCardTitle>
      <VDivider/><VAlert v-if="error" type="error" variant="tonal" closable class="ma-4 mb-0" @click:close="error=''">{{error}}</VAlert>
      <VTabs v-model="tab" class="operations-tabs px-4"><VTab value="schedule">Schedule</VTab><VTab value="desk">{{eventId?'Event desk':'Tournament desk'}}</VTab><VTab v-if="!eventId" value="setup">Sessions & constraints</VTab><VTab v-if="!eventId" value="comms">Communications</VTab></VTabs>
      <VDivider class="operations-divider"/><VCardText class="operations-content pa-5"><VWindow v-model="tab">
        <VWindowItem value="schedule">
          <div class="d-flex align-center mb-5"><VTextField v-model="selectedDate" type="date" label="Playing date" style="max-width:240px"/><VSpacer/><VBtn class="me-2" prepend-icon="tabler-wand" :loading="saving" @click="autoSchedule">Auto-schedule queue</VBtn><VBtn color="success" prepend-icon="tabler-speakerphone" :disabled="!dayMatches.length" @click="publish">Publish order of play</VBtn></div>
          <VAlert v-if="notice" :type="notice.unscheduled?'warning':'success'" variant="tonal" closable class="schedule-feedback mb-5" @click:close="notice=null">
            <div class="font-weight-bold">{{notice.message}}</div>
            <div class="text-body-2 mt-1">{{notice.scheduled}} scheduled · {{notice.unscheduled}} remaining · {{notice.configuration.sessions}} sessions · {{notice.configuration.courts}} courts</div>
            <div v-if="!notice.configuration.sessions" class="mt-2">No playing sessions are configured. Add a session with at least one court under Sessions & constraints.</div>
            <div v-else-if="!notice.configuration.courts" class="mt-2">The configured playing sessions do not contain any courts.</div>
            <div v-if="notice.failures?.length" class="schedule-feedback-details mt-3">
              <div v-for="failure in notice.failures" :key="failure.match_id" class="py-1"><strong>{{failure.event}} · {{failure.round}}</strong><br><span class="text-body-2">{{failure.reasons?.join(' ')||'No valid court and time slot was found.'}}</span></div>
            </div>
          </VAlert>
          <VRow><VCol cols="12" lg="5"><VCard variant="outlined"><VCardTitle>Scheduling queue <VChip size="small" class="ms-2">{{queue.length}}</VChip></VCardTitle><VDivider/><VCardText>
            <VAlert v-if="!queue.length" type="success" variant="tonal">The scheduling queue is clear.</VAlert>
            <div v-for="match in queue" :key="match.id" class="operation-row"><strong>{{match.event_name}} · {{match.round_name}}</strong><div class="text-body-2 mb-3">{{participant(match.entry1)}} vs {{participant(match.entry2)}}</div><div class="d-flex gap-2"><VTextField v-model="(scheduleForm[match.id]||(scheduleForm[match.id]={})).scheduled_at" type="datetime-local" label="Start" density="compact"/><VSelect v-model="scheduleForm[match.id].facility_id" :items="data.facilities" item-title="name" item-value="id" label="Court" density="compact"/><VBtn size="small" class="mt-1" @click="schedule(match)">Set</VBtn></div></div>
          </VCardText></VCard></VCol><VCol cols="12" lg="7"><VCard variant="outlined"><VCardTitle>Order of play · {{selectedDate}}</VCardTitle><VDivider/><VCardText>
            <VAlert v-if="!dayMatches.length" type="info" variant="tonal">No matches scheduled for this date.</VAlert><div v-for="match in dayMatches" :key="match.id" class="order-row"><div class="order-time">{{new Date(match.scheduled_at).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'})}}</div><div><strong>{{match.facility?.name}}</strong><div>{{participant(match.entry1)}} vs {{participant(match.entry2)}}</div><small>{{match.event_name}} · {{match.round_name}}</small></div><VSpacer/><VBtn icon="tabler-calendar-x" size="small" variant="text" color="error" @click="unschedule(match)"/></div>
            <VAlert v-if="publications.length" type="success" variant="tonal" class="mt-4">Published version {{Math.max(...publications.map(item=>item.version))}} is currently visible to members.</VAlert>
          </VCardText></VCard></VCol></VRow>
        </VWindowItem>
        <VWindowItem value="desk"><VCard variant="outlined"><VCardTitle>Live tournament desk</VCardTitle><VDivider/><VCardText><VAlert v-if="!deskMatches.length" type="info" variant="tonal">No scheduled matches yet.</VAlert>
          <div v-for="match in deskMatches" :key="match.id" class="operation-row"><div class="d-flex align-center"><div><strong>{{match.event_name}} · {{match.round_name}}</strong><div>{{participant(match.entry1)}} vs {{participant(match.entry2)}}</div></div><VSpacer/><VChip :color="match.status==='live'?'error':match.status==='completed'?'success':'primary'">{{match.status}}</VChip></div>
            <div class="d-flex flex-wrap gap-2 mt-3"><VBtn v-for="action in ['checkin1','checkin2','call','start','suspend','resume','postpone','cancel']" :key="action" size="small" variant="tonal" @click="desk(match,action)">{{action}}</VBtn></div>
            <div v-if="match.status==='live'||match.status==='completed'" class="result-grid mt-4"><VSelect v-model="(resultForm[match.id]||(resultForm[match.id]={})).winner_entry_id" :items="[{title:participant(match.entry1),value:match.entry1_id},{title:participant(match.entry2),value:match.entry2_id}]" label="Winner" density="compact"/><VTextField v-model="resultForm[match.id].final_score" label="Score (e.g. 6-4, 6-3)" density="compact"/><VBtn v-if="match.status!=='completed'" @click="recordResult(match)">Record result</VBtn><VBtn v-else-if="match.result_status!=='verified'" color="success" @click="verify(match)">Verify result</VBtn><VChip v-else color="success">Verified</VChip></div>
          </div></VCardText></VCard></VWindowItem>
        <VWindowItem value="setup"><VRow><VCol cols="12" md="4"><VCard variant="outlined"><VCardTitle>{{editingSessionId?'Edit playing session':'Playing session'}}</VCardTitle><VCardText><VAlert v-if="sessionMessage" type="success" variant="tonal" density="compact" closable class="mb-4" @click:close="sessionMessage=''">{{sessionMessage}}</VAlert><VTextField v-model="session.name" label="Name"/><VTextField v-model="session.play_date" type="date" label="Date"/><div class="d-flex gap-2"><VTextField v-model="session.starts_at" type="time" label="Starts"/><VTextField v-model="session.ends_at" type="time" label="Ends"/></div><VSelect v-model="session.facility_ids" :items="data.facilities" item-title="name" item-value="id" multiple chips label="Courts"/><VTextField v-model.number="session.slot_minutes" type="number" label="Slot minutes"/><div class="d-flex gap-2"><VBtn block :loading="saving" @click="addSession">{{editingSessionId?'Save changes':'Add session'}}</VBtn><VBtn v-if="editingSessionId" variant="outlined" @click="resetSessionForm">Cancel</VBtn></div><VDivider class="my-5"/><div class="text-subtitle-1 font-weight-bold mb-2">Added sessions</div><VAlert v-if="!data.championship.sessions?.length" type="info" variant="tonal" density="compact">No playing sessions have been added yet.</VAlert><VList v-else density="compact" class="session-list"><VListItem v-for="item in data.championship.sessions" :key="item.id" prepend-icon="tabler-calendar-event"><VListItemTitle>{{item.name}}</VListItemTitle><VListItemSubtitle>{{String(item.play_date).slice(0,10)}} · {{String(item.starts_at).slice(0,5)}}–{{String(item.ends_at).slice(0,5)}}<br>{{sessionCourts(item)}} · every {{item.slot_minutes}} minutes</VListItemSubtitle><template #append><VBtn icon="tabler-edit" size="small" variant="text" title="Edit session" @click="editSession(item)"/><VBtn icon="tabler-trash" size="small" variant="text" color="error" title="Delete session" @click="deleteSession(item)"/></template></VListItem></VList></VCardText></VCard></VCol>
          <VCol cols="12" md="4"><VCard variant="outlined"><VCardTitle>Player unavailable</VCardTitle><VCardText><VSelect v-model="availability.member_id" :items="memberItems" label="Member"/><VTextField v-model="availability.unavailable_from" type="datetime-local" label="From"/><VTextField v-model="availability.unavailable_until" type="datetime-local" label="Until"/><VTextField v-model="availability.reason" label="Reason"/><VBtn block @click="addAvailability">Save constraint</VBtn></VCardText></VCard></VCol>
          <VCol cols="12" md="4"><VCard variant="outlined"><VCardTitle>Court block</VCardTitle><VCardText><VSelect v-model="block.facility_id" :items="data.facilities" item-title="name" item-value="id" label="Court"/><VTextField v-model="block.starts_at" type="datetime-local" label="From"/><VTextField v-model="block.ends_at" type="datetime-local" label="Until"/><VTextField v-model="block.reason" label="Reason"/><VBtn block @click="addBlock">Block court</VBtn></VCardText></VCard></VCol></VRow></VWindowItem>
        <VWindowItem value="comms"><VRow><VCol cols="12" md="7"><VCard variant="outlined"><VCardTitle>Tournament announcement</VCardTitle><VCardText><VTextField v-model="announcement.title" label="Title"/><VTextarea v-model="announcement.message" label="Message"/><div class="d-flex gap-2"><VSelect v-model="announcement.audience" :items="['all','competitors','today']" label="Audience"/><VSelect v-model="announcement.priority" :items="['normal','important','urgent']" label="Priority"/></div><VSelect v-model="announcement.channels" :items="[{title:'Member portal',value:'portal'},{title:'Email',value:'email'}]" multiple chips label="Channels"/><VBtn :loading="saving" @click="announce">Publish announcement</VBtn></VCardText></VCard></VCol><VCol cols="12" md="5"><VCard variant="outlined"><VCardTitle>Published announcements</VCardTitle><VCardText><div v-for="item in data.championship.announcements" :key="item.id" class="operation-row"><strong>{{item.title}}</strong><div>{{item.message}}</div><small>{{item.priority}} · {{item.audience}}</small></div></VCardText></VCard></VCol></VRow></VWindowItem>
      </VWindow></VCardText></VCard></VDialog></span>
</template>

<style scoped>
.operation-row{padding:16px 0;border-block-end:1px solid rgba(var(--v-border-color),var(--v-border-opacity))}.order-row{display:flex;align-items:center;gap:16px;padding:14px 0;border-block-end:1px solid rgba(var(--v-border-color),var(--v-border-opacity))}.order-time{min-width:70px;color:rgb(var(--v-theme-primary));font-size:18px;font-weight:700}.result-grid{display:grid;grid-template-columns:2fr 2fr auto;gap:12px;align-items:start}.gap-2{gap:8px}@media(max-width:900px){.result-grid{grid-template-columns:1fr}}
.operations-card{width:100%;height:100%;min-height:100%;display:flex;flex-direction:column;overflow:hidden}.operations-title,.operations-tabs,.operations-divider{flex:0 0 auto}.operations-tabs{min-height:48px}.operations-content{flex:1 1 auto;min-height:0;overflow-y:auto}.schedule-feedback{flex:0 0 auto}.schedule-feedback-details{max-height:180px;overflow-y:auto;padding-inline-end:8px;border-block-start:1px solid rgba(var(--v-theme-on-surface),.14)}.session-list{max-height:260px;overflow-y:auto;border:1px solid rgba(var(--v-border-color),var(--v-border-opacity));border-radius:6px}:deep(.operations-dialog){width:min(1600px,calc(100vw - 48px))!important;height:min(900px,calc(100vh - 48px))!important;max-width:none!important;max-height:none!important;margin:24px!important}
</style>
