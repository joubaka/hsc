<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { $api } from '@/utils/api'
import ChampionshipDrawViewer from '@/components/ChampionshipDrawViewer.vue'
import ChampionshipOperations from '@/components/ChampionshipOperations.vue'

const props = defineProps({ event: { type: Object, required: true }, championship: { type: Object, required: true }, categories: { type: Array, default: () => [] } })
const emit = defineEmits(['refresh', 'error', 'add-entry'])
const tab = ref('entries')
const expanded = ref(false)
const search = ref('')
const statusFilter = ref('all')
const density = ref('compact')
const busy = ref(false)
const settingsDialog = ref(false)
const rowDialog = ref(false)
const selectedEntry = ref(null)
const eventForm = reactive({})
const rowForm = reactive({ status: 'pending', payment_status: 'pending', seed: null })
const drawFormats = [{title:'Knockout',value:'knockout'},{title:'Round robin',value:'round_robin'},{title:'Round robin → elimination',value:'group_knockout'},{title:'Consolation',value:'consolation'},{title:'Feed-in',value:'feed_in'},{title:'Compass',value:'compass'},{title:'Monrad',value:'monrad'},{title:'Double elimination',value:'double_elimination'},{title:'Swiss',value:'swiss'},{title:'Custom',value:'custom'}]
const rankingOptions = [{title:'Matches won',value:'matches_won'},{title:'Head-to-head result',value:'head_to_head'},{title:'Sets won',value:'sets_won'},{title:'Percentage of sets won',value:'set_percentage'},{title:'Games won',value:'games_won'},{title:'Percentage of games won',value:'game_percentage'},{title:'Original seed',value:'seed'}]
const headers = [{title:'Entrant',key:'entrant'},{title:'Status',key:'status'},{title:'Payment',key:'payment_status'},{title:'Seed',key:'seed',align:'center'},{title:'Actions',key:'actions',sortable:false,align:'end'}]
const filteredEntries = computed(() => props.event.entries.filter(entry => {
  const name = entry.members.map(member => member.display_name).join(' / ').toLowerCase()
  return name.includes(search.value.toLowerCase()) && (statusFilter.value === 'all' || entry.status === statusFilter.value)
}))
const eligibleCount = computed(() => props.event.entries.filter(entry => ['pending','accepted'].includes(entry.status) && ['paid','waived','not_required'].includes(entry.payment_status)).length)
const minimumEntries = computed(() => props.event.draw_format === 'group_knockout' ? 4 : 2)
const money = cents => new Intl.NumberFormat('en-ZA',{style:'currency',currency:'ZAR'}).format((cents||0)/100)
const entryName = entry => entry.members.map(member => member.display_name).join(' / ')

function report(error, fallback) { emit('error', error?.data?.errors?.event?.[0] || error?.data?.errors?.draw?.[0] || error?.data?.message || fallback) }
function editEvent() { Object.assign(eventForm,{name:props.event.name,discipline:props.event.discipline,gender_rule:props.event.gender_rule,age_category_id:props.event.age_category_id,draw_format:props.event.draw_format,ranking_rules:[...(props.event.ranking_rules?.length?props.event.ranking_rules:['matches_won','head_to_head','sets_won','game_percentage'])],entry_fee_rands:(props.event.entry_fee_cents||0)/100,fee_basis:props.event.fee_basis,capacity:props.event.capacity,seed_count:props.event.seed_count});settingsDialog.value=true }
function rankingTitle(value){return rankingOptions.find(option=>option.value===value)?.title||value}
function moveRule(index,direction){const target=index+direction;if(target<0||target>=eventForm.ranking_rules.length)return;[eventForm.ranking_rules[index],eventForm.ranking_rules[target]]=[eventForm.ranking_rules[target],eventForm.ranking_rules[index]]}
function addRule(){const unused=rankingOptions.find(option=>!eventForm.ranking_rules.includes(option.value));if(unused)eventForm.ranking_rules.push(unused.value)}
function editEntry(entry) { selectedEntry.value=entry;Object.assign(rowForm,{status:entry.status,payment_status:entry.payment_status,seed:entry.seed});rowDialog.value=true }
async function saveEvent(){busy.value=true;try{await $api(`/championship-events/${props.event.id}`,{method:'PATCH',body:{...eventForm,entry_fee_cents:Math.round((Number(eventForm.entry_fee_rands)||0)*100),capacity:eventForm.capacity||null}});settingsDialog.value=false;emit('refresh')}catch(error){report(error,'Unable to update the event.')}finally{busy.value=false}}
async function saveEntry(){busy.value=true;try{await $api(`/championship-entries/${selectedEntry.value.id}`,{method:'PATCH',body:rowForm});rowDialog.value=false;emit('refresh')}catch(error){report(error,'Unable to update the entry.')}finally{busy.value=false}}
async function generate(){busy.value=true;try{await $api(`/championship-events/${props.event.id}/draws`,{method:'POST'});tab.value='draws';emit('refresh')}catch(error){report(error,'Unable to generate the draw.')}finally{busy.value=false}}
async function publish(draw){busy.value=true;try{await $api(`/championship-draws/${draw.id}/publish`,{method:'POST'});emit('refresh')}catch(error){report(error,'Unable to publish the draw.')}finally{busy.value=false}}
async function deleteDraw(draw){if(!confirm(`Delete draft draw v${draw.version}?`))return;busy.value=true;try{await $api(`/championship-draws/${draw.id}`,{method:'DELETE'});emit('refresh')}catch(error){report(error,'Unable to delete the draw.')}finally{busy.value=false}}
async function archive(){if(!confirm(`Archive ${props.event.name}?`))return;try{await $api(`/championship-events/${props.event.id}/archive`,{method:'PATCH'});emit('refresh')}catch(error){report(error,'Unable to archive the event.')}}
async function remove(){if(!confirm(`Permanently delete ${props.event.name}?`))return;try{await $api(`/championship-events/${props.event.id}`,{method:'DELETE'});emit('refresh')}catch(error){report(error,'Unable to delete the event.')}}
watch(() => props.event.id, () => { tab.value='entries';search.value='';statusFilter.value='all' })
</script>

<template>
  <VCard class="event-workspace mb-5" :loading="busy">
    <VCardTitle class="d-flex flex-wrap align-center gap-2 pa-5 category-tab" @click="expanded=!expanded">
      <VBtn :icon="expanded?'tabler-chevron-up':'tabler-chevron-down'" size="small" variant="tonal" :title="expanded?'Collapse category':'Expand category'"/>
      <div class="me-auto"><div class="text-h5">{{event.name}}</div><div class="text-caption text-medium-emphasis mt-1">{{event.discipline}} · {{event.gender_rule}} · {{event.age_category?.name||'Open'}} · {{money(event.entry_fee_cents)}} · {{event.draw_format.replaceAll('_',' ')}}</div></div>
      <VChip size="small" variant="tonal">{{event.entries.length}} entries</VChip>
      <VChip v-if="event.draws.length" size="small" color="info" variant="tonal">{{event.draws.length}} draw{{event.draws.length===1?'':'s'}}</VChip>
      <VChip :color="event.status==='archived'?'default':'primary'" size="small" variant="tonal">{{event.status}}</VChip>
      <span @click.stop><ChampionshipDrawViewer v-if="event.draws?.length" :event="event"/></span>
      <span @click.stop><ChampionshipOperations v-if="event.draws?.length" :championship="championship" :event-id="event.id" :event-name="event.name"/></span>
      <VBtn size="small" prepend-icon="tabler-user-plus" variant="tonal" :disabled="event.status==='archived'" @click.stop="emit('add-entry',event)">Add entry</VBtn>
      <span @click.stop><VMenu><template #activator="{props:menuProps}"><VBtn v-bind="menuProps" icon="tabler-dots-vertical" size="small" variant="text"/></template><VList><VListItem prepend-icon="tabler-edit" title="Edit event settings" @click="editEvent"/><VListItem prepend-icon="tabler-archive" title="Archive event" @click="archive"/><VListItem prepend-icon="tabler-trash" title="Delete empty event" class="text-error" @click="remove"/></VList></VMenu></span>
    </VCardTitle>
    <VExpandTransition><div v-show="expanded">
    <VDivider/>
    <VTabs v-model="tab" class="px-4"><VTab value="entries" prepend-icon="tabler-users">Entries <VChip size="x-small" class="ms-2">{{event.entries.length}}</VChip></VTab><VTab value="draws" prepend-icon="tabler-tournament">Draws <VChip size="x-small" class="ms-2">{{event.draws.length}}</VChip></VTab><VTab value="settings" prepend-icon="tabler-settings">Settings</VTab></VTabs>
    <VDivider/>
    <VWindow v-model="tab">
      <VWindowItem value="entries"><VCardText class="pa-5">
        <div class="d-flex flex-wrap gap-3 align-center mb-4"><VTextField v-model="search" prepend-inner-icon="tabler-search" label="Search entrants" density="compact" hide-details style="max-width:280px"/><VSelect v-model="statusFilter" :items="['all','pending','accepted','waitlisted','rejected','withdrawn']" label="Status" density="compact" hide-details style="max-width:180px"/><VBtnToggle v-model="density" mandatory density="compact"><VBtn value="compact" icon="tabler-list" title="Compact rows"/><VBtn value="comfortable" icon="tabler-menu-2" title="Comfortable rows"/></VBtnToggle><VSpacer/><VChip color="success" variant="tonal">{{eligibleCount}} eligible</VChip></div>
        <VDataTable :headers="headers" :items="filteredEntries" :density="density" :items-per-page="10" item-value="id" class="entries-table">
          <template #item.entrant="{item}"><div class="font-weight-medium">{{entryName(item)}}</div></template>
          <template #item.status="{item}"><VChip size="small" :color="item.status==='accepted'?'success':item.status==='rejected'?'error':'warning'" variant="tonal">{{item.status}}</VChip></template>
          <template #item.payment_status="{item}"><VChip size="small" :color="['paid','waived','not_required'].includes(item.payment_status)?'success':'warning'" variant="tonal">{{item.payment_status.replaceAll('_',' ')}}</VChip></template>
          <template #item.seed="{item}"><span>{{item.seed||'—'}}</span></template>
          <template #item.actions="{item}"><VBtn size="x-small" prepend-icon="tabler-edit" variant="text" @click="editEntry(item)">Edit</VBtn></template>
          <template #no-data><div class="pa-8 text-center text-medium-emphasis">No entries match these filters.</div></template>
        </VDataTable>
      </VCardText></VWindowItem>
      <VWindowItem value="draws"><VCardText class="pa-5"><div class="d-flex align-center mb-4"><div><div class="text-h6">Draw management</div><div class="text-body-2 text-medium-emphasis">Generate, inspect, publish, or replace unpublished draw versions.</div></div><VSpacer/><VBtn prepend-icon="tabler-plus" :disabled="event.status==='archived'||eligibleCount<minimumEntries" @click="generate">Generate new draw</VBtn></div><VAlert v-if="!event.draws.length" type="info" variant="tonal">No draw exists yet. At least {{minimumEntries}} eligible entries are required.</VAlert><VRow><VCol v-for="draw in event.draws" :key="draw.id" cols="12" md="6"><VCard variant="outlined"><VCardTitle class="d-flex align-center">Version {{draw.version}}<VChip size="small" class="ms-2" :color="draw.status==='published'?'success':'warning'">{{draw.status}}</VChip></VCardTitle><VCardText>{{draw.matches.length}} matches · {{draw.format.replaceAll('_',' ')}}</VCardText><VCardActions><ChampionshipDrawViewer :event="{...event,draws:[draw]}"/><VSpacer/><VBtn v-if="draw.status==='draft'" color="error" variant="text" @click="deleteDraw(draw)">Delete draft</VBtn><VBtn v-if="draw.status==='draft'" color="success" @click="publish(draw)">Publish</VBtn></VCardActions></VCard></VCol></VRow></VCardText></VWindowItem>
      <VWindowItem value="settings"><VCardText class="pa-5"><VRow><VCol cols="12" md="4"><div class="setting-label">Format</div><div class="setting-value">{{event.draw_format.replaceAll('_',' ')}}</div></VCol><VCol cols="12" md="4"><div class="setting-label">Discipline</div><div class="setting-value">{{event.discipline}} · {{event.gender_rule}}</div></VCol><VCol cols="12" md="4"><div class="setting-label">Age category</div><div class="setting-value">{{event.age_category?.name||'Open'}}</div></VCol><VCol cols="12" md="4"><div class="setting-label">Entry fee</div><div class="setting-value">{{money(event.entry_fee_cents)}} per {{event.fee_basis}}</div></VCol><VCol cols="12" md="4"><div class="setting-label">Capacity</div><div class="setting-value">{{event.capacity||'Unlimited'}}</div></VCol><VCol cols="12" md="4"><div class="setting-label">Seeds</div><div class="setting-value">{{event.seed_count||0}}</div></VCol><VCol v-if="['round_robin','group_knockout'].includes(event.draw_format)" cols="12"><div class="setting-label">Group tie-break order</div><div class="d-flex flex-wrap gap-2 mt-2"><VChip v-for="(rule,index) in (event.ranking_rules||['matches_won','head_to_head','sets_won','game_percentage'])" :key="rule" color="primary" variant="tonal">{{index+1}}. {{rankingTitle(rule)}}</VChip></div></VCol></VRow><VBtn class="mt-5" prepend-icon="tabler-edit" @click="editEvent">Edit event settings</VBtn></VCardText></VWindowItem>
    </VWindow>
    </div></VExpandTransition>
  </VCard>

  <VDialog v-model="rowDialog" max-width="560"><VCard><VCardTitle class="pa-6">Edit {{selectedEntry?entryName(selectedEntry):'entry'}}</VCardTitle><VCardText><VRow><VCol cols="12" md="6"><VSelect v-model="rowForm.status" :items="['pending','accepted','waitlisted','rejected','withdrawn']" label="Entry status"/></VCol><VCol cols="12" md="6"><VSelect v-model="rowForm.payment_status" :items="['pending','paid','waived','refunded']" label="Payment"/></VCol><VCol cols="12"><VTextField v-model.number="rowForm.seed" type="number" min="1" clearable label="Seed number" hint="Leave blank for unseeded" persistent-hint/></VCol></VRow></VCardText><VCardActions class="pa-6"><VSpacer/><VBtn variant="tonal" @click="rowDialog=false">Cancel</VBtn><VBtn :loading="busy" @click="saveEntry">Save entry</VBtn></VCardActions></VCard></VDialog>
  <VDialog v-model="settingsDialog" max-width="760"><VCard><VCardTitle class="pa-6">Edit event settings</VCardTitle><VCardText><VAlert v-if="event.draws.length" type="info" variant="tonal" class="mb-4">Delete draft draws before changing discipline or draw format. Published draws are protected.</VAlert><VRow><VCol cols="12"><VTextField v-model="eventForm.name" label="Event name"/></VCol><VCol cols="6"><VSelect v-model="eventForm.discipline" :items="['singles','doubles']" label="Discipline"/></VCol><VCol cols="6"><VSelect v-model="eventForm.gender_rule" :items="['open','male','female','mixed']" label="Gender category"/></VCol><VCol cols="6"><VSelect v-model="eventForm.age_category_id" :items="categories" item-title="name" item-value="id" clearable label="Age category"/></VCol><VCol cols="6"><VSelect v-model="eventForm.draw_format" :items="drawFormats" label="Draw format"/></VCol><VCol cols="4"><VTextField v-model.number="eventForm.entry_fee_rands" type="number" min="0" prefix="R" label="Entry fee"/></VCol><VCol cols="4"><VSelect v-model="eventForm.fee_basis" :items="['player','team']" label="Fee basis"/></VCol><VCol cols="4"><VTextField v-model.number="eventForm.seed_count" type="number" min="0" label="Seeds"/></VCol><VCol cols="6"><VTextField v-model.number="eventForm.capacity" type="number" min="2" clearable label="Capacity"/></VCol><VCol v-if="['round_robin','group_knockout'].includes(eventForm.draw_format)" cols="12"><VDivider class="mb-5"/><div class="text-h6 mb-1">Group tie-break order</div><p class="text-body-2 text-medium-emphasis mb-3">Criteria are applied from top to bottom. Use the arrows to change their priority.</p><div v-for="(rule,index) in eventForm.ranking_rules" :key="`${rule}-${index}`" class="d-flex align-center gap-2 mb-2"><VAvatar color="primary" variant="tonal" size="32">{{index+1}}</VAvatar><VSelect v-model="eventForm.ranking_rules[index]" :items="rankingOptions" density="compact" hide-details/><VBtn icon="tabler-arrow-up" size="small" variant="text" :disabled="index===0" @click="moveRule(index,-1)"/><VBtn icon="tabler-arrow-down" size="small" variant="text" :disabled="index===eventForm.ranking_rules.length-1" @click="moveRule(index,1)"/><VBtn icon="tabler-x" size="small" color="error" variant="text" @click="eventForm.ranking_rules.splice(index,1)"/></div><VBtn v-if="eventForm.ranking_rules.length<rankingOptions.length" prepend-icon="tabler-plus" size="small" variant="tonal" @click="addRule">Add criterion</VBtn></VCol></VRow></VCardText><VCardActions class="pa-6"><VSpacer/><VBtn variant="tonal" @click="settingsDialog=false">Cancel</VBtn><VBtn :loading="busy" @click="saveEvent">Save settings</VBtn></VCardActions></VCard></VDialog>
</template>

<style scoped>
.event-workspace{overflow:hidden}.category-tab{flex-wrap:nowrap!important;overflow:hidden;cursor:pointer;transition:background-color .15s ease}.category-tab:hover{background:rgba(var(--v-theme-primary),.04)}.category-tab>*{flex-shrink:0}.category-tab>.me-auto{min-width:0;flex-shrink:1;overflow:hidden}.category-tab>.me-auto .text-caption{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.entries-table{border:1px solid rgba(var(--v-border-color),var(--v-border-opacity));border-radius:8px}.setting-label{color:rgba(var(--v-theme-on-surface),.6);font-size:12px;text-transform:uppercase}.setting-value{margin-top:4px;font-size:15px;font-weight:600;text-transform:capitalize}
</style>
