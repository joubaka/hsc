<script setup>
import { $api } from '@/utils/api'

const settings = reactive({
  default_currency: 'ZAR',
  invoice_prefix: 'INV',
  receipt_prefix: 'REC',
  credit_note_prefix: 'CN',
  financial_year_start_month: 1,
  default_invoice_due_days: 30,
  banking_reference_instructions: '',
  payment_reference_format: '{member_number}',
  joining_fee_owner_type: 'sport_code',
  generate_zero_value_invoices: false,
})
const loading = ref(true)
const saving = ref(false)
const message = ref('')
const error = ref('')
const months = Array.from({ length: 12 }, (_, index) => ({
  title: new Date(2026, index, 1).toLocaleString('en-ZA', { month: 'long' }),
  value: index + 1,
}))

onMounted(async () => {
  try {
    Object.assign(settings, await $api('/finance/settings'))
  } catch (exception) {
    error.value = exception?.data?.message || 'Financial settings could not be loaded.'
  } finally {
    loading.value = false
  }
})

async function save() {
  saving.value = true
  error.value = ''
  message.value = ''
  try {
    Object.assign(settings, await $api('/finance/settings', { method: 'PUT', body: settings }))
    message.value = 'Financial settings saved.'
  } catch (exception) {
    error.value = exception?.data?.message || 'Financial settings could not be saved.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div>
    <div class="mb-6">
      <h3 class="text-h3">Financial settings</h3>
      <p class="text-medium-emphasis">Central HSC controls for document numbering, due dates, references, and ownership.</p>
    </div>
    <VAlert v-if="message" type="success" class="mb-4">{{ message }}</VAlert>
    <VAlert v-if="error" type="error" class="mb-4">{{ error }}</VAlert>
    <VCard :loading="loading">
      <VCardText>
        <VRow>
          <VCol cols="12" md="4"><VTextField v-model="settings.default_currency" label="Default currency" maxlength="3" /></VCol>
          <VCol cols="12" md="4"><VTextField v-model="settings.invoice_prefix" label="Invoice prefix" /></VCol>
          <VCol cols="12" md="4"><VTextField v-model="settings.receipt_prefix" label="Receipt prefix" /></VCol>
          <VCol cols="12" md="4"><VTextField v-model="settings.credit_note_prefix" label="Credit note prefix" /></VCol>
          <VCol cols="12" md="4"><VSelect v-model="settings.financial_year_start_month" :items="months" label="Financial year starts" /></VCol>
          <VCol cols="12" md="4"><VTextField v-model.number="settings.default_invoice_due_days" type="number" min="0" max="365" label="Default payment terms (days)" /></VCol>
          <VCol cols="12" md="6"><VTextField v-model="settings.payment_reference_format" label="Payment reference format" hint="For example: {member_number}" persistent-hint /></VCol>
          <VCol cols="12" md="6"><VSelect v-model="settings.joining_fee_owner_type" :items="[{ title: 'Sport code', value: 'sport_code' }, { title: 'Central HSC', value: 'central_hsc' }]" label="Joining fee owner" /></VCol>
          <VCol cols="12"><VTextarea v-model="settings.banking_reference_instructions" label="Banking and reference instructions" rows="3" /></VCol>
          <VCol cols="12"><VSwitch v-model="settings.generate_zero_value_invoices" label="Generate zero-value invoices" color="primary" /></VCol>
        </VRow>
      </VCardText>
      <VCardActions class="px-6 pb-6"><VSpacer /><VBtn :loading="saving" @click="save">Save settings</VBtn></VCardActions>
    </VCard>
  </div>
</template>
