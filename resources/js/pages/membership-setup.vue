<script setup>
import { $api } from '@/utils/api'

const loading = ref(false)
const codes = ref([])
const selectedCode = ref(null)
const sportDialog = ref(false)
const categoryDialog = ref(false)
const saving = ref(false)
const errors = ref({})
const currentUser = JSON.parse(localStorage.getItem('hsc_admin_user') || '{}')
const isCentral = ['hsc_super_admin', 'hsc_admin'].includes(currentUser.role)
const isSuperAdmin = currentUser.role === 'hsc_super_admin'
const sportForm = reactive({ id: null, name: '', colour: '#7367F0', active: true })
const categoryForm = reactive({
  id: null, sport_code_id: null, name: '', billing_period: 'annual',
  code_fee: 0, hsc_levy: 0, minimum_age: null, maximum_age: null, active: true,
})
const money = value => new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR' }).format(Number(value || 0))

async function load() {
  loading.value = true
  try {
    codes.value = await $api('/membership-setup')
    if (!selectedCode.value && codes.value.length) selectedCode.value = codes.value[0]
    else if (selectedCode.value) selectedCode.value = codes.value.find(code => code.id === selectedCode.value.id) || codes.value[0]
  } finally {
    loading.value = false
  }
}
function newSport() {
  Object.assign(sportForm, { id: null, name: '', colour: '#7367F0', active: true })
  errors.value = {}
  sportDialog.value = true
}
function editSport(code) {
  Object.assign(sportForm, { id: code.id, name: code.name, colour: code.colour, active: code.active })
  errors.value = {}
  sportDialog.value = true
}
function newCategory() {
  Object.assign(categoryForm, {
    id: null, sport_code_id: selectedCode.value.id, name: '', billing_period: 'annual',
    code_fee: 0, hsc_levy: 0, minimum_age: null, maximum_age: null, active: true,
  })
  errors.value = {}
  categoryDialog.value = true
}
function editCategory(category) {
  Object.assign(categoryForm, {
    id: category.id, sport_code_id: category.sport_code_id, name: category.name,
    billing_period: category.billing_period, code_fee: category.code_fee, hsc_levy: category.hsc_levy,
    minimum_age: category.minimum_age, maximum_age: category.maximum_age, active: category.active,
  })
  errors.value = {}
  categoryDialog.value = true
}
async function saveSport() {
  saving.value = true
  errors.value = {}
  try {
    await $api(sportForm.id ? `/membership-setup/sport-codes/${sportForm.id}` : '/membership-setup/sport-codes', {
      method: sportForm.id ? 'PUT' : 'POST', body: sportForm,
    })
    sportDialog.value = false
    await load()
  } catch (error) {
    errors.value = error?.data?.errors || {}
  } finally {
    saving.value = false
  }
}
async function saveCategory() {
  saving.value = true
  errors.value = {}
  try {
    await $api(categoryForm.id ? `/membership-setup/categories/${categoryForm.id}` : '/membership-setup/categories', {
      method: categoryForm.id ? 'PUT' : 'POST', body: categoryForm,
    })
    categoryDialog.value = false
    await load()
  } catch (error) {
    errors.value = error?.data?.errors || {}
  } finally {
    saving.value = false
  }
}
function ageLabel(category) {
  if (category.minimum_age !== null && category.maximum_age !== null) return `${category.minimum_age}–${category.maximum_age}`
  if (category.maximum_age !== null) return `Up to ${category.maximum_age}`
  if (category.minimum_age !== null) return `${category.minimum_age}+`
  return 'All ages'
}
onMounted(load)
</script>

<template>
  <div>
    <div class="d-flex flex-wrap justify-space-between align-center gap-4 mb-6">
      <div>
        <h3 class="text-h3">Membership setup</h3>
        <p class="text-body-1 text-medium-emphasis mb-0">Manage each sport code’s categories, fees and HSC levy.</p>
      </div>
      <VBtn v-if="isSuperAdmin" prepend-icon="tabler-plus" @click="newSport">Add sport code</VBtn>
    </div>

    <VRow>
      <VCol cols="12" md="4" lg="3">
        <VCard>
          <VCardTitle>Sport codes</VCardTitle>
          <VDivider />
          <VList nav>
            <VListItem v-for="code in codes" :key="code.id" :active="selectedCode?.id === code.id" @click="selectedCode = code">
              <template #prepend><VAvatar size="34" :style="{ backgroundColor: `${code.colour}22`, color: code.colour }"><VIcon icon="tabler-ball-tennis" size="20" /></VAvatar></template>
              <VListItemTitle>{{ code.name }}</VListItemTitle>
              <VListItemSubtitle>{{ code.members_count }} members · {{ code.categories.length }} categories</VListItemSubtitle>
              <template v-if="isSuperAdmin" #append><VBtn icon="tabler-edit" size="small" variant="text" @click.stop="editSport(code)" /></template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" md="8" lg="9">
        <VCard v-if="selectedCode" :loading="loading">
          <VCardTitle class="d-flex justify-space-between align-center pa-6">
            <div><span :style="{ color: selectedCode.colour }">{{ selectedCode.name }}</span> membership categories</div>
            <VBtn size="small" prepend-icon="tabler-plus" @click="newCategory">Add category</VBtn>
          </VCardTitle>
          <VDivider />
          <VTable>
            <thead><tr><th>Category</th><th>Billing</th><th>Age</th><th class="text-end">Code fee</th><th class="text-end">HSC levy</th><th class="text-end">Total</th><th>Status</th><th /></tr></thead>
            <tbody>
              <tr v-for="category in selectedCode.categories" :key="category.id">
                <td class="font-weight-medium">{{ category.name }}</td>
                <td class="text-capitalize">{{ category.billing_period.replace('_', ' ') }}</td>
                <td>{{ ageLabel(category) }}</td>
                <td class="text-end">{{ money(category.code_fee) }}</td>
                <td class="text-end">{{ money(category.hsc_levy) }}</td>
                <td class="text-end font-weight-bold">{{ money(category.total_fee) }}</td>
                <td><VChip size="small" :color="category.active ? 'success' : 'secondary'" variant="tonal">{{ category.active ? 'Active' : 'Inactive' }}</VChip></td>
                <td><VBtn icon="tabler-edit" size="small" variant="text" @click="editCategory(category)" /></td>
              </tr>
              <tr v-if="!selectedCode.categories.length"><td colspan="8" class="text-center pa-8 text-medium-emphasis">No membership categories yet.</td></tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="sportDialog" max-width="520">
      <VCard>
        <VCardTitle class="pa-6">{{ sportForm.id ? 'Edit sport code' : 'Add sport code' }}</VCardTitle>
        <VCardText>
          <VTextField v-model="sportForm.name" label="Sport code name" :error-messages="errors.name" class="mb-4" />
          <VTextField v-model="sportForm.colour" label="Colour" type="color" :error-messages="errors.colour" class="mb-2" />
          <VSwitch v-model="sportForm.active" label="Active" color="success" />
        </VCardText>
        <VCardActions class="pa-6 pt-0"><VSpacer /><VBtn variant="tonal" @click="sportDialog = false">Cancel</VBtn><VBtn :loading="saving" @click="saveSport">Save</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="categoryDialog" max-width="720">
      <VCard>
        <VCardTitle class="pa-6">{{ categoryForm.id ? 'Edit membership category' : 'Add membership category' }}</VCardTitle>
        <VCardText>
          <VRow>
            <VCol cols="12" md="7"><VTextField v-model="categoryForm.name" label="Category name" :error-messages="errors.name" /></VCol>
            <VCol cols="12" md="5"><VSelect v-model="categoryForm.billing_period" label="Billing period" :items="['monthly', 'quarterly', 'annual', 'once_off']" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model.number="categoryForm.code_fee" label="Sport-code fee (R)" type="number" min="0" step="0.01" prefix="R" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model.number="categoryForm.hsc_levy" label="HSC levy (R)" type="number" min="0" step="0.01" prefix="R" :readonly="!isCentral" :hint="!isCentral ? 'Managed by central HSC' : ''" persistent-hint /></VCol>
            <VCol cols="12" md="6"><VTextField v-model.number="categoryForm.minimum_age" label="Minimum age" type="number" min="0" clearable /></VCol>
            <VCol cols="12" md="6"><VTextField v-model.number="categoryForm.maximum_age" label="Maximum age" type="number" min="0" clearable /></VCol>
            <VCol cols="12"><VAlert type="info" variant="tonal">Member total: <strong>{{ money(Number(categoryForm.code_fee) + Number(categoryForm.hsc_levy)) }}</strong></VAlert></VCol>
            <VCol cols="12"><VSwitch v-model="categoryForm.active" label="Available for membership selection" color="success" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-6 pt-0"><VSpacer /><VBtn variant="tonal" @click="categoryDialog = false">Cancel</VBtn><VBtn :loading="saving" @click="saveCategory">Save category</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
