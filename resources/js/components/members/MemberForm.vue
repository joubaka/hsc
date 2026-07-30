<script setup>
import { $api } from '@/utils/api'

const props = defineProps({
  memberId: { type: Number, default: null },
  meta: { type: Object, required: true },
})
const emit = defineEmits(['saved', 'cancel'])
const saving = ref(false)
const loading = ref(false)
const errors = ref({})
const selectedCodes = ref([])
const form = reactive({
  first_name: '', preferred_name: '', last_name: '', date_of_birth: '', email: '', mobile: '',
  status: 'active', household_name: '', household_role: '', emergency_contact_name: '',
  emergency_contact_mobile: '', email_consent: true, whatsapp_consent: false, notes: '',
  sport_codes: [],
  access: { credential_type: 'pin', status: 'not_issued', areas: [], valid_until: '' },
})
const statusOptions = [
  { title: 'Prospective', value: 'prospective' }, { title: 'Active', value: 'active' },
  { title: 'Suspended', value: 'suspended' }, { title: 'Resigned', value: 'resigned' },
]
const areaOptions = [
  { title: 'Main entrance', value: 'main_entrance' }, { title: 'Clubhouse', value: 'clubhouse' },
  { title: 'Tennis courts', value: 'tennis_courts' }, { title: 'Padel courts', value: 'padel_courts' },
  { title: 'Squash building', value: 'squash_building' }, { title: 'Equipment room', value: 'equipment_room' },
]

function toggleCode(codeId) {
  if (selectedCodes.value.includes(codeId)) {
    if (!form.sport_codes.some(item => item.sport_code_id === codeId))
      form.sport_codes.push({ sport_code_id: codeId, category_id: null, status: 'active', role: 'member' })
  } else {
    form.sport_codes = form.sport_codes.filter(item => item.sport_code_id !== codeId)
  }
}
function settingsFor(codeId) {
  return form.sport_codes.find(item => item.sport_code_id === codeId)
}
async function load() {
  if (!props.memberId) return
  loading.value = true
  try {
    const member = await $api(`/members/${props.memberId}`)
    Object.keys(form).forEach(key => {
      if (!['sport_codes', 'access'].includes(key)) form[key] = member[key] ?? ''
    })
    form.household_name = member.household?.name ?? ''
    form.sport_codes = member.sport_codes.map(code => ({
      sport_code_id: code.id,
      category_id: code.pivot.membership_category_id,
      status: code.pivot.status,
      role: code.pivot.role,
    }))
    selectedCodes.value = form.sport_codes.map(item => item.sport_code_id)
    Object.assign(form.access, member.access_credential ?? {})
    form.access.areas ||= []
    form.access.valid_until = form.access.valid_until?.slice(0, 10) ?? ''
  } finally {
    loading.value = false
  }
}
async function submit() {
  saving.value = true
  errors.value = {}
  try {
    const member = await $api(props.memberId ? `/members/${props.memberId}` : '/members', {
      method: props.memberId ? 'PUT' : 'POST',
      body: form,
    })
    emit('saved', member)
  } catch (error) {
    errors.value = error?.data?.errors ?? {}
  } finally {
    saving.value = false
  }
}
onMounted(load)
</script>

<template>
  <VForm @submit.prevent="submit">
    <VProgressLinear v-if="loading" indeterminate class="mb-4" />
    <VAlert v-if="Object.keys(errors).length" type="error" variant="tonal" class="mb-5">Please check the highlighted information.</VAlert>
    <h5 class="text-h5 mb-4">Personal details</h5>
    <VRow>
      <VCol cols="12" md="4"><VTextField v-model="form.first_name" label="First name *" :error-messages="errors.first_name" /></VCol>
      <VCol cols="12" md="4"><VTextField v-model="form.preferred_name" label="Preferred name" /></VCol>
      <VCol cols="12" md="4"><VTextField v-model="form.last_name" label="Last name *" :error-messages="errors.last_name" /></VCol>
      <VCol cols="12" md="4"><VTextField v-model="form.date_of_birth" label="Date of birth" type="date" /></VCol>
      <VCol cols="12" md="4"><VTextField v-model="form.email" label="Email" type="email" :error-messages="errors.email" /></VCol>
      <VCol cols="12" md="4"><VTextField v-model="form.mobile" label="Mobile" /></VCol>
      <VCol cols="12" md="4"><VSelect v-model="form.status" label="HSC status" :items="statusOptions" /></VCol>
      <VCol cols="12" md="4"><VTextField v-model="form.household_name" label="Household / family" /></VCol>
      <VCol cols="12" md="4"><VTextField v-model="form.household_role" label="Household role" /></VCol>
    </VRow>
    <VDivider class="my-6" />
    <h5 class="text-h5 mb-1">Sport memberships</h5>
    <p class="text-body-2 text-medium-emphasis mb-4">A member can belong to more than one sport code.</p>
    <div v-for="code in meta.sport_codes" :key="code.id" class="border rounded pa-4 mb-3">
      <VCheckbox v-model="selectedCodes" :value="code.id" :label="code.name" color="primary" hide-details @update:model-value="toggleCode(code.id)" />
      <VRow v-if="selectedCodes.includes(code.id) && settingsFor(code.id)" class="mt-1">
        <VCol cols="12" md="5">
          <VSelect v-model="settingsFor(code.id).category_id" label="Membership category" :items="code.categories" item-title="name" item-value="id">
            <template #item="{ props: itemProps, item }"><VListItem v-bind="itemProps" :subtitle="`R ${Number(item.raw.total_fee).toFixed(2)} / ${item.raw.billing_period}`" /></template>
          </VSelect>
        </VCol>
        <VCol cols="12" md="4"><VSelect v-model="settingsFor(code.id).status" label="Code status" :items="['application', 'awaiting_payment', 'active', 'on_hold', 'lapsed', 'suspended']" /></VCol>
        <VCol cols="12" md="3"><VSelect v-model="settingsFor(code.id).role" label="Role" :items="['member', 'player', 'social', 'coach', 'committee', 'administrator']" /></VCol>
      </VRow>
    </div>
    <VDivider class="my-6" />
    <h5 class="text-h5 mb-4">Premises access</h5>
    <VRow>
      <VCol cols="12" md="4"><VSelect v-model="form.access.credential_type" label="Credential type" :items="['pin', 'card', 'tag', 'mobile']" /></VCol>
      <VCol cols="12" md="4"><VSelect v-model="form.access.status" label="Access status" :items="['not_issued', 'pending', 'active', 'suspended', 'expired', 'revoked']" /></VCol>
      <VCol cols="12" md="4"><VTextField v-model="form.access.valid_until" label="Valid until" type="date" /></VCol>
      <VCol cols="12"><VSelect v-model="form.access.areas" label="Permitted areas" :items="areaOptions" multiple chips /></VCol>
    </VRow>
    <VDivider class="my-6" />
    <h5 class="text-h5 mb-4">Emergency contact & communication</h5>
    <VRow>
      <VCol cols="12" md="6"><VTextField v-model="form.emergency_contact_name" label="Emergency contact" /></VCol>
      <VCol cols="12" md="6"><VTextField v-model="form.emergency_contact_mobile" label="Emergency mobile" /></VCol>
      <VCol cols="12" md="6"><VCheckbox v-model="form.email_consent" label="May receive email communication" /></VCol>
      <VCol cols="12" md="6"><VCheckbox v-model="form.whatsapp_consent" label="May receive WhatsApp communication" /></VCol>
      <VCol cols="12"><VTextarea v-model="form.notes" label="Internal notes" rows="3" /></VCol>
    </VRow>
    <div class="d-flex justify-end gap-3 mt-6">
      <VBtn variant="tonal" color="secondary" @click="emit('cancel')">Cancel</VBtn>
      <VBtn type="submit" :loading="saving">{{ memberId ? 'Save changes' : 'Create member' }}</VBtn>
    </div>
  </VForm>
</template>
